import { Modal, Button, Spinner } from '@/shared/ui'
import { useToast } from '@/shared/ui/Toast'
import { useAuth } from '@/features/auth/AuthProvider'
import { ApiError } from '@/shared/api/errors'
import { useOccurrenceSplit } from './queries'
import { useSettleShare } from './mutations'
import { formatAmount } from './money'
import type { BillOccurrence } from '@/shared/api/types'

/**
 * Cotas de um vencimento.
 *
 * Com `scope: own` — morador sem `splits.manage` — a lista traz só a cota dele e
 * o `total` continua sendo o do vencimento: somar a lista parcial daria um
 * número que não é o total da conta. Por isso o rodapé só aparece no escopo
 * inteiro, e o total nunca é recalculado aqui.
 *
 * Desfazer (`settled: false`) é correção explícita e tem botão próprio: quem
 * marcou errado precisa desfazer de propósito, não por um segundo clique no
 * mesmo comando.
 */
export function OccurrenceSplitModal({
  occurrence,
  onClose,
}: {
  occurrence: BillOccurrence
  onClose: () => void
}) {
  const { can } = useAuth()
  const toast = useToast()
  const split = useOccurrenceSplit(occurrence.id)
  const settle = useSettleShare()
  const manage = can('splits.manage')

  const settleShare = (userId: string, settled: boolean) => {
    void settle
      .mutateAsync({ occurrenceId: occurrence.id, userId, settled })
      .then(() => toast.success(settled ? 'Cota baixada.' : 'Baixa desfeita.'))
      .catch((error: unknown) =>
        toast.error(
          error instanceof ApiError ? error.message : 'Não foi possível atualizar a cota.',
        ),
      )
  }

  return (
    <Modal
      open
      title={`Cotas · ${occurrence.bill_name}`}
      description={`Vencimento de ${occurrence.due_date}`}
      onClose={onClose}
      footer={
        <Button variant="ghost" onClick={onClose}>
          Fechar
        </Button>
      }
    >
      {split.isPending ? (
        <div className="flex items-center gap-2 text-sm text-ink-muted">
          <Spinner className="size-4" />
          Carregando as cotas...
        </div>
      ) : split.isError ? (
        <p className="text-sm text-danger">
          {split.error instanceof ApiError
            ? split.error.message
            : 'Não foi possível carregar as cotas.'}
        </p>
      ) : split.data ? (
        <>
          <p className="text-sm text-ink">
            Total do vencimento:{' '}
            <span className="font-medium tabular-nums">{formatAmount(split.data.total)}</span>
          </p>

          <ul className="mt-3 space-y-2">
            {split.data.shares.map((share) => (
              <li key={share.user_id} className="flex items-center justify-between gap-3 text-sm">
                <span className="truncate text-ink">{share.user_name}</span>
                <span className="flex items-center gap-2">
                  <span className="tabular-nums text-ink-muted">
                    {formatAmount(share.share_amount)}
                  </span>
                  {share.settled ? (
                    <span className="rounded-control bg-success/15 px-1.5 py-0.5 text-[11px] text-success">
                      pago
                    </span>
                  ) : null}
                  {manage ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      loading={
                        settle.isPending &&
                        settle.variables?.occurrenceId === occurrence.id &&
                        settle.variables?.userId === share.user_id
                      }
                      onClick={() => settleShare(share.user_id, !share.settled)}
                    >
                      {share.settled ? 'Desfazer' : 'Dar baixa'}
                    </Button>
                  ) : null}
                </span>
              </li>
            ))}
          </ul>

          {split.data.scope === 'own' ? (
            <p className="mt-3 text-xs text-ink-subtle">
              Você está vendo só a sua cota — o total acima é o da conta inteira.
            </p>
          ) : null}
        </>
      ) : null}
    </Modal>
  )
}
