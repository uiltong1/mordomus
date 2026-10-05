import { useState } from 'react'
import { Card, CardBody, EmptyState, ErrorState, Button, Field, Select, Spinner } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { useToast } from '@/shared/ui/Toast'
import { ApiError } from '@/shared/api/errors'
import { useBills } from './queries'
import { useUpdateBill } from './mutations'
import { BillFormModal } from './BillFormModal'
import { SplitRuleModal } from './SplitRuleModal'
import { Explainer } from './Explainer'
import { formatAmount } from './money'
import { BILL_KINDS, type Bill, type BillKind } from '@/shared/api/types'

const KIND_LABEL: Record<BillKind, string> = { fixed: 'Fixa', variable: 'Variável' }

const PER_PAGE = 50

/**
 * A tela se explica porque o modelo tem duas metades e o nome das duas não
 * denuncia isso: "conta" é o combinado que se repete, "vencimento" é o dia
 * concreto que aquele combinado tem em cada mês. Sem essa frase, o morador
 * cadastra o aluguel aqui e depois procura o boleto em Vencimentos sem saber
 * que ele já estava escrito.
 */
const EXPLAINER_ITEMS = [
  {
    title: 'Conta e vencimento são coisas diferentes',
    body: 'A conta guarda o combinado: nome, valor previsto, dia do vencimento. O vencimento é um dia específico, com o valor que foi pago naquele dia. Editar a conta muda os próximos ciclos; pagar é coisa do vencimento.',
  },
  {
    title: 'Conta fixa vence sozinha, conta variável não',
    body: 'A fixa tem um dia do mês, então o Mordomus gera o vencimento de cada ciclo sem ninguém pedir. A variável não tem dia — o valor só existe depois da fatura, então cada vencimento dela é lançado por você em "Lançar fatura", na aba Vencimentos.',
  },
  {
    title: 'Pausar não apaga histórico',
    body: 'Pausar tira a conta dos próximos ciclos. O que já venceu e foi pago continua em Vencimentos — é o histórico da casa.',
  },
] as const

/**
 * Lista de contas.
 *
 * Não existe `DELETE /bills`: a ação destrutiva é **Pausar** (`PATCH` com
 * `is_active: false`), e o par é Reativar. Um item pausado continua na lista
 * porque o morador procura a conta para reativá-la, não para descobrir que ela
 * sumiu.
 *
 * A escrita fica escondida sem `bills.manage`; a leitura é de qualquer morador.
 */
