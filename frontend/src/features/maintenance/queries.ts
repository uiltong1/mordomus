import { useQuery } from '@tanstack/react-query'
import { resourceKeys } from '@/shared/api/queryKeys'
import { useTenant } from '@/tenants/TenantProvider'
import { fetchAssets, fetchRoom, fetchRooms } from './api'

/**
 * Leitura de cômodos e inventário.
 *
 * Toda chave carrega o `tenantId` (R6) e toda query é desligada sem residência
 * ativa: sem `tid` no token a chamada volta 403 e a tela pisca "sem residência".
 */

export function useRooms(options: { includeArchived?: boolean } = {}) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.rooms(activeTenantId),
    queryFn: ({ signal }) =>
      fetchRooms(signal, { include_archived: options.includeArchived ?? false }),
    enabled: activeTenantId !== null,
    // a lista de cômodos muda pouco e é o que decide o desenho do painel
    staleTime: 60_000,
  })
}

export function useRoom(roomId: string | undefined) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.room(activeTenantId, roomId ?? ''),
    queryFn: ({ signal }) => fetchRoom(roomId!, signal),
    enabled: activeTenantId !== null && Boolean(roomId),
  })
}

export function useAssets(params: { roomId?: string | null; includeArchived?: boolean } = {}) {
  const { activeTenantId } = useTenant()
  const roomId = params.roomId ?? null

  return useQuery({
    queryKey: resourceKeys.assets(activeTenantId, roomId),
    queryFn: ({ signal }) =>
      fetchAssets(signal, {
        roomId,
        includeArchived: params.includeArchived ?? false,
      }),
    enabled: activeTenantId !== null,
  })
}
