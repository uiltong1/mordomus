import { useMemo, useRef, useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import type { IntervalUnit, RecalculateBase, TriggerConfig, TriggerType } from '@/shared/api/types'
import { formatDayLong, formatInstant, todayInputValue } from '@/shared/datetime'
import { Button, DatePicker, Field, Input, Modal, Select, Textarea, useToast } from '@/shared/ui'
import { previewNextDue } from './api'
import { useSaveTriggerConfig } from './triggerQueries'
import {
  TRIGGER_TYPE_OPTIONS,
  buildConfigPayload,
  buildPreviewPayload,
  emptyTriggerForm,
  fieldsForType,
  triggerFormFrom,
  validateTriggerForm,
  type TriggerFormState,
  type TriggerTypeFieldSet,
} from './triggerTypes'

const UNIT_OPTIONS: readonly { value: IntervalUnit; label: string }[] = [
  { value: 'days', label: 'dias' },
  { value: 'weeks', label: 'semanas' },
  { value: 'months', label: 'meses' },
]

const RECALCULATE_OPTIONS: readonly { value: RecalculateBase; label: string; hint: string }[] = [
  {
    value: 'COMPLETION',
    label: 'A partir do dia em que eu marcar como feito',
    hint: 'É o que se espera de filtro e de pilha: a conta segue o consumo real.',
  },
  {
    value: 'DUE_DATE',
    label: 'A partir do dia que estava marcado para fazer',
    hint: 'Use quando a data vale por si, mesmo se atrasar.',
  },
]

export interface TriggerBuilderModalProps {
  open: boolean
  onClose: () => void
  /** Ativo alvo. A regra nasce presa a ele. */
  subjectId: string
  /** Nome do ativo, usado no título e na descrição do modal. */
  subjectName: string
  /** `null` na criação; a regra existente na edição. */
  config?: TriggerConfig | null
  onSaved?: () => void
}

/**
 * Formulário visual de regra de manutenção.
 *
 * O morador escolhe **como** quer repetir, em português, e a tela descobre quais
 * campos o backend exige naquele tipo. O preview da próxima data é a peça que
 * fecha o ciclo de confiança: ele pergunta ao mesmo motor que vai gravar
 * (`POST /scheduling/preview`), então o que aparece na tela antes de salvar é
 * literalmente a data que o calendário vai assumir.
 */
export function TriggerBuilderModal({
  open,
  onClose,
  subjectId,
  subjectName,
  config = null,
  onSaved,
}: TriggerBuilderModalProps) {
  if (!open) return null

  return (
    <TriggerForm
      key={config?.id ?? `novo:${subjectId}`}
      onClose={onClose}
      subjectId={subjectId}
      subjectName={subjectName}
      config={config}
      onSaved={onSaved}
    />
  )
}

function TriggerForm({
  onClose,
  subjectId,
  subjectName,
  config,
  onSaved,
}: {
  onClose: () => void
  subjectId: string
  subjectName: string
  config: TriggerConfig | null
  onSaved?: () => void
}) {
  const [form, setForm] = useState<TriggerFormState>(() =>
    config ? triggerFormFrom(config) : emptyTriggerForm(),
  )
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const toast = useToast()
  const save = useSaveTriggerConfig()

  /**
   * Cada campo guarda o último valor que o morador digitou, para voltar intacto
   * quando ele troca de tipo e depois volta. Sem isso, ir de "a cada 90 dias"
   * para "dia fixo do mês" e de volta apagaria os 90 — a troca de tipo é para
   * comparar as opções, não para jogar fora o que já estava pronto.
   */
  const remembered = useRef<RememberedTypeFields>({})

  const update = <K extends keyof TriggerFormState>(field: K, value: TriggerFormState[K]) => {
    if (isTypeField(field) && value !== null) {
      remembered.current[field] = value as TriggerTypeFieldSet[keyof TriggerTypeFieldSet]
    }
    setForm((current) => ({ ...current, [field]: value }))
  }

  /**
   * Trocar de tipo limpa os campos que o novo tipo proíbe. Sem isso o valor
   * antigo sobrevive no estado e volta no payload como `prohibited` — o
   * morador receberia um 422 por um campo que nem está na tela.
   */
  const changeType = (type: TriggerType) => {
    setForm((current) => {
      const allowed = fieldsForType(type)
      const next: TriggerFormState = { ...current, type }

      if (allowed.includes('interval_value')) {
        next.interval_value =
          (remembered.current.interval_value as number | undefined) ?? current.interval_value ?? 30
      }
      if (allowed.includes('interval_unit')) {
        next.interval_unit =
          (remembered.current.interval_unit as IntervalUnit | undefined) ??
          current.interval_unit ??
          'days'
      }
      if (allowed.includes('day_of_month')) {
        next.day_of_month =
          (remembered.current.day_of_month as number | undefined) ?? current.day_of_month ?? 10
      }
      if (allowed.includes('recalculate_base')) {
        next.recalculate_base =
          (remembered.current.recalculate_base as RecalculateBase | undefined) ??
          current.recalculate_base ??
          'COMPLETION'
      }
      if (allowed.includes('custom_offsets')) {
        next.custom_offsets =
          (remembered.current.custom_offsets as number[] | undefined) ?? current.custom_offsets
      }

      return next
    })
    setErrors({})
  }

  const fields = fieldsForType(form.type)
  const localErrors = useMemo(() => validateTriggerForm(form), [form])

  // o backend manda o 422 por campo e vale mais que a regra local: a recusa dele
  // sabe o que aconteceu com este dado, e a local só olha a forma
  const fieldError = (field: string) => errors[field] ?? localErrors[field]

  const preview = useMutation({
    mutationFn: () => previewNextDue(buildPreviewPayload(form)),
  })

  const onPreview = () => {
    const invalid = validateTriggerForm(form, 'preview')
    setErrors(invalid)
    if (Object.keys(invalid).length > 0) return
    preview.mutate()
  }

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    const invalid = validateTriggerForm(form)
    setErrors(invalid)
    if (Object.keys(invalid).length > 0) {
      setFormError('Confira os campos destacados antes de salvar.')
      return
    }

    setSubmitting(true)
    setFormError(null)
    try {
      await save.mutateAsync({
        configId: config?.id ?? null,
        subjectId,
        payload: buildConfigPayload(form),
      })
      toast.success(config ? 'Regra atualizada.' : 'Regra criada.')
      onSaved?.()
      onClose()
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors)
        setFormError(error.message)
      } else {
        setFormError('Não foi possível salvar a regra.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  const selected = TRIGGER_TYPE_OPTIONS.find((option) => option.value === form.type)
  const show = (field: keyof TriggerTypeFieldSet) => fields.includes(field)

  return (
    <Modal
      open
      title={config ? 'Editar regra' : 'Nova regra de manutenção'}
      description={`${subjectName} — ${selected?.example ?? ''}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onPreview} loading={preview.isPending}>
            Ver próxima data
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="trigger-form" loading={submitting}>
            {config ? 'Salvar alterações' : 'Criar regra'}
          </Button>
        </>
      }
    >
      <form id="trigger-form" onSubmit={onSubmit} className="space-y-4" noValidate>
        {formError ? (
          <p role="alert" className="rounded-control bg-danger/15 px-3 py-2 text-xs text-danger">
            {formError}
          </p>
        ) : null}

        <fieldset className="space-y-2">
          <legend className="text-xs font-medium text-ink-muted">Como você quer repetir?</legend>
          <div className="space-y-2" role="radiogroup" aria-label="Tipo de repetição">
            {TRIGGER_TYPE_OPTIONS.map((option) => (
              <label
                key={option.value}
                className={[
                  'flex cursor-pointer gap-3 rounded-control border px-3 py-2.5 transition-colors',
                  form.type === option.value
                    ? 'border-brand bg-brand/10'
                    : 'border-line hover:bg-surface-2',
                ].join(' ')}
              >
                <input
                  type="radio"
                  name="trigger-type"
                  value={option.value}
                  checked={form.type === option.value}
                  onChange={() => changeType(option.value)}
                  className="mt-1 accent-brand"
                />
                <span className="min-w-0">
                  <span className="block text-sm font-medium text-ink">{option.label}</span>
                  <span className="mt-0.5 block text-xs text-ink-muted">{option.description}</span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>

        <Field label="Nome da regra" errors={fieldError('title')}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              name="title"
              value={form.title}
              maxLength={160}
              placeholder="Limpar o filtro da cozinha"
              aria-describedby={describedBy}
              invalid={invalid}
              onChange={(event) => update('title', event.target.value)}
            />
          )}
        </Field>

        <Field label="Anotação" hint="Opcional. Serve para lembrar por quê a regra existe.">
          {({ id, describedBy }) => (
            <Textarea
              id={id}
              name="description"
              value={form.description}
              maxLength={500}
              aria-describedby={describedBy}
              onChange={(event) => update('description', event.target.value)}
            />
          )}
        </Field>

        {show('interval_value') ? (
          <div className="grid gap-3 sm:grid-cols-[1fr_1.4fr]">
            <Field label="De quantos em quantos" errors={fieldError('interval_value')}>
              {({ id, describedBy, invalid }) => (
                <Input
                  id={id}
                  name="interval_value"
                  type="number"
                  min={1}
                  max={65535}
                  value={form.interval_value ?? ''}
                  aria-describedby={describedBy}
                  invalid={invalid}
                  onChange={(event) => {
                    const raw = event.target.value
                    update('interval_value', raw === '' ? null : Number(raw))
                  }}
                />
              )}
            </Field>

            <Field label="Unidade" errors={fieldError('interval_unit')}>
              {({ id, describedBy, invalid }) => (
                <Select
                  id={id}
                  name="interval_unit"
                  value={form.interval_unit ?? ''}
                  aria-describedby={describedBy}
                  invalid={invalid}
                  onChange={(event) =>
                    update('interval_unit', (event.target.value || null) as IntervalUnit | null)
                  }
                >
                  <option value="">Escolha</option>
                  {UNIT_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
        ) : null}

        {show('day_of_month') ? (
          <Field
            label="Dia do mês"
            hint={
              form.day_of_month
                ? `Todo dia ${form.day_of_month} — em fevereiro, ${clampedDayInFebruary(form.day_of_month)}.`
                : undefined
            }
            errors={fieldError('day_of_month')}
          >
            {({ id, describedBy, invalid }) => (
              <Select
                id={id}
                name="day_of_month"
                value={form.day_of_month ?? ''}
                aria-describedby={describedBy}
                invalid={invalid}
                onChange={(event) =>
                  update('day_of_month', event.target.value ? Number(event.target.value) : null)
                }
              >
                <option value="">Escolha o dia</option>
                {Array.from({ length: 31 }, (_, index) => index + 1).map((day) => (
                  <option key={day} value={day}>
                    Dia {day}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}

        {show('recalculate_base') ? (
          <Field
            label="Contar a partir de quando"
            hint="A próxima data só existe depois que houver uma conclusão para contar."
            errors={fieldError('recalculate_base')}
          >
            {({ id, describedBy, invalid }) => (
              <Select
                id={id}
                name="recalculate_base"
                value={form.recalculate_base ?? ''}
                aria-describedby={describedBy}
                invalid={invalid}
                onChange={(event) =>
                  update('recalculate_base', (event.target.value || null) as RecalculateBase | null)
                }
              >
                <option value="">Escolha</option>
                {RECALCULATE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}

        {show('custom_offsets') ? (
          <Field
            label="Avisos"
            hint="Números negativos avisam antes, 0 avisa no dia, positivos avisam depois."
            errors={fieldError('custom_offsets')}
          >
            {({ id, describedBy, invalid }) => (
              <div className="space-y-2" id={id} aria-describedby={describedBy}>
                <div className="flex flex-wrap gap-2">
                  {[-7, -3, -1, 0, 1, 3, 7].map((offset) => {
                    const active = form.custom_offsets.includes(offset)
                    return (
                      <button
                        key={offset}
                        type="button"
                        aria-pressed={active}
                        className={[
                          'rounded-control border px-2.5 py-1.5 text-xs transition-colors',
                          active
                            ? 'border-brand bg-brand/15 text-brand'
                            : 'border-line text-ink-muted hover:bg-surface-2',
                        ].join(' ')}
                        onClick={() =>
                          update(
                            'custom_offsets',
                            active
                              ? form.custom_offsets.filter((value) => value !== offset)
                              : [...form.custom_offsets, offset],
                          )
                        }
                      >
                        {offset === 0
                          ? 'No dia'
                          : offset < 0
                            ? `${Math.abs(offset)}d antes`
                            : `${offset}d depois`}
                      </button>
                    )
                  })}
                </div>
                {invalid ? (
                  <p className="text-xs text-danger" role="alert">
                    {(fieldError('custom_offsets') ?? []).join(' ')}
                  </p>
                ) : null}
              </div>
            )}
          </Field>
        ) : null}

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Avisar quantos dias antes" hint="0 avisa no próprio dia da tarefa.">
            {({ id, describedBy }) => (
              <Input
                id={id}
                name="advance_notice_days"
                type="number"
                min={0}
                max={365}
                value={form.advanceNoticeDays}
                aria-describedby={describedBy}
                onChange={(event) => update('advanceNoticeDays', Number(event.target.value) || 0)}
              />
            )}
          </Field>

          <Field label="Horário do aviso" hint="Vazio usa o horário da residência.">
            {({ id, describedBy }) => (
              <Input
                id={id}
                name="preferred_hour"
                type="time"
                value={form.preferredHour}
                aria-describedby={describedBy}
                onChange={(event) => update('preferredHour', event.target.value)}
              />
            )}
          </Field>
        </div>

        <DatePicker
          label="Simular a partir de"
          value={form.base}
          onChange={(value) => update('base', value)}
          max={todayInputValue()}
        />

        <label className="flex items-center gap-2 text-sm text-ink">
          <input
            type="checkbox"
            name="is_active"
            checked={form.isActive}
            onChange={(event) => update('isActive', event.target.checked)}
            className="size-4 accent-brand"
          />
          Regra ativa
        </label>

        <PreviewPanel
          result={preview.data ?? null}
          pending={preview.isPending}
          error={preview.error instanceof ApiError ? preview.error.message : null}
          isOpen={preview.isSuccess}
        />
      </form>
    </Modal>
  )
}

function PreviewPanel({
  result,
  pending,
  error,
  isOpen,
}: {
  result: { scheduled_for: string | null; next_due_at: string | null; timezone: string } | null
  pending: boolean
  error: string | null
  isOpen: boolean
}) {
  if (!isOpen && !pending && !error) return null

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="trigger-preview"
      className="rounded-control border border-brand/40 bg-brand/10 px-3 py-3"
    >
      <p className="text-xs font-medium text-brand">Próxima data</p>
      {pending ? (
        <p className="mt-1 text-sm text-ink-muted">Calculando com o calendário da residência...</p>
      ) : error ? (
        <p className="mt-1 text-sm text-danger">{error}</p>
      ) : result ? (
        result.scheduled_for ? (
          <p className="mt-1 text-sm text-ink">
            <strong className="font-semibold">{formatDayLong(result.scheduled_for)}</strong>
            {result.next_due_at ? (
              <span className="text-ink-muted"> — {formatInstant(result.next_due_at)}</span>
            ) : null}
            <span className="mt-1 block text-xs text-ink-subtle">
              Fuso {result.timezone}. É a mesma data que o calendário vai assumir.
            </span>
          </p>
        ) : (
          <p className="mt-1 text-sm text-ink-muted">
            Ainda não há data: esta regra só ganha a próxima data depois do primeiro check-in.
          </p>
        )
      ) : null}
    </div>
  )
}

/** Valor de qualquer campo de tipo, sem discrimination — o ref é por nome de campo. */
type RememberedTypeFields = Record<string, TriggerTypeFieldSet[keyof TriggerTypeFieldSet]>

function isTypeField(field: keyof TriggerFormState): field is keyof TriggerTypeFieldSet {
  return (
    field === 'interval_value' ||
    field === 'interval_unit' ||
    field === 'day_of_month' ||
    field === 'recalculate_base' ||
    field === 'custom_offsets'
  )
}

/**
 * O motor prende o dia ao último dia do mês: regra de dia 31 em fevereiro vira
 * dia 29, não 3 de março. Dizer isso na tela evita o morador de achar que o
 * calendário errou.
 */
function clampedDayInFebruary(dayOfMonth: number): string {
  return dayOfMonth > 29 ? `dia 28 ou 29, não dia ${dayOfMonth}` : `dia ${dayOfMonth}`
}
