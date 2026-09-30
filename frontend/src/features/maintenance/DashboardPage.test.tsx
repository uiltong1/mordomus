import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as MaintenanceApi from '@/features/maintenance/api'
import type * as SchedulingApi from '@/features/scheduling/api'
import { ApiError } from '@/shared/api/errors'
import { saveSession } from '@/shared/api/tokenStore'
import type { Asset, Occurrence, OccurrenceStatus, Room } from '@/shared/api/types'
import { renderWithRouter } from '@/test/utils'
import { DashboardPage } from './DashboardPage'

vi.mock('@/features/maintenance/api', async (importOriginal) => {
  const actual = await importOriginal<typeof MaintenanceApi>()
  return { ...actual, fetchRooms: vi.fn(), fetchAssets: vi.fn() }
})

vi.mock('@/features/scheduling/api', async (importOriginal) => {
  const actual = await importOriginal<typeof SchedulingApi>()
  return { ...actual, fetchOccurrences: vi.fn(), completeOccurrence: vi.fn() }
})

const { fetchRooms, fetchAssets } = await import('@/features/maintenance/api')
const { fetchOccurrences, completeOccurrence } = await import('@/features/scheduling/api')

const TENANT = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const COZINHA = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'
const BANHEIRO = '01J8Z0M9W3K6Q2T4R5Y7B8C9D2'

function day(offset: number): string {
  const date = new Date()
  date.setDate(date.getDate() + offset)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

const rooms: Room[] = [
  {
    id: COZINHA,
    tenant_id: TENANT,
    name: 'Cozinha',
    icon: '🍳',
    sort_order: 0,
    archived: false,
    archived_at: null,
    created_at: null,
    updated_at: null,
  },
  {
    id: BANHEIRO,
    tenant_id: TENANT,
    name: 'Banheiro',
    icon: '🚿',
    sort_order: 1,
    archived: false,
    archived_at: null,
    created_at: null,
    updated_at: null,
  },
]

const assets: Asset[] = (
  [
    { id: 'a1', room_id: COZINHA, name: 'Filtro do ar' },
    { id: 'a2', room_id: COZINHA, name: 'Bocal' },
    { id: 'a3', room_id: BANHEIRO, name: 'Chuveiro' },
  ] as const
).map((item) => ({
  tenant_id: TENANT,
  category: null,
  brand: null,
  model: null,
  acquired_at: null,
  warranty_until: null,
  metadata: null,
  archived: false,
  archived_at: null,
  created_at: null,
  updated_at: null,
  ...item,
}))

const occurrences: Occurrence[] = (
  [
    {
      id: 'o1',
      trigger_config_id: 'cfg1',
      subject_id: 'a1',
      title: 'Limpar o filtro',
      scheduled_for: day(-4),
      due_at: `${day(-4)}T09:00:00-03:00`,
      status: 'overdue',
    },
    {
      id: 'o2',
      trigger_config_id: 'cfg1',
      subject_id: 'a1',
      title: 'Limpar o filtro',
      scheduled_for: day(3),
      due_at: `${day(3)}T09:00:00-03:00`,
      status: 'pending',
    },
    {
      id: 'o3',
      trigger_config_id: 'cfg2',
      subject_id: 'a3',
      title: 'Limpar o chuveiro',
      scheduled_for: day(10),
      due_at: `${day(10)}T09:00:00-03:00`,
      status: 'notified',
    },
  ] as const
).map((item) => ({
  tenant_id: TENANT,
  subject_type: 'asset',
  notified_at: null,
  completed_at: null,
  completed_by: null,
  created_at: null,
  updated_at: null,
  ...item,
})) as unknown as Occurrence[]

function page<T>(data: T[]) {
  return { data, meta: { page: 1, per_page: 100, total: data.length, last_page: 1 } }
}

async function signIn(
  capabilities = [
    'rooms.manage',
    'assets.manage',
    'occurrences.complete',
    'occurrences.skip',
    'rules.edit',
  ],
) {
  saveSession({
    access_token: 'access-1',
    refresh_token: 'refresh-1',
    expires_in: 3600,
    active_tenant: TENANT,
    user: { id: 'u1', name: 'Ana', email: 'ana@ex.com' },
    tenants: [],
  })

  vi.stubGlobal(
    'fetch',
    vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes('/identity/me')) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                user: { id: 'u1', name: 'Ana', email: 'ana@ex.com', locale: 'pt_BR' },
                active_tenant: TENANT,
                tenants: [],
                capabilities,
              },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
          ),
        )
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({ error: { code: 'not_found', message: 'rota não stubada' } }),
          {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
          },
        ),
      )
    }),
  )
}

