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
  /** `null` na criação, o id da regra na edição. */
  configId: string | null
  /** Ativo alvo. Não entra no `PATCH` — o backend trata o alvo como imutável. */
  subjectId: string
  payload: TriggerConfigInput
}

/**
 * Mutação de regra.
 *
 * O `POST /preview` roda com o mesmo `enabled` da regra: ele é a razão de o
 * formulário existir, e sem ele o morador só descobre a data depois de salvar.
 * Já vem sem capability no backend (`auth:jwt` + `tenant`), então o botão de
 * preview existe mesmo para quem não pode editar regra.
 */
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
        // o alvo não volta no `PATCH`: `subject_type`/`subject_id` são imutáveis
        // e enviá-los derrubaria a requisição
        return updateTriggerConfig(configId, payload)
      }
      // regra de manutenção passa pelo atalho do inventário: o upsert por
      // título é o que impede "limpar filtro" duplicado quando o morador abre
      // a mesma regra duas vezes sem saber que ela já existe
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

/**
 * Criação avulsa pela porta do Scheduling. Existe separada da anterior porque
 * o `POST /trigger-configs` exige `subject_type` explícito e é a rota que a
 * Fase 5 vai usar para as contas — deixá-la pronta evita a tela de contas
 * nascer fingindo que manutenção é caso especial.
 */
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
