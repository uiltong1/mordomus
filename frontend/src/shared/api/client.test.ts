import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, onSessionChange, request } from './client'
import { ApiError } from './errors'
import { getSession, saveSession, setActiveTenant } from './tokenStore'
import { errorResponse, jsonResponse } from '@/test/utils'

const BASE = 'http://localhost:8080/api/v1'

function sessionFixture(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    token_type: 'bearer',
    access_token: 'access-1',
    refresh_token: 'refresh-1',
    expires_in: 3600,
    active_tenant: '01J8Z0M9W3K6Q2T4R5Y7B8C9D0',
    refresh_expires_in: 2592000,
    user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
    tenants: [],
    ...overrides,
  }
}

function fetchMock(...responses: Response[]) {
  const mock = vi.fn()
  for (const response of responses) mock.mockResolvedValueOnce(response)
  vi.stubGlobal('fetch', mock)
  return mock
}

describe('api client', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
  })

  it('injeta o Authorization e monta a URL com o prefixo de versão', async () => {
    saveSession(sessionFixture())
    const mock = fetchMock(jsonResponse({ data: [] }))

    await api.get('/identity/tenants')

    const [url, init] = mock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe(`${BASE}/identity/tenants`)
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer access-1')
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
  })

  it('nunca envia X-Tenant-ID: o gateway descarta e o escopo vem do JWT', async () => {
    saveSession(sessionFixture())
    setActiveTenant('01J8Z0M9W3K6Q2T4R5Y7B8C9D0')
    const mock = fetchMock(jsonResponse({ data: {} }))

    await api.get('/identity/me')

    const headers = (mock.mock.calls[0]![1] as RequestInit).headers as Record<string, string>
    expect(Object.keys(headers).map((key) => key.toLowerCase())).not.toContain('x-tenant-id')
  })

  it('não envia Authorization nas rotas públicas de sessão', async () => {
    const mock = fetchMock(jsonResponse(sessionFixture()))

    await api.post('/identity/auth/login', { email: 'a@b.c', password: 'x' }, { auth: false })

    const headers = (mock.mock.calls[0]![1] as RequestInit).headers as Record<string, string>
    expect(headers.Authorization).toBeUndefined()
    expect(headers['Content-Type']).toBe('application/json')
  })

  it('renova o token no 401 e repete a requisição original', async () => {
    saveSession(sessionFixture())
    const mock = fetchMock(
      errorResponse(401, { code: 'unauthenticated', message: 'Token inválido ou ausente.' }),
      jsonResponse(sessionFixture({ access_token: 'access-2', refresh_token: 'refresh-2' })),
      jsonResponse({ data: { id: 'u1' } }),
    )

    const result = await request<{ data: { id: string } }>({ path: '/identity/me' })

    expect(result.data.id).toBe('u1')
    expect(mock).toHaveBeenCalledTimes(3)
    // a repetição vai com o token novo, não com o velho que deu 401
    const retried = (mock.mock.calls[2]![1] as RequestInit).headers as Record<string, string>
    expect(retried.Authorization).toBe('Bearer access-2')
    expect(getSession()?.accessToken).toBe('access-2')
  })

  it('faz um único refresh quando várias requisições tomam 401 juntas', async () => {
    saveSession(sessionFixture())
    const mock = fetchMock(
      errorResponse(401, { code: 'unauthenticated', message: 'Token inválido.' }),
      errorResponse(401, { code: 'unauthenticated', message: 'Token inválido.' }),
      jsonResponse(sessionFixture({ access_token: 'access-2', refresh_token: 'refresh-2' })),
      jsonResponse({ data: { id: 'u1' } }),
      jsonResponse({ data: { id: 'u1' } }),
    )

    await Promise.all([request({ path: '/identity/me' }), request({ path: '/identity/tenants' })])

    const refreshCalls = mock.mock.calls.filter((call) =>
      String(call[0]).includes('/identity/auth/refresh'),
    )
    expect(refreshCalls).toHaveLength(1)
  })

  it('renova antes do exp estourar, sem esperar o 401', async () => {
    // token com 10 s de vida entra na margem de 30 s: a próxima requisição
    // tem de sair já com o token novo, senão ela paga um 401 à toa
    saveSession(sessionFixture({ expires_in: 10, access_token: 'access-velho' }))
    const mock = fetchMock(
      jsonResponse(sessionFixture({ access_token: 'access-novo', refresh_token: 'refresh-2' })),
      jsonResponse({ data: { id: 'u1' } }),
    )

    await request({ path: '/identity/me' })

    expect(mock).toHaveBeenCalledTimes(2)
    // o refresh vem primeiro: a requisição original nunca levou o token velho
    expect(String(mock.mock.calls[0]![0])).toContain('/identity/auth/refresh')
    const headers = (mock.mock.calls[1]![1] as RequestInit).headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer access-novo')
  })

  it('não renova com folga de sobra para não gastar refresh à toa', async () => {
    saveSession(sessionFixture({ expires_in: 3600, access_token: 'access-1' }))
    const mock = fetchMock(jsonResponse({ data: { id: 'u1' } }))

    await request({ path: '/identity/me' })

    expect(mock).toHaveBeenCalledOnce()
    expect(String(mock.mock.calls[0]![0])).not.toContain('/auth/refresh')
  })

  it('derruba a sessão quando o refresh rotativo é rejeitado', async () => {
    saveSession(sessionFixture())
    fetchMock(
      errorResponse(401, { code: 'unauthenticated', message: 'Token inválido.' }),
      errorResponse(401, { code: 'refresh_token_reused', message: 'Refresh token já utilizado.' }),
    )
    const seen: (string | null)[] = []
    const stop = onSessionChange((session) => seen.push(session?.accessToken ?? null))

    await expect(request({ path: '/identity/me' })).rejects.toMatchObject({
      code: 'unauthenticated',
    })

    expect(getSession()).toBeNull()
    expect(seen).toContain(null)
    stop()
  })

  it('traduz 422 em erros por campo', async () => {
    saveSession(sessionFixture())
    fetchMock(
      errorResponse(422, {
        code: 'validation_failed',
        message: 'Dados inválidos.',
        details: { email: ['Este e-mail já está cadastrado.'] },
        request_id: 'smoke-0001',
      }),
    )

    const error = await request({ path: '/identity/auth/register' }).catch(
      (cause: unknown) => cause,
    )

    expect(error).toBeInstanceOf(ApiError)
    const apiError = error as ApiError
    expect(apiError.status).toBe(422)
    expect(apiError.code).toBe('validation_failed')
    expect(apiError.fieldErrors.email).toEqual(['Este e-mail já está cadastrado.'])
    expect(apiError.requestId).toBe('smoke-0001')
  })

  it('preserva os 403 de domínio, que a interface trata por código', async () => {
    saveSession(sessionFixture())
    fetchMock(
      errorResponse(403, {
        code: 'tenant_required',
        message: 'Nenhuma residência ativa no token.',
      }),
    )

    const error = (await request({ path: '/maintenance/rooms' }).catch(
      (cause: unknown) => cause,
    )) as ApiError

    expect(error.isForbidden).toBe(true)
    expect(error.needsTenant).toBe(true)
  })

  it('cai no envelope do status quando o gateway responde fora do contrato', async () => {
    saveSession(sessionFixture())
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('<html>rate limited</html>', { status: 429 })),
    )

    const error = (await request({ path: '/identity/me' }).catch(
      (cause: unknown) => cause,
    )) as ApiError

    expect(error.isRateLimited).toBe(true)
    expect(error.code).toBe('http_error')
  })

  it('converte falha de rede em erro conhecido, sem engolir o AbortError', async () => {
    saveSession(sessionFixture())
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    const error = (await request({ path: '/identity/me' }).catch(
      (cause: unknown) => cause,
    )) as ApiError
    expect(error.isNetwork).toBe(true)

    const controller = new AbortController()
    vi.stubGlobal(
      'fetch',
      vi.fn().mockRejectedValue(new DOMException('The operation was aborted.', 'AbortError')),
    )
    await expect(
      request({ path: '/identity/me', signal: controller.signal }),
    ).rejects.toBeInstanceOf(DOMException)
  })
})
