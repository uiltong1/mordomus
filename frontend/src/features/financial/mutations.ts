import { useMutation, useQueryClient } from '@tanstack/react-query'
import { resourceKeys, tenantKey } from '@/shared/api/queryKeys'
import { useTenant } from '@/tenants/TenantProvider'
import {
  createBill,
  createOccurrence,
  payOccurrence,
  saveSplitRule,
  settleShare,
  updateBill,
} from './api'
import type { BillInput, BillUpdate, OccurrenceInput, PayInput, SplitRuleInput } from './api'

export interface UpdateBillVariables {
  billId: string
  input: BillUpdate
}

export interface PayOccurrenceVariables {
  occurrenceId: string
  input: PayInput
}

export interface SettleShareVariables {
  occurrenceId: string
  userId: string
  settled: boolean
}

export function useCreateBill() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: BillInput) => createBill(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.bills(activeTenantId) })
      void queryClient.invalidateQueries({ queryKey: resourceKeys.billOccurrences(activeTenantId) })
    },
  })
}

export function useUpdateBill() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ billId, input }: UpdateBillVariables) => updateBill(billId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.bills(activeTenantId) })
    },
  })
}

export function useCreateOccurrence() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: OccurrenceInput) => createOccurrence(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.billOccurrences(activeTenantId) })
      void queryClient.invalidateQueries({ queryKey: resourceKeys.summary(activeTenantId) })
    },
  })
}

export function usePayOccurrence() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ occurrenceId, input }: PayOccurrenceVariables) =>
      payOccurrence(occurrenceId, input),
    onSuccess: (_occurrence, variables) => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.billOccurrences(activeTenantId) })
      void queryClient.invalidateQueries({ queryKey: resourceKeys.summary(activeTenantId) })
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.occurrenceSplit(activeTenantId, variables.occurrenceId),
      })
    },
  })
}

export function useSaveSplitRule() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SplitRuleInput) => saveSplitRule(input),
    onSuccess: (rule) => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.splitRules(activeTenantId) })
      void queryClient.invalidateQueries({
        queryKey: tenantKey(activeTenantId, 'occurrence-split'),
      })
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.bill(activeTenantId, rule.bill_id ?? ''),
      })
    },
  })
}

export function useSettleShare() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ occurrenceId, userId, settled }: SettleShareVariables) =>
      settleShare(occurrenceId, { user_id: userId, settled }),
    onSuccess: (_split, variables) => {
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.occurrenceSplit(activeTenantId, variables.occurrenceId),
      })
    },
  })
}
