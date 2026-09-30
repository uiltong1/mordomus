import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '@/shared/api/errors'
import { formatInstant } from '@/shared/datetime'
import { Button, Card, CardHeader, EmptyState, ErrorState, Spinner, useToast } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { completeOccurrence } from '@/features/scheduling/api'
import { useTenant } from '@/tenants/TenantProvider'
import { useRoomArchive } from './mutations'
import { RoomFormModal } from './RoomFormModal'
import type { RoomSummary } from './roomSummary'
import { usePropertyOverview } from './usePropertyOverview'

/**
 * Painel do imóvel: um card por cômodo, com o que está pendente dentro dele.
 *
 * A ordem de leitura é do topo para baixo — o que está atrasado, o que é hoje,
 * o que vem depois — e não por ordem alfabética. Um cômodo called "Abaixo" com
 * nada pendente não pode ficar acima da cozinha com a filtro vencida.
 */
export function DashboardPage() {
  const { activeTenant } = useTenant()
  const { can } = useAuth()
  const overview = usePropertyOverview()
  const [creatingRoom, setCreatingRoom] = useState(false)

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-ink">
            {activeTenant?.name ?? 'Sua residência'}
          </h1>
          <p className="mt-1 text-sm text-ink-muted">
            {overview.isPending
              ? 'Carregando a casa...'
              : overview.totals.pending === 0
                ? 'Nada pendente no momento.'
                : `${overview.totals.pending} ${overview.totals.pending === 1 ? 'coisa para cuidar' : 'coisas para cuidar'}${
                    overview.totals.overdue > 0
                      ? ` — ${overview.totals.overdue} ${overview.totals.overdue === 1 ? 'atrasada' : 'atrasadas'}`
                      : ''
                  }`}
          </p>
        </div>
        {can('rooms.manage') ? (
          <Button size="sm" onClick={() => setCreatingRoom(true)}>
            Novo cômodo
          </Button>
        ) : null}
      </header>

      {overview.isPending ? (
        <div className="flex items-center gap-2 text-sm text-ink-muted">
          <Spinner className="size-4" />
          Carregando cômodos e pendências...
        </div>
      ) : overview.isError ? (
        <ErrorState
          message={
            overview.error instanceof ApiError
              ? overview.error.message
              : 'Falha ao carregar a casa.'
          }
          requestId={overview.error instanceof ApiError ? overview.error.requestId : null}
          onRetry={overview.refetch}
        />
      ) : overview.summaries.length === 0 ? (
        <EmptyState
          title="Nenhum cômodo cadastrado"
          description="Comece pelos cômodos da casa. Cada um vira um card aqui, com o que está para fazer dentro dele."
          action={
            can('rooms.manage') ? (
              <Button size="sm" onClick={() => setCreatingRoom(true)}>
                Criar o primeiro cômodo
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          {overview.totals.next ? (
            <NextUpBanner next={overview.totals.next} canComplete={can('occurrences.complete')} />
          ) : null}

          {overview.hasMoreAssets ? (
            <p className="text-xs text-warning">
              Há mais de 100 itens no inventário; os contadores abaixo consideram só os 100
              primeiros.
            </p>
          ) : null}

          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="room-cards">
            {overview.summaries.map((summary) => (
              <li key={summary.room.id}>
                <RoomCard summary={summary} />
              </li>
            ))}
          </ul>
        </>
      )}

      <RoomFormModal open={creatingRoom} onClose={() => setCreatingRoom(false)} />
    </div>
  )
}

function NextUpBanner({ next, canComplete }: { next: RoomSummary['next']; canComplete: boolean }) {
  if (!next) return null
  return <PendingCheckIn item={next} canComplete={canComplete} compact />
}

function RoomCard({ summary }: { summary: RoomSummary }) {
  const { room, assets, pending, overdueCount, next, withoutRules } = summary
  const { can } = useAuth()
  const archive = useRoomArchive()

  return (
    <Card className="flex h-full flex-col">
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            <span aria-hidden="true">{room.icon ?? '⌂'}</span>
            {room.name}
          </span>
        }
        description={`${assets.length} ${assets.length === 1 ? 'item' : 'itens'}`}
        action={
          can('rooms.manage') ? (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => archive.archive.mutate(room.id)}
              disabled={archive.archive.isPending}
            >
              Arquivar
            </Button>
          ) : null
        }
      />

      <div className="flex-1 space-y-3 px-5 py-4">
        <div className="flex flex-wrap gap-2">
          {overdueCount > 0 ? (
            <span className="rounded-control bg-danger/15 px-2 py-1 text-xs font-medium text-danger">
              {overdueCount} {overdueCount === 1 ? 'atrasada' : 'atrasadas'}
            </span>
          ) : null}
          {pending.length - overdueCount > 0 ? (
            <span className="rounded-control bg-warning/15 px-2 py-1 text-xs font-medium text-warning">
              {pending.length - overdueCount} a caminho
            </span>
          ) : null}
          {pending.length === 0 ? (
            <span className="rounded-control bg-success/15 px-2 py-1 text-xs font-medium text-success">
              Em dia
            </span>
          ) : null}
          {room.archived ? (
            <span className="rounded-control bg-surface-2 px-2 py-1 text-xs text-ink-muted">
              Arquivado
            </span>
          ) : null}
        </div>

        {next ? (
          <div className="rounded-control bg-surface-2 px-3 py-2">
            <p className="text-xs text-ink-subtle">Próxima manutenção</p>
            <p className="mt-0.5 truncate text-sm font-medium text-ink">{next.occurrence.title}</p>
            <p className="text-xs text-ink-muted">{formatInstant(next.occurrence.due_at)}</p>
          </div>
        ) : (
          <p className="text-xs text-ink-subtle">Nenhuma manutenção programada.</p>
        )}

        {withoutRules > 0 ? (
          <p className="text-xs text-ink-subtle">
            {withoutRules} {withoutRules === 1 ? 'item ainda não tem' : 'itens ainda não têm'} regra
            de repetição.
          </p>
        ) : null}
      </div>

      <footer className="flex items-center justify-between gap-2 border-t border-line px-5 py-3">
        <Link to={`/comodos/${room.id}`} className="text-sm font-medium text-brand hover:underline">
          Abrir cômodo
        </Link>
        {pending.length > 1 ? (
          <span className="text-xs text-ink-subtle">{pending.length} pendências</span>
        ) : null}
      </footer>
    </Card>
  )
}

