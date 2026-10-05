import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { resourceKeys } from '@/shared/api/queryKeys'
import {
  SCHEDULING_OCCURRENCE_STATUSES,
  type Occurrence,
  type SchedulingOccurrenceStatus,
} from '@/shared/api/types'
import {
  describeClosedDue,
  describeDue,
  formatDayHeading,
  formatInstant,
  shiftInputValue,
  todayInputValue,
  type DueTone,
} from '@/shared/datetime'
import { Button, Card, EmptyState, ErrorState, Field, Select, Spinner, useToast } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { useTenant } from '@/tenants/TenantProvider'
import { useAssets, useRooms } from '@/features/maintenance/queries'
import { completeOccurrence, skipOccurrence } from './api'
import { useOccurrences } from './queries'
import { isOpen } from '@/features/maintenance/roomSummary'

type Period = 'upcoming' | 'past' | 'all'

const PERIOD_OPTIONS: readonly { value: Period; label: string; from: number; to: number }[] = [
  { value: 'upcoming', label: 'Próximos 30 dias', from: 0, to: 30 },
  { value: 'past', label: 'Últimos 30 dias', from: -30, to: 0 },
  { value: 'all', label: 'Tudo', from: -90, to: 90 },
]

const STATUS_LABEL: Record<SchedulingOccurrenceStatus, string> = {
  pending: 'Pendente',
  notified: 'Avisada',
  completed: 'Concluída',
  skipped: 'Dispensada',
  overdue: 'Atrasada',
}

const TONE_CLASS: Record<DueTone, string> = {
  overdue: 'bg-danger/15 text-danger',
  today: 'bg-warning/15 text-warning',
  tomorrow: 'bg-warning/15 text-warning',
  soon: 'bg-surface-2 text-ink-muted',
  later: 'bg-surface-2 text-ink-muted',
  done: 'bg-success/15 text-success',
}

/**
 * Agenda da residência, agrupada por dia.
 *
 * O filtro de cômodo é aplicado aqui, e não no `GET /occurrences`: o endpoint
 * aceita período, alvo e status, mas não cômodo. Como a ocorrência carrega
 * `subject_id` e não o cômodo, a junção passa pelo inventário — e o contador
 * mostrado no cabeçalho diz "de N" quando o filtro deixa de lado alguma coisa,
 * para a lista nunca parecer completa quando não está.
 */
