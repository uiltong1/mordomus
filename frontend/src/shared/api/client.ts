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

const BASE = import.meta.env.VITE_API_BASE ?? 'http://localhost:8080'
const API_PREFIX = '/api/v1'
const REFRESH_PATH = '/identity/auth/refresh'
const EXPIRY_MARGIN_SECONDS = 30

export type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

export type QueryValue = string | number | boolean | null | undefined

export interface RequestOptions {
  path: string
  method?: HttpMethod
  body?: unknown
  query?: Record<string, QueryValue>
  auth?: boolean
  signal?: AbortSignal
  skipTenantLost?: boolean
}

type SessionListener = (session: StoredSession | null) => void
type TenantLostListener = (error: ApiError) => void

const listeners = new Set<SessionListener>()
const tenantLostListeners = new Set<TenantLostListener>()

export function onSessionChange(listener: SessionListener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function onTenantLost(listener: TenantLostListener): () => void {
  tenantLostListeners.add(listener)
  return () => tenantLostListeners.delete(listener)
}

const TENANT_LOST_CODES = new Set(['tenant_required', 'membership_required'])

function emitTenantLost(error: ApiError): void {
  for (const listener of tenantLostListeners) listener(error)
}

function emitSession(session: StoredSession | null): void {
  for (const listener of listeners) listener(session)
}

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