export interface PendingCheckInProps {
  item: NonNullable<RoomSummary['next']>
  canComplete: boolean
  onDone?: () => void
  compact?: boolean
}

/**
 * Check-in rápido: concluir sem sair da tela.
 *
 * A conclusão é idempotente no monólito, então dois toques não abrem dois
 * ciclos; o botão fica travado durante a chamada para o toque duplo nem
 * chegar a ser feito.
 */
export function PendingCheckIn({ item, canComplete, onDone, compact }: PendingCheckInProps) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)

  const onComplete = async () => {
    setBusy(true)
    try {
      await completeOccurrence(item.occurrence.id)
      toast.success('Feito! A próxima data já foi recalculada.')
      onDone?.()
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível concluir a tarefa.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      data-testid="next-up"
      className={
        compact
          ? 'flex flex-wrap items-center justify-between gap-3 rounded-card border border-brand/40 bg-brand/10 px-4 py-3'
          : 'flex flex-wrap items-center justify-between gap-3 rounded-control bg-surface-2 px-3 py-2'
      }
    >
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{item.occurrence.title}</p>
        <p className="text-xs text-ink-muted">{formatInstant(item.occurrence.due_at)}</p>
      </div>
      {canComplete ? (
        <Button size="sm" loading={busy} onClick={() => void onComplete()}>
          Já fiz
        </Button>
      ) : (
        <span className="text-xs text-ink-subtle">Sem permissão para concluir</span>
      )}
    </div>
  )
}
