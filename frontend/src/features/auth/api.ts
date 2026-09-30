import { api, unwrap } from '@/shared/api/client'
import type {
  AcceptedInvitation,
  Member,
  Paginated,
  Profile,
  Session,
  Tenant,
  TenantCreatedSession,
} from '@/shared/api/types'

/** Rotas públicas de sessão — o token ainda não existe. */
export function login(input: {
  email: string
  password: string
  tenant_id?: string
}): Promise<Session> {
  return api.post<Session>('/identity/auth/login', input, { auth: false })
}

export function register(input: {
  name: string
  email: string
  password: string
  password_confirmation: string
  home_name?: string
  timezone?: string
}): Promise<Session> {
  return api.post<Session>('/identity/auth/register', input, { auth: false })
}

export function logout(refreshToken: string): Promise<{ status: string }> {
  return api.post<{ status: string }>('/identity/auth/logout', {
    refresh_token: refreshToken,
  })
}

/**
 * Troca de residência. Não é um header: o `TenantScope` deriva o tenant do claim
 * `tid`, então a troca emite um JWT novo (R1). O par anterior deixa de valer.
 */
export function switchTenant(tenantId: string): Promise<Session> {
  return api.post<Session>('/identity/auth/switch-tenant', { tenant_id: tenantId })
}

export function acceptInvitation(token: string): Promise<AcceptedInvitation> {
  return api.post<AcceptedInvitation>(`/identity/invitations/${encodeURIComponent(token)}/accept`)
}

export function fetchProfile(signal?: AbortSignal): Promise<Profile> {
  return api.get<{ data: Profile }>('/identity/me', { signal }).then(unwrap)
}

export function fetchTenants(signal?: AbortSignal): Promise<Paginated<Tenant>> {
  return api.get<Paginated<Tenant>>('/identity/tenants', { signal })
}

/** `POST /tenants` devolve sessão nova já apontada para a residência criada. */
export function createTenant(input: {
  name: string
  timezone?: string
  preferred_hour?: string
}): Promise<TenantCreatedSession> {
  return api.post<TenantCreatedSession>('/identity/tenants', input)
}

export function fetchTenant(tenantId: string, signal?: AbortSignal): Promise<Tenant> {
  return api.get<{ data: Tenant }>(`/identity/tenants/${tenantId}`, { signal }).then(unwrap)
}

export function fetchMembers(tenantId: string, signal?: AbortSignal): Promise<Member[]> {
  return api
    .get<{ data: Member[] }>(`/identity/tenants/${tenantId}/members`, { signal })
    .then(unwrap)
}
