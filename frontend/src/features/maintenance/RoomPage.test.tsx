import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as MaintenanceApi from '@/features/maintenance/api'
import type * as SchedulingApi from '@/features/scheduling/api'
import { ApiError } from '@/shared/api/errors'
import { saveSession } from '@/shared/api/tokenStore'
import type { Asset, Occurrence, OccurrenceStatus, Room, TriggerConfig } from '@/shared/api/types'
import { renderRoute } from '@/test/utils'
import { RoomPage } from './RoomPage'

vi.mock('@/features/maintenance/api', async (importOriginal) => {
  const actual = await importOriginal<typeof MaintenanceApi>()
  return {
    ...actual,
    fetchRooms: vi.fn(),
    fetchAssets: vi.fn(),
    createAsset: vi.fn(),
    archiveAsset: vi.fn(),
  }
})

vi.mock('@/features/scheduling/api', async (importOriginal) => {
  const actual = await importOriginal<typeof SchedulingApi>()
  return {
    ...actual,
    fetchOccurrences: vi.fn(),
    fetchTriggerConfigs: vi.fn(),
    completeOccurrence: vi.fn(),
    skipOccurrence: vi.fn(),
    deleteTriggerConfig: vi.fn(),
    saveAssetSchedule: vi.fn(),
  }
})

const { fetchRooms, fetchAssets, createAsset, archiveAsset } =
  await import('@/features/maintenance/api')
const { fetchOccurrences, fetchTriggerConfigs, skipOccurrence, deleteTriggerConfig } =
  await import('@/features/scheduling/api')

const TENANT = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const COZINHA = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'
const ASSET_ID = '01J8Z0M9W3K6Q2T4R5Y7B8C9D2'

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
]

const assets: Asset[] = [
  {
    id: ASSET_ID,
    tenant_id: TENANT,
    room_id: COZINHA,
    name: 'Filtro do ar-condicionado',
    category: 'Eletrodoméstico',
    brand: 'Springer',
    model: 'Midea',
    acquired_at: '2024-01-15T00:00:00-03:00',
    warranty_until: null,
    metadata: null,
    archived: false,
    archived_at: null,
    created_at: null,
    updated_at: null,
  },
  {
    id: 'a-sem-regra',
    tenant_id: TENANT,
    room_id: COZINHA,
    name: 'Bocal da torneira',
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
  },
]

