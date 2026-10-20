import { useMemo } from 'react'
import { shiftInputValue, todayInputValue } from '@/shared/datetime'
import {
  DASHBOARD_BACKWARD_DAYS,
  DASHBOARD_FORWARD_DAYS,
  useOccurrences,
} from '@/features/scheduling/queries'
import { useAssets, useRooms } from './queries'
import { buildRoomSummaries, propertyTotals, type RoomSummary } from './roomSummary'

export interface PropertyOverview {
  summaries: RoomSummary[]
  totals: ReturnType<typeof propertyTotals>
  isPending: boolean
  isError: boolean
  error: Error | null
  refetch: () => void
  hasMoreAssets: boolean
}

export function usePropertyOverview(): PropertyOverview {
  const roomsQuery = useRooms()
  const assetsQuery = useAssets()
  const occurrencesQuery = useOccurrences({
    from: shiftInputValue(todayInputValue(), -DASHBOARD_BACKWARD_DAYS),
    to: shiftInputValue(todayInputValue(), DASHBOARD_FORWARD_DAYS),
  })

  const rooms = useMemo(() => roomsQuery.data?.data ?? [], [roomsQuery.data])
  const assets = useMemo(() => assetsQuery.data?.data ?? [], [assetsQuery.data])
  const occurrences = useMemo(() => occurrencesQuery.data?.data ?? [], [occurrencesQuery.data])

  const summaries = useMemo(
    () => buildRoomSummaries(rooms, assets, occurrences),
    [assets, occurrences, rooms],
  )

  return {
    summaries,
    totals: useMemo(() => propertyTotals(summaries), [summaries]),
    isPending: roomsQuery.isPending || assetsQuery.isPending || occurrencesQuery.isPending,
    isError: roomsQuery.isError || assetsQuery.isError || occurrencesQuery.isError,
    error: (roomsQuery.error ?? assetsQuery.error ?? occurrencesQuery.error) as Error | null,
    refetch: () => {
      void roomsQuery.refetch()
      void assetsQuery.refetch()
      void occurrencesQuery.refetch()
    },
    hasMoreAssets: (assetsQuery.data?.meta.total ?? 0) > (assetsQuery.data?.data.length ?? 0),
  }
}
