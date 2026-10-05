import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as FinancialApi from './api'
import { ApiError } from '@/shared/api/errors'
import { saveSession } from '@/shared/api/tokenStore'
import { renderWithRouter } from '@/test/utils'
import type { Bill, BillOccurrence } from '@/shared/api/types'
import { BillListPage } from './BillListPage'
import { ManualOccurrenceModal } from './ManualOccurrenceModal'
import { PayModal } from './PayModal'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof FinancialApi>()
  return {
    ...actual,
    fetchBills: vi.fn(),
    fetchActiveMembers: vi.fn(),
    createBill: vi.fn(),
    updateBill: vi.fn(),
    createOccurrence: vi.fn(),
    payOccurrence: vi.fn(),
  }
})

const { fetchBills, createBill, updateBill, createOccurrence, payOccurrence } =
  await import('./api')

const TENANT = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const BILL_ID = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'
const OCCURRENCE_ID = '01J8Z0M9W3K6Q2T4R5Y7B8C9D2'

const bill: Bill = {
  id: BILL_ID,
  tenant_id: TENANT,
  name: 'Aluguel',
  kind: 'fixed',
  category: 'moradia',
  amount: '1200.00',
  currency: 'BRL',
  is_active: true,
  schedule: {
    id: 'cfg1',
    day_of_month: 10,
    advance_notice_days: 5,
    preferred_hour: '09:00',
    is_active: true,
    next_due_at: '2026-04-10T09:00:00-03:00',
  },
  created_by: 'u1',
  created_at: null,
  updated_at: null,
}

const variableBill: Bill = {
  ...bill,
  id: '01J8Z0M9W3K6Q2T4R5Y7B8C9D3',
  name: 'Energia',
  kind: 'variable',
  amount: null,
  schedule: null,
}

const occurrence: BillOccurrence = {
  id: OCCURRENCE_ID,
  tenant_id: TENANT,
  bill_id: BILL_ID,
  bill_name: 'Aluguel',
  bill_kind: 'fixed',
  category: 'moradia',
  schedule_id: 'cfg1',
  due_date: '2026-04-10',
  amount: '1200.00',
  status: 'open',
  paid_at: null,
  paid_by: null,
  payments: [],
  created_at: null,
  updated_at: null,
}

function page<T>(data: T[]) {
  return { data, meta: { page: 1, per_page: 50, total: data.length, last_page: 1 } }
}

