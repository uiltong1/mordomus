import { api, unwrap } from '@/shared/api/client'
import type { Asset, Paginated, Room } from '@/shared/api/types'

const ALL = { per_page: 100 } as const

export interface RoomInput {
  name: string
  icon?: string | null
  sort_order?: number
}

export interface AssetInput {
  room_id: string
  name: string
  category?: string | null
  brand?: string | null
  model?: string | null
  acquired_at?: string | null
  warranty_until?: string | null
}

export function fetchRooms(
  signal?: AbortSignal,
  params: { include_archived?: boolean } = {},
): Promise<Paginated<Room>> {
  return api.get<Paginated<Room>>('/maintenance/rooms', {
    signal,
    query: { ...ALL, include_archived: params.include_archived ?? false },
  })
}

export function fetchRoom(roomId: string, signal?: AbortSignal): Promise<Room> {
  return api.get<{ data: Room }>(`/maintenance/rooms/${roomId}`, { signal }).then(unwrap)
}

export function createRoom(input: RoomInput): Promise<Room> {
  return api.post<{ data: Room }>('/maintenance/rooms', input).then(unwrap)
}

export function updateRoom(roomId: string, input: Partial<RoomInput>): Promise<Room> {
  return api.patch<{ data: Room }>(`/maintenance/rooms/${roomId}`, input).then(unwrap)
}

export function archiveRoom(roomId: string): Promise<Room> {
  return api.patch<{ data: Room }>(`/maintenance/rooms/${roomId}`, { archived: true }).then(unwrap)
}

export function restoreRoom(roomId: string): Promise<Room> {
  return api.patch<{ data: Room }>(`/maintenance/rooms/${roomId}`, { archived: false }).then(unwrap)
}

export function reorderRooms(ids: string[]): Promise<Room[]> {
  return api.put<{ data: Room[] }>('/maintenance/rooms/order', { ids }).then(unwrap)
}

export function fetchAssets(
  signal?: AbortSignal,
  params: { roomId?: string | null; includeArchived?: boolean } = {},
): Promise<Paginated<Asset>> {
  return api.get<Paginated<Asset>>('/maintenance/assets', {
    signal,
    query: {
      ...ALL,
      room_id: params.roomId ?? undefined,
      include_archived: params.includeArchived ?? false,
    },
  })
}

export function fetchAsset(assetId: string, signal?: AbortSignal): Promise<Asset> {
  return api.get<{ data: Asset }>(`/maintenance/assets/${assetId}`, { signal }).then(unwrap)
}

export function createAsset(input: AssetInput): Promise<Asset> {
  return api.post<{ data: Asset }>('/maintenance/assets', input).then(unwrap)
}

export function updateAsset(assetId: string, input: Partial<AssetInput>): Promise<Asset> {
  return api.patch<{ data: Asset }>(`/maintenance/assets/${assetId}`, input).then(unwrap)
}

export function archiveAsset(assetId: string): Promise<Asset> {
  return api
    .patch<{ data: Asset }>(`/maintenance/assets/${assetId}`, { archived: true })
    .then(unwrap)
}

export function restoreAsset(assetId: string): Promise<Asset> {
  return api
    .patch<{ data: Asset }>(`/maintenance/assets/${assetId}`, { archived: false })
    .then(unwrap)
}
