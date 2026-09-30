import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as MaintenanceApi from '@/features/maintenance/api'
import type * as SchedulingApi from '@/features/scheduling/api'
import { ApiError } from '@/shared/api/errors'
import { saveSession } from '@/shared/api/tokenStore'
import type { Asset, Occurrence, OccurrenceStatus, Room } from '@/shared/api/types'
import { renderWithRouter } from '@/test/utils'
import { TimelinePage } from './TimelinePage'

vi.mock('@/features/maintenance/api', async (importOriginal) => {
  const actual = await importOriginal<typeof MaintenanceApi>()
  return { ...actual, fetchRooms: vi.fn(), fetchAssets: vi.fn() }
})

vi.mock('@/features/scheduling/api', async (importOriginal) => {
  const actual = await importOriginal<typeof SchedulingApi>()
  return {
    ...actual,
    fetchOccurrences: vi.fn(),
    completeOccurrence: vi.fn(),
    skipOccurrence: vi.fn(),
  }
})

const { fetchRooms, fetchAssets } = await import('@/features/maintenance/api')
const { fetchOccurrences, completeOccurrence, skipOccurrence } =
  await import('@/features/scheduling/api')

const TENANT = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const COZINHA = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'
const BANHEIRO = '01J8Z0M9W3K6Q2T4R5Y7B8C9D2'

