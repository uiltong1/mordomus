import { useState } from 'react'
import { Card, CardBody, EmptyState, ErrorState, Button, Field, Select, Spinner } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { ApiError } from '@/shared/api/errors'
import { useBillOccurrences } from './queries'
import { ManualOccurrenceModal } from './ManualOccurrenceModal'
import { PayModal } from './PayModal'
import { OccurrenceSplitModal } from './OccurrenceSplitModal'
import { Explainer } from './Explainer'
import { formatAmount } from './money'
import {
  FINANCIAL_OCCURRENCE_STATUSES,
  type BillOccurrence,
  type FinancialOccurrenceStatus,
} from '@/shared/api/types'

type Recorte = 'month' | 'range'

const STATUS_LABEL: Record<FinancialOccurrenceStatus, string> = {
  open: 'Em aberto',
  paid: 'Pago',
  overdue: 'Atrasado',
  cancelled: 'Cancelado',
}

const STATUS_TONE: Record<FinancialOccurrenceStatus, string> = {
  open: 'bg-surface-2 text-ink-muted',
  paid: 'bg-success/15 text-success',
  overdue: 'bg-danger/15 text-danger',
  cancelled: 'bg-surface-2 text-ink-subtle',
}

/** Só `open` e `overdue` aceitam baixa — `paid` já foi, `cancelled` não existe mais. */
const PAYABLE: readonly FinancialOccurrenceStatus[] = ['open', 'overdue']

/**
 * Explica por que a lista muda sozinha e quando o morador precisa mexer nela.
 *
 * A dúvida real é "cadê a minha fatura": em conta fixa ela aparece sozinha, em
 * conta variável só depois de um lançamento. Dizer isso na tela evita que o
 * morador conclua que o lançamento manual serve para toda conta e crie
 * duplicata da fixa — o que o servidor recusa com 409.
 */
const EXPLAINER_ITEMS = [
  {
    title: 'Cada linha é um vencimento, não a conta',
    body: 'A conta é o combinado que se repete e fica na aba Contas. Aqui é um dia específico, com o valor daquele dia. Pagar é nesta aba; mudar o valor previsto e o dia é na outra.',
  },
  {
    title: 'Por que a fatura aparece sozinha',
    body: 'Conta fixa tem dia de vencimento, então o Mordomus gera um vencimento por ciclo sem ninguém pedir. É por isso que o aluguel aparece aqui sem ninguém cadastrar nada.',
  },
  {
    title: 'Quando usar "Lançar fatura"',
    body: 'Só para conta de valor variável — energia, mercado, água — em que o valor só existe depois da fatura. Lançar a mesma conta na mesma data duas vezes é recusado: o vencimento daquela data já existe.',
  },
] as const

/**
 * Vencimentos e pagamento.
 *
 * `month` e `from`/`to` são dois jeitos de dizer o mesmo recorte e o backend
 * recusa os dois juntos (`IndexBillOccurrencesRequest`); por isso o recorte é um
 * `<select>` só e a query monta um ou o outro — nunca os dois no payload.
 */
