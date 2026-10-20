import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as MaintenanceApi from '@/features/maintenance/api'
import type * as SchedulingApi from '@/features/scheduling/api'
import { ApiError } from '@/shared/api/errors'
import { saveSession } from '@/shared/api/tokenStore'
import type { TriggerConfig } from '@/shared/api/types'
import { renderWithProviders } from '@/test/utils'
import { TriggerBuilderModal } from './TriggerBuilderModal'

vi.mock('@/features/maintenance/api', async (importOriginal) => {
  const actual = await importOriginal<typeof MaintenanceApi>()
  return { ...actual }
})

vi.mock('@/features/scheduling/api', async (importOriginal) => {
  const actual = await importOriginal<typeof SchedulingApi>()
  return {
    ...actual,
    previewNextDue: vi.fn(),
    saveAssetSchedule: vi.fn(),
    updateTriggerConfig: vi.fn(),
  }
})

const { previewNextDue, saveAssetSchedule, updateTriggerConfig } =
  await import('@/features/scheduling/api')

const TENANT = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const ASSET_ID = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'

const existingConfig: TriggerConfig = {
  id: 'cfg-edit',
  tenant_id: TENANT,
  subject_type: 'asset',
  subject_id: ASSET_ID,
  title: 'Limpeza das serpentinas',
  description: 'Aspirar a serpentina e passar água quente nas aletas.',
  is_active: true,
  type: 'INTERVAL',
  interval_value: 90,
  interval_unit: 'days',
  day_of_month: null,
  advance_notice_days: 7,
  recalculate_base: null,
  custom_offsets: null,
  preferred_hour: '14:30',
  last_base_date: null,
  next_due_at: null,
  created_at: null,
  updated_at: null,
}

async function signIn() {
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
            capabilities: ['rules.edit'],
          },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    ),
  )
}

function open(config: Parameters<typeof TriggerBuilderModal>[0]['config'] = null) {
  return renderWithProviders(
    <TriggerBuilderModal
      open
      onClose={vi.fn()}
      subjectId={ASSET_ID}
      subjectName="Filtro do ar-condicionado"
      config={config}
    />,
  )
}

