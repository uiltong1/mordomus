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
import { onSessionChange, onTenantLost } from '@/shared/api/client'
import { getActiveTenant, setActiveTenant } from '@/shared/api/tokenStore'
import type { TenantSummary } from '@/shared/api/types'
import { useAuth } from '@/features/auth/AuthProvider'
import { switchTenant as switchTenantRequest } from '@/features/auth/api'

export interface TenantContextValue {
  activeTenantId: string | null
  activeTenant: TenantSummary | null
  tenants: TenantSummary[]
  isSwitching: boolean
  needsTenant: boolean
  switchTenant: (tenantId: string) => Promise<void>
}

const TenantContext = createContext<TenantContextValue | null>(null)

export function TenantProvider({ children }: { children: ReactNode }) {
  const { adoptSession, tenants, isAuthenticated } = useAuth()
  const queryClient = useQueryClient()
  const [activeTenantId, setActiveTenantId] = useState<string | null>(() => getActiveTenant())
  const [isSwitching, setIsSwitching] = useState(false)

  useEffect(
    () =>
      onSessionChange(() => {
        setActiveTenantId(getActiveTenant())
      }),
    [],
  )

  useEffect(
    () =>
      onTenantLost(() => {
        setActiveTenant(null)
        setActiveTenantId(null)
      }),
    [],
  )

  const switchTenant = useCallback(
    async (tenantId: string) => {
      if (tenantId === getActiveTenant()) return
      setIsSwitching(true)
      try {
        const session = await switchTenantRequest(tenantId)
        queryClient.clear()
        adoptSession(session)
        setActiveTenantId(session.active_tenant)
      } catch (error) {
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
