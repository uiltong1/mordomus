import {
  OPEN_OCCURRENCE_STATUSES,
  type Asset,
  type Occurrence,
  type Room,
} from '@/shared/api/types'
import { daysBetween, todayInputValue } from '@/shared/datetime'

/**
 * O painel precisa responder "o que está pendente neste cômodo?" cruzando três
 * listas que o backend entrega separadas: cômodos, inventário e ocorrências. O
 * cruzamento é puro e fica aqui — sem ele, a mesma junção nasce de novo na
 * tela do cômodo e na agenda, e as duas divergem no primeiro filtro novo.
 */

export interface PendingItem {
  occurrence: Occurrence
  asset: Asset | null
  roomId: string | null
  /** Dias em atraso; `0` para o que vence hoje ou depois. */
  daysOverdue: number
}

export interface RoomSummary {
  room: Room
  assets: Asset[]
  /** Ocorrências em aberto (pendente, avisada ou atrasada) dos ativos do cômodo. */
  pending: PendingItem[]
  overdueCount: number
  /** Vencimento mais próximo ainda em aberto. */
  next: PendingItem | null
  /** Ativos do cômodo sem nenhuma regra de manutenção. */
  withoutRules: number
}

export function isOpen(occurrence: Occurrence): boolean {
  return OPEN_OCCURRENCE_STATUSES.includes(occurrence.status)
}

function overdueDays(occurrence: Occurrence): number {
  if (!occurrence.scheduled_for) return 0
  return Math.max(0, -daysBetween(todayInputValue(), occurrence.scheduled_for))
}

/**
 * Junta ocorrência → ativo → cômodo.
 *
 * A ocorrência traz `subject_id` e não o cômodo, e o endpoint de ocorrências
 * não aceita filtro por cômodo: a associação existe só aqui. Ocorrência cujo
 * ativo não está mais no inventário (arquivado ou removido) entra com
 * `asset: null` e `roomId: null` — some dos cards, mas continua na agenda, onde
 * o título da regra identifica o que é.
 */
export function toPendingItems(
  occurrences: readonly Occurrence[],
  assets: readonly Asset[],
): PendingItem[] {
  const byId = new Map(assets.map((asset) => [asset.id, asset]))

  return occurrences.filter(isOpen).map((occurrence) => {
    const asset =
      occurrence.subject_type === 'asset' ? (byId.get(occurrence.subject_id) ?? null) : null
    return {
      occurrence,
      asset,
      roomId: asset?.room_id ?? null,
      daysOverdue: overdueDays(occurrence),
    }
  })
}

export function buildRoomSummaries(
  rooms: readonly Room[],
  assets: readonly Asset[],
  occurrences: readonly Occurrence[],
): RoomSummary[] {
  const pending = toPendingItems(occurrences, assets)
  const pendingByRoom = new Map<string, PendingItem[]>()
  const ruledSubjects = new Set(occurrences.map((occurrence) => occurrence.subject_id))

  for (const item of pending) {
    if (!item.roomId) continue
    const bucket = pendingByRoom.get(item.roomId)
    if (bucket) {
      bucket.push(item)
    } else {
      pendingByRoom.set(item.roomId, [item])
    }
  }

  return rooms.map((room) => {
    const roomAssets = assets.filter((asset) => asset.room_id === room.id && !asset.archived)
    const roomPending = (pendingByRoom.get(room.id) ?? []).sort(byDue)

    return {
      room,
      assets: roomAssets,
      pending: roomPending,
      overdueCount: roomPending.filter((item) => item.daysOverdue > 0).length,
      next: roomPending[0] ?? null,
      withoutRules: roomAssets.filter((asset) => !ruledSubjects.has(asset.id)).length,
    }
  })
}

/** Vencimento mais antigo primeiro; o que não tem data vai para o fim. */
function byDue(left: PendingItem, right: PendingItem): number {
  const a = left.occurrence.scheduled_for ?? '9999-12-31'
  const b = right.occurrence.scheduled_for ?? '9999-12-31'
  if (a === b) return left.occurrence.due_at?.localeCompare(right.occurrence.due_at ?? '') ?? 0
  return a.localeCompare(b)
}

/** Contagem geral do imóvel, para o cabeçalho do painel. */
export interface PropertyTotals {
  rooms: number
  assets: number
  pending: number
  overdue: number
  next: PendingItem | null
}

export function propertyTotals(summaries: readonly RoomSummary[]): PropertyTotals {
  const pending = summaries.flatMap((summary) => summary.pending).sort(byDue)

  return {
    rooms: summaries.length,
    assets: summaries.reduce((total, summary) => total + summary.assets.length, 0),
    pending: pending.length,
    overdue: pending.filter((item) => item.daysOverdue > 0).length,
    next: pending[0] ?? null,
  }
}