async function signIn(capabilities: string[] = ['bills.manage', 'bills.pay']) {
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

describe('BillListPage', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(fetchBills).mockResolvedValue(page([bill, variableBill]))
    vi.mocked(createBill).mockResolvedValue(bill)
  })

  it('abre o cadastro de conta no clique em "Nova conta"', async () => {
    renderWithRouter(<BillListPage />)

    await screen.findByText('Aluguel')

    // o botão precisa estar lá e o clique precisa abrir o diálogo: o regresso
    // desta tela era o clique não rendendo nada
    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))

    expect(await screen.findByRole('dialog', { name: 'Nova conta' })).toBeInTheDocument()
    expect(screen.getByLabelText('Nome')).toBeInTheDocument()
  })

  it('cria a conta com a cadência que o Scheduling vai ler', async () => {
    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nova conta' })
    await userEvent.type(within(dialog).getByLabelText('Nome'), 'Internet')
    await userEvent.type(within(dialog).getByLabelText('Valor previsto'), '99.90')
    await userEvent.type(within(dialog).getByLabelText('Dia do vencimento'), '10')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(createBill).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Internet',
          kind: 'fixed',
          amount: '99.90',
          due_day: 10,
        }),
      ),
    )
  })

  it('esconde a cadência na conta variável, porque o campo não existe ali', async () => {
    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nova conta' })
    await userEvent.selectOptions(within(dialog).getByLabelText('Tipo'), 'variable')

    expect(within(dialog).queryByLabelText('Dia do vencimento')).not.toBeInTheDocument()
  })

  it('não manda a cadência de uma conta variável no payload', async () => {
    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nova conta' })
    await userEvent.type(within(dialog).getByLabelText('Nome'), 'Mercado')
    await userEvent.selectOptions(within(dialog).getByLabelText('Tipo'), 'variable')
    await userEvent.type(within(dialog).getByLabelText('Valor previsto'), '250.00')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(createBill).toHaveBeenCalled())
    const payload = vi.mocked(createBill).mock.calls[0]![0]
    expect(payload).not.toHaveProperty('due_day')
    expect(payload).not.toHaveProperty('advance_notice_days')
  })

  it('pinta o 422 por cima do campo que o backend recusou', async () => {
    vi.mocked(createBill).mockRejectedValue(
      new ApiError(422, {
        code: 'validation_failed',
        message: 'Dados inválidos.',
        details: { due_day: ['O dia precisa estar entre 1 e 31.'] },
        request_id: 'req-1',
      }),
    )

    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nova conta' })
    await userEvent.type(within(dialog).getByLabelText('Nome'), 'Internet')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Salvar' }))

    expect(await within(dialog).findByText('O dia precisa estar entre 1 e 31.')).toBeInTheDocument()
  })

  it('pausa a conta em vez de excluir — a API não tem delete', async () => {
    vi.mocked(updateBill).mockResolvedValue({ ...bill, is_active: false })

    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getAllByRole('button', { name: 'Pausar' })[0]!)

    await waitFor(() => expect(updateBill).toHaveBeenCalledWith(BILL_ID, { is_active: false }))
  })

  it('esconde toda escrita sem bills.manage, mas mantém a leitura', async () => {
    await signIn([])
    renderWithRouter(<BillListPage />)

    // a leitura é de qualquer morador
    expect(await screen.findByText('Aluguel')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nova conta' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pausar' })).not.toBeInTheDocument()
  })

  it('mostra a próxima data que o Scheduling calculou, sem calcular na tela', async () => {
    renderWithRouter(<BillListPage />)

    expect(await screen.findByText(/próximo 2026-04-10/)).toBeInTheDocument()
  })
})

describe('ManualOccurrenceModal', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(fetchBills).mockResolvedValue(page([variableBill]))
    vi.mocked(createOccurrence).mockResolvedValue(occurrence)
  })

  it('abre e lança a fatura da conta escolhida', async () => {
    renderWithRouter(<ManualOccurrenceModal onClose={() => {}} />)

    const conta = await screen.findByLabelText('Conta')
    await waitFor(() =>
      expect(within(conta).getByRole('option', { name: 'Energia' })).toBeInTheDocument(),
    )
    await userEvent.selectOptions(conta, variableBill.id)
    await userEvent.type(screen.getByLabelText('Vencimento'), '2026-04-15')
    await userEvent.type(screen.getByLabelText('Valor'), '187.40')
    await userEvent.click(screen.getByRole('button', { name: 'Lançar' }))

    await waitFor(() =>
      expect(createOccurrence).toHaveBeenCalledWith({
        bill_id: variableBill.id,
        due_date: '2026-04-15',
        amount: '187.40',
      }),
    )
  })

  it('traduz o 409 de lançamento repetido em vez de "conflito"', async () => {
    vi.mocked(createOccurrence).mockRejectedValue(
      new ApiError(409, {
        code: 'bill_occurrence_exists',
        message: 'Já existe vencimento.',
        request_id: 'req-2',
      }),
    )

    renderWithRouter(<ManualOccurrenceModal onClose={() => {}} />)

    const conta = await screen.findByLabelText('Conta')
    await waitFor(() =>
      expect(within(conta).getByRole('option', { name: 'Energia' })).toBeInTheDocument(),
    )
    await userEvent.selectOptions(conta, variableBill.id)
    await userEvent.type(screen.getByLabelText('Vencimento'), '2026-04-15')
    await userEvent.type(screen.getByLabelText('Valor'), '187.40')
    await userEvent.click(screen.getByRole('button', { name: 'Lançar' }))

    expect(
      await screen.findByText('Esta conta já tem um vencimento nesta data.'),
    ).toBeInTheDocument()
  })

  it('recusa conta e valor vazios antes de chamar o servidor', async () => {
    renderWithRouter(<ManualOccurrenceModal onClose={() => {}} />)

    await screen.findByLabelText('Conta')
    await userEvent.click(screen.getByRole('button', { name: 'Lançar' }))

    expect(await screen.findByText('Escolha a conta.')).toBeInTheDocument()
    expect(createOccurrence).not.toHaveBeenCalled()
  })
})

