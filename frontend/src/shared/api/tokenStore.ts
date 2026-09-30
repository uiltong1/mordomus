/**
 * Sessão persistida em `localStorage`. Os nomes seguem o TECHSPEC §2.1
 * (`mordomus.token`, `mordomus.tenant`).
 *
 * O `active_tenant` fica guardado separado do par de tokens porque é a única
 * coisa que o `TenantProvider` precisa ler antes de qualquer requisição —
 * inclusive para decidir se há residência ativa.
 */
const SESSION_KEY = 'mordomus.token'
const TENANT_KEY = 'mordomus.tenant'

export interface StoredUser {
  id: string
  name: string
  email: string
}

/**
 * O que toda rota de sessão entrega. `Session`, o aceite de convite e a
 * criação de residência divergem no resto do envelope, mas sempre trazem o par
 * de tokens e o `active_tenant` correspondente.
 */
export interface TokenGrant {
  access_token: string
  refresh_token: string
  expires_in: number
  active_tenant: string | null
  user?: StoredUser
  /** Só `POST /tenants` não devolve a lista; as demais sessões trazem. */
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
  /** epoch em segundos, do claim `exp` derivado de `expires_in`. */
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
  } catch {
    // modo privado / quota cheia: a sessão vive apenas em memória
  }
}

let memory: StoredSession | null = null

export function getSession(): StoredSession | null {
  return readJson<StoredSession>(SESSION_KEY) ?? memory
}

/**
 * `user` é opcional porque `POST /tenants` devolve tokens sem o usuário — quem
 * cria uma residência continua sendo a mesma pessoa, então a anterior vale.
 */
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
  // sempre reescreve: o refresh pode devolver `active_tenant` nulo e a
  // residência anterior não pode sobreviver à sessão
  setActiveTenant(grant.active_tenant)
  return stored
}

export function clearSession(): void {
  memory = null
  try {
    window.localStorage.removeItem(SESSION_KEY)
    window.localStorage.removeItem(TENANT_KEY)
  } catch {
    // storage indisponível: o cache em memória já foi limpo
  }
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
  } catch {
    // sem storage o tenant vive só no estado do provider
  }
}
