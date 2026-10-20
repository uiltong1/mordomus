import { useEffect, useRef, useState } from 'react'
import {
  registerServiceWorker,
  type ServiceWorkerHandle,
} from '@/features/notifications/registerServiceWorker'
import { Button } from '@/shared/ui'

type UpdateState = 'idle' | 'ready' | 'applying'

export function UpdateBanner() {
  const [state, setState] = useState<UpdateState>('idle')
  const handleRef = useRef<ServiceWorkerHandle | null>(null)

  useEffect(() => {
    if (import.meta.env.MODE !== 'production') return

    let cancelled = false

    try {
      handleRef.current = registerServiceWorker({
        onNeedRefresh: () => {
          if (!cancelled) setState('ready')
        },
        onError: (error) => console.error('pwa.register_error', error),
      })
    } catch {}

    return () => {
      cancelled = true
    }
  }, [])

  if (state === 'idle') return null

  if (state === 'applying') {
    return (
      <div
        role="status"
        className="border-b border-brand/40 bg-brand/15 px-4 py-2 text-center text-xs text-brand"
      >
        Atualizando o aplicativo...
      </div>
    )
  }

  return (
    <div
      role="status"
      data-testid="update-banner"
      className="flex flex-wrap items-center justify-center gap-3 border-b border-brand/40 bg-brand/15 px-4 py-2"
    >
      <p className="text-xs text-brand">
        Há uma versão nova do aplicativo. Se você atualizar agora, o que está preenchendo nesta tela
        pode ser perdido.
      </p>
      <div className="flex items-center gap-2">
        <Button
          size="sm"
          variant="ghost"
          onClick={() => setState('idle')}
          title="Continuar na versão atual e atualizar depois"
        >
          Agora não
        </Button>
        <Button
          size="sm"
          onClick={() => {
            setState('applying')
            handleRef.current?.applyUpdate()
          }}
        >
          Atualizar agora
        </Button>
      </div>
    </div>
  )
}
