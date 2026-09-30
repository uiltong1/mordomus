/**
 * Tipos do contrato da API, derivados dos schemas do OpenAPI do monólito
 * (`make openapi`). Mudou o schema, muda o tipo.
 */

export interface User {
  id: string
  name: string
  email: string
  locale: string
}

export interface TenantSummary {
  id: string
  name: string
  slug: string
  role: string
  role_key: string
  capabilities: string[]
}

export interface RoleRef {
  id: string
  key: string
  name: string
}

export interface Tenant {
  id: string
  name: string
  slug: string
  timezone: string
  preferred_hour: string
  archived: boolean
  archived_at: string | null
  role: RoleRef | null
  capabilities: string[]
  created_at: string | null
}

export interface Member {
  id: string
  user: { id: string; name: string; email: string }
  role: RoleRef
  status: 'active' | 'invited' | 'archived'
  capabilities: string[]
}

export interface PageMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export interface Profile {
  user: User
  active_tenant: string | null
  tenants: TenantSummary[]
  capabilities: string[]
}

/** `POST /auth/login`, `/auth/register`, `/auth/refresh`, `/auth/switch-tenant`. */
export interface Session {
  token_type: string
  access_token: string
  expires_in: number
  active_tenant: string | null
  refresh_token: string
  refresh_expires_in: number
  user: User
  tenants: TenantSummary[]
}

/**
 * `POST /invitations/{token}/accept` devolve um terceiro formato de sessão:
 * sem `refresh_expires_in` e com o `tenant` convidado à parte.
 */
export interface AcceptedInvitation {
  token_type: string
  access_token: string
  expires_in: number
  active_tenant: string | null
  refresh_token: string
  tenant: { id: string; name: string; slug: string; role: string }
  user: { id: string; name: string; email: string }
  tenants: TenantSummary[]
}

/** `POST /tenants` devolve sessão + o tenant criado, mas sem `user`/`tenants`. */
export interface TenantCreatedSession {
  token_type: string
  access_token: string
  expires_in: number
  refresh_token: string
  refresh_expires_in: number
  active_tenant: string
  data: Tenant
}

/** Capacidades do RBAC (ADR-007); `role_permissions` no monólito. */
export const CAPABILITIES = [
  'tenant.manage',
  'members.manage',
  'rooms.manage',
  'assets.manage',
  'rules.edit',
  'occurrences.complete',
  'occurrences.skip',
  'bills.manage',
  'bills.pay',
  'splits.manage',
  'splits.view_own',
  'notifications.manage',
] as const

export type Capability = (typeof CAPABILITIES)[number]
