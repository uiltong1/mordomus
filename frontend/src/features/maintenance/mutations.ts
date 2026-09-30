import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { resourceKeys } from '@/shared/api/queryKeys'
import { useToast } from '@/shared/ui'
import { useTenant } from '@/tenants/TenantProvider'
import type { Asset, Room } from '@/shared/api/types'
import { archiveAsset, archiveRoom, reorderRooms, restoreAsset, restoreRoom } from './api'

/**
 * Arquivamento do inventário.
 *
 * O `DELETE` do monólito é soft delete: a linha vira `archived` e continua
 * referenciada pelas regras que apontam para ela. Por isso a tela oferece
 * "Arquivar" e "Restaurar" em vez de apagar — um cômodo errado se corrige, e a
 * regra de manutenção que dependia dele não fica órfã.
 */

function useArchive<T extends Asset | Room>(
  archive: (id: string) => Promise<T>,
  restore: (id: string) => Promise<T>,
  label: string,
) {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: resourceKeys.rooms(activeTenantId) })
    void queryClient.invalidateQueries({ queryKey: resourceKeys.assets(activeTenantId) })
  }

  const toArchived = useMutation({
    mutationFn: (id: string) => archive(id),
    onSuccess: () => {
      invalidate()
      toast.success(`${label} arquivado.`)
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? error.message : `Não foi possível arquivar.`)
    },
  })

  const toActive = useMutation({
    mutationFn: (id: string) => restore(id),
    onSuccess: () => {
      invalidate()
      toast.success(`${label} restaurado.`)
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível restaurar.')
    },
  })

  return { archive: toArchived, restore: toActive }
}

export function useRoomArchive() {
  return useArchive(archiveRoom, restoreRoom, 'Cômodo')
}

export function useAssetArchive() {
  return useArchive(archiveAsset, restoreAsset, 'Item')
}

export function useReorderRooms() {
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()

  return useMutation({
    mutationFn: (ids: string[]) => reorderRooms(ids),
    onSuccess: () => {
      // a resposta já é a ordem definitiva do servidor; sem invalidar, um
      // `refetch` posterior com o `sort_order` antigo reordenaria a tela de volta
      void queryClient.invalidateQueries({ queryKey: resourceKeys.rooms(activeTenantId) })
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível reordenar.')
    },
  })
}
