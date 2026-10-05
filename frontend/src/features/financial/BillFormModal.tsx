import { useState } from 'react'
import { Modal, Field, Button, Input, Select } from '@/shared/ui'
import { useToast } from '@/shared/ui/Toast'
import { ApiError } from '@/shared/api/errors'
import { useCreateBill, useUpdateBill } from './mutations'
import type { BillInput } from './api'
import { BILL_KINDS, type Bill, type BillKind } from '@/shared/api/types'

const KIND_LABEL: Record<BillKind, string> = {
  fixed: 'Fixa — vence todo mês',
  variable: 'Variável — você lança cada fatura',
}

/**
 * Cadastro e edição da conta.
 *
 * `kind=fixed` abre a cadência (`due_day` + `advance_notice_days`), que o
 * Scheduling materializa; a tela **não** calcula a próxima data (R7) — exibe o
 * `schedule.next_due_at` que veio do servidor.
 */
export function BillFormModal({
  bill = null,
  onClose,
}: {
  bill?: Bill | null
  onClose: () => void
}) {
  const create = useCreateBill()
  const update = useUpdateBill()
  const toast = useToast()

  const [name, setName] = useState(bill?.name ?? '')
  const [kind, setKind] = useState<BillKind>(bill?.kind ?? 'fixed')
  const [category, setCategory] = useState(bill?.category ?? '')
  const [amount, setAmount] = useState(bill?.amount ?? '')
  const [isActive, setIsActive] = useState(bill?.is_active ?? true)
  const [dueDay, setDueDay] = useState<string>(bill?.schedule?.day_of_month?.toString() ?? '')
  const [advanceNoticeDays, setAdvanceNoticeDays] = useState<string>(
    bill?.schedule?.advance_notice_days?.toString() ?? '0',
  )
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  /**
   * A cadência só entra para conta fixa: mandá-la na variável é o tipo de campo
   * sobrando que o `prohibited` do backend recusa.
   */
  function buildInput(): BillInput {
    const payload: BillInput = {
      name: name.trim(),
      kind,
      category: category.trim() === '' ? null : category.trim(),
      amount: amount.trim() === '' ? null : amount.trim(),
      currency: bill?.currency ?? 'BRL',
      is_active: isActive,
    }
    if (kind === 'fixed') {
      payload.due_day = dueDay === '' ? null : Number(dueDay)
      payload.advance_notice_days = Number(advanceNoticeDays)
    }
    return payload
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()

    if (name.trim() === '') {
      setErrors({ name: ['Dê um nome para a conta.'] })
      return
    }

    try {
      if (bill) {
        await update.mutateAsync({ billId: bill.id, input: buildInput() })
      } else {
        await create.mutateAsync(buildInput())
      }
      toast.success(bill ? 'Conta atualizada.' : 'Conta criada.')
      onClose()
    } catch (error) {
      if (!(error instanceof ApiError)) {
        toast.error('Não foi possível salvar a conta.')
        return
      }
      setErrors(error.fieldErrors)
      toast.error(error.message)
    }
  }

  const busy = create.isPending || update.isPending

  return (
    <Modal
      open
      title={bill ? 'Editar conta' : 'Nova conta'}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="bill-form" loading={busy}>
            Salvar
          </Button>
        </>
      }
    >
      <form id="bill-form" onSubmit={submit} className="space-y-4">
        <Field label="Nome" errors={errors.name}>
          {(props) => (
            <Input {...props} value={name} onChange={(event) => setName(event.target.value)} />
          )}
        </Field>

        <Field label="Tipo" errors={errors.kind}>
          {(props) => (
            <Select
              {...props}
              value={kind}
              onChange={(event) => setKind(event.target.value as BillKind)}
            >
              {BILL_KINDS.map((option) => (
                <option key={option} value={option}>
                  {KIND_LABEL[option]}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Categoria" hint="Moradia, energia, água…" errors={errors.category}>
          {(props) => (
            <Input
              {...props}
              value={category}
              onChange={(event) => setCategory(event.target.value)}
            />
          )}
        </Field>

        <Field
          label="Valor previsto"
          hint="Deixe vazio se o valor muda a cada fatura — a conta fica variável e cada vencimento sai de um lançamento seu."
          errors={errors.amount}
        >
          {(props) => (
            <Input
              {...props}
              type="text"
              inputMode="decimal"
              placeholder="0,00"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          )}
        </Field>

        {kind === 'fixed' ? (
          <>
            <Field
              label="Dia do vencimento"
              hint="A conta vence todo dia X do mês. Quem calcula a próxima data é o agendador, não esta tela: aqui o Mordomus mostra o dia que ele calculou."
              errors={errors.due_day}
            >
              {(props) => (
                <Input
                  {...props}
                  type="number"
                  min={1}
                  max={31}
                  value={dueDay}
                  onChange={(event) => setDueDay(event.target.value)}
                />
              )}
            </Field>

            <Field
              label="Avisar com quantos dias de antecedência"
              hint="0 avisa no dia do vencimento; 5 avisa cinco dias antes."
              errors={errors.advance_notice_days}
            >
              {(props) => (
                <Input
                  {...props}
                  type="number"
                  min={0}
                  max={365}
                  value={advanceNoticeDays}
                  onChange={(event) => setAdvanceNoticeDays(event.target.value)}
                />
              )}
            </Field>
          </>
        ) : (
          <p className="rounded-control bg-surface-2 px-3 py-2 text-xs text-ink-muted">
            Conta variável não tem dia de vencimento. Cada fatura entra pela aba Vencimentos, em
            &quot;Lançar fatura&quot;, com o valor e a data que você leu nela.
          </p>
        )}

        <label className="flex items-center gap-2 text-sm text-ink">
          <input
            type="checkbox"
            checked={isActive}
            onChange={(event) => setIsActive(event.target.checked)}
          />
          Conta ativa
        </label>

        {bill?.schedule?.next_due_at ? (
          <p className="text-xs text-ink-muted">
            Próximo vencimento: {bill.schedule.next_due_at.slice(0, 10)}
          </p>
        ) : null}
      </form>
    </Modal>
  )
}