export function TimelinePage() {
  const [period, setPeriod] = useState<Period>('upcoming')
  const [roomId, setRoomId] = useState('')
  const [status, setStatus] = useState<SchedulingOccurrenceStatus | ''>('')

  const range = PERIOD_OPTIONS.find((option) => option.value === period) ?? PERIOD_OPTIONS[0]!

  // o filtro de status é o único que vai para o servidor; período e cômodo são
  // recorte de tela e mandá-los no `from`/`to` economizaria uma montagem inútil
  const query = useOccurrences({
    from: shiftInputValue(todayInputValue(), range.from),
    to: shiftInputValue(todayInputValue(), range.to),
    status: status === '' ? null : status,
  })

  const roomsQuery = useRooms()
  const assetsQuery = useAssets()

  const rooms = useMemo(() => roomsQuery.data?.data ?? [], [roomsQuery.data])
  const assets = useMemo(() => assetsQuery.data?.data ?? [], [assetsQuery.data])
  const occurrences = useMemo(() => query.data?.data ?? [], [query.data])

  const roomByAsset = useMemo(() => {
    const byId = new Map(rooms.map((room) => [room.id, room]))
    return new Map(assets.map((asset) => [asset.id, byId.get(asset.room_id) ?? null]))
  }, [assets, rooms])

  const visible = useMemo(() => {
    if (roomId === '') return occurrences
    return occurrences.filter((occurrence) => roomByAsset.get(occurrence.subject_id)?.id === roomId)
  }, [occurrences, roomByAsset, roomId])

  const groups = useMemo(() => groupByDay(visible), [visible])

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-lg font-semibold text-ink">Agenda da casa</h1>
        <p data-testid="timeline-summary" className="mt-1 text-sm text-ink-muted">
          {query.isPending
            ? 'Carregando...'
            : `${visible.length} ${visible.length === 1 ? 'tarefa' : 'tarefas'}${
                roomId === '' ? '' : ' neste cômodo'
              }${occurrences.length > visible.length ? ` de ${occurrences.length}` : ''}`}
        </p>
      </header>

      <Card>
        <div className="grid gap-3 p-4 sm:grid-cols-3">
          <Field label="Período">
            {({ id }) => (
              <Select
                id={id}
                value={period}
                onChange={(event) => setPeriod(event.target.value as Period)}
              >
                {PERIOD_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field label="Cômodo">
            {({ id }) => (
              <Select id={id} value={roomId} onChange={(event) => setRoomId(event.target.value)}>
                <option value="">Todos os cômodos</option>
                {rooms.map((room) => (
                  <option key={room.id} value={room.id}>
                    {room.icon ? `${room.icon} ` : ''}
                    {room.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field label="Situação">
            {({ id }) => (
              <Select
                id={id}
                value={status}
                onChange={(event) =>
                  setStatus(event.target.value as SchedulingOccurrenceStatus | '')
                }
              >
                <option value="">Todas</option>
                {SCHEDULING_OCCURRENCE_STATUSES.map((value) => (
                  <option key={value} value={value}>
                    {STATUS_LABEL[value]}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
      </Card>

      {query.isPending ? (
        <div className="flex items-center gap-2 text-sm text-ink-muted">
          <Spinner className="size-4" />
          Carregando a agenda...
        </div>
      ) : query.isError ? (
        <ErrorState
          message={
            query.error instanceof ApiError ? query.error.message : 'Falha ao carregar a agenda.'
          }
          requestId={query.error instanceof ApiError ? query.error.requestId : null}
          onRetry={() => void query.refetch()}
        />
      ) : visible.length === 0 ? (
        <EmptyState
          title="Nada neste recorte"
          description={
            roomId !== ''
              ? 'Nenhuma tarefa para este cômodo no período escolhido.'
              : 'Nenhuma tarefa no período escolhido. Alargue o período ou crie uma regra de manutenção.'
          }
          action={
            <Button size="sm" variant="secondary" onClick={() => setPeriod('all')}>
              Ver tudo
            </Button>
          }
        />
      ) : (
        <div className="space-y-6" data-testid="timeline">
          {groups.map(([day, items]) => (
            <section key={day} aria-label={formatDayHeading(day)}>
              <h2 className="sticky top-14 z-10 -mx-1 mb-2 bg-canvas/95 px-1 py-1 text-xs font-medium text-ink-muted backdrop-blur">
                {formatDayHeading(day)}
              </h2>
              <ul className="space-y-2">
                {items.map((occurrence) => (
                  <li key={occurrence.id}>
                    <OccurrenceRow
                      occurrence={occurrence}
                      roomName={roomByAsset.get(occurrence.subject_id)?.name ?? null}
                    />
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}

/** Ordena por dia de calendário e agrupa. Sem `scheduled_for` a linha vai para o fim. */
function groupByDay(occurrences: readonly Occurrence[]): [string, Occurrence[]][] {
  const buckets = new Map<string, Occurrence[]>()
  const ordered = [...occurrences].sort((left, right) =>
    (left.scheduled_for ?? '9999-12-31').localeCompare(right.scheduled_for ?? '9999-12-31'),
  )

  for (const occurrence of ordered) {
    const day = occurrence.scheduled_for ?? '9999-12-31'
    const bucket = buckets.get(day)
    if (bucket) {
      bucket.push(occurrence)
    } else {
      buckets.set(day, [occurrence])
    }
  }

  return [...buckets.entries()]
}

function OccurrenceRow({
  occurrence,
  roomName,
}: {
  occurrence: Occurrence
  roomName: string | null
}) {
  const { can } = useAuth()
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [pendingAction, setPendingAction] = useState<'complete' | 'skip' | null>(null)

  const open = isOpen(occurrence)
  const due = open
    ? describeDue(occurrence.scheduled_for)
    : describeClosedDue(occurrence.scheduled_for)

  const act = async (action: 'complete' | 'skip') => {
    setPendingAction(action)
    try {
      if (action === 'complete') {
        await completeOccurrence(occurrence.id)
        toast.success('Tarefa concluída.')
      } else {
        await skipOccurrence(occurrence.id)
        toast.info('Tarefa dispensada.')
      }
    } catch (error) {
      toast.error(
        error instanceof ApiError ? error.message : 'Não foi possível atualizar a tarefa.',
      )
    } finally {
      setPendingAction(null)
      // a linha muda de lugar e de situação sem reload — é o critério de aceite
      // de "concluir atualiza a timeline"
      void queryClient.invalidateQueries({ queryKey: resourceKeys.occurrences(activeTenantId) })
    }
  }

  return (
    <div
      data-testid="occurrence-row"
      data-status={occurrence.status}
      className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-surface px-4 py-3"
    >
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">
          {occurrence.title}
          {roomName ? <span className="font-normal text-ink-muted"> · {roomName}</span> : null}
        </p>
        <p className="text-xs text-ink-muted">
          {formatInstant(occurrence.due_at)} · {STATUS_LABEL[occurrence.status]}
        </p>
      </div>

      <div className="flex items-center gap-2">
        <span className={`rounded-control px-2 py-1 text-xs font-medium ${TONE_CLASS[due.tone]}`}>
          {due.label}
        </span>
        {open && can('occurrences.complete') ? (
          <Button
            size="sm"
            loading={pendingAction === 'complete'}
            disabled={pendingAction !== null}
            onClick={() => void act('complete')}
          >
            Concluir
          </Button>
        ) : null}
        {open && can('occurrences.skip') ? (
          <Button
            variant="ghost"
            size="sm"
            loading={pendingAction === 'skip'}
            disabled={pendingAction !== null}
            onClick={() => void act('skip')}
            title="Este dia não aconteceu; o ciclo não é recalculado"
          >
            Pular
          </Button>
        ) : null}
        {!open ? (
          <Link to="/" className="text-xs text-ink-muted hover:text-ink">
            No painel
          </Link>
        ) : null}
      </div>
    </div>
  )
}