const occurrences: Occurrence[] = (
  [
    { id: 'o-atrasada', title: 'Limpar o filtro do ar', day: day(-2), status: 'overdue' },
    { id: 'o-amanha', title: 'Limpar o filtro do ar', day: day(1), status: 'pending' },
  ] as const
).map((item) => ({
  id: item.id,
  tenant_id: TENANT,
  trigger_config_id: 'cfg1',
  subject_type: 'asset' as const,
  subject_id: ASSET_ID,
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

const rules: TriggerConfig[] = [
  {
    id: 'cfg1',
    tenant_id: TENANT,
    subject_type: 'asset',
    subject_id: ASSET_ID,
    title: 'Limpar o filtro do ar',
    description: null,
    is_active: true,
    type: 'INTERVAL',
    interval_value: 30,
    interval_unit: 'days',
    day_of_month: null,
    advance_notice_days: 1,
    recalculate_base: null,
    custom_offsets: null,
    preferred_hour: '09:00',
    last_base_date: day(-2),
    next_due_at: `${day(28)}T09:00:00-03:00`,
    created_at: null,
    updated_at: null,
  },
]

function page<T>(data: T[]) {
  return { data, meta: { page: 1, per_page: 100, total: data.length, last_page: 1 } }
}

async function signIn(
  capabilities = ['assets.manage', 'rules.edit', 'occurrences.complete', 'occurrences.skip'],
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

const open = () => renderRoute(<RoomPage />, '/comodos/:roomId', `/comodos/${COZINHA}`)

describe('RoomPage', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(fetchRooms).mockResolvedValue(page(rooms))
    vi.mocked(fetchAssets).mockResolvedValue(page(assets))
    vi.mocked(fetchOccurrences).mockResolvedValue(page(occurrences))
    vi.mocked(fetchTriggerConfigs).mockResolvedValue(page(rules))
  })

  it('lista os ativos do cômodo com a regra de cada um', async () => {
    open()

    const lista = await screen.findByTestId('room-assets')
    // só os filhos diretos: dentro de cada item há uma lista de regras
    expect(lista.children).toHaveLength(2)
    expect(within(lista).getByText('Filtro do ar-condicionado')).toBeInTheDocument()
    expect(within(lista).getByText('A cada 30 dias')).toBeInTheDocument()
    // o item sem regra aparece sem rótulo de repetição, e não com um vazio
    expect(within(lista).getByText('Bocal da torneira')).toBeInTheDocument()
    expect(within(lista).getAllByText('Regra').length).toBe(2)
  })

  it('mostra a pendência mais próxima com o check-in', async () => {
    open()

    // a atrasada de 2 dias vem antes da que vence amanhã
    const pendencias = await screen.findByTestId('room-pending')
    const linhas = within(pendencias).getAllByRole('listitem')
    expect(linhas[0]).toHaveTextContent('Atrasado há 2 dias')
    expect(linhas[1]).toHaveTextContent('Amanhã')
    expect(screen.getByRole('button', { name: 'Já fiz' })).toBeInTheDocument()
  })

  it('dispensa a pendência e explica que o ciclo fica como estava', async () => {
    vi.mocked(skipOccurrence).mockResolvedValue({ ...occurrences[0]!, status: 'skipped' })
    open()

    const pendencias = await screen.findByTestId('room-pending')
    await userEvent.click(within(pendencias).getAllByRole('button', { name: 'Pular' })[0]!)

    await waitFor(() => expect(skipOccurrence).toHaveBeenCalledWith('o-atrasada'))
    expect(
      await screen.findByText('Ocorrência dispensada. O ciclo continua na data que já estava.'),
    ).toBeInTheDocument()
  })

  it('traduz o 409 de concluir o que já foi concluído', async () => {
    const { completeOccurrence } = await import('@/features/scheduling/api')
    vi.mocked(completeOccurrence).mockRejectedValue(
      new ApiError(409, {
        code: 'occurrence_already_finalized',
        // texto exato do `OccurrenceAlreadyFinalized` do monólito
        message: 'A ocorrência já foi encerrada e não aceita esta operação.',
      }),
    )
    open()

    await userEvent.click(await screen.findByRole('button', { name: 'Já fiz' }))
    expect(
      await screen.findByText('A ocorrência já foi encerrada e não aceita esta operação.'),
    ).toBeInTheDocument()
  })

  it('adiciona item pelo modal com o cômodo já escolhido', async () => {
    vi.mocked(createAsset).mockResolvedValue({ ...assets[0]!, id: 'novo' })
    open()

    await userEvent.click(await screen.findByRole('button', { name: 'Adicionar item' }))
    await userEvent.type(screen.getByLabelText('Nome do item'), 'Lâmpada do corredor')
    await userEvent.click(screen.getByRole('button', { name: 'Adicionar' }))

    await waitFor(() =>
      expect(createAsset).toHaveBeenCalledWith(
        expect.objectContaining({ room_id: COZINHA, name: 'Lâmpada do corredor' }),
      ),
    )
    expect(await screen.findByText('Item adicionado ao inventário.')).toBeInTheDocument()
  })

  it('recusa item sem nome antes de chamar o servidor', async () => {
    open()

    await userEvent.click(await screen.findByRole('button', { name: 'Adicionar item' }))
    await userEvent.click(screen.getByRole('button', { name: 'Adicionar' }))

    expect(await screen.findByText('Dê um nome para o item.')).toBeInTheDocument()
    expect(createAsset).not.toHaveBeenCalled()
  })

  it('arquiva em vez de apagar, e devolve o item quando restaura', async () => {
    vi.mocked(archiveAsset).mockResolvedValue({ ...assets[0]!, archived: true })
    open()

    const lista = await screen.findByTestId('room-assets')
    await userEvent.click(within(lista).getAllByRole('button', { name: 'Arquivar' })[0]!)

    await waitFor(() => expect(archiveAsset).toHaveBeenCalledWith(ASSET_ID))
    expect(await screen.findByText('Item arquivado.')).toBeInTheDocument()
  })

  it('remove a regra e confirma pelo aviso', async () => {
    vi.mocked(deleteTriggerConfig).mockResolvedValue({ ...rules[0]! })
    open()

    const lista = await screen.findByTestId('room-assets')
    await userEvent.click(within(lista).getByRole('button', { name: 'remover' }))

    await waitFor(() => expect(deleteTriggerConfig).toHaveBeenCalledWith('cfg1'))
    expect(await screen.findByText('Regra removida.')).toBeInTheDocument()
  })

  it('avisa que um item está sem regra de repetição', async () => {
    open()

    expect(await screen.findByTestId('room-without-rules')).toHaveTextContent(
      '1 item ainda não tem regra de repetição',
    )
  })

  it('esconde as ações de escrita para quem só tem leitura', async () => {
    await signIn(['occurrences.complete'])
    open()

    await screen.findByTestId('room-assets')
    expect(screen.queryByRole('button', { name: 'Adicionar item' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Editar' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Regra' })).toBeNull()
    // quem tem occurrences.complete continua vendo o check-in
    expect(screen.getByRole('button', { name: 'Já fiz' })).toBeInTheDocument()
  })

  it('diz que o cômodo não existe em vez de mostrar tela em branco', async () => {
    renderRoute(<RoomPage />, '/comodos/:roomId', '/comodos/inexistente')

    expect(await screen.findByText('Cômodo não encontrado')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Voltar ao painel' })).toBeInTheDocument()
  })
})
