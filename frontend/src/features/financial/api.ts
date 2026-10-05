import { api, unwrap } from '@/shared/api/client'
import type {
  Bill,
  BillKind,
  BillOccurrence,
  BillSummary,
  FinancialOccurrenceStatus,
  Member,
  OccurrenceSplit,
  Paginated,
  PaymentMethod,
  SplitMode,
  SplitRule,
} from '@/shared/api/types'

/**
 * Contas, vencimentos e divisão (`/financial/*`).
 *
 * Montantes viajam como **texto decimal** nos dois sentidos (regra R5): a soma
 * fecha em centavos e binário de ponto flutuante erraria. Por isso os payloads
 * de entrada também são `string` — converter para `number` antes de enviar
 * reintroduz o erro que o backend trabalha para não ter.
 */

export interface BillFilters {
  kind?: BillKind | null
  isActive?: boolean | null
  category?: string | null
  page?: number
  perPage?: number
}

/** `month` e `from`/`to` são mutuamente exclusivos: o backend recusa os dois juntos. */
export interface OccurrenceFilters {
  month?: string | null
  from?: string | null
  to?: string | null
  billId?: string | null
  status?: FinancialOccurrenceStatus | null
}

/** `POST /bills`. Espelha `StoreBillRequest` — os campos fora daqui são recusados. */
export interface BillInput {
  name: string
  kind: BillKind
  category?: string | null
  amount?: string | null
  currency?: string
  is_active?: boolean
  /** Cadência — só faz sentido em `kind: 'fixed'`; quem calcula a data é o Scheduling (R7). */
  due_day?: number | null
  advance_notice_days?: number
}

/** `PATCH /bills/{bill}`: campo omitido mantém o que está gravado. */
export type BillUpdate = Partial<BillInput>

/** `POST /occurrences`. Espelha `StoreBillOccurrenceRequest`. */
export interface OccurrenceInput {
  bill_id: string
  due_date: string
  amount: string
}

/** `POST /occurrences/{id}/paid`. Espelha `PayBillOccurrenceRequest` — `method` é obrigatório. */
export interface PayInput {
  method: PaymentMethod
  amount?: string
  paid_at?: string
  receipt_url?: string | null
}

/** Uma cota em_edição. Só o campo do regime entra no payload. */
export interface SplitEntryInput {
  user_id: string
  weight?: number
  percent?: number
  fixed_amount?: string
}

/** `PUT /split-rules`: `entries` é o conjunto completo, nunca um incremento. */
export interface SplitRuleInput {
  bill_id: string | null
  mode: SplitMode
  is_active?: boolean
  entries: SplitEntryInput[]
}

export function fetchSummary(signal: AbortSignal | undefined, month: string): Promise<BillSummary> {
  return api
    .get<{ data: BillSummary }>('/financial/summary', { signal, query: { month } })
    .then(unwrap)
}

export function fetchBills(
  signal: AbortSignal | undefined,
  filters: BillFilters,
): Promise<Paginated<Bill>> {
  return api.get<Paginated<Bill>>('/financial/bills', {
    signal,
    query: {
      kind: filters.kind ?? undefined,
      // `IndexBillsRequest` valida `is_active` como `boolean`, e a regra do Laravel
      // só aceita `0`/`1` na query — mandar `false` em texto é 422 na lista
      // inteira, que é o pior lugar para o morador ver um erro.
      is_active:
        filters.isActive === null || filters.isActive === undefined
          ? undefined
          : filters.isActive
            ? 1
            : 0,
      category: filters.category ?? undefined,
      // o clamp é do servidor (`OffsetPagination`); repetir aqui evita o 422 e
      // garante que a meta que volte seja a da página que a tela está mostrando
      page: clampPage(filters.page),
      per_page: clampPerPage(filters.perPage),
    },
  })
}

/** `page` começa em 1 — `0` seria a segunda página no offset do backend. */
function clampPage(page: number | undefined): number {
  return Math.min(Math.max(Math.trunc(page ?? 1), 1), 10_000)
}

function clampPerPage(perPage: number | undefined): number {
  return Math.min(Math.max(Math.trunc(perPage ?? 50), 1), 100)
}

export function fetchBill(signal: AbortSignal | undefined, billId: string): Promise<Bill> {
  return api.get<{ data: Bill }>(`/financial/bills/${billId}`, { signal }).then(unwrap)
}

export function createBill(input: BillInput): Promise<Bill> {
  return api.post<{ data: Bill }>('/financial/bills', input).then(unwrap)
}

export function updateBill(billId: string, input: BillUpdate): Promise<Bill> {
  return api.patch<{ data: Bill }>(`/financial/bills/${billId}`, input).then(unwrap)
}

export function fetchOccurrences(
  signal: AbortSignal | undefined,
  filters: OccurrenceFilters,
): Promise<Paginated<BillOccurrence>> {
  return api.get<Paginated<BillOccurrence>>('/financial/occurrences', {
    signal,
    query: {
      month: filters.month ?? undefined,
      from: filters.from ?? undefined,
      to: filters.to ?? undefined,
      bill_id: filters.billId ?? undefined,
      status: filters.status ?? undefined,
    },
  })
}

export function createOccurrence(input: OccurrenceInput): Promise<BillOccurrence> {
  return api.post<{ data: BillOccurrence }>('/financial/occurrences', input).then(unwrap)
}

export function payOccurrence(occurrenceId: string, input: PayInput): Promise<BillOccurrence> {
  return api
    .post<{ data: BillOccurrence }>(`/financial/occurrences/${occurrenceId}/paid`, input)
    .then(unwrap)
}

export function fetchSplitRules(
  signal: AbortSignal | undefined,
  billId?: string | null,
): Promise<SplitRule[]> {
  return api
    .get<{ data: SplitRule[] }>('/financial/split-rules', {
      signal,
      query: { bill_id: billId ?? undefined },
    })
    .then(unwrap)
}

export function saveSplitRule(input: SplitRuleInput): Promise<SplitRule> {
  return api.put<{ data: SplitRule }>('/financial/split-rules', input).then(unwrap)
}

export function fetchOccurrenceSplit(
  signal: AbortSignal | undefined,
  occurrenceId: string,
): Promise<OccurrenceSplit> {
  return api
    .get<{ data: OccurrenceSplit }>(`/financial/occurrences/${occurrenceId}/split`, { signal })
    .then(unwrap)
}

export function settleShare(
  occurrenceId: string,
  input: { user_id: string; settled: boolean },
): Promise<OccurrenceSplit> {
  return api
    .patch<{ data: OccurrenceSplit }>(`/financial/occurrences/${occurrenceId}/split`, input)
    .then(unwrap)
}

/** Participantes do split: o endpoint do Identity já devolve só os ativos. */
export function fetchActiveMembers(
  signal: AbortSignal | undefined,
  tenantId: string,
): Promise<Member[]> {
  return api
    .get<{ data: Member[] }>(`/identity/tenants/${tenantId}/members`, { signal })
    .then(unwrap)
}
