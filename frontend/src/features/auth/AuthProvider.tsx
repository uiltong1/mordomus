import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { adoptSession as persistSession, expireSession, onSessionChange } from '@/shared/api/client'
import { authKeys } from '@/shared/api/queryKeys'
import {
  getRefreshToken,
  getSession,
  type StoredSession,
  type StoredUser,
  type TokenGrant,
} from '@/shared/api/tokenStore'
import type { Profile, TenantSummary } from '@/shared/api/types'
import * as identity from './api'

export interface AuthContextValue {
  session: StoredSession | null
  profile: Profile | null
  isAuthenticated: boolean
  isLoadingProfile: boolean
  tenants: TenantSummary[]
  capabilities: string[]
  user: StoredUser | null
  login: typeof identity.login
  register: (input: Parameters<typeof identity.register>[0]) => Promise<void>
  acceptInvitation: (token: string) => Promise<void>
  logout: () => Promise<void>
  adoptSession: (grant: TokenGrant) => void
  can: (capability: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

function profileFromGrant(grant: TokenGrant, user: StoredUser): Profile {
  const tenants = grant.tenants ?? []
  const active = tenants.find((tenant) => tenant.id === grant.active_tenant)
  return {
    user: { ...user, locale: 'pt_BR' },
    active_tenant: grant.active_tenant,
    tenants,
    capabilities: active?.capabilities ?? [],
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [session, setSession] = useState<StoredSession | null>(() => getSession())

  useEffect(() => onSessionChange(setSession), [])

  const profileQuery = useQuery({
    queryKey: authKeys.profile(),
    queryFn: ({ signal }) => identity.fetchProfile(signal),
    enabled: session !== null,
    staleTime: 60_000,
  })

  const adoptSession = useCallback(
    (grant: TokenGrant) => {
      const stored = persistSession(grant)
      if (stored.user) {
        queryClient.setQueryData(authKeys.profile(), profileFromGrant(grant, stored.user))
      }
      void queryClient.invalidateQueries({ queryKey: authKeys.profile() })
    },
    [queryClient],
  )

  const logout = useCallback(async () => {
    const refreshToken = getRefreshToken()
    if (refreshToken) {
      try {
        await identity.logout(refreshToken)
      } catch {}
    }
    expireSession()
    queryClient.clear()
  }, [queryClient])

  const value = useMemo<AuthContextValue>(() => {
    const profile = profileQuery.data ?? null
    const capabilities = profile?.capabilities ?? []

    return {
      session,
      profile,
      user: profile?.user ?? session?.user ?? null,
      isAuthenticated: session !== null,
      isLoadingProfile: session !== null && profileQuery.isPending,
      tenants: profile?.tenants ?? [],
      capabilities,
      login: identity.login,
      register: async (input) => {
        adoptSession(await identity.register(input))
      },
      acceptInvitation: async (token) => {
        adoptSession(await identity.acceptInvitation(token))
      },
      logout,
      adoptSession,
      can: (capability: string) => capabilities.includes(capability),
    }
  }, [adoptSession, logout, profileQuery.data, profileQuery.isPending, session])

  return <AuthContext value={value}>{children}</AuthContext>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth precisa estar dentro de <AuthProvider>')
  return context
}