describe('TriggerBuilderModal', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
  })

  it('cria uma regra de 30 dias em três interações', async () => {
    vi.mocked(saveAssetSchedule).mockResolvedValue({ id: 'cfg1' } as never)
    open()

    await userEvent.type(screen.getByLabelText('Nome da regra'), 'Limpar o filtro')
    await userEvent.click(screen.getByRole('button', { name: 'Criar regra' }))

    await waitFor(() =>
      expect(saveAssetSchedule).toHaveBeenCalledWith(ASSET_ID, {
        type: 'INTERVAL',
        title: 'Limpar o filtro',
        description: null,
        is_active: true,
        advance_notice_days: 0,
        preferred_hour: '09:00',
        interval_value: 30,
        interval_unit: 'days',
      }),
    )
    expect(await screen.findByText('Regra criada.')).toBeInTheDocument()
  })

  it('ao editar, o tipo vira select e todos os campos vêm preenchidos', async () => {
    open(existingConfig)

    expect(await screen.findByLabelText('Como você quer repetir?')).toHaveValue('INTERVAL')
    expect(screen.queryByRole('radio')).toBeNull()
    expect(screen.getByLabelText('Nome da regra')).toHaveValue('Limpeza das serpentinas')
    expect(screen.getByLabelText('Anotação')).toHaveValue(
      'Aspirar a serpentina e passar água quente nas aletas.',
    )
    expect(screen.getByLabelText('De quantos em quantos')).toHaveValue(90)
    expect(screen.getByLabelText('Unidade')).toHaveValue('days')
    expect(screen.getByLabelText('Avisar quantos dias antes')).toHaveValue(7)
    expect(screen.getByLabelText('Horário do aviso')).toHaveValue('14:30')
    expect(screen.getByLabelText('Regra ativa')).toBeChecked()

    const dialog = screen.getByRole('dialog', { name: 'Editar regra' })
    expect(dialog).toHaveTextContent('A cada 90 dias')
    expect(dialog).not.toHaveTextContent('A cada 30 dias')
  })

  it('na edição, trocar o tipo pelo select corta o campo proibido do PATCH', async () => {
    vi.mocked(updateTriggerConfig).mockResolvedValue({ ...existingConfig })
    open({
      ...existingConfig,
      type: 'CALENDAR_MONTHLY',
      interval_value: null,
      interval_unit: null,
      day_of_month: 15,
    })

    await userEvent.selectOptions(screen.getByLabelText('Como você quer repetir?'), 'INTERVAL')
    await userEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }))

    await waitFor(() =>
      expect(updateTriggerConfig).toHaveBeenCalledWith('cfg-edit', expect.anything()),
    )
    const payload = vi.mocked(updateTriggerConfig).mock.calls[0]![1] as Record<string, unknown>
    expect(payload).not.toHaveProperty('day_of_month')
    expect(payload).toMatchObject({ type: 'INTERVAL', interval_value: 30, interval_unit: 'days' })
    expect(await screen.findByText('Regra atualizada.')).toBeInTheDocument()
  })

  it('mostra a data que o backend calculou, sem inventar a sua', async () => {
    vi.mocked(previewNextDue).mockResolvedValue({
      type: 'INTERVAL',
      scheduled_for: '2026-11-14',
      next_due_at: '2026-11-14T12:00:00Z',
      preferred_hour: '09:00',
      timezone: 'America/Sao_Paulo',
    })
    open()

    await userEvent.click(screen.getByRole('button', { name: 'Ver próxima data' }))

    await waitFor(() =>
      expect(previewNextDue).toHaveBeenCalledWith({
        type: 'INTERVAL',
        base: null,
        preferred_hour: '09:00',
        interval_value: 30,
        interval_unit: 'days',
      }),
    )

    const preview = await screen.findByTestId('trigger-preview')
    expect(preview).toHaveTextContent('14 de novembro de 2026')
    expect(preview).toHaveTextContent('America/Sao_Paulo')
  })

  it('avisa quando a regra ainda não tem data para dar', async () => {
    vi.mocked(previewNextDue).mockResolvedValue({
      type: 'POST_COMPLETION',
      scheduled_for: null,
      next_due_at: null,
      preferred_hour: '09:00',
      timezone: 'America/Sao_Paulo',
    })
    open()

    await userEvent.click(screen.getByRole('radio', { name: /X dias depois de eu fazer/ }))
    await userEvent.selectOptions(screen.getByLabelText('Unidade'), 'days')
    await userEvent.type(screen.getByLabelText('De quantos em quantos'), '15')
    await userEvent.selectOptions(screen.getByLabelText('Contar a partir de quando'), 'COMPLETION')
    await userEvent.click(screen.getByRole('button', { name: 'Ver próxima data' }))

    expect(
      await screen.findByText(/só ganha a próxima data depois do primeiro check-in/),
    ).toBeInTheDocument()
  })

  it('trocar de tipo esconde os campos que o novo tipo proíbe', async () => {
    open()

    await userEvent.click(screen.getByRole('radio', { name: /Todo dia fixo do mês/ }))
    expect(screen.getByLabelText('Dia do mês')).toBeInTheDocument()
    expect(screen.queryByLabelText('De quantos em quantos')).toBeNull()

    await userEvent.click(screen.getByRole('radio', { name: /Repetir a cada X dias/ }))
    expect(screen.queryByLabelText('Dia do mês')).toBeNull()
    expect(screen.getByLabelText('De quantos em quantos')).toBeInTheDocument()
  })

  it('não manda no payload o campo que ficou de fora da tela', async () => {
    vi.mocked(saveAssetSchedule).mockResolvedValue({ id: 'cfg1' } as never)
    open()

    await userEvent.click(screen.getByRole('radio', { name: /Todo dia fixo do mês/ }))
    await userEvent.selectOptions(screen.getByLabelText('Dia do mês'), '15')
    await userEvent.type(screen.getByLabelText('Nome da regra'), 'Conta do condomínio')

    await userEvent.click(screen.getByRole('radio', { name: /Repetir a cada X dias/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Criar regra' }))

    await waitFor(() => expect(saveAssetSchedule).toHaveBeenCalled())
    const payload = vi.mocked(saveAssetSchedule).mock.calls[0]![1] as Record<string, unknown>
    expect(payload).not.toHaveProperty('day_of_month')
    expect(payload).toMatchObject({ type: 'INTERVAL', interval_value: 30, interval_unit: 'days' })
  })

  it('recusa o intervalo que o tipo exige antes de chamar o servidor', async () => {
    open()

    await userEvent.clear(screen.getByLabelText('De quantos em quantos'))
    await userEvent.click(screen.getByRole('button', { name: 'Criar regra' }))

    expect(
      await screen.findByText('Informe de quanto em quanto tempo repetir.'),
    ).toBeInTheDocument()
    expect(saveAssetSchedule).not.toHaveBeenCalled()
  })

  it('traduz o 422 por campo em cima do formulário', async () => {
    vi.mocked(saveAssetSchedule).mockRejectedValue(
      new ApiError(422, {
        code: 'validation_failed',
        message: 'Dados inválidos.',
        details: { interval_value: ['O intervalo informado é grande demais.'] },
        request_id: 'req-9',
      }),
    )
    open()

    await userEvent.type(screen.getByLabelText('Nome da regra'), 'Regra')
    await userEvent.click(screen.getByRole('button', { name: 'Criar regra' }))

    expect(await screen.findByText('O intervalo informado é grande demais.')).toBeInTheDocument()
    expect(
      screen.getAllByRole('alert').some((node) => node.textContent?.includes('Dados inválidos.')),
    ).toBe(true)
  })

  it('monta os avisos do ESCALATED a partir dos offsets escolhidos', async () => {
    vi.mocked(saveAssetSchedule).mockResolvedValue({ id: 'cfg1' } as never)
    open()

    await userEvent.click(screen.getByRole('radio', { name: /com avisos extras/ }))
    await userEvent.clear(screen.getByLabelText('De quantos em quantos'))
    await userEvent.type(screen.getByLabelText('De quantos em quantos'), '90')
    await userEvent.click(screen.getByRole('button', { name: '7d antes' }))
    await userEvent.type(screen.getByLabelText('Nome da regra'), 'Revisar o quadro de luz')
    await userEvent.click(screen.getByRole('button', { name: 'Criar regra' }))

    await waitFor(() =>
      expect(saveAssetSchedule).toHaveBeenCalledWith(
        ASSET_ID,
        expect.objectContaining({ type: 'ESCALATED', custom_offsets: [-7] }),
      ),
    )
  })

  it('avisa que POST_COMPLETION depende do primeiro check-in', async () => {
    open()

    await userEvent.click(screen.getByRole('radio', { name: /X dias depois de eu fazer/ }))
    expect(screen.getByLabelText('Contar a partir de quando')).toBeInTheDocument()
    expect(screen.queryByLabelText('Dia do mês')).toBeNull()
  })
})
