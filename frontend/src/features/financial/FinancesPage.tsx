import { useState } from 'react'
import { Button } from '@/shared/ui'
import { SummaryPage } from './SummaryPage'
import { BillListPage } from './BillListPage'
import { OccurrencesPage } from './OccurrencesPage'
import { useAuth } from '@/features/auth/AuthProvider'
import { useSplitRule } from './queries'
import { SplitRuleModal } from './SplitRuleModal'

type Tab = 'resumo' | 'contas' | 'vencimentos'

const TABS: readonly { id: Tab; label: string }[] = [
  { id: 'resumo', label: 'Resumo' },
  { id: 'contas', label: 'Contas' },
  { id: 'vencimentos', label: 'Vencimentos' },
]

/**
 * Tela de finanças.
 *
 * A regra padrão da casa vive aqui, e não em "Contas": ela não pertence a uma
 * conta nenhuma — é o que vale para o que não tem regra própria. Quem só tem
 * `splits.view_own` não vê o botão, porque não abriria o builder.
 */
export function FinancesPage() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('resumo')
  const [editingHouseRule, setEditingHouseRule] = useState(false)
  const houseRule = useSplitRule(null)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">Finanças</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Contas, vencimentos e divisão de despesas da residência.
          </p>
        </div>

        {can('splits.manage') ? (
          <Button variant="secondary" size="sm" onClick={() => setEditingHouseRule(true)}>
            {houseRule.data?.[0] ? 'Editar divisão da casa' : 'Dividir despesas da casa'}
          </Button>
        ) : null}
      </div>

      <nav aria-label="Seções das finanças" className="flex gap-1 border-b border-line">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => setTab(item.id)}
            aria-current={tab === item.id ? 'page' : undefined}
            className={[
              'rounded-t-control px-3 py-2 text-sm transition-colors',
              tab === item.id
                ? 'border-b-2 border-brand font-medium text-ink'
                : 'text-ink-muted hover:text-ink',
            ].join(' ')}
          >
            {item.label}
          </button>
        ))}
      </nav>

      {tab === 'resumo' ? <SummaryPage /> : null}
      {tab === 'contas' ? <BillListPage /> : null}
      {tab === 'vencimentos' ? <OccurrencesPage /> : null}

      {editingHouseRule ? (
        <SplitRuleModal
          rule={houseRule.data?.[0] ?? null}
          onClose={() => setEditingHouseRule(false)}
        />
      ) : null}
    </div>
  )
}
