import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createBill,
  createOccurrence,
  fetchBills,
  fetchOccurrences,
  payOccurrence,
  saveSplitRule,
} from './api'

/**
 * Fiação com a API: o que a tela manda e o que ela pede.
 *
 * O foco é o contrato, não o pixel — o campo certo na requisição errada é o que
 * o backend recusa com 422 (`prohibited`, `required`) ou 409.
 */
function lastCall(): [string, RequestInit | undefined] {
  const call = fetchMock.mock.calls.at(-1)
  if (!call) throw new Error('nenhuma requisição foi feita')
  const [url, init] = call as unknown as [string, RequestInit | undefined]
  return [url, init]
}

function bodyOf(): Record<string, unknown> {
  const [, init] = lastCall()
  return JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>
}

const fetchMock = vi.fn<(input: string, init?: RequestInit) => Promise<Response>>()

function ok(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

const BILL = {
  id: '01J8Z0M9W3K6Q2T4R5Y7B8C9D3',
  tenant_id: '01J8Z0M9W3K6Q2T4R5Y7B8C9D4',
  name: 'Aluguel',
  kind: 'fixed',
  category: 'moradia',
  amount: '1200.00',
  currency: 'BRL',
  is_active: true,
  schedule: null,
  created_by: 'u1',
  created_at: null,
  updated_at: null,
}

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('fetchBills', () => {
  it('revela no máximo uma página de 100 e começa em 1', async () => {
    fetchMock.mockResolvedValue(
      ok({ data: [BILL], meta: { page: 1, per_page: 100, total: 1, last_page: 1 } }),
    )

    await fetchBills(undefined, { page: 0, perPage: 500 })

    const [url] = lastCall()
    expect(url).toContain('page=1')
    expect(url).toContain('per_page=100')
  })

  it('omite o filtro ausente em vez de mandar vazio', async () => {
    fetchMock.mockResolvedValue(
      ok({ data: [], meta: { page: 1, per_page: 50, total: 0, last_page: 1 } }),
    )

    await fetchBills(undefined, {})

    const [url] = lastCall()
    expect(url).not.toContain('kind=')
    expect(url).not.toContain('is_active=')
    expect(url).not.toContain('category=')
  })

  it('manda o filtro escolhido com o nome que a API usa', async () => {
    fetchMock.mockResolvedValue(
      ok({ data: [], meta: { page: 1, per_page: 50, total: 0, last_page: 1 } }),
    )

    await fetchBills(undefined, { kind: 'variable', isActive: false, category: 'energia' })

    const [url] = lastCall()
    expect(url).toContain('kind=variable')
    expect(url).toContain('is_active=0')
    expect(url).toContain('category=energia')
  })
})

describe('fetchOccurrences', () => {
  beforeEach(() => {
    fetchMock.mockResolvedValue(
      ok({ data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } }),
    )
  })

  it('manda month sozinho', async () => {
    await fetchOccurrences(undefined, { month: '2026-03' })

    const [url] = lastCall()
    expect(url).toContain('month=2026-03')
    expect(url).not.toContain('from=')
    expect(url).not.toContain('to=')
  })

  it('manda o intervalo sem month — o backend recusa os dois juntos', async () => {
    await fetchOccurrences(undefined, { from: '2026-03-01', to: '2026-03-31' })

    const [url] = lastCall()
    expect(url).toContain('from=2026-03-01')
    expect(url).toContain('to=2026-03-31')
    expect(url).not.toContain('month=')
  })

  it('traduz bill_id e status para o nome da query', async () => {
    await fetchOccurrences(undefined, { billId: 'bill-1', status: 'overdue' })

    const [url] = lastCall()
    expect(url).toContain('bill_id=bill-1')
    expect(url).toContain('status=overdue')
  })
})

describe('createBill', () => {
  it('manda a cadência com o nome que o Scheduling lê', async () => {
    fetchMock.mockResolvedValue(ok({ data: BILL }))

    await createBill({
      name: 'Aluguel',
      kind: 'fixed',
      amount: '1200.00',
      due_day: 10,
      advance_notice_days: 5,
    })

    expect(bodyOf()).toEqual({
      name: 'Aluguel',
      kind: 'fixed',
      amount: '1200.00',
      due_day: 10,
      advance_notice_days: 5,
    })
  })

  it('envia o valor como texto decimal, nunca number', async () => {
    fetchMock.mockResolvedValue(ok({ data: BILL }))

    await createBill({ name: 'Energia', kind: 'variable', amount: '187.4' })

    const body = bodyOf()
    expect(typeof body.amount).toBe('string')
    expect(body.amount).toBe('187.4')
  })
})

describe('createOccurrence', () => {
  it('manda a data como dia de calendário, sem passar por Date', async () => {
    fetchMock.mockResolvedValue(ok({ data: { id: 'o1' } }))

    await createOccurrence({ bill_id: 'bill-1', due_date: '2026-03-10', amount: '80.00' })

    expect(bodyOf()).toEqual({
      bill_id: 'bill-1',
      due_date: '2026-03-10',
      amount: '80.00',
    })
  })
})

describe('payOccurrence', () => {
  it('manda o método no campo que a API exige', async () => {
    fetchMock.mockResolvedValue(ok({ data: { id: 'o1' } }))

    await payOccurrence('o1', { method: 'pix', amount: '80.00', paid_at: '2026-03-09' })

    const [url, init] = lastCall()
    expect(url).toContain('/financial/occurrences/o1/paid')
    expect(init?.method).toBe('POST')
    expect(bodyOf()).toEqual({ method: 'pix', amount: '80.00', paid_at: '2026-03-09' })
  })

  it('manda receipt_url nulo como null, para o sometimes nullable', async () => {
    fetchMock.mockResolvedValue(ok({ data: { id: 'o1' } }))

    await payOccurrence('o1', { method: 'cash', receipt_url: null })

    expect(bodyOf()).toEqual({ method: 'cash', receipt_url: null })
  })
})

describe('saveSplitRule', () => {
  it('é PUT no conjunto de regras e manda bill_id nulo para a casa', async () => {
    fetchMock.mockResolvedValue(ok({ data: { id: 'r1' } }))

    await saveSplitRule({
      bill_id: null,
      mode: 'EQUAL',
      entries: [{ user_id: 'u1' }, { user_id: 'u2' }],
    })

    const [url, init] = lastCall()
    expect(url).toContain('/financial/split-rules')
    expect(init?.method).toBe('PUT')
    expect(bodyOf()).toEqual({
      bill_id: null,
      mode: 'EQUAL',
      entries: [{ user_id: 'u1' }, { user_id: 'u2' }],
    })
  })

  it('repassa as entradas com o campo do regime e nada mais', async () => {
    fetchMock.mockResolvedValue(ok({ data: { id: 'r1' } }))

    await saveSplitRule({
      bill_id: 'bill-1',
      mode: 'WEIGHTED',
      is_active: true,
      entries: [{ user_id: 'u1', weight: 2 }],
    })

    expect(bodyOf().entries).toEqual([{ user_id: 'u1', weight: 2 }])
  })
})
