import { useState } from 'react'
import { Card, CardBody, CardHeader, EmptyState, ErrorState } from '@/shared/ui'
import { Spinner } from '@/shared/ui/Button'
import { ApiError } from '@/shared/api/errors'
import { useSummary } from './queries'
import { formatAmount } from './money'
import type { BillSummary } from '@/shared/api/types'

const TOTALS: readonly { key: keyof BillSummary['totals']; label: string }[] = [
  { key: 'due', label: 'Previsto no mês' },
  { key: 'paid', label: 'Já pago' },
  { key: 'open', label: 'Em aberto' },
  { key: 'overdue', label: 'Atrasado' },
  { key: 'cancelled', label: 'Cancelado' },
]

const COUNTS: readonly { key: keyof BillSummary['counts']; label: string }[] = [
  { key: 'occurrences', label: 'Vencimentos' },
  { key: 'paid', label: 'Pagos' },
  { key: 'open', label: 'Abertos' },
  { key: 'overdue', label: 'Atrasados' },
  { key: 'cancelled', label: 'Cancelados' },
]

/** Mês corrente no fuso do navegador — o seletor manda `YYYY-MM` e o backend
 *  recalcula a janela no fuso da residência. */
function currentMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

export function SummaryPage() {
  const [month, setMonth] = useState(currentMonth)
  const query = useSummary(month)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-ink">Resumo do mês</h2>
        <input
          type="month"
          value={month}
          onChange={(event) => setMonth(event.target.value)}
          aria-label="Mês do resumo"
          className="rounded-control border border-line bg-surface-2 px-3 py-1.5 text-sm text-ink"
        />
      </div>

      {query.isPending ? (
        <div className="flex items-center gap-2 text-sm text-ink-muted">
          <Spinner className="size-4" />
          Carregando o resumo...
        </div>
      ) : query.isError ? (
        <ErrorState
          message={
            query.error instanceof ApiError ? query.error.message : 'Falha ao carregar o resumo.'
          }
          requestId={query.error instanceof ApiError ? query.error.requestId : null}
          onRetry={() => void query.refetch()}
        />
      ) : query.data.counts.occurrences === 0 ? (
        <EmptyState
          title="Nenhum vencimento neste mês"
          description="Cadastre uma conta para o resumo começar a somar o que vence."
          icon="R$"
        />
      ) : (
        <>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {TOTALS.map((total) => (
              <li key={total.key}>
                <Card>
                  <CardBody>
                    <p className="text-xs text-ink-muted">{total.label}</p>
                    <p className="mt-1 text-lg font-semibold tabular-nums text-ink">
                      {formatAmount(query.data.totals[total.key])}
                    </p>
                  </CardBody>
                </Card>
              </li>
            ))}
          </ul>

          <Card>
            <CardHeader title="Quantos vencimentos" />
            <CardBody>
              <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                {COUNTS.map((count) => (
                  <div key={count.key}>
                    <dt className="text-xs text-ink-muted">{count.label}</dt>
                    <dd className="text-base font-medium tabular-nums text-ink">
                      {query.data.counts[count.key]}
                    </dd>
                  </div>
                ))}
              </dl>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Por categoria" />
            <CardBody>
              {query.data.by_category.length === 0 ? (
                <p className="text-sm text-ink-muted">
                  Nenhuma conta com categoria neste mês — dá para categorizar em Contas.
                </p>
              ) : (
                <ul className="space-y-2">
                  {query.data.by_category.map((row) => (
                    <li
                      key={row.category}
                      className="flex items-center justify-between gap-3 text-sm"
                    >
                      <span className="truncate text-ink">{row.category}</span>
                      <span className="tabular-nums text-ink-muted">
                        {formatAmount(row.amount)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          <p className="text-xs text-ink-subtle">
            Total do mês em {query.data.timezone}. Os valores são os que o servidor somou — a tela
            não recalcula total.
          </p>
        </>
      )}
    </div>
  )
}
