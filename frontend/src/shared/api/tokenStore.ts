const SESSION_KEY = 'mordomus.token'
const TENANT_KEY = 'mordomus.tenant'

export interface StoredUser {
  id: string
  name: string
  email: string
}

export interface TokenGrant {
  access_token: string
  refresh_token: string
  expires_in: number
  active_tenant: string | null
  user?: StoredUser
  tenants?: {
    id: string
    name: string
    slug: string
    role: string
    role_key: string
    capabilities: string[]
  }[]
}

export interface StoredSession {
  accessToken: string
  refreshToken: string
  expiresAt: number
  user: StoredUser | null
}

function readJson<T>(key: string): T | null {
  try {
    const raw = window.localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : null
  } catch {
    return null
  }
}

function writeJson(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {}
}

let memory: StoredSession | null = null

export function getSession(): StoredSession | null {
  return readJson<StoredSession>(SESSION_KEY) ?? memory
}

export function saveSession(grant: TokenGrant): StoredSession {
  const previous = getSession()
  const stored: StoredSession = {
    accessToken: grant.access_token,
    refreshToken: grant.refresh_token,
    expiresAt: Math.floor(Date.now() / 1000) + grant.expires_in,
    user: grant.user ?? previous?.user ?? null,
  }
  memory = stored
  writeJson(SESSION_KEY, stored)
  setActiveTenant(grant.active_tenant)
  return stored
}

export function clearSession(): void {
  memory = null
  try {
    window.localStorage.removeItem(SESSION_KEY)
    window.localStorage.removeItem(TENANT_KEY)
  } catch {}
}

export function getAccessToken(): string | null {
  return getSession()?.accessToken ?? null
}

export function getRefreshToken(): string | null {
  return getSession()?.refreshToken ?? null
}

export function getActiveTenant(): string | null {
  try {
    return window.localStorage.getItem(TENANT_KEY)
  } catch {
    return null
  }
}

export function setActiveTenant(tenantId: string | null): void {
  try {
    if (tenantId) window.localStorage.setItem(TENANT_KEY, tenantId)
    else window.localStorage.removeItem(TENANT_KEY)
  } catch {}
}