describe('PayModal', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(payOccurrence).mockResolvedValue({ ...occurrence, status: 'paid' })
  })

  it('abre com o valor previsto e os sete métodos', async () => {
    renderWithRouter(<PayModal occurrence={occurrence} onClose={() => {}} />)

    expect(await screen.findByLabelText('Valor pago')).toHaveValue('1200.00')
    const select = screen.getByLabelText('Como foi pago') as HTMLSelectElement
    expect(select.options).toHaveLength(7)
  })

  it('baixa o vencimento com o método escolhido', async () => {
    renderWithRouter(<PayModal occurrence={occurrence} onClose={() => {}} />)

    await userEvent.selectOptions(await screen.findByLabelText('Como foi pago'), 'boleto')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar pagamento' }))

    await waitFor(() =>
      expect(payOccurrence).toHaveBeenCalledWith(
        OCCURRENCE_ID,
        expect.objectContaining({ method: 'boleto', amount: '1200.00' }),
      ),
    )
  })

  it('explica o 409 de ocorrência cancelada em vez de erro genérico', async () => {
    vi.mocked(payOccurrence).mockRejectedValue(
      new ApiError(409, {
        code: 'bill_occurrence_not_payable',
        message: 'Ocorrência não pagável.',
        request_id: 'req-3',
      }),
    )

    renderWithRouter(<PayModal occurrence={occurrence} onClose={() => {}} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar pagamento' }))

    expect(
      await screen.findByText('Esta ocorrência está cancelada — não há o que pagar.'),
    ).toBeInTheDocument()
  })

  it('pinta o 422 por cima do campo recusado', async () => {
    vi.mocked(payOccurrence).mockRejectedValue(
      new ApiError(422, {
        code: 'validation_failed',
        message: 'Dados inválidos.',
        details: { receipt_url: ['O comprovante precisa ser um link válido.'] },
        request_id: 'req-4',
      }),
    )

    renderWithRouter(<PayModal occurrence={occurrence} onClose={() => {}} />)

    await userEvent.type(await screen.findByLabelText('Comprovante'), 'não é link')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar pagamento' }))

    expect(await screen.findByText('O comprovante precisa ser um link válido.')).toBeInTheDocument()
  })
})

describe('erro de leitura', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
  })

  it('mostra o request_id para o morador citar no suporte', async () => {
    vi.stubGlobal('fetch', vi.fn())
    vi.mocked(fetchBills).mockRejectedValue(
      new ApiError(500, {
        code: 'server_error',
        message: 'Erro interno.',
        request_id: 'req-abc',
      }),
    )

    renderWithRouter(<BillListPage />)

    expect(await screen.findByText(/Erro interno/)).toBeInTheDocument()
    expect(screen.getByText('referência: req-abc')).toBeInTheDocument()
  })
})

describe('BillFormModal — os campos se explicam', () => {
  beforeEach(async () => {
    vi.clearAllMocks()
    await signIn()
    vi.mocked(fetchBills).mockResolvedValue(page([bill, variableBill]))
    vi.mocked(createBill).mockResolvedValue(bill)
  })

  it('avisa que quem calcula a data é o agendador, não a tela', async () => {
    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nova conta' })

    expect(
      within(dialog).getByText(/Quem calcula a próxima data é o agendador/),
    ).toBeInTheDocument()
  })

  it('explica o que acontece com a conta variável em vez de só sumir com os campos', async () => {
    renderWithRouter(<BillListPage />)
    await screen.findByText('Aluguel')

    await userEvent.click(screen.getByRole('button', { name: 'Nova conta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nova conta' })
    await userEvent.selectOptions(within(dialog).getByLabelText('Tipo'), 'variable')

    // campos da cadência somem, mas o morador fica sabendo onde lançar a fatura
    expect(within(dialog).queryByLabelText('Dia do vencimento')).not.toBeInTheDocument()
    expect(within(dialog).getByText(/cada fatura entra pela aba Vencimentos/i)).toBeInTheDocument()
  })
})
