import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { resourceKeys } from '@/shared/api/queryKeys'
import type { Asset, TriggerConfig } from '@/shared/api/types'
import { describeDue, formatDayShort, formatInstant, type DueTone } from '@/shared/datetime'
import { Button, Card, EmptyState, ErrorState, Spinner, useToast } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { useTenant } from '@/tenants/TenantProvider'
import { useDeleteTriggerConfig, useTriggerConfigs } from '@/features/scheduling/triggerQueries'
import { summarizeTrigger } from '@/features/scheduling/triggerTypes'
import { TriggerBuilderModal } from '@/features/scheduling/TriggerBuilderModal'
import { skipOccurrence } from '@/features/scheduling/api'
import { AssetFormModal } from './AssetFormModal'
import { PendingCheckIn } from './DashboardPage'
import { useAssetArchive } from './mutations'
import { usePropertyOverview } from './usePropertyOverview'
import type { PendingItem } from './roomSummary'

const TONE_CLASS: Record<DueTone, string> = {
  overdue: 'bg-danger/15 text-danger',
  today: 'bg-warning/15 text-warning',
  tomorrow: 'bg-warning/15 text-warning',
  soon: 'bg-surface-2 text-ink-muted',
  later: 'bg-surface-2 text-ink-muted',
  done: 'bg-success/15 text-success',
}

/**
 * Visão de um cômodo: o que existe dentro dele, o que está para acontecer e o
 * check-in de cada item. É a tela que responde "o que eu preciso fazer na
 * cozinha hoje" sem o morador precisar abrir a agenda e filtrar por cômodo.
 */
