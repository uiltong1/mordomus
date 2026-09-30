import { describe, expect, it } from 'vitest'
import type { Asset, Occurrence, Room } from '@/shared/api/types'
import { buildRoomSummaries, propertyTotals, toPendingItems } from './roomSummary'

const ROOM_COZINHA = '01J8Z0M9W3K6Q2T4R5Y7B8C9D0'
const ROOM_BANHEIRO = '01J8Z0M9W3K6Q2T4R5Y7B8C9D1'

function room(id: string, name: string, extra: Partial<Room> = {}): Room {
  return {
    id,
    tenant_id: 't1',
    name,
    icon: null,
    sort_order: 0,
    archived: false,
    archived_at: null,
    created_at: null,
    updated_at: null,
    ...extra,
  }
}

function asset(id: string, roomId: string, name: string, extra: Partial<Asset> = {}): Asset {
  return {
    id,
    tenant_id: 't1',
    room_id: roomId,
    name,
    category: null,
    brand: null,
    model: null,
    acquired_at: null,
    warranty_until: null,
    metadata: null,
    archived: false,
    archived_at: null,
    created_at: null,
    updated_at: null,
    ...extra,
  }
}

function occurrence(
  id: string,
  subjectId: string,
  status: Occurrence['status'],
  day: string,
): Occurrence {
  return {
    id,
    tenant_id: 't1',
    trigger_config_id: `cfg-${id}`,
    subject_type: 'asset',
    subject_id: subjectId,
    title: 'Limpar o filtro',
    scheduled_for: day,
    due_at: `${day}T09:00:00-03:00`,
    status,
    notified_at: null,
    completed_at: null,
    completed_by: null,
    created_at: null,
    updated_at: null,
  }
}

