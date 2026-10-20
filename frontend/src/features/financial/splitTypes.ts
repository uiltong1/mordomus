import type { SplitEntry, SplitMode } from '@/shared/api/types'
import type { SplitEntryInput } from './api'

export interface SplitModeOption {
  value: SplitMode
  label: string
  description: string
  fieldLabel: string | null
}

export const SPLIT_MODE_OPTIONS: readonly SplitModeOption[] = [
  {
    value: 'EQUAL',
    label: 'Todos igual',
    description: 'A conta divide em partes iguais entre quem está na regra.',
    fieldLabel: null,
  },
  {
    value: 'WEIGHTED',
    label: 'Por peso',
    description:
      'Cada morador tem um peso e a parte é proporcional a ele. Serve para quem mora mais espaço ou ocupa mais cômodo.',
    fieldLabel: 'Peso',
  },
  {
    value: 'PERCENT',
    label: 'Por percentual',
    description: 'Cada morador recebe uma porcentagem escrita à mão. Os percentuais somam 100.',
    fieldLabel: 'Percentual',
  },
  {
    value: 'CUSTOM',
    label: 'Valores fixos',
    description: 'Cada morador tem um valor próprio e a soma precisa fechar com o total da conta.',
    fieldLabel: 'Valor',
  },
] as const

const REQUIRED_FIELD_BY_MODE: Partial<Record<SplitMode, RequiredField>> = {
  WEIGHTED: 'weight',
  PERCENT: 'percent',
  CUSTOM: 'fixed_amount',
}

export type RequiredField = 'weight' | 'percent' | 'fixed_amount'

export interface SplitEntryForm {
  user_id: string
  weight: number | null
  percent: number | null
  fixed_amount: string | null
}

export interface SplitRuleForm {
  mode: SplitMode
  isActive: boolean
  entries: SplitEntryForm[]
}

export function emptySplitEntry(userId: string): SplitEntryForm {
  return { user_id: userId, weight: null, percent: null, fixed_amount: null }
}

export function emptySplitRule(userIds: readonly string[]): SplitRuleForm {
  return {
    mode: 'EQUAL',
    isActive: true,
    entries: userIds.map(emptySplitEntry),
  }
}

export function splitRuleFromRule(rule: {
  mode: SplitMode
  is_active: boolean
  entries: SplitEntry[]
}): SplitRuleForm {
  return {
    mode: rule.mode,
    isActive: rule.is_active,
    entries: rule.entries.map((entry) => ({
      user_id: entry.user_id,
      weight: entry.weight,
      percent: entry.percent,
      fixed_amount: entry.fixed_amount,
    })),
  }
}

export function requiredFieldFor(mode: SplitMode): RequiredField | null {
  return REQUIRED_FIELD_BY_MODE[mode] ?? null
}

export function splitModeOption(mode: SplitMode): SplitModeOption {
  return SPLIT_MODE_OPTIONS.find((option) => option.value === mode) ?? SPLIT_MODE_OPTIONS[0]!
}

export function entryFieldsFor(mode: SplitMode): readonly RequiredField[] {
  const field = requiredFieldFor(mode)
  return field ? [field] : []
}

export function entriesPayload(form: SplitRuleForm): SplitEntryInput[] {
  const allowed = entryFieldsFor(form.mode)

  return form.entries.map((entry) => {
    const payload: SplitEntryInput = { user_id: entry.user_id }
    if (allowed.includes('weight') && entry.weight !== null) payload.weight = entry.weight
    if (allowed.includes('percent') && entry.percent !== null) payload.percent = entry.percent
    if (allowed.includes('fixed_amount') && entry.fixed_amount !== null) {
      payload.fixed_amount = entry.fixed_amount
    }
    return payload
  })
}

export function validateSplitRule(form: SplitRuleForm): Record<string, string[]> {
  const errors: Record<string, string[]> = {}

  if (form.entries.length === 0) {
    errors.entries = ['Escolha quem participa da divisão.']
    return errors
  }

  const field = requiredFieldFor(form.mode)

  if (field) {
    form.entries.forEach((entry, index) => {
      const value = entry[field]
      if (value === null || value === '') {
        errors[`entries.${index}.${field}`] = ['Preencha o valor desta pessoa.']
      }
    })
  }

  if (form.mode === 'PERCENT') {
    const sum = form.entries.reduce((total, entry) => total + (entry.percent ?? 0), 0)
    if (Math.abs(sum - 100) > 0.01) {
      errors.entries = [`Os percentuais somam ${trimNumber(sum)}%. Ajuste para fechar em 100%.`]
    }
  }

  return errors
}

function trimNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)))
}
