import { useState } from 'react'
import { Modal, Field, Button, Input, Select } from '@/shared/ui'
import { useToast } from '@/shared/ui/Toast'
import { useActiveMembers } from './queries'
import { useSaveSplitRule } from './mutations'
import { ApiError } from '@/shared/api/errors'
import type { SplitMode, SplitRule } from '@/shared/api/types'
import {
  SPLIT_MODE_OPTIONS,
  emptySplitRule,
  entriesPayload,
  requiredFieldFor,
  splitRuleFromRule,
  validateSplitRule,
  type SplitRuleForm,
} from './splitTypes'

/**
 * Builder da regra de divisão.
 *
 * `billId` nulo = regra padrão da casa, que vale para o que não tem regra
 * própria; aberto a partir de uma conta, o `bill_id` vai preenchido e a regra
 * vale só para ela.
 */
export function SplitRuleModal({
  billId = null,
  rule = null,
  onClose,
}: {
  billId?: string | null
  rule?: SplitRule | null
  onClose: () => void
}) {
  const members = useActiveMembers()
  const save = useSaveSplitRule()
  const toast = useToast()
  const [form, setForm] = useState<SplitRuleForm | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const participants = members.data ?? []
  const base = form ?? (rule ? splitRuleFromRule(rule) : null)

  const changeMode = (mode: SplitMode) => {
    setForm({
      ...(base ?? emptySplitRule(participants.map((member) => member.user.id))),
      mode,
      // o campo do regime anterior é zerado aqui: ele não pode sobreviver no
      // estado, ou entra no payload e o backend recusa com 422
      entries: (base?.entries ?? []).map((entry) => ({
        ...entry,
        weight: null,
        percent: null,
        fixed_amount: null,
      })),
    })
  }

  const setEntry = (userId: string, patch: Partial<SplitRuleForm['entries'][number]>) => {
    if (!base) return
    setForm({
      ...base,
      entries: base.entries.map((entry) =>
        entry.user_id === userId ? { ...entry, ...patch } : entry,
      ),
    })
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!base) return

    const found = validateSplitRule(base)
    setErrors(found)
    if (Object.keys(found).length > 0) return

    try {
      await save.mutateAsync({
        bill_id: billId,
        mode: base.mode,
        is_active: base.isActive,
        entries: entriesPayload(base),
      })
      toast.success('Regra de divisão salva.')
      onClose()
    } catch (error) {
      if (!(error instanceof ApiError)) {
        toast.error('Não foi possível salvar a regra.')
        return
      }
      setErrors(error.fieldErrors)
      toast.error(error.message)
    }
  }

  if (!base) {
    return (
      <Modal open title="Regra de divisão" onClose={onClose}>
        <p className="text-sm text-ink-muted">
          {members.isPending
            ? 'Carregando moradores...'
            : 'Nenhum morador ativo na residência para dividir.'}
        </p>
      </Modal>
    )
  }

  const field = requiredFieldFor(base.mode)

  return (
    <Modal
      open
      title="Regra de divisão"
      description={billId ? 'Vale só para esta conta.' : 'Vale para as contas sem regra própria.'}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="split-rule-form" loading={save.isPending}>
            Salvar regra
          </Button>
        </>
      }
    >
      <form id="split-rule-form" onSubmit={submit} className="space-y-4">
        <Field label="Como dividir" errors={errors.mode}>
          {(props) => (
            <Select
              {...props}
              value={base.mode}
              onChange={(e) => changeMode(e.target.value as SplitMode)}
            >
              {SPLIT_MODE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <p className="text-xs text-ink-muted">
          {SPLIT_MODE_OPTIONS.find((option) => option.value === base.mode)?.description}
        </p>

        <p className="rounded-control bg-surface-2 px-3 py-2 text-xs text-ink-muted">
          {base.mode === 'EQUAL'
            ? 'Ninguém precisa preencher nada: a conta divide em partes iguais entre quem está na regra.'
            : 'A soma precisa fechar — 100% no percentual, e o total da conta nos valores fixos. Se não fechar, o servidor recusa e o vencimento continua na lista: nada é derrubado.'}
        </p>

        {errors.entries ? <p className="text-xs text-danger">{errors.entries.join(' ')}</p> : null}

        <ul className="space-y-3">
          {base.entries.map((entry, index) => {
            const participant = participants.find((item) => item.user.id === entry.user_id)
            const name = participant?.user.name ?? entry.user_id
            return (
              <li key={entry.user_id} className="space-y-1.5">
                <p className="text-xs font-medium text-ink-muted">{name}</p>
                {field === null ? (
                  <p className="text-sm text-ink-subtle">Cota calculada igualmente</p>
                ) : (
                  <Field
                    label={
                      field === 'weight' ? 'Peso' : field === 'percent' ? 'Percentual' : 'Valor'
                    }
                    errors={errors[`entries.${index}.${field}`]}
                  >
                    {(props) => (
                      <Input
                        {...props}
                        type="number"
                        step="0.01"
                        min={field === 'fixed_amount' ? '0' : '0.01'}
                        value={
                          field === 'fixed_amount'
                            ? (entry.fixed_amount ?? '')
                            : (entry[field] ?? '')
                        }
                        onChange={(event) => {
                          const raw = event.target.value
                          setEntry(
                            entry.user_id,
                            field === 'fixed_amount'
                              ? { fixed_amount: raw === '' ? null : raw }
                              : { [field]: raw === '' ? null : Number(raw) },
                          )
                        }}
                      />
                    )}
                  </Field>
                )}
              </li>
            )
          })}
        </ul>

        <label className="flex items-center gap-2 text-sm text-ink">
          <input
            type="checkbox"
            checked={base.isActive}
            onChange={(event) => setForm({ ...base, isActive: event.target.checked })}
          />
          Regra ativa
        </label>
      </form>
    </Modal>
  )
}
