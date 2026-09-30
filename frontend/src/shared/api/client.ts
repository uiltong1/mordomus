import { ApiError, networkError, toApiError } from './errors'
import {
  clearSession,
  getAccessToken,
  getRefreshToken,
  getSession,
  saveSession,
  type StoredSession,
  type TokenGrant,
} from './tokenStore'

/**
 * Client central da API (T4.1.2). Nenhum componente chama `fetch` diretamente:
 * é este módulo que monta a URL, injeta o `Authorization` e traduz a falha em
 * `ApiError`.
 *
 * O escopo de residência NÃO vai em header: o gateway descarta `X-Tenant-ID`
 * (ADR-011) e o `TenantScope` deriva o tenant do claim `tid` do JWT. Trocar de
 * residência é `POST /identity/auth/switch-tenant`, que emite um token novo —
 * ver `features/auth/AuthProvider`.
 */
const BASE = import.meta.env.VITE_API_BASE ?? 'http://localhost:8080'
const API_PREFIX = '/api/v1'
const REFRESH_PATH = '/identity/auth/refresh'
/** Renova antes de estourar o `exp` para a requisição não pagar o round-trip do 401. */
const EXPIRY_MARGIN_SECONDS = 30

export type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

export type QueryValue = string | number | boolean | null | undefined

export interface RequestOptions {
  path: string
  method?: HttpMethod
  body?: unknown
  query?: Record<string, QueryValue>
  /** `false` para as rotas públicas de sessão (login, registro, refresh). */
  auth?: boolean
  signal?: AbortSignal
  /**
   * A troca deresidência também recebe 403 quando o destino não é acessível,
   * mas nesse caso a residência atual continua válida — a recusa é sobre o
   * destino, não sobre a sessão.
   */
  skipTenantLost?: boolean
}

type SessionListener = (session: StoredSession | null) => void
type TenantLostListener = (error: ApiError) => void

const listeners = new Set<SessionListener>()
const tenantLostListeners = new Set<TenantLostListener>()

/** Notifica a aplicação de troca de sessão: usado no login, no refresh e no logout. */
export function onSessionChange(listener: SessionListener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/**
 * O token é válido mas a residência ativa deixou de servir: membership
 * removido, tenant arquivado ou `tid` ausente. A sessão continua de pé, então
 * quem reage é a tela — normalmente o `TenantProvider` caindo na seleção.
 */
export function onTenantLost(listener: TenantLostListener): () => void {
  tenantLostListeners.add(listener)
  return () => tenantLostListeners.delete(listener)
}

/** Códigos que significam "esta residência não é mais sua", não "sem permissão pontual". */
const TENANT_LOST_CODES = new Set(['tenant_required', 'membership_required'])

function emitTenantLost(error: ApiError): void {
  for (const listener of tenantLostListeners) listener(error)
}

function emitSession(session: StoredSession | null): void {
  for (const listener of listeners) listener(session)
}

/**
 * Persiste um par de tokens novo e avisa a aplicação. É o caminho do refresh
 * rotativo, do login e da troca de residência.
 */
export function adoptSession(grant: TokenGrant): StoredSession {
  const stored = saveSession(grant)
  emitSession(stored)
  return stored
}

export function expireSession(): void {
  clearSession()
  emitSession(null)
}

function buildUrl(path: string, query?: Record<string, QueryValue>): string {
  const url = new URL(`${API_PREFIX}${path}`, BASE)
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null) url.searchParams.set(key, String(value))
  }
  return url.toString()
}

function requestId(): string {
  return crypto.randomUUID()
}

async function send(options: RequestOptions, accessToken: string | null): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    // rastreia a requisição no log json do backend: o mesmo id volta em
    // error.request_id e no cabeçalho da resposta
    'X-Request-Id': requestId(),
  }
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  return fetch(buildUrl(options.path, options.query), {
    method: options.method ?? 'GET',
    headers,
    signal: options.signal,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  })
}

/**
 * Renova o par de tokens. O refresh é de uso único (rotação), então uma falha
 * aqui derruba a sessão inteira — inclusive quando o token foi reusado.
 */
async function performRefresh(): Promise<boolean> {
  const refreshToken = getRefreshToken()
  if (!refreshToken) return false

  try {
    const response = await send(
      { path: REFRESH_PATH, method: 'POST', body: { refresh_token: refreshToken }, auth: false },
      null,
    )
    if (!response.ok) return false

    adoptSession((await response.json()) as TokenGrant)
    return true
  } catch {
    return false
  }
}

let refreshInFlight: Promise<boolean> | null = null

/**
 * Renova uma única vez por rajada: várias queries que tomam 401 no mesmo
 * instante compartilham a mesma promessa, porque o refresh é rotativo e o
 * reuso derrubaria a sessão.
 */
function refreshSession(): Promise<boolean> {
  refreshInFlight ??= performRefresh().finally(() => {
    refreshInFlight = null
  })
  return refreshInFlight
}

function needsProactiveRefresh(): boolean {
  const session = getSession()
  if (!session) return false
  return session.expiresAt - Math.floor(Date.now() / 1000) <= EXPIRY_MARGIN_SECONDS
}

async function parse<T>(response: Response): Promise<T> {
  if (response.status === 204) return undefined as T
  const text = await response.text()
  return (text ? JSON.parse(text) : undefined) as T
}

export async function request<T>(options: RequestOptions): Promise<T> {
  const authenticated = options.auth !== false

  try {
    let token = authenticated ? getAccessToken() : null

    if (authenticated && token && needsProactiveRefresh()) {
      await refreshSession()
      token = getAccessToken()
    }

    let response = await send(options, token)

    if (authenticated && response.status === 401) {
      const renewed = await refreshSession()
      if (!renewed) {
        expireSession()
        throw await toApiError(response)
      }
      response = await send(options, getAccessToken())
    }

    if (!response.ok) {
      const error = await toApiError(response)
      if (authenticated && !options.skipTenantLost && TENANT_LOST_CODES.has(error.code)) {
        emitTenantLost(error)
      }
      throw error
    }

    return await parse<T>(response)
  } catch (error) {
    if (error instanceof ApiError) throw error
    if (isAbort(error)) throw error
    throw networkError(error)
  }
}

function isAbort(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

/** Atalho para quando a resposta já vem embrulhada em `{ data: ... }`. */
export function unwrap<T>(response: { data: T }): T {
  return response.data
}

export const api = {
  get: <T>(path: string, options: Omit<RequestOptions, 'path' | 'method' | 'body'> = {}) =>
    request<T>({ ...options, path, method: 'GET' }),
  post: <T>(
    path: string,
    body?: unknown,
    options: Omit<RequestOptions, 'path' | 'method' | 'body'> = {},
  ) => request<T>({ ...options, path, method: 'POST', body }),
  patch: <T>(
    path: string,
    body?: unknown,
    options: Omit<RequestOptions, 'path' | 'method' | 'body'> = {},
  ) => request<T>({ ...options, path, method: 'PATCH', body }),
  put: <T>(
    path: string,
    body?: unknown,
    options: Omit<RequestOptions, 'path' | 'method' | 'body'> = {},
  ) => request<T>({ ...options, path, method: 'PUT', body }),
  delete: <T>(path: string, options: Omit<RequestOptions, 'path' | 'method' | 'body'> = {}) =>
    request<T>({ ...options, path, method: 'DELETE' }),
}
