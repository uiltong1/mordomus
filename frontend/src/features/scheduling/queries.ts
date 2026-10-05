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

/**
 * Janela padrão do painel: o passado curto pega o que está atrasado e o futuro
 * médio mostra a fila que vem. Fora dela não há nada acionável — o que já foi
 * concluído há mais de 3 meses é histórico, e o que vence depois de 2 meses
 * ainda dá tempo de cadastrar a regra antes.
 */
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
  /** `true` quando a transição aconteceu nesta chamada, `false` quando era repetição. */
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
      // a linha muda de status e a regra recalcula o próximo ciclo na fila; sem
      // invalidar, o card continuaria mostrando o mesmo vencimento
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

/**
 * Check-in pelo card do cômodo. Usa o atalho de `maintenance` — mesmo efeito
 * sobre a mesma linha, e o 404 de escopo (`occurrence_not_found`) é o que
 * impede concluir uma conta por este caminho.
 */
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