describe('DashboardPage', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(fetchRooms).mockResolvedValue(page(rooms))
    vi.mocked(fetchAssets).mockResolvedValue(page(assets))
    vi.mocked(fetchOccurrences).mockResolvedValue(page(occurrences))
  })

  it('mostra um card por cômodo com a contagem de pendências', async () => {
    renderWithRouter(<DashboardPage />)

    const cards = await screen.findByTestId('room-cards')
    await waitFor(() => expect(within(cards).getAllByRole('listitem')).toHaveLength(2))

    const cozinha = within(cards).getByText('Cozinha').closest('div[class*="rounded-card"]')!
    // o que está atrasado e o que vem depois não podem virar o mesmo número
    expect(within(cozinha as HTMLElement).getByText('1 atrasada')).toBeInTheDocument()
    expect(within(cozinha as HTMLElement).getByText('1 a caminho')).toBeInTheDocument()
    expect(within(cozinha as HTMLElement).getByText(/2 itens/)).toBeInTheDocument()

    const banheiro = within(cards).getByText('Banheiro').closest('div[class*="rounded-card"]')!
    expect(within(banheiro as HTMLElement).getByText('1 a caminho')).toBeInTheDocument()
    expect(within(banheiro as HTMLElement).queryByText(/atrasada/)).toBeNull()
  })

  it('aponta o vencimento mais próximo da casa no topo', async () => {
    renderWithRouter(<DashboardPage />)

    // o atrasado de 4 dias vem antes do que vence em 3
    const banner = await screen.findByTestId('next-up')
    expect(within(banner).getByText('Limpar o filtro')).toBeInTheDocument()
    expect(within(banner).getByRole('button', { name: 'Já fiz' })).toBeInTheDocument()
  })

  it('conclui pelo card e invalida a agenda sem recarregar a página', async () => {
    vi.mocked(completeOccurrence).mockResolvedValue({
      ...occurrences[0]!,
      status: 'completed' as OccurrenceStatus,
    })
    renderWithRouter(<DashboardPage />)

    const botao = await screen.findByRole('button', { name: 'Já fiz' })
    await userEvent.click(botao)

    await waitFor(() => expect(completeOccurrence).toHaveBeenCalledWith('o1'))
    expect(await screen.findByText('Feito! A próxima data já foi recalculada.')).toBeInTheDocument()
  })

  it('mostra estado vazio com a ação de criar o primeiro cômodo', async () => {
    vi.mocked(fetchRooms).mockResolvedValue(page([]))
    renderWithRouter(<DashboardPage />)

    expect(await screen.findByText('Nenhum cômodo cadastrado')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Criar o primeiro cômodo' })).toBeInTheDocument()
  })

  it('mostra erro com a referência do backend e a ação de tentar de novo', async () => {
    vi.mocked(fetchRooms).mockRejectedValue(
      new ApiError(500, {
        code: 'server_error',
        message: 'Falhou',
        request_id: 'req-123',
      }),
    )
    renderWithRouter(<DashboardPage />)

    expect(await screen.findByText('Falhou')).toBeInTheDocument()
    expect(screen.getByText('referência: req-123')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tentar de novo' })).toBeInTheDocument()
  })

  it('esconde as ações de escrita para quem só tem leitura', async () => {
    await signIn([])
    renderWithRouter(<DashboardPage />)

    await screen.findByTestId('room-cards')
    expect(screen.queryByRole('button', { name: 'Novo cômodo' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Já fiz' })).toBeNull()
  })

  it('avisa que o inventário tem mais itens do que a tela considera', async () => {
    vi.mocked(fetchAssets).mockResolvedValue({
      data: assets,
      meta: { page: 1, per_page: 100, total: 143, last_page: 2 },
    })
    renderWithRouter(<DashboardPage />)

    expect(await screen.findByText(/mais de 100 itens/)).toBeInTheDocument()
  })
})
