import { useQuery } from '@tanstack/react-query'
import { resourceKeys, tenantKey } from '@/shared/api/queryKeys'
import { useTenant } from '@/tenants/TenantProvider'
import {
  fetchActiveMembers,
  fetchBill,
  fetchBills,
  fetchOccurrenceSplit,
  fetchOccurrences,
  fetchSplitRules,
  fetchSummary,
} from './api'
import type { BillFilters, OccurrenceFilters } from './api'

/**
 * Todo hook exige residência ativa antes de sair para a rede (R6): sem `tid` o
 * `TenantScope` recusa com 403 e a tela mostra erro onde devia mostrar seleção.
 */
export function useSummary(month: string) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.summary(activeTenantId, month),
    queryFn: ({ signal }) => fetchSummary(signal, month),
    enabled: activeTenantId !== null && month !== '',
  })
}

export function useBills(filters: BillFilters) {
  const { activeTenantId } = useTenant()
  const { kind = null, isActive = null, category = null, page = 1, perPage = 50 } = filters

  return useQuery({
    queryKey: [
      ...resourceKeys.bills(activeTenantId, kind, isActive, category),
      'page',
      page,
      perPage,
    ],
    queryFn: ({ signal }) => fetchBills(signal, { kind, isActive, category, page, perPage }),
    enabled: activeTenantId !== null,
  })
}

export function useBill(billId: string | null) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.bill(activeTenantId, billId ?? ''),
    queryFn: ({ signal }) => fetchBill(signal, billId!),
    enabled: activeTenantId !== null && billId !== null,
  })
}

export function useBillOccurrences(filters: OccurrenceFilters) {
  const { activeTenantId } = useTenant()
  const { month = null, billId = null, status = null } = filters

  return useQuery({
    queryKey: resourceKeys.billOccurrences(activeTenantId, month, billId, status),
    queryFn: ({ signal }) => fetchOccurrences(signal, filters),
    enabled: activeTenantId !== null,
  })
}

/** Sem `billId`, a lista traz a regra da casa — é o que o builder abre primeiro. */
export function useSplitRule(billId?: string | null) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.splitRules(activeTenantId, billId),
    queryFn: ({ signal }) => fetchSplitRules(signal, billId),
    enabled: activeTenantId !== null,
  })
}

export function useOccurrenceSplit(occurrenceId: string | null) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.occurrenceSplit(activeTenantId, occurrenceId ?? ''),
    queryFn: ({ signal }) => fetchOccurrenceSplit(signal, occurrenceId!),
    enabled: activeTenantId !== null && occurrenceId !== null,
  })
}

/** Moradores ativos da residência: a lista de quem pode entrar no split. */
export function useActiveMembers() {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: tenantKey(activeTenantId, 'members'),
    queryFn: ({ signal }) => fetchActiveMembers(signal, activeTenantId!),
    enabled: activeTenantId !== null,
  })
}