export function RoomPage() {
  const { roomId } = useParams<{ roomId: string }>()
  const { can } = useAuth()
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const overview = usePropertyOverview()

  const [editingAsset, setEditingAsset] = useState<Asset | null>(null)
  const [addingAsset, setAddingAsset] = useState(false)
  const [ruleFor, setRuleFor] = useState<Asset | null>(null)

  const summary = useMemo(
    () => overview.summaries.find((item) => item.room.id === roomId) ?? null,
    [overview.summaries, roomId],
  )

  const rulesQuery = useTriggerConfigs(summary ? { subjectType: 'asset' } : { subjectType: null })

  const rulesForRoom = useMemo(() => {
    if (!summary) return []
    const assetIds = new Set(summary.assets.map((asset) => asset.id))
    return (rulesQuery.data?.data ?? []).filter((rule) => assetIds.has(rule.subject_id))
  }, [rulesQuery.data, summary])

  const rulesByAsset = useMemo(() => {
    const map = new Map<string, typeof rulesForRoom>()
    for (const rule of rulesForRoom) {
      const bucket = map.get(rule.subject_id)
      if (bucket) {
        bucket.push(rule)
      } else {
        map.set(rule.subject_id, [rule])
      }
    }
    return map
  }, [rulesForRoom])

  if (overview.isPending) {
    return (
      <div className="flex items-center gap-2 text-sm text-ink-muted">
        <Spinner className="size-4" />
        Carregando o cômodo...
      </div>
    )
  }

  if (overview.isError) {
    return (
      <ErrorState
        message={
          overview.error instanceof ApiError
            ? overview.error.message
            : 'Falha ao carregar o cômodo.'
        }
        requestId={overview.error instanceof ApiError ? overview.error.requestId : null}
        onRetry={overview.refetch}
      />
    )
  }

  if (!summary) {
    return (
      <EmptyState
        title="Cômodo não encontrado"
        description="Ele pode ter sido arquivado, ou o endereço está errado."
        action={
          <Link to="/" className="text-sm font-medium text-brand hover:underline">
            Voltar ao painel
          </Link>
        }
      />
    )
  }

  const { room, assets, pending, next, withoutRules } = summary

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link to="/" className="text-xs text-ink-muted hover:text-ink">
            ← Painel da residência
          </Link>
          <h1 className="mt-1 flex items-center gap-2 text-lg font-semibold text-ink">
            <span aria-hidden="true">{room.icon ?? '⌂'}</span>
            {room.name}
          </h1>
          <p className="mt-1 text-sm text-ink-muted">
            {assets.length} {assets.length === 1 ? 'item' : 'itens'}
            {pending.length > 0
              ? ` · ${pending.length} ${pending.length === 1 ? 'pendência' : 'pendências'}`
              : ' · nada pendente'}
          </p>
        </div>
        {can('assets.manage') ? (
          <Button size="sm" onClick={() => setAddingAsset(true)}>
            Adicionar item
          </Button>
        ) : null}
      </header>

      {next ? (
        <section aria-labelledby="room-next-heading">
          <h2 id="room-next-heading" className="mb-2 text-xs font-medium text-ink-muted">
            Próxima manutenção
          </h2>
          <PendingCheckIn item={next} canComplete={can('occurrences.complete')} />
        </section>
      ) : null}

      <section aria-labelledby="room-pending-heading" className="space-y-3">
        <h2 id="room-pending-heading" className="text-xs font-medium text-ink-muted">
          Manutenções em aberto
        </h2>
        {pending.length === 0 ? (
          <Card>
            <div className="px-5 py-6 text-center">
              <p className="text-sm text-ink-muted">Nada pendente neste cômodo.</p>
              {withoutRules > 0 ? (
                <p className="mt-1 text-xs text-ink-subtle">
                  {withoutRules} {withoutRules === 1 ? 'item ainda não tem' : 'itens ainda não têm'}{' '}
                  regra de repetição — vale cadastrar para não depender de memória.
                </p>
              ) : null}
            </div>
          </Card>
        ) : (
          <ul className="space-y-2" data-testid="room-pending">
            {pending.map((item) => (
              <li key={item.occurrence.id}>
                <PendingRow item={item} />
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="room-assets-heading" className="space-y-3">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 id="room-assets-heading" className="text-xs font-medium text-ink-muted">
            Inventário
          </h2>
          {withoutRules > 0 ? (
            <p data-testid="room-without-rules" className="text-xs text-ink-subtle">
              {withoutRules} {withoutRules === 1 ? 'item ainda não tem' : 'itens ainda não têm'}{' '}
              regra de repetição — vale cadastrar para não depender de memória.
            </p>
          ) : null}
        </div>
        {assets.length === 0 ? (
          <EmptyState
            title="Nenhum item neste cômodo"
            description="Cadastre o que precisa de manutenção para o painel acompanhar as datas."
            action={
              can('assets.manage') ? (
                <Button size="sm" onClick={() => setAddingAsset(true)}>
                  Adicionar item
                </Button>
              ) : undefined
            }
          />
        ) : (
          <Card>
            <ul className="divide-y divide-line" data-testid="room-assets">
              {assets.map((asset) => (
                <AssetRow
                  key={asset.id}
                  asset={asset}
                  rules={rulesByAsset.get(asset.id) ?? []}
                  onEdit={() => setEditingAsset(asset)}
                  onAddRule={() => setRuleFor(asset)}
                  onChanged={() => {
                    void queryClient.invalidateQueries({
                      queryKey: resourceKeys.assets(activeTenantId),
                    })
                  }}
                />
              ))}
            </ul>
          </Card>
        )}
      </section>

      <AssetFormModal
        open={addingAsset}
        onClose={() => setAddingAsset(false)}
        defaultRoomId={room.id}
        rooms={[room]}
      />
      <AssetFormModal
        open={editingAsset !== null}
        onClose={() => setEditingAsset(null)}
        rooms={[room]}
        asset={editingAsset}
      />
      <TriggerBuilderModal
        open={ruleFor !== null}
        onClose={() => setRuleFor(null)}
        subjectId={ruleFor?.id ?? ''}
        subjectName={ruleFor?.name ?? ''}
      />
    </div>
  )
}

function PendingRow({ item }: { item: PendingItem }) {
  const { can } = useAuth()
  const toast = useToast()
  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const [busy, setBusy] = useState(false)

  const due = describeDue(item.occurrence.scheduled_for)

  const onSkip = async () => {
    setBusy(true)
    try {
      await skipOccurrence(item.occurrence.id)
      toast.info('Ocorrência dispensada. O ciclo continua na data que já estava.')
      void queryClient.invalidateQueries({
        queryKey: resourceKeys.occurrences(activeTenantId),
      })
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível dispensar.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-surface px-4 py-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{item.occurrence.title}</p>
        <p className="text-xs text-ink-muted">
          {item.asset ? `${item.asset.name} · ` : ''}
          {formatInstant(item.occurrence.due_at)}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <span className={`rounded-control px-2 py-1 text-xs font-medium ${TONE_CLASS[due.tone]}`}>
          {due.label}
        </span>
        {can('occurrences.skip') ? (
          <Button
            variant="ghost"
            size="sm"
            loading={busy}
            onClick={() => void onSkip()}
            title="Pular este dia; o ciclo não é recalculado"
          >
            Pular
          </Button>
        ) : null}
      </div>
    </div>
  )
}

function AssetRow({
  asset,
  rules,
  onEdit,
  onAddRule,
  onChanged,
}: {
  asset: Asset
  rules: TriggerConfig[]
  onEdit: () => void
  onAddRule: () => void
  onChanged: () => void
}) {
  const { can } = useAuth()
  const archive = useAssetArchive()
  const [editingRule, setEditingRule] = useState<string | null>(null)
  const remove = useDeleteTriggerConfig()

  const warranty = asset.warranty_until ? describeDue(asset.warranty_until.slice(0, 10)) : null

  return (
    <li className="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-ink">{asset.name}</p>
        <p className="text-xs text-ink-muted">
          {[asset.category, asset.brand, asset.model].filter(Boolean).join(' · ') || 'Sem detalhes'}
        </p>
        {warranty ? (
          <p className="mt-1 text-xs text-ink-subtle">
            Garantia até {formatDayShort(asset.warranty_until?.slice(0, 10) ?? null)} (
            {warranty.label})
          </p>
        ) : null}

        <ul className="mt-2 space-y-1">
          {rules.map((rule) => (
            <li key={rule.id} className="flex flex-wrap items-center gap-2 text-xs">
              <span className="rounded-control bg-surface-2 px-2 py-0.5 text-ink-muted">
                {summarizeTrigger(rule)}
              </span>
              {rule.next_due_at ? (
                <span className="text-ink-subtle">
                  próxima: {formatDayShort(rule.next_due_at.slice(0, 10))}
                </span>
              ) : (
                <span className="text-ink-subtle">sem data até o primeiro check-in</span>
              )}
              {!rule.is_active ? (
                <span className="rounded-control bg-warning/15 px-1.5 py-0.5 text-warning">
                  pausada
                </span>
              ) : null}
              {can('rules.edit') ? (
                <>
                  <button
                    type="button"
                    className="text-brand hover:underline"
                    onClick={() => setEditingRule(rule.id)}
                  >
                    editar
                  </button>
                  <button
                    type="button"
                    className="text-danger hover:underline"
                    onClick={() => remove.mutate(rule.id)}
                    disabled={remove.isPending}
                  >
                    remover
                  </button>
                </>
              ) : null}
            </li>
          ))}
        </ul>
      </div>

      <div className="flex shrink-0 items-center gap-1">
        {can('rules.edit') ? (
          <Button variant="ghost" size="sm" onClick={onAddRule}>
            Regra
          </Button>
        ) : null}
        {can('assets.manage') ? (
          <>
            <Button variant="ghost" size="sm" onClick={onEdit}>
              Editar
            </Button>
            {asset.archived ? (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  archive.restore.mutate(asset.id)
                  onChanged()
                }}
              >
                Restaurar
              </Button>
            ) : (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  archive.archive.mutate(asset.id)
                  onChanged()
                }}
              >
                Arquivar
              </Button>
            )}
          </>
        ) : null}
      </div>

      {editingRule ? (
        <AssetRuleEditor
          ruleId={editingRule}
          assetName={asset.name}
          onClose={() => setEditingRule(null)}
        />
      ) : null}
    </li>
  )
}

function AssetRuleEditor({
  ruleId,
  assetName,
  onClose,
}: {
  ruleId: string
  assetName: string
  onClose: () => void
}) {
  const rulesQuery = useTriggerConfigs({ subjectType: 'asset' })
  const config = (rulesQuery.data?.data ?? []).find((rule) => rule.id === ruleId) ?? null

  if (rulesQuery.isPending) {
    return (
      <div className="flex items-center gap-2 px-5 py-3 text-sm text-ink-muted">
        <Spinner className="size-4" />
        Carregando a regra...
      </div>
    )
  }

  if (!config) {
    return <p className="px-5 py-3 text-sm text-ink-muted">Regra não encontrada.</p>
  }

  return (
    <TriggerBuilderModal
      open
      onClose={onClose}
      subjectId={config.subject_id}
      subjectName={assetName}
      config={config}
    />
  )
}
