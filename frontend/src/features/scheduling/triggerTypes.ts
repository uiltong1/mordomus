import type { IntervalUnit, RecalculateBase, TriggerConfig, TriggerType } from '@/shared/api/types'
import type { TriggerConfigInput, TriggerPreviewInput, TriggerTypeFields } from './api'

/**
 * Os quatro jeitos de repetir uma manutenção, escritos para o morador ler.
 *
 * O backend valida por tipo: cada campo que **não** pertence ao tipo escolhido
 * é `prohibited` e derruba o `POST` com 422. A descrição aqui não é enfeite —
 * é o que permite a tela escolher o tipo certo sem o morador tropeçar num
 * campo recusado.
 */
export interface TriggerTypeOption {
  value: TriggerType
  label: string
  description: string
  /** Frase de exemplo do formulário já preenchido com o tipo certo. */
  example: string
}

export const TRIGGER_TYPE_OPTIONS: readonly TriggerTypeOption[] = [
  {
    value: 'INTERVAL',
    label: 'Repetir a cada X dias',
    description:
      'O ciclo recomeça X dias depois do dia anterior. Serve para o que se repete em linha reta: limpar o filtro, conferir o medidor de energia.',
    example: 'A cada 30 dias',
  },
  {
    value: 'CALENDAR_MONTHLY',
    label: 'Todo dia fixo do mês',
    description:
      'Acontece sempre no mesmo dia do mês, mesmo que passe muito tempo sem ser feito. Serve para o que não pode escorregar de dia.',
    example: 'Todo dia 10 do mês',
  },
  {
    value: 'POST_COMPLETION',
    label: 'X dias depois de eu fazer',
    description:
      'A contagem só começa quando você marca como feito. Serve para o que deve ficar colado na última troca: filtro novo, produto que dura X usos.',
    example: '15 dias depois de trocar',
  },
  {
    value: 'ESCALATED',
    label: 'Repetir a cada X com avisos extras',
    description:
      'Como "a cada X", mas com vários avisos: um antes, um no dia e um depois. Serve para o que não pode passar batido.',
    example: 'A cada 90 dias, avisando 7 dias antes e 7 dias depois',
  },
] as const

/**
 * Campos de tipo que cada configuração exige. Espelha `TriggerTypeRules::requiredByType`
 * do monólito — e o espelho é literal de propósito: um campo a mais aqui vira um
 * 422 que o morador lê como "o aplicativo quebrou".
 */
const FIELDS_BY_TYPE: Record<TriggerType, readonly (keyof TriggerTypeFieldSet)[]> = {
  INTERVAL: ['interval_value', 'interval_unit'],
  CALENDAR_MONTHLY: ['day_of_month'],
  POST_COMPLETION: ['interval_value', 'interval_unit', 'recalculate_base'],
  ESCALATED: ['interval_value', 'interval_unit', 'custom_offsets'],
}

export interface TriggerTypeFieldSet {
  interval_value: number | null
  interval_unit: IntervalUnit | null
  day_of_month: number | null
  recalculate_base: RecalculateBase | null
  custom_offsets: number[]
}

/** Estado do formulário. Espelha o payload, mas guarda `null` no que não foi preenchido. */
export interface TriggerFormState extends TriggerTypeFieldSet {
  title: string
  description: string
  isActive: boolean
  advanceNoticeDays: number
  preferredHour: string
  type: TriggerType
  /** Âncora do ciclo para o preview ("e se o último foi dia X?"). */
  base: string
}

export function emptyTriggerForm(): TriggerFormState {
  return {
    title: '',
    description: '',
    isActive: true,
    advanceNoticeDays: 0,
    preferredHour: '09:00',
    type: 'INTERVAL',
    interval_value: 30,
    interval_unit: 'days',
    day_of_month: null,
    recalculate_base: null,
    custom_offsets: [],
    base: '',
  }
}

export function triggerFormFrom(config: TriggerConfig): TriggerFormState {
  return {
    title: config.title,
    description: config.description ?? '',
    isActive: config.is_active,
    advanceNoticeDays: config.advance_notice_days,
    preferredHour: config.preferred_hour ?? '09:00',
    type: config.type,
    interval_value: config.interval_value,
    interval_unit: config.interval_unit,
    day_of_month: config.day_of_month,
    recalculate_base: config.recalculate_base,
    custom_offsets: config.custom_offsets ?? [],
    base: config.last_base_date ?? '',
  }
}

/** Campos que o tipo escolhido realmente usa — o resto nem entra no payload. */
export function fieldsForType(type: TriggerType): readonly (keyof TriggerTypeFieldSet)[] {
  return FIELDS_BY_TYPE[type]
}

/**
 * Monta o payload de tipo descartando o que o tipo escolhido proíbe.
 *
 * É o ponto onde a maior parte dos 422 desta tela nasce: o formulário guarda o
 * `day_of_month` que o morador digitou num tipo mensal, troca para "a cada 30
 * dias" e o campo continua no estado. Sem o corte aqui, o backend responde
 * "The day of month field is prohibited" para um formulário que está certo.
 */
export function typeFieldsPayload(form: TriggerFormState): TriggerTypeFields {
  const allowed = FIELDS_BY_TYPE[form.type]
  const payload: TriggerTypeFields = {}

  if (allowed.includes('interval_value') && form.interval_value !== null) {
    payload.interval_value = form.interval_value
  }
  if (allowed.includes('interval_unit') && form.interval_unit !== null) {
    payload.interval_unit = form.interval_unit
  }
  if (allowed.includes('day_of_month') && form.day_of_month !== null) {
    payload.day_of_month = form.day_of_month
  }
  if (allowed.includes('recalculate_base') && form.recalculate_base !== null) {
    payload.recalculate_base = form.recalculate_base
  }
  if (allowed.includes('custom_offsets') && form.custom_offsets.length > 0) {
    payload.custom_offsets = [...form.custom_offsets]
  }

  return payload
}