export function BillListPage() {
  const { can } = useAuth()
  const toast = useToast()
  const update = useUpdateBill()

  const [kind, setKind] = useState<BillKind | ''>('')
  const [isActive, setIsActive] = useState<'1' | '0' | ''>('1')
  const [category, setCategory] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Bill | null>(null)
  const [creating, setCreating] = useState(false)
  const [ruleFor, setRuleFor] = useState<Bill | null>(null)

  const query = useBills({
    kind: kind === '' ? null : kind,
    isActive: isActive === '' ? null : isActive === '1',
    category: category.trim() === '' ? null : category.trim(),
    page,
    perPage: PER_PAGE,
  })

  const bills = query.data?.data ?? []
  const meta = query.data?.meta

  // o backend recusa `per_page` fora de 1..100; o clamp evita a tela pedir o que
  // a API não devolve
  const perPage = Math.min(Math.max(meta?.per_page ?? PER_PAGE, 1), 100)
  const lastPage = Math.max(meta?.last_page ?? 1, 1)

  const toggleActive = async (bill: Bill) => {
    try {
      await update.mutateAsync({
        billId: bill.id,
        input: { is_active: !bill.is_active },
      })
      toast.success(bill.is_active ? 'Conta pausada.' : 'Conta reativada.')
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'Não foi possível mudar a conta.')
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-ink">Contas</h2>
        {can('bills.manage') ? (
          <Button size="sm" onClick={() => setCreating(true)}>
            Nova conta
          </Button>
        ) : null}
      </div>

      <Explainer
        summary="Conta é o cadastro que se repete — nome, valor, dia. O vencimento é o dia concreto que essa conta tem em cada mês, e é onde a fatura aparece para pagar."
        items={EXPLAINER_ITEMS}
      />

      <Card>
        <CardBody>
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Tipo">
              {(props) => (
                <Select
                  {...props}
                  value={kind}
                  onChange={(event) => {
                    setKind(event.target.value as BillKind | '')
                    setPage(1)
                  }}
                >
                  <option value="">Fixa e variável</option>
                  {BILL_KINDS.map((option) => (
                    <option key={option} value={option}>
                      {KIND_LABEL[option]}
                    </option>
                  ))}
                </Select>
              )}
            </Field>

            <Field label="Situação">
              {(props) => (
                <Select
                  {...props}
                  value={isActive}
                  onChange={(event) => {
                    setIsActive(event.target.value as '1' | '0' | '')
                    setPage(1)
                  }}
                >
                  <option value="1">Ativas</option>
                  <option value="0">Pausadas</option>
                  <option value="">Todas</option>
                </Select>
              )}
            </Field>

            <Field label="Categoria">
              {(props) => (
                <Select
                  {...props}
                  value={category}
                  onChange={(event) => setCategory(event.target.value)}
                >
                  <option value="">Todas</option>
                  {bills
                    .map((bill) => bill.category)
                    .filter((value): value is string => Boolean(value))
                    .map((value) => (
                      <option key={value} value={value}>
                        {value}
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
          Carregando contas...
        </div>
      ) : query.isError ? (
        <ErrorState
          message={
            query.error instanceof ApiError ? query.error.message : 'Falha ao carregar as contas.'
          }
          requestId={query.error instanceof ApiError ? query.error.requestId : null}
          onRetry={() => void query.refetch()}
        />
      ) : bills.length === 0 ? (
        <EmptyState
          title="Nenhuma conta cadastrada"
          description="Cadastre o aluguel, a energia, a internet — o Mordomus avisa antes do vencimento."
          action={
            can('bills.manage') ? (
              <Button size="sm" onClick={() => setCreating(true)}>
                Cadastrar conta
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <ul className="space-y-2">
            {bills.map((bill) => (
              <li key={bill.id}>
                <Card>
                  <CardBody className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-ink">
                        {bill.name}
                        {bill.is_active ? null : (
                          <span className="ml-2 rounded-control bg-surface-2 px-1.5 py-0.5 text-[11px] text-ink-muted">
                            pausada
                          </span>
                        )}
                      </p>
                      <p className="text-xs text-ink-muted">{describeBill(bill)}</p>
                    </div>

                    <div className="flex items-center gap-2">
                      <span className="tabular-nums text-sm text-ink">
                        {bill.amount === null ? 'valor variável' : formatAmount(bill.amount)}
                      </span>

                      {can('splits.manage') ? (
                        <Button variant="ghost" size="sm" onClick={() => setRuleFor(bill)}>
                          Divisão
                        </Button>
                      ) : null}

                      {can('bills.manage') ? (
                        <>
                          <Button variant="ghost" size="sm" onClick={() => setEditing(bill)}>
                            Editar
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            loading={update.isPending && update.variables?.billId === bill.id}
                            onClick={() => void toggleActive(bill)}
                          >
                            {bill.is_active ? 'Pausar' : 'Reativar'}
                          </Button>
                        </>
                      ) : null}
                    </div>
                  </CardBody>
                </Card>
              </li>
            ))}
          </ul>

          {lastPage > 1 ? (
            <nav aria-label="Paginação" className="flex items-center justify-between gap-3">
              <Button
                variant="ghost"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((current) => Math.max(current - 1, 1))}
              >
                Anterior
              </Button>
              <span className="text-xs text-ink-muted">
                Página {page} de {lastPage} · {meta?.total ?? bills.length} contas · {perPage} por
                página
              </span>
              <Button
                variant="ghost"
                size="sm"
                disabled={page >= lastPage}
                onClick={() => setPage((current) => Math.min(current + 1, lastPage))}
              >
                Próxima
              </Button>
            </nav>
          ) : null}
        </>
      )}

      {creating ? <BillFormModal onClose={() => setCreating(false)} /> : null}
      {editing ? <BillFormModal bill={editing} onClose={() => setEditing(null)} /> : null}
      {ruleFor ? <SplitRuleModal billId={ruleFor.id} onClose={() => setRuleFor(null)} /> : null}
    </div>
  )
}

/**
 * A linha diz por que a conta se comporta como se comporta.
 *
 * Sem isso, a conta variável fica parecendo quebrada: ela não tem próxima data
 * justamente porque não tem dia, e o que falta — o lançamento da fatura —
 * acontece em outra tela.
 */
function describeBill(bill: Bill): string {
  const parts = [KIND_LABEL[bill.kind]]

  if (bill.category) parts.push(bill.category)

  if (bill.kind === 'fixed') {
    const day = bill.schedule?.day_of_month
    parts.push(day ? `vence todo dia ${day}` : 'sem dia de vencimento definido')
    if (bill.schedule?.next_due_at) {
      parts.push(`próximo ${bill.schedule.next_due_at.slice(0, 10)}`)
    }
  } else {
    parts.push('lance cada fatura em Vencimentos')
  }

  return parts.join(' · ')
}
