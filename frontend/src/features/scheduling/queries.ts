import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { resourceKeys } from '@/shared/api/queryKeys'
import type { SchedulingOccurrenceStatus, SubjectType } from '@/shared/api/types'
import { useTenant } from '@/tenants/TenantProvider'
import {
  completeAssetOccurrence,
  completeOccurrence,
  fetchOccurrences,
  skipOccurrence,
} from './api'

export const DASHBOARD_BACKWARD_DAYS = 90
export const DASHBOARD_FORWARD_DAYS = 60

export interface OccurrenceFilters {
  from?: string | null
  to?: string | null
  subjectType?: SubjectType | null
  status?: SchedulingOccurrenceStatus | null
}

export function useOccurrences(filters: OccurrenceFilters, enabled = true) {
  const { activeTenantId } = useTenant()
  const { from = null, to = null, subjectType = null, status = null } = filters

  return useQuery({
    queryKey: resourceKeys.occurrences(activeTenantId, from, to, subjectType, status),
    queryFn: ({ signal }) => fetchOccurrences(signal, { from, to, subjectType, status }),
    enabled: activeTenantId !== null && enabled,
  })
}

export interface OccurrenceActionResult {
  changed: boolean
}

function useOccurrenceTransition(action: 'complete' | 'skip') {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (occurrenceId: string): Promise<OccurrenceActionResult> => {
      const before = queryClient
        .getQueriesData<{ data: { id: string; status: string }[] }>({
          queryKey: resourceKeys.occurrences(activeTenantId),
        })
        .flatMap(([, page]) => page?.data ?? [])
        .find((occurrence) => occurrence.id === occurrenceId)

      const settled = before && (before.status === 'completed' || before.status === 'skipped')
      if (action === 'complete') {
        await completeOccurrence(occurrenceId)
      } else {
        await skipOccurrence(occurrenceId)
      }
      return { changed: !settled }
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.occurrences(activeTenantId),
      })
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.triggerConfigs(activeTenantId),
      })
    },
  })
}

export function useCompleteOccurrence() {
  return useOccurrenceTransition('complete')
}

export function useSkipOccurrence() {
  return useOccurrenceTransition('skip')
}

export function useCompleteAssetOccurrence() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (occurrenceId: string) => completeAssetOccurrence(occurrenceId),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.occurrences(activeTenantId),
      })
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.triggerConfigs(activeTenantId),
      })
    },
  })
}
