import { useState } from 'react'
import { Modal, Field, Button, Input, Select } from '@/shared/ui'
import { useToast } from '@/shared/ui/Toast'
import { ApiError } from '@/shared/api/errors'
import { usePayOccurrence } from './mutations'
import { PAYMENT_METHODS, type BillOccurrence, type PaymentMethod } from '@/shared/api/types'

const METHOD_LABEL: Record<PaymentMethod, string> = {
  pix: 'Pix',
  boleto: 'Boleto',
  debit_card: 'Débito automático',
  credit_card: 'Cartão de crédito',
  cash: 'Dinheiro',
  transfer: 'Transferência',
  other: 'Outro',
}

/**
 * Baixa do vencimento.
 *
 * `method` é obrigatório porque o backend exige (não há valor padrão plausível);
 * `amount` vem preenchido com o previsto porque em conta de valor conhecido o
 * morador não precisa redigitar o que já está no cadastro.
 *
 * Baixa repetida não abre erro: o backend é idempotente e devolve a mesma linha,
 * então a tela só reflete o estado novo. `cancelled` é a recusa que vale
 * explicar — não é "erro", é a conta que não existe mais.
 */
export function PayModal({
  occurrence,
  onClose,
}: {
  occurrence: BillOccurrence
  onClose: () => void
}) {
  const pay = usePayOccurrence()
  const toast = useToast()

  const [method, setMethod] = useState<PaymentMethod>('pix')
  const [amount, setAmount] = useState(occurrence.amount)
  const [paidAt, setPaidAt] = useState(today())
  const [receiptUrl, setReceiptUrl] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()

    const found: Record<string, string[]> = {}
    if (amount.trim() === '') found.amount = ['Informe o valor pago.']
    setErrors(found)
    if (Object.keys(found).length > 0) return

    try {
      await pay.mutateAsync({
        occurrenceId: occurrence.id,
        input: {
          method,
          amount: amount.trim(),
          paid_at: paidAt,
          receipt_url: receiptUrl.trim() === '' ? null : receiptUrl.trim(),
        },
      })
      toast.success('Pagamento registrado.')
      onClose()
    } catch (error) {
      if (!(error instanceof ApiError)) {
        toast.error('Não foi possível registrar o pagamento.')
        return
      }
      if (error.code === 'bill_occurrence_not_payable') {
        toast.info('Esta ocorrência está cancelada — não há o que pagar.')
        onClose()
        return
      }
      setErrors(error.fieldErrors)
      toast.error(error.message)
    }
  }

  return (
    <Modal
      open
      title="Registrar pagamento"
      description={`${occurrence.bill_name} · vencimento ${occurrence.due_date}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="pay-form" loading={pay.isPending}>
            Confirmar pagamento
          </Button>
        </>
      }
    >
      <form id="pay-form" onSubmit={submit} className="space-y-4">
        <Field label="Como foi pago" errors={errors.method}>
          {(props) => (
            <Select
              {...props}
              value={method}
              onChange={(event) => setMethod(event.target.value as PaymentMethod)}
            >
              {PAYMENT_METHODS.map((option) => (
                <option key={option} value={option}>
                  {METHOD_LABEL[option]}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <Field
          label="Valor pago"
          hint="Ajuste se foi pago menos que o previsto (multa, desconto)."
          errors={errors.amount}
        >
          {(props) => (
            <Input
              {...props}
              type="text"
              inputMode="decimal"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          )}
        </Field>

        <Field label="Data do pagamento" errors={errors.paid_at}>
          {(props) => (
            <Input
              {...props}
              type="date"
              value={paidAt}
              onChange={(event) => setPaidAt(event.target.value)}
            />
          )}
        </Field>

        <Field
          label="Comprovante"
          hint="Link do PDF ou da foto do boleto. Opcional."
          errors={errors.receipt_url}
        >
          {(props) => (
            // `type="text"` e não `type="url"`: a validação nativa do navegador
            // barraria o envio com a mensagem do idioma do navegador, em vez do
            // `url` do backend, que é a mensagem que o resto da tela fala
            <Input
              {...props}
              type="text"
              inputMode="url"
              placeholder="https://…"
              value={receiptUrl}
              onChange={(event) => setReceiptUrl(event.target.value)}
            />
          )}
        </Field>
      </form>
    </Modal>
  )
}

function today(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}
