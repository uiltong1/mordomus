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
  /** `true` só enquanto o perfil da sessão ainda não chegou do `/identity/me`. */
  isLoadingProfile: boolean
  tenants: TenantSummary[]
  capabilities: string[]
  user: StoredUser | null
  login: typeof identity.login
  register: (input: Parameters<typeof identity.register>[0]) => Promise<void>
  acceptInvitation: (token: string) => Promise<void>
  logout: () => Promise<void>
  /** Persiste um par de tokens novo: troca de residência, aceite, criação. */
  adoptSession: (grant: TokenGrant) => void
  can: (capability: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

/** Reconstrói o perfil a partir da sessão, sem round-trip: a sessão já traz tudo. */
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

  // o client central avisa quando o refresh rotativo troca o par de tokens ou
  // quando a sessão morre; sem isso a UI ficaria com o token velho em mãos
  useEffect(() => onSessionChange(setSession), [])

  const profileQuery = useQuery({
    queryKey: authKeys.profile(),
    queryFn: ({ signal }) => identity.fetchProfile(signal),
    enabled: session !== null,
    staleTime: 60_000,
  })

  const adoptSession = useCallback(
    (grant: TokenGrant) => {
      // passar pelo client central é o que publica a mudança: o
      // TenantProvider lê a residência daqui, e sem o aviso quem entra pelo
      // login ou pelo registro cairia no painel sem residência até recarregar
      const stored = persistSession(grant)
      // semeia o perfil quando a rota entrega o usuário, para a tela não
      // depender de um segundo round-trip; o refetch reconcilia em seguida
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
      } catch {
        // revogar no servidor é melhor-esforço: a sessão local cai de todo modo
      }
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
