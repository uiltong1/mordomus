import { useQuery } from '@tanstack/react-query'
import { resourceKeys } from '@/shared/api/queryKeys'
import { useTenant } from '@/tenants/TenantProvider'
import { fetchAssets, fetchRoom, fetchRooms } from './api'

export function useRooms(options: { includeArchived?: boolean } = {}) {
  const { activeTenantId } = useTenant()

  return useQuery({
    queryKey: resourceKeys.rooms(activeTenantId),
    queryFn: ({ signal }) =>
      fetchRooms(signal, { include_archived: options.includeArchived ?? false }),
    enabled: activeTenantId !== null,
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
