import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as IdentityApi from '@/features/auth/api'
import { ApiError } from '@/shared/api/errors'
import { getSession } from '@/shared/api/tokenStore'
import { renderWithProviders } from '@/test/utils'
import { CreateTenantPage } from './CreateTenantPage'

vi.mock('@/features/auth/api', async (importOriginal) => {
  const actual = await importOriginal<typeof IdentityApi>()
  return { ...actual, createTenant: vi.fn() }
})

const { createTenant } = await import('@/features/auth/api')

const TENANT_A = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const TENANT_B = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'

function createdSession() {
  return {
    token_type: 'bearer',
    access_token: 'access-novo',
    refresh_token: 'refresh-novo',
    expires_in: 3600,
    refresh_expires_in: 2592000,
    active_tenant: TENANT_B,
    data: {
      id: TENANT_B,
      name: 'Casa de praia',
      slug: 'casa-de-praia',
      timezone: 'America/Sao_Paulo',
      preferred_hour: '09:00',
      archived: false,
      archived_at: null,
      role: null,
      capabilities: [],
      created_at: null,
    },
  }
}

async function fillForm() {
  await userEvent.clear(screen.getByLabelText('Nome da residência'))
  await userEvent.type(screen.getByLabelText('Nome da residência'), 'Casa de praia')
  await userEvent.click(screen.getByRole('button', { name: 'Criar residência' }))
}

describe('CreateTenantPage', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    // a tela vive atrás do RequireAuth: chegar aqui já exige sessão
    const { saveSession } = await import('@/shared/api/tokenStore')
    saveSession({
      access_token: 'access-1',
      refresh_token: 'refresh-1',
      expires_in: 3600,
      active_tenant: TENANT_A,
      user: { id: 'u1', name: 'Ana', email: 'ana@ex.com' },
      tenants: [],
    })
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            data: {
              user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
              active_tenant: TENANT_A,
              tenants: [],
              capabilities: [],
            },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    )
  })

  it('adota o token da residência criada e devolve o controle', async () => {
    vi.mocked(createTenant).mockResolvedValue(createdSession())
    const onDone = vi.fn()

    renderWithProviders(<CreateTenantPage onDone={onDone} />)
    await fillForm()

    await waitFor(() => expect(createTenant).toHaveBeenCalled())
    expect(vi.mocked(createTenant).mock.calls[0]![0]).toMatchObject({
      name: 'Casa de praia',
      timezone: 'America/Sao_Paulo',
      preferred_hour: '09:00',
    })
    // sem adotar o token novo, a tela seguinte leria a residência antiga
    await waitFor(() => expect(getSession()?.accessToken).toBe('access-novo'))
    expect(getSession()?.user?.name).toBe('Ana')
    expect(onDone).toHaveBeenCalledOnce()
  })

  it('mantém o formulário e explica a recusa do backend', async () => {
    vi.mocked(createTenant).mockRejectedValue(
      new ApiError(422, {
        code: 'validation_failed',
        message: 'Dados inválidos.',
        details: { name: ['Nome muito curto.'] },
      }),
    )
    const onDone = vi.fn()

    renderWithProviders(<CreateTenantPage onDone={onDone} />)
    await fillForm()

    expect(await screen.findByRole('alert')).toHaveTextContent('Dados inválidos.')
    // o que a pessoa digitou não pode sumir
    expect(screen.getByLabelText('Nome da residência')).toHaveValue('Casa de praia')
    expect(onDone).not.toHaveBeenCalled()
  })

  it('tranca o botão enquanto a criação está em curso', async () => {
    let resolveCreate: (value: ReturnType<typeof createdSession>) => void = () => {}
    vi.mocked(createTenant).mockReturnValue(
      new Promise((resolve) => {
        resolveCreate = resolve
      }),
    )

    renderWithProviders(<CreateTenantPage onDone={vi.fn()} />)
    await fillForm()

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Criar residência' })).toBeDisabled(),
    )
    resolveCreate(createdSession())
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Criar residência' })).toBeEnabled(),
    )
  })
})