/**
 * Recusa local antes do round-trip, com a mesma severidade do backend.
 *
 * `scope: 'preview'` valida só os campos de tipo: a data que o morador quer ver
 * não depende do nome da regra, e exigir o nome antes de mostrar a data
 * transformaria o botão "ver quando cai" em mais uma etapa. O `scope: 'save'`
 * valida tudo, porque o backend exige o título.
 */
export function validateTriggerForm(
  form: TriggerFormState,
  scope: 'save' | 'preview' = 'save',
): Record<string, string[]> {
  const errors: Record<string, string[]> = {}

  if (scope === 'save') {
    if (form.title.trim().length === 0) {
      errors.title = ['Dê um nome para a regra.']
    } else if (form.title.trim().length > 160) {
      errors.title = ['O nome pode ter no máximo 160 caracteres.']
    }
  }

  const allowed = FIELDS_BY_TYPE[form.type]

  if (allowed.includes('interval_value')) {
    if (form.interval_value === null) {
      errors.interval_value = ['Informe de quanto em quanto tempo repetir.']
    } else if (form.interval_value < 1 || form.interval_value > 65535) {
      errors.interval_value = ['O intervalo precisa estar entre 1 e 65535.']
    }
  }

  if (allowed.includes('interval_unit') && form.interval_unit === null) {
    errors.interval_unit = ['Escolha dias, semanas ou meses.']
  }

  if (allowed.includes('day_of_month')) {
    if (form.day_of_month === null) {
      errors.day_of_month = ['Escolha o dia do mês.']
    } else if (form.day_of_month < 1 || form.day_of_month > 31) {
      errors.day_of_month = ['O dia precisa estar entre 1 e 31.']
    }
  }

  if (allowed.includes('recalculate_base') && form.recalculate_base === null) {
    errors.recalculate_base = ['Escolha a partir de quando contar.']
  }

  if (allowed.includes('custom_offsets') && form.custom_offsets.length === 0) {
    errors.custom_offsets = ['Adicione ao menos um dia de aviso.']
  }

  return errors
}

/** Payload de `POST /trigger-configs` e do `PATCH`. */
export function buildConfigPayload(form: TriggerFormState): TriggerConfigInput {
  return {
    type: form.type,
    title: form.title.trim(),
    description: form.description.trim() === '' ? null : form.description.trim(),
    is_active: form.isActive,
    advance_notice_days: form.advanceNoticeDays,
    preferred_hour: form.preferredHour === '' ? null : form.preferredHour,
    ...typeFieldsPayload(form),
  }
}

/**
 * Payload de `POST /preview`. Mesmo corte de campos proibidos do `buildConfigPayload`
 * — a validação de tipo é a mesma trait no backend, e o preview não é exceção.
 */
export function buildPreviewPayload(form: TriggerFormState): TriggerPreviewInput {
  return {
    type: form.type,
    base: form.base === '' ? null : form.base,
    preferred_hour: form.preferredHour === '' ? null : form.preferredHour,
    ...typeFieldsPayload(form),
  }
}

/** Texto curto da regra, para o card e para o item da agenda. */
export function describeTriggerType(type: TriggerType): string {
  return TRIGGER_TYPE_OPTIONS.find((option) => option.value === type)?.label ?? type
}

/**
 * "A cada 30 dias" / "Todo dia 10 do mês". O rótulo de 30 dias que o morador
 * digita precisa reaparecer na agenda: sem ele, um `INTERVAL` de 30 e um de 15
 * ficariam idênticos na lista.
 */
export function summarizeTrigger(config: {
  type: TriggerType
  interval_value: number | null
  interval_unit: IntervalUnit | null
  day_of_month: number | null
  custom_offsets: number[] | null
}): string {
  switch (config.type) {
    case 'INTERVAL':
      return config.interval_value && config.interval_unit
        ? `A cada ${config.interval_value} ${unitLabel(config.interval_unit)}`
        : 'Intervalo incompleto'
    case 'CALENDAR_MONTHLY':
      return config.day_of_month
        ? `Todo dia ${config.day_of_month} do mês`
        : 'Dia do mês não informado'
    case 'POST_COMPLETION':
      return config.interval_value && config.interval_unit
        ? `${config.interval_value} ${unitLabel(config.interval_unit)} depois de concluir`
        : 'Intervalo incompleto'
    case 'ESCALATED': {
      const base =
        config.interval_value && config.interval_unit
          ? `A cada ${config.interval_value} ${unitLabel(config.interval_unit)}`
          : 'Intervalo incompleto'
      const offsets = describeOffsets(config.custom_offsets)
      return offsets ? `${base}, avisando ${offsets}` : base
    }
  }
}

function unitLabel(unit: IntervalUnit): string {
  return unit === 'days' ? 'dias' : unit === 'weeks' ? 'semanas' : 'meses'
}

function describeOffsets(offsets: number[] | null): string {
  if (!offsets || offsets.length === 0) return ''
  return offsets
    .map((offset) =>
      offset === 0 ? 'no dia' : offset < 0 ? `${Math.abs(offset)}d antes` : `${offset}d depois`,
    )
    .join(', ')
}
