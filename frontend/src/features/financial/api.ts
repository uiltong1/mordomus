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

export interface BillFilters {
  kind?: BillKind | null
  isActive?: boolean | null
  category?: string | null
  page?: number
  perPage?: number
}

export interface OccurrenceFilters {
  month?: string | null
  from?: string | null
  to?: string | null
  billId?: string | null
  status?: FinancialOccurrenceStatus | null
}

export interface BillInput {
  name: string
  kind: BillKind
  category?: string | null
  amount?: string | null
  currency?: string
  is_active?: boolean
  due_day?: number | null
  advance_notice_days?: number
}

export type BillUpdate = Partial<BillInput>

export interface OccurrenceInput {
  bill_id: string
  due_date: string
  amount: string
}

export interface PayInput {
  method: PaymentMethod
  amount?: string
  paid_at?: string
  receipt_url?: string | null
}

export interface SplitEntryInput {
  user_id: string
  weight?: number
  percent?: number
  fixed_amount?: string
}

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
      is_active:
        filters.isActive === null || filters.isActive === undefined
          ? undefined
          : filters.isActive
            ? 1
            : 0,
      category: filters.category ?? undefined,
      page: clampPage(filters.page),
      per_page: clampPerPage(filters.perPage),
    },
  })
}

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

export function fetchActiveMembers(
  signal: AbortSignal | undefined,
  tenantId: string,
): Promise<Member[]> {
  return api
    .get<{ data: Member[] }>(`/identity/tenants/${tenantId}/members`, { signal })
    .then(unwrap)
}