function today(offsetDays: number): string {
  const date = new Date()
  date.setDate(date.getDate() + offsetDays)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

describe('toPendingItems', () => {
  it('some o que já foi concluído ou dispensado, preservando a ordem recebida', () => {
    // a ordenação por vencimento é de `buildRoomSummaries`; aqui o contrato é
    // só filtrar e juntar, sem mexer na ordem que o backend entregou
    const items = toPendingItems(
      [
        occurrence('o1', 'a1', 'pending', today(1)),
        occurrence('o2', 'a1', 'completed', today(2)),
        occurrence('o3', 'a1', 'skipped', today(3)),
        occurrence('o4', 'a1', 'overdue', today(-1)),
        occurrence('o5', 'a1', 'notified', today(4)),
      ],
      [asset('a1', ROOM_COZINHA, 'Filtro')],
    )
    expect(items.map((item) => item.occurrence.id)).toEqual(['o1', 'o4', 'o5'])
  })

  it('conta os dias de atraso pelo dia de calendário', () => {
    const [item] = toPendingItems(
      [occurrence('o1', 'a1', 'overdue', today(-3))],
      [asset('a1', ROOM_COZINHA, 'Filtro')],
    )
    expect(item?.daysOverdue).toBe(3)
  })

  it('junta a ocorrência ao cômodo pelo ativo', () => {
    const [item] = toPendingItems(
      [occurrence('o1', 'a1', 'pending', today(1))],
      [asset('a1', ROOM_COZINHA, 'Filtro')],
    )
    expect(item?.roomId).toBe(ROOM_COZINHA)
    expect(item?.asset?.name).toBe('Filtro')
  })

  it('mantém a ocorrência órfã sem inventar cômodo', () => {
    const [item] = toPendingItems(
      [occurrence('o1', 'a-inexistente', 'pending', today(1))],
      [asset('a1', ROOM_COZINHA, 'Filtro')],
    )
    expect(item?.roomId).toBeNull()
    expect(item?.asset).toBeNull()
  })
})

describe('buildRoomSummaries', () => {
  it('conta as pendências por cômodo e não por ocorrência solta', () => {
    const summaries = buildRoomSummaries(
      [room(ROOM_COZINHA, 'Cozinha'), room(ROOM_BANHEIRO, 'Banheiro')],
      [
        asset('a1', ROOM_COZINHA, 'Filtro do ar'),
        asset('a2', ROOM_COZINHA, 'Bocal'),
        asset('a3', ROOM_BANHEIRO, 'Chuveiro'),
      ],
      [
        occurrence('o1', 'a1', 'overdue', today(-2)),
        occurrence('o2', 'a1', 'pending', today(1)),
        occurrence('o3', 'a3', 'pending', today(5)),
      ],
    )

    const cozinha = summaries.find((summary) => summary.room.id === ROOM_COZINHA)
    const banheiro = summaries.find((summary) => summary.room.id === ROOM_BANHEIRO)

    expect(cozinha?.assets).toHaveLength(2)
    expect(cozinha?.pending).toHaveLength(2)
    expect(cozinha?.overdueCount).toBe(1)
    expect(cozinha?.next?.occurrence.id).toBe('o1')
    expect(banheiro?.pending).toHaveLength(1)
    expect(banheiro?.overdueCount).toBe(0)
  })

  it('ordena a fila pelo vencimento mais antigo, não pela ordem do backend', () => {
    const summaries = buildRoomSummaries(
      [room(ROOM_COZINHA, 'Cozinha')],
      [asset('a1', ROOM_COZINHA, 'Filtro'), asset('a2', ROOM_COZINHA, 'Bocal')],
      [
        occurrence('futuro', 'a1', 'pending', today(9)),
        occurrence('proximo', 'a2', 'pending', today(2)),
        occurrence('atrasado', 'a1', 'overdue', today(-4)),
      ],
    )
    expect(summaries[0]?.pending.map((item) => item.occurrence.id)).toEqual([
      'atrasado',
      'proximo',
      'futuro',
    ])
  })

  it('conta o ativo sem regra de manutenção', () => {
    const summaries = buildRoomSummaries(
      [room(ROOM_COZINHA, 'Cozinha')],
      [asset('a1', ROOM_COZINHA, 'Filtro'), asset('a2', ROOM_COZINHA, 'Bocal')],
      [occurrence('o1', 'a1', 'pending', today(1))],
    )
    expect(summaries[0]?.withoutRules).toBe(1)
  })

  it('ignora ativo arquivado na contagem do cômodo', () => {
    const summaries = buildRoomSummaries(
      [room(ROOM_COZINHA, 'Cozinha')],
      [asset('a1', ROOM_COZINHA, 'Filtro', { archived: true }), asset('a2', ROOM_COZINHA, 'Bocal')],
      [],
    )
    expect(summaries[0]?.assets).toHaveLength(1)
  })

  it('cria resumo mesmo para cômodo sem nada', () => {
    const summaries = buildRoomSummaries([room(ROOM_COZINHA, 'Cozinha')], [], [])
    expect(summaries[0]?.pending).toEqual([])
    expect(summaries[0]?.next).toBeNull()
  })
})

describe('propertyTotals', () => {
  it('soma a casa inteira e aponta o próximo vencimento', () => {
    const summaries = buildRoomSummaries(
      [room(ROOM_COZINHA, 'Cozinha'), room(ROOM_BANHEIRO, 'Banheiro')],
      [asset('a1', ROOM_COZINHA, 'Filtro'), asset('a2', ROOM_BANHEIRO, 'Chuveiro')],
      [occurrence('o1', 'a1', 'overdue', today(-1)), occurrence('o2', 'a2', 'pending', today(4))],
    )
    const totals = propertyTotals(summaries)

    expect(totals.rooms).toBe(2)
    expect(totals.assets).toBe(2)
    expect(totals.pending).toBe(2)
    expect(totals.overdue).toBe(1)
    expect(totals.next?.occurrence.id).toBe('o1')
  })

  it('não quebra com a casa vazia', () => {
    const totals = propertyTotals([])
    expect(totals).toMatchObject({ rooms: 0, assets: 0, pending: 0, overdue: 0, next: null })
  })
})