export function OccurrencesPage() {
  const { can } = useAuth()
  const [recorte, setRecorte] = useState<Recorte>('month')
  const [month, setMonth] = useState(currentMonth)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [status, setStatus] = useState<FinancialOccurrenceStatus | ''>('')
  const [launching, setLaunching] = useState(false)
  const [paying, setPaying] = useState<BillOccurrence | null>(null)
  const [splitting, setSplitting] = useState<BillOccurrence | null>(null)

  const query = useBillOccurrences({
    month: recorte === 'month' && month !== '' ? month : null,
    from: recorte === 'range' && from !== '' ? from : null,
    to: recorte === 'range' && to !== '' ? to : null,
    status: status === '' ? null : status,
  })

  const occurrences = query.data?.data ?? []
  const canSeeSplit = can('splits.manage') || can('splits.view_own')

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-ink">Vencimentos</h2>
        {can('bills.manage') ? (
          <Button size="sm" onClick={() => setLaunching(true)}>
            Lançar fatura
          </Button>
        ) : null}
      </div>

      <Explainer
        summary="Cada linha é uma conta vencendo num dia. Em conta fixa ela aparece sozinha; em conta variável, só depois que você lança a fatura."
        items={EXPLAINER_ITEMS}
      />

      <Card>
        <CardBody>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Recorte">
              {(props) => (
                <Select
                  {...props}
                  value={recorte}
                  onChange={(event) => setRecorte(event.target.value as Recorte)}
                >
                  <option value="month">Por mês</option>
                  <option value="range">Por intervalo</option>
                </Select>
              )}
            </Field>

            {recorte === 'month' ? (
              <Field label="Mês">
                {(props) => (
                  <input
                    {...props}
                    type="month"
                    value={month}
                    onChange={(event) => setMonth(event.target.value)}
                    className="w-full rounded-control border border-line bg-surface-2 px-3 py-2 text-sm text-ink"
                  />
                )}
              </Field>
            ) : (
              <>
                <Field label="De">
                  {(props) => (
                    <input
                      {...props}
                      type="date"
                      value={from}
                      onChange={(event) => setFrom(event.target.value)}
                      className="w-full rounded-control border border-line bg-surface-2 px-3 py-2 text-sm text-ink"
                    />
                  )}
                </Field>
                <Field label="Até">
                  {(props) => (
                    <input
                      {...props}
                      type="date"
                      value={to}
                      onChange={(event) => setTo(event.target.value)}
                      className="w-full rounded-control border border-line bg-surface-2 px-3 py-2 text-sm text-ink"
                    />
                  )}
                </Field>
              </>
            )}

            <Field label="Situação">
              {(props) => (
                <Select
                  {...props}
                  value={status}
                  onChange={(event) =>
                    setStatus(event.target.value as FinancialOccurrenceStatus | '')
                  }
                >
                  <option value="">Todas</option>
                  {FINANCIAL_OCCURRENCE_STATUSES.map((option) => (
                    <option key={option} value={option}>
                      {STATUS_LABEL[option]}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
        </CardBody>
      </Card>

      {query.isPending ? (
        <div className="flex items-center gap-2 text-sm text-ink-muted">
          <Spinner className="size-4" />
          Carregando vencimentos...
        </div>
      ) : query.isError ? (
        <ErrorState
          message={
            query.error instanceof ApiError
              ? query.error.message
              : 'Falha ao carregar os vencimentos.'
          }
          requestId={query.error instanceof ApiError ? query.error.requestId : null}
          onRetry={() => void query.refetch()}
        />
      ) : occurrences.length === 0 ? (
        <EmptyState
          title="Nenhum vencimento neste recorte"
          description="Troque o mês ou o intervalo. Se a conta é de valor variável, lance a fatura."
          action={
            can('bills.manage') ? (
              <Button size="sm" onClick={() => setLaunching(true)}>
                Lançar fatura
              </Button>
            ) : undefined
          }
        />
      ) : (
        <ul className="space-y-2">
          {occurrences.map((occurrence) => (
            <li key={occurrence.id}>
              <Card>
                <CardBody className="flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{occurrence.bill_name}</p>
                    <p className="text-xs text-ink-muted">
                      Vence {occurrence.due_date}
                      {occurrence.category ? ` · ${occurrence.category}` : ''}
                      {occurrence.paid_at ? ` · pago em ${occurrence.paid_at.slice(0, 10)}` : ''}
                    </p>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="tabular-nums text-sm text-ink">
                      {formatAmount(occurrence.amount)}
                    </span>
                    <span
                      className={`rounded-control px-2 py-0.5 text-[11px] font-medium ${STATUS_TONE[occurrence.status]}`}
                    >
                      {STATUS_LABEL[occurrence.status]}
                    </span>
                    {canSeeSplit ? (
                      <Button variant="ghost" size="sm" onClick={() => setSplitting(occurrence)}>
                        Cotas
                      </Button>
                    ) : null}
                    {PAYABLE.includes(occurrence.status) && can('bills.pay') ? (
                      <Button size="sm" onClick={() => setPaying(occurrence)}>
                        Pagar
                      </Button>
                    ) : null}
                  </div>
                </CardBody>
              </Card>
            </li>
          ))}
        </ul>
      )}

      {launching ? <ManualOccurrenceModal onClose={() => setLaunching(false)} /> : null}
      {paying ? <PayModal occurrence={paying} onClose={() => setPaying(null)} /> : null}
      {splitting ? (
        <OccurrenceSplitModal occurrence={splitting} onClose={() => setSplitting(null)} />
      ) : null}
    </div>
  )
}

function currentMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}
