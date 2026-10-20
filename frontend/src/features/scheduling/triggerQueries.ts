import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { resourceKeys } from '@/shared/api/queryKeys'
import type { SubjectType, TriggerConfig, TriggerPreview } from '@/shared/api/types'
import { useToast } from '@/shared/ui'
import { useTenant } from '@/tenants/TenantProvider'
import { ApiError } from '@/shared/api/errors'
import {
  createTriggerConfig,
  deleteTriggerConfig,
  fetchTriggerConfigs,
  saveAssetSchedule,
  updateTriggerConfig,
  type TriggerConfigInput,
} from './api'

export function useTriggerConfigs(filters: { subjectType?: SubjectType | null } = {}) {
  const { activeTenantId } = useTenant()
  const subjectType = filters.subjectType ?? null

  return useQuery({
    queryKey: resourceKeys.triggerConfigs(activeTenantId, subjectType),
    queryFn: ({ signal }) => fetchTriggerConfigs(signal, { subjectType }),
    enabled: activeTenantId !== null,
  })
}

export interface SaveTriggerConfigInput {
  configId: string | null
  subjectId: string
  payload: TriggerConfigInput
}

export function useSaveTriggerConfig() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()

  return useMutation({
    mutationFn: async ({
      configId,
      subjectId,
      payload,
    }: SaveTriggerConfigInput): Promise<TriggerConfig> => {
      if (configId) {
        return updateTriggerConfig(configId, payload)
      }
      return saveAssetSchedule(subjectId, payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.triggerConfigs(activeTenantId) })
      void queryClient.invalidateQueries({ queryKey: resourceKeys.rooms(activeTenantId) })
      void queryClient.invalidateQueries({ queryKey: resourceKeys.assets(activeTenantId) })
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível salvar a regra.')
    },
  })
}

export function useCreateTriggerConfig() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()

  return useMutation({
    mutationFn: (input: TriggerConfigInput) => createTriggerConfig(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.triggerConfigs(activeTenantId) })
      toast.success('Regra criada.')
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível criar a regra.')
    },
  })
}

export function useDeleteTriggerConfig() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()

  return useMutation({
    mutationFn: (configId: string) => deleteTriggerConfig(configId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.triggerConfigs(activeTenantId) })
      toast.success('Regra removida.')
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível remover a regra.')
    },
  })
}

export type { TriggerPreview }
