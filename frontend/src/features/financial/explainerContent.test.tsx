import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type * as FinancialApi from './api'
import { saveSession } from '@/shared/api/tokenStore'
import { renderWithRouter } from '@/test/utils'
import type { Bill, BillOccurrence } from '@/shared/api/types'
import { BillListPage } from './BillListPage'
import { OccurrencesPage } from './OccurrencesPage'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof FinancialApi>()
  return {
    ...actual,
    fetchBills: vi.fn(),
    fetchOccurrences: vi.fn(),
    fetchSplitRules: vi.fn(),
    fetchOccurrenceSplit: vi.fn(),
  }
})

const { fetchBills, fetchOccurrences } = await import('./api')

const TENANT = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'

const fixedBill: Bill = {
  id: 'b-fixa',
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
  ...fixedBill,
  id: 'b-variavel',
  name: 'Energia',
  kind: 'variable',
  category: null,
  amount: null,
  schedule: null,
}

const openOccurrence: BillOccurrence = {
  id: 'o1',
  tenant_id: TENANT,
  bill_id: 'b-fixa',
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

async function signIn(capabilities = ['bills.manage', 'bills.pay', 'splits.manage']) {
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

beforeEach(async () => {
  vi.clearAllMocks()
  await signIn()
  vi.mocked(fetchBills).mockResolvedValue(page([fixedBill, variableBill]))
  vi.mocked(fetchOccurrences).mockResolvedValue(page([openOccurrence]))
})

describe('BillListPage — a tela se explica', () => {
  it('distingue conta de vencimento antes de qualquer dado aparecer', async () => {
    renderWithRouter(<BillListPage />)

    const explainer = await screen.findByText(/Conta é o cadastro que se repete/)
    expect(explainer).toBeInTheDocument()
    // a ponte com a outra aba está na frase, não escondida no detalhe
    expect(explainer).toHaveTextContent('vencimento')
  })

  it('detalha fixa × variável e o efeito de pausar sob demanda', async () => {
    renderWithRouter(<BillListPage />)

    await userEvent.click(screen.getByText('Como isto funciona'))

    expect(screen.getByText('Conta fixa vence sozinha, conta variável não')).toBeInTheDocument()
    expect(screen.getByText('Pausar não apaga histórico')).toBeInTheDocument()
  })

  it('diz na própria linha por que a conta variável não tem data', async () => {
    renderWithRouter(<BillListPage />)

    await screen.findByText('Energia')

    // a linha que mais parece quebrada é a que precisa dizer onde está o resto
    expect(screen.getByText(/lance cada fatura em Vencimentos/)).toBeInTheDocument()
    expect(screen.getByText(/vence todo dia 10/)).toBeInTheDocument()
  })
})

describe('OccurrencesPage — a tela se explica', () => {
  it('diz de onde vem a linha antes de qualquer dado aparecer', async () => {
    renderWithRouter(<OccurrencesPage />)

    expect(await screen.findByText(/Em conta fixa ela aparece sozinha/)).toBeInTheDocument()
  })

  it('esclarece que lançar é só para variável, sob demanda', async () => {
    renderWithRouter(<OccurrencesPage />)

    await userEvent.click(screen.getByText('Como isto funciona'))

    expect(screen.getByText('Quando usar "Lançar fatura"')).toBeInTheDocument()
    expect(screen.getByText('Cada linha é um vencimento, não a conta')).toBeInTheDocument()
  })

  it('não repete a explicação de um botão que está logo abaixo', async () => {
    renderWithRouter(<OccurrencesPage />)

    await screen.findByText('Aluguel')
    const explainer = screen.getByText(/Cada linha é uma conta vencendo/).closest('aside')

    expect(explainer).not.toBeNull()
    expect(within(explainer as HTMLElement).queryByRole('button')).toBeNull()
  })
})
