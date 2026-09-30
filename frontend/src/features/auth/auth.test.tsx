import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ToastProvider } from '@/shared/ui'
import type { Session, TenantSummary } from '@/shared/api/types'
import { getSession, saveSession } from '@/shared/api/tokenStore'
import { createTestQueryClient, errorResponse, jsonResponse } from '@/test/utils'
import { AuthProvider } from './AuthProvider'
import { LoginPage } from './LoginPage'
import { AcceptInvitationPage } from './AcceptInvitationPage'
import { TenantProvider } from '@/tenants/TenantProvider'
import { TenantSwitcher } from '@/tenants/TenantSwitcher'

const TENANT_A = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const TENANT_B = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'

function summary(id: string, name: string): TenantSummary {
  return {
    id,
    name,
    slug: name.toLowerCase(),
    role: 'Proprietário',
    role_key: 'owner',
    capabilities: ['rooms.manage'],
  }
}

function sessionFixture(activeTenant: string | null, accessToken = 'access-1'): Session {
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

function profileResponse(activeTenant: string) {
  return jsonResponse({
    data: {
      user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
      active_tenant: activeTenant,
      tenants: [summary(TENANT_A, 'Casa'), summary(TENANT_B, 'Apartamento')],
      capabilities: ['rooms.manage'],
    },
  })
}

/** Aplica o mesmo providers do app real, sem BrowserRouter. */
function Providers({ children, route = '/entrar' }: { children: React.ReactNode; route?: string }) {
  return (
    <MemoryRouter initialEntries={[route]}>
      <QueryClientProvider client={createTestQueryClient()}>
        <ToastProvider>
          <AuthProvider>
            <TenantProvider>{children}</TenantProvider>
          </AuthProvider>
        </ToastProvider>
      </QueryClientProvider>
    </MemoryRouter>
  )
}

/** `useParams` só resolve dentro de um Route com o caminho declarado. */
function AuthRoutes() {
  return (
    <Routes>
      {/* no app real estas duas rotas compartilham o AppLayout */}
      <Route path="/" element={<TenantSwitcher />} />
      <Route path="/entrar" element={<LoginPage />} />
      <Route path="/painel" element={<TenantSwitcher />} />
      <Route path="/convite/:token" element={<AcceptInvitationPage />} />
    </Routes>
  )
}

async function fillLogin() {
  await userEvent.type(screen.getByLabelText('E-mail'), 'ana@ex.com')
  await userEvent.type(screen.getByLabelText('Senha'), 'senha12345')
  await userEvent.click(screen.getByRole('button', { name: 'Entrar' }))
}

describe('login', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
  })

  it('mostra a recusa do 401 sem desmontar a tela', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        errorResponse(401, {
          code: 'invalid_credentials',
          message: 'E-mail ou senha inválidos.',
        }),
      ),
    )

    render(
      <Providers>
        <AuthRoutes />
      </Providers>,
    )

    await fillLogin()

    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou senha inválidos.')
    // a pessoa não perde o que digitou para corrigir
    expect(screen.getByLabelText('E-mail')).toHaveValue('ana@ex.com')
  })

  it('devolve a mensagem do backend no campo que a recusou', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        errorResponse(422, {
          code: 'validation_failed',
          message: 'Dados inválidos.',
          details: { email: ['Este e-mail já está cadastrado.'] },
        }),
      ),
    )

    render(
      <Providers>
        <AuthRoutes />
      </Providers>,
    )

    await fillLogin()

    expect(await screen.findByText('Este e-mail já está cadastrado.')).toBeInTheDocument()
  })

  it('guarda o par de tokens e publica o perfil sem novo round-trip', async () => {
    const fetchMock = vi.fn().mockImplementation((url: string) => {
      if (url.includes('/auth/login')) {
        return Promise.resolve(jsonResponse(sessionFixture(TENANT_A)))
      }
      return Promise.resolve(profileResponse(TENANT_A))
    })
    vi.stubGlobal('fetch', fetchMock)

    render(
      <Providers>
        <AuthRoutes />
      </Providers>,
    )

    await fillLogin()

    await waitFor(() => expect(getSession()?.accessToken).toBe('access-1'))
    expect(getSession()?.user?.name).toBe('Ana')
  })

  it('reconhece a residência ativa logo após entrar, sem recarregar', async () => {
    // quem entra não tinha sessão: o TenantProvider montou sem `tid`. Se a
    // publicação da sessão não chegar até ele, o painel abre pedindo uma
    // residência que o usuário já tem.
    const fetchMock = vi.fn().mockImplementation((url: string) => {
      if (url.includes('/auth/login')) {
        return Promise.resolve(jsonResponse(sessionFixture(TENANT_A)))
      }
      return Promise.resolve(profileResponse(TENANT_A))
    })
    vi.stubGlobal('fetch', fetchMock)

    render(
      <Providers>
        <AuthRoutes />
      </Providers>,
    )

    const switcher = screen.queryByTestId('tenant-switcher')
    expect(switcher).not.toBeInTheDocument()

    await fillLogin()

    await waitFor(() => expect(screen.getByTestId('tenant-switcher')).toHaveTextContent('Casa'))
    expect(screen.queryByText('Nenhuma residência escolhida')).not.toBeInTheDocument()
  })
})

describe('TenantSwitcher', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
  })

  it('troca de residência pelo menu e adota o token novo', async () => {
    saveSession(sessionFixture(TENANT_A))

    const fetchMock = vi.fn().mockImplementation((url: string) => {
      if (url.includes('/auth/switch-tenant')) {
        return Promise.resolve(jsonResponse(sessionFixture(TENANT_B, 'access-2')))
      }
      return Promise.resolve(profileResponse(TENANT_B))
    })
    vi.stubGlobal('fetch', fetchMock)

    render(
      <Providers route="/painel">
        <AuthRoutes />
      </Providers>,
    )

    const trigger = await screen.findByTestId('tenant-switcher')
    await waitFor(() => expect(trigger).toHaveTextContent('Casa'))

    await userEvent.click(trigger)
    await userEvent.click(screen.getByRole('option', { name: /Apartamento/ }))

    await waitFor(() =>
      expect(screen.getByTestId('tenant-switcher')).toHaveTextContent('Apartamento'),
    )
    expect(getSession()?.accessToken).toBe('access-2')
  })

  it('não renderiza o seletor para quem não tem residência', async () => {
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

    render(
      <Providers route="/painel">
        <AuthRoutes />
      </Providers>,
    )

    await waitFor(() => expect(screen.queryByTestId('tenant-switcher')).not.toBeInTheDocument())
  })
})

describe('aceite de convite', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
  })

  it('traduz 409 de convite já usado em uma frase que leva ao login', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          errorResponse(409, { code: 'invitation_already_used', message: 'Convite já utilizado.' }),
        ),
    )

    render(
      <Providers route="/convite/token-123">
        <AuthRoutes />
      </Providers>,
    )

    expect(
      await screen.findByText('Este convite já foi usado. Entre com a sua conta.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voltar para o login' })).toBeInTheDocument()
  })

  it('traduz 410 de convite expirado', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          errorResponse(410, { code: 'invitation_expired', message: 'Convite expirado.' }),
        ),
    )

    render(
      <Providers route="/convite/token-123">
        <AuthRoutes />
      </Providers>,
    )

    expect(
      await screen.findByText('Este convite expirou. Peça um novo para quem convidou.'),
    ).toBeInTheDocument()
  })
})
