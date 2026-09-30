import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { adoptSession, expireSession } from '@/shared/api/client'
import { saveSession, getActiveTenant } from '@/shared/api/tokenStore'
import type { Session } from '@/shared/api/types'
import { ToastProvider } from '@/shared/ui'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { TenantProvider, useTenant } from './TenantProvider'
import { createTestQueryClient, jsonResponse } from '@/test/utils'
import type * as IdentityApi from '@/features/auth/api'

vi.mock('@/features/auth/api', async (importOriginal) => {
  const actual = await importOriginal<typeof IdentityApi>()
  return { ...actual, switchTenant: vi.fn() }
})

const { switchTenant } = await import('@/features/auth/api')

const TENANT_A = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const TENANT_B = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'

function summary(id: string, name: string) {
  return {
    id,
    name,
    slug: name.toLowerCase(),
    role: 'Proprietário',
    role_key: 'owner',
    capabilities: ['rooms.manage'],
  }
}

function sessionFixture(activeTenant: string | null, accessToken = 'access-1') {
  return {
    token_type: 'bearer',
    access_token: accessToken,
    refresh_token: 'refresh-1',
    expires_in: 3600,
    active_tenant: activeTenant,
    refresh_expires_in: 2592000,
    user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
    tenants: [summary(TENANT_A, 'Casa'), summary(TENANT_B, 'Apartamento')],
  }
}

function wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={createTestQueryClient()}>
      <ToastProvider>
        <AuthProvider>
          <TenantProvider>{children}</TenantProvider>
        </AuthProvider>
      </ToastProvider>
    </QueryClientProvider>
  )
}

function renderTenant() {
  return renderHook(() => ({ tenant: useTenant(), queryClient: useQueryClient() }), { wrapper })
}

describe('TenantProvider', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // o perfil vem do /identity/me; a lista de casas é o que o seletor mostra
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          data: {
            user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
            active_tenant: TENANT_A,
            tenants: [summary(TENANT_A, 'Casa'), summary(TENANT_B, 'Apartamento')],
            capabilities: ['rooms.manage'],
          },
        }),
      ),
    )
  })

  it('recupera a residência ativa do localStorage e a expõe no contexto', async () => {
    saveSession(sessionFixture(TENANT_A))

    const { result } = renderTenant()

    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))
    expect(result.current.tenant.activeTenantId).toBe(TENANT_A)
    expect(result.current.tenant.activeTenant?.name).toBe('Casa')
    expect(result.current.tenant.needsTenant).toBe(false)
  })

  it('pede uma residência quando a sessão não tem tid', async () => {
    saveSession(sessionFixture(null))
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          data: {
            user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
            active_tenant: null,
            tenants: [],
            capabilities: [],
          },
        }),
      ),
    )

    const { result } = renderTenant()

    await waitFor(() => expect(result.current.tenant.needsTenant).toBe(true))
    expect(result.current.tenant.activeTenantId).toBeNull()
  })

  it('trocar de residência adota o token novo e limpa o cache inteiro (R6)', async () => {
    saveSession(sessionFixture(TENANT_A))
    const { result } = renderTenant()
    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))

    // dado da casa A já em cache: se sobreviver à troca, vaza para a casa B
    result.current.queryClient.setQueryData(['mordomus', 'rooms', TENANT_A], ['sala'])
    expect(result.current.queryClient.getQueryData(['mordomus', 'rooms', TENANT_A])).toEqual([
      'sala',
    ])

    vi.mocked(switchTenant).mockResolvedValue(sessionFixture(TENANT_B, 'access-2') as Session)

    await act(async () => {
      await result.current.tenant.switchTenant(TENANT_B)
    })

    expect(switchTenant).toHaveBeenCalledWith(TENANT_B)
    await waitFor(() => expect(result.current.tenant.activeTenantId).toBe(TENANT_B))
    expect(result.current.queryClient.getQueryData(['mordomus', 'rooms', TENANT_A])).toBeUndefined()
    expect(result.current.tenant.activeTenant?.name).toBe('Apartamento')
  })

  it('persiste a residência nova em localStorage', async () => {
    saveSession(sessionFixture(TENANT_A))
    const { result } = renderTenant()
    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))

    vi.mocked(switchTenant).mockResolvedValue(sessionFixture(TENANT_B, 'access-2') as Session)

    await act(async () => {
      await result.current.tenant.switchTenant(TENANT_B)
    })

    expect(getActiveTenant()).toBe(TENANT_B)
  })

  it('mantém a residência atual quando o backend recusa a troca com 403', async () => {
    saveSession(sessionFixture(TENANT_A))
    const { result } = renderTenant()
    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))

    vi.mocked(switchTenant).mockRejectedValue(
      new ApiError(403, { code: 'membership_required', message: 'Sem acesso à residência.' }),
    )

    await act(async () => {
      await expect(result.current.tenant.switchTenant(TENANT_B)).rejects.toBeInstanceOf(ApiError)
    })

    expect(result.current.tenant.activeTenantId).toBe(TENANT_A)
    expect(result.current.tenant.isSwitching).toBe(false)
  })

  it('não chama o backend quando a residência escolhida é a atual', async () => {
    saveSession(sessionFixture(TENANT_A))
    const { result } = renderTenant()
    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))

    await act(async () => {
      await result.current.tenant.switchTenant(TENANT_A)
    })

    expect(switchTenant).not.toHaveBeenCalled()
  })

  it('acompanha o tid novo quando o refresh rotativo reemite a sessão', async () => {
    saveSession(sessionFixture(TENANT_A))
    const { result } = renderTenant()
    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))

    // o refresh devolve a primeira residência ativa do usuário (TECHSPEC §4.1)
    await act(async () => {
      adoptSession(sessionFixture(TENANT_B, 'access-2') as Session)
    })

    await waitFor(() => expect(result.current.tenant.activeTenantId).toBe(TENANT_B))
  })

  it('não deixa residência ativa quando a sessão é encerrada', async () => {
    saveSession(sessionFixture(TENANT_A))
    const { result } = renderTenant()
    await waitFor(() => expect(result.current.tenant.tenants).toHaveLength(2))
    expect(result.current.tenant.activeTenantId).toBe(TENANT_A)

    await act(async () => {
      expireSession()
    })

    await waitFor(() => expect(result.current.tenant.activeTenantId).toBeNull())
    expect(result.current.tenant.needsTenant).toBe(false)
  })
})
