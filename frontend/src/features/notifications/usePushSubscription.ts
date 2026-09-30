import { useCallback, useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { resourceKeys } from '@/shared/api/queryKeys'
import { useTenant } from '@/tenants/TenantProvider'
import { registerDevice, removeDevice } from './devicesApi'
import {
  askPermission,
  currentPermission,
  currentSubscription,
  isPushSupported,
  subscribePush,
  unsubscribePush,
} from './push'

/** Chave pública VAPID do ambiente. As chaves são geradas na T6.1.4. */
const VAPID_PUBLIC_KEY = import.meta.env.VITE_VAPID_PUBLIC_KEY ?? ''

export type PushState = 'unsupported' | 'default' | 'granted' | 'denied'

export interface UsePushSubscription {
  state: PushState
  isSubscribed: boolean
  isPending: boolean
  /** `true` quando o navegador aceitou mas o backend ainda não tem a rota. */
  isBackendMissing: boolean
  /** Recusa do navegador (permissão negada) ou chave VAPID ausente. */
  error: string | null
  enable: () => void
  disable: () => void
}

/**
 * Assina o Web Push e entrega a assinatura ao backend.
 *
 * A permissão nunca é pedida no carregamento: só no clique do sino, senão o
 * prompt chega antes de o morador saber do que se trata e a resposta é
 * «não» para sempre (TECHSPEC §4.7).
 */
export function usePushSubscription(): UsePushSubscription {
  const queryClient = useQueryClient()
  const { activeTenantId } = useTenant()
  // a permissão é legível de forma síncrona no primeiro render — buscar no
  // efeito custaria um render a mais sem ganhar nada
  const [state, setState] = useState<PushState>(() =>
    isPushSupported() ? currentPermission() : 'unsupported',
  )
  const [isSubscribed, setIsSubscribed] = useState(false)
  const [isBackendMissing, setIsBackendMissing] = useState(false)

  useEffect(() => {
    let alive = true
    void currentSubscription().then((subscription) => {
      if (alive) setIsSubscribed(subscription !== null)
    })
    return () => {
      alive = false
    }
  }, [])

  const mutation = useMutation({
    mutationFn: async (action: 'enable' | 'disable') => {
      if (action === 'disable') {
        const existing = await currentSubscription()
        if (!existing) return
        // desinscrever localmente sem tirar do backend deixaria o servidor
        // mandando para uma assinatura morta
        try {
          await removeDevice(existing.endpoint)
        } catch {
          // melhor-esforço: o cancelamento local acontece de todo modo
        }
        await unsubscribePush()
        setIsSubscribed(false)
        return
      }

      if (!VAPID_PUBLIC_KEY) {
        throw new Error(
          'Este ambiente ainda não tem chave de push configurada (VITE_VAPID_PUBLIC_KEY).',
        )
      }

      const permission = await askPermission()
      setState(permission)
      if (permission !== 'granted') throw new Error('Permissão de notificação negada.')

      const subscription = await subscribePush(VAPID_PUBLIC_KEY)
      setIsSubscribed(true)
      // o registro no backend é melhor-esforço: sem a rota da T6.1 o push
      // continua funcionando localmente e a UI reporta a pendência
      try {
        await registerDevice(subscription)
        setIsBackendMissing(false)
      } catch (error) {
        setIsBackendMissing(error instanceof ApiError && error.status === 404)
      }
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.devices(activeTenantId) })
    },
  })

  const enable = useCallback(() => mutation.mutate('enable'), [mutation])
  const disable = useCallback(() => mutation.mutate('disable'), [mutation])

  return {
    state,
    isSubscribed,
    isPending: mutation.isPending,
    isBackendMissing,
    error: mutation.isError ? (mutation.error as Error).message : null,
    enable,
    disable,
  }
}