function day(offset: number): string {
  const date = new Date()
  date.setDate(date.getDate() + offset)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

const rooms: Room[] = [
  { id: COZINHA, name: 'Cozinha', icon: '🍳', sort_order: 0 },
  { id: BANHEIRO, name: 'Banheiro', icon: '🚿', sort_order: 1 },
].map((item) => ({
  tenant_id: TENANT,
  archived: false,
  archived_at: null,
  created_at: null,
  updated_at: null,
  ...item,
}))

const assets: Asset[] = [
  { id: 'a1', room_id: COZINHA, name: 'Filtro do ar' },
  { id: 'a3', room_id: BANHEIRO, name: 'Chuveiro' },
].map((item) => ({
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
    { id: 'o1', subject_id: 'a1', title: 'Limpar o filtro do ar', day: day(1), status: 'pending' },
    { id: 'o2', subject_id: 'a3', title: 'Limpar o chuveiro', day: day(2), status: 'pending' },
    {
      id: 'o3',
      subject_id: 'a1',
      title: 'Conferir o medidor',
      day: day(-1),
      status: 'overdue',
    },
  ] as const
).map((item) => ({
  id: item.id,
  tenant_id: TENANT,
  trigger_config_id: `cfg-${item.id}`,
  subject_type: 'asset' as const,
  subject_id: item.subject_id,
  title: item.title,
  scheduled_for: item.day,
  due_at: `${item.day}T09:00:00-03:00`,
  status: item.status as OccurrenceStatus,
  notified_at: null,
  completed_at: null,
  completed_by: null,
  created_at: null,
  updated_at: null,
}))

/** Isola a linha pelo título — "Concluir" aparece em todas as tarefas abertas. */
function rowFor(title: string) {
  return within(screen.getByText(title).closest('[data-testid="occurrence-row"]') as HTMLElement)
}

function page<T>(data: T[]) {
  return { data, meta: { page: 1, per_page: 100, total: data.length, last_page: 1 } }
}

async function signIn(capabilities = ['occurrences.complete', 'occurrences.skip']) {
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
    vi.fn().mockResolvedValue(
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
    ),
  )
}

describe('TimelinePage', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(fetchRooms).mockResolvedValue(page(rooms))
    vi.mocked(fetchAssets).mockResolvedValue(page(assets))
    vi.mocked(fetchOccurrences).mockResolvedValue(page(occurrences))
  })

  it('agrupa por dia e nomeia o cômodo de cada tarefa', async () => {
    renderWithRouter(<TimelinePage />)

    const timeline = await screen.findByTestId('timeline')
    const rows = within(timeline).getAllByTestId('occurrence-row')
    expect(rows).toHaveLength(3)
    // o mais antigo primeiro, mesmo vindo fora de ordem do backend
    expect(rows[0]).toHaveTextContent('Conferir o medidor')
    expect(rows[0]).toHaveTextContent('Cozinha')
    expect(rows[0]).toHaveAttribute('data-status', 'overdue')
    expect(rows[1]).toHaveTextContent('Limpar o filtro do ar')
    expect(rows[2]).toHaveTextContent('Banheiro')
  })

  it('filtra por cômodo contando o que ficou de fora', async () => {
    renderWithRouter(<TimelinePage />)
    await screen.findByTestId('timeline')

    await userEvent.selectOptions(screen.getByLabelText('Cômodo'), BANHEIRO)

    await waitFor(() => expect(screen.getAllByTestId('occurrence-row')).toHaveLength(1))
    // o "de 3" existe para a lista nunca parecer completa quando o filtro cortou
    expect(screen.getByTestId('timeline-summary')).toHaveTextContent('1 tarefa neste cômodo de 3')
  })

  it('manda o filtro de situação para o servidor', async () => {
    renderWithRouter(<TimelinePage />)
    await screen.findByTestId('timeline')

    await userEvent.selectOptions(screen.getByLabelText('Situação'), 'overdue')

    await waitFor(() => {
      const lastCall = vi.mocked(fetchOccurrences).mock.calls.at(-1)!
      expect(lastCall[1]).toMatchObject({ status: 'overdue' })
    })
  })

  /**
   * Critério de aceite: concluir atualiza a timeline sem reload. A linha vira
   * `completed` no retorno e a query é invalidada — não há navegação fora da tela.
   */
  it('conclui a tarefa e a linha reflete o novo estado, sem sair da tela', async () => {
    vi.mocked(completeOccurrence).mockImplementation(async () => {
      // o backend responde com a ocorrência já concluída
      vi.mocked(fetchOccurrences).mockResolvedValue(
        page([{ ...occurrences[0]!, status: 'completed' }]),
      )
      return { ...occurrences[0]!, status: 'completed' as OccurrenceStatus }
    })

    renderWithRouter(<TimelinePage />)
    await screen.findByTestId('timeline')

    await userEvent.click(rowFor('Limpar o filtro do ar').getByRole('button', { name: 'Concluir' }))

    await waitFor(() => expect(completeOccurrence).toHaveBeenCalledWith('o1'))
    expect(await screen.findByText('Tarefa concluída.')).toBeInTheDocument()
    // a tela continua montada: a única linha agora está concluída e sem botão
    await waitFor(() => expect(screen.getAllByTestId('occurrence-row')).toHaveLength(1))
    expect(screen.getByTestId('occurrence-row')).toHaveAttribute('data-status', 'completed')
    expect(screen.queryByRole('button', { name: 'Concluir' })).toBeNull()
  })

  it('dispensa a tarefa e explica que o ciclo não muda', async () => {
    vi.mocked(skipOccurrence).mockResolvedValue(occurrences[0]!)
    renderWithRouter(<TimelinePage />)
    await screen.findByTestId('timeline')

    await userEvent.click(rowFor('Limpar o filtro do ar').getByRole('button', { name: 'Pular' }))

    await waitFor(() => expect(skipOccurrence).toHaveBeenCalledWith('o1'))
    expect(await screen.findByText('Tarefa dispensada.')).toBeInTheDocument()
  })

  it('traduz o 409 de ocorrência já finalizada', async () => {
    vi.mocked(completeOccurrence).mockRejectedValue(
      new ApiError(409, {
        code: 'occurrence_already_finalized',
        // texto exato do `OccurrenceAlreadyFinalized` do monólito
        message: 'A ocorrência já foi encerrada e não aceita esta operação.',
      }),
    )
    renderWithRouter(<TimelinePage />)
    await screen.findByTestId('timeline')

    await userEvent.click(rowFor('Limpar o filtro do ar').getByRole('button', { name: 'Concluir' }))

    expect(
      await screen.findByText('A ocorrência já foi encerrada e não aceita esta operação.'),
    ).toBeInTheDocument()
  })

  it('esconde concluir e pular para quem não tem a capability', async () => {
    await signIn([])
    renderWithRouter(<TimelinePage />)

    await screen.findByTestId('timeline')
    expect(screen.queryByRole('button', { name: 'Concluir' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Pular' })).toBeNull()
  })

  it('mostra estado vazio com a ação de alargar o período', async () => {
    vi.mocked(fetchOccurrences).mockResolvedValue(page([]))
    renderWithRouter(<TimelinePage />)

    expect(await screen.findByText('Nada neste recorte')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ver tudo' })).toBeInTheDocument()
  })

  it('mostra o erro com a referência do backend', async () => {
    vi.mocked(fetchOccurrences).mockRejectedValue(
      new ApiError(500, {
        code: 'server_error',
        message: 'Agenda indisponível.',
        request_id: 'req-7',
      }),
    )
    renderWithRouter(<TimelinePage />)

    expect(await screen.findByText('Agenda indisponível.')).toBeInTheDocument()
    expect(screen.getByText('referência: req-7')).toBeInTheDocument()
  })
})
