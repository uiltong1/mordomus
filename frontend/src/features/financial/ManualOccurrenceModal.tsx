import { useState } from 'react'
import { Modal, Field, Button, Input, Select, DatePicker } from '@/shared/ui'
import { useToast } from '@/shared/ui/Toast'
import { ApiError } from '@/shared/api/errors'
import { useBills } from './queries'
import { useCreateOccurrence } from './mutations'

/**
 * Lançamento manual — o caminho da conta variável, em que a fatura chega com
 * valor e data que só o morador sabe.
 *
 * O `<input type="date">` já entrega `YYYY-MM-DD`, que é o formato que o
 * `date_format:Y-m-d` do backend valida: a data vai como o morador leu na
 * fatura, sem passar por `Date` — este módulo não calcula data (regra R7).
 */
export function ManualOccurrenceModal({ onClose }: { onClose: () => void }) {
  const bills = useBills({ isActive: true })
  const create = useCreateOccurrence()
  const toast = useToast()

  const [billId, setBillId] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [amount, setAmount] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()

    const found: Record<string, string[]> = {}
    if (billId === '') found.bill_id = ['Escolha a conta.']
    if (amount.trim() === '') found.amount = ['Informe o valor da fatura.']
    setErrors(found)
    if (Object.keys(found).length > 0) return

    try {
      await create.mutateAsync({
        bill_id: billId,
        due_date: dueDate,
        amount: amount.trim(),
      })
      toast.success('Lançamento registrado.')
      onClose()
    } catch (error) {
      if (!(error instanceof ApiError)) {
        toast.error('Não foi possível registrar o lançamento.')
        return
      }
      setErrors(error.fieldErrors)
      // 409 bill_occurrence_exists: a mesma conta já tem vencimento nesta data —
      // o erro aponta a data, e a tela precisa dizer isso e não "conflito".
      if (error.code === 'bill_occurrence_exists') {
        toast.error('Esta conta já tem um vencimento nesta data.')
        return
      }
      toast.error(error.message)
    }
  }

  return (
    <Modal
      open
      title="Lançar fatura"
      description="Conta variável: o valor e a data saem da fatura."
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="manual-occurrence" loading={create.isPending}>
            Lançar
          </Button>
        </>
      }
    >
      <form id="manual-occurrence" onSubmit={submit} className="space-y-4">
        <Field label="Conta" errors={errors.bill_id}>
          {(props) => (
            <Select {...props} value={billId} onChange={(event) => setBillId(event.target.value)}>
              <option value="">Escolha a conta…</option>
              {bills.data?.data.map((bill) => (
                <option key={bill.id} value={bill.id}>
                  {bill.name}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field label="Vencimento" errors={errors.due_date}>
          {(props) => <DatePicker {...props} value={dueDate} onChange={setDueDate} />}
        </Field>

        <Field label="Valor" errors={errors.amount}>
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
      </form>
    </Modal>
  )
}
