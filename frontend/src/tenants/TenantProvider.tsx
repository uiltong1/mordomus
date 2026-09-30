import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { onSessionChange } from '@/shared/api/client'
import { getActiveTenant } from '@/shared/api/tokenStore'
import type { TenantSummary } from '@/shared/api/types'
import { useAuth } from '@/features/auth/AuthProvider'
import { switchTenant as switchTenantRequest } from '@/features/auth/api'

export interface TenantContextValue {
  /** Residência ativa — `null` quando o morador ainda não escolheu uma. */
  activeTenantId: string | null
  activeTenant: TenantSummary | null
  tenants: TenantSummary[]
  isSwitching: boolean
  /** `true` quando há sessão, mas nenhuma residência escolhida. */
  needsTenant: boolean
  switchTenant: (tenantId: string) => Promise<void>
}

const TenantContext = createContext<TenantContextValue | null>(null)

/**
 * Residência ativa (T4.1.3/T4.1.4, regra R6).
 *
 * Trocar de residência não é mudar um header: `POST /identity/auth/switch-tenant`
 * emite um JWT com o `tid` novo, e só então o cache pode ser reconstruído. O
 * `clear()` é o que garante o critério de aceite — nada do lar anterior pode
 * reaparecer na tela, nem por um frame, porque as chaves são namespaced por
 * tenant e a cache inteira é zerada antes do próximo render com dados.
 */
export function TenantProvider({ children }: { children: ReactNode }) {
  const { adoptSession, tenants, isAuthenticated } = useAuth()
  const queryClient = useQueryClient()
  const [activeTenantId, setActiveTenantId] = useState<string | null>(() => getActiveTenant())
  const [isSwitching, setIsSwitching] = useState(false)

  // o refresh rotativo reemite o token com a primeira residência ativa do
  // usuário; a tela precisa acompanhar em vez de mostrar o tid velho
  useEffect(
    () =>
      onSessionChange(() => {
        setActiveTenantId(getActiveTenant())
      }),
    [],
  )

  const switchTenant = useCallback(
    async (tenantId: string) => {
      if (tenantId === getActiveTenant()) return
      setIsSwitching(true)
      try {
        const session = await switchTenantRequest(tenantId)
        // R6 — invalida tudo: o cache inteiro pertence à residência anterior.
        // Antes de adotar a sessão nova, senão o clear levaria junto o perfil
        // recém-semeado e o selector piscaria "Sem residência" a cada troca.
        queryClient.clear()
        // o token novo é o que carrega o escopo: só depois de adotá-lo a
        // residência nova é a real
        adoptSession(session)
        setActiveTenantId(session.active_tenant)
      } catch (error) {
        // 403 membership_required: o usuário não pertence mais àquela casa.
        // O token segue válido, então a tela recua para a residencia ativa.
        if (error instanceof ApiError && error.isForbidden) {
          setActiveTenantId(getActiveTenant())
        }
        throw error
      } finally {
        setIsSwitching(false)
      }
    },
    [adoptSession, queryClient],
  )

  const value = useMemo<TenantContextValue>(() => {
    // sem sessão não há residência ativa: derivar aqui evita um frame mostrando
    // a casa de quem acabou de sair
    const current = isAuthenticated ? activeTenantId : null
    const activeTenant = tenants.find((tenant) => tenant.id === current) ?? null
    return {
      activeTenantId: current,
      activeTenant,
      tenants,
      isSwitching,
      needsTenant: isAuthenticated && current === null,
      switchTenant,
    }
  }, [activeTenantId, isAuthenticated, isSwitching, switchTenant, tenants])

  return <TenantContext value={value}>{children}</TenantContext>
}

export function useTenant(): TenantContextValue {
  const context = useContext(TenantContext)
  if (!context) throw new Error('useTenant precisa estar dentro de <TenantProvider>')
  return context
}
