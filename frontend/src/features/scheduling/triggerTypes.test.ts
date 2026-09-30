import { describe, expect, it } from 'vitest'
import {
  buildConfigPayload,
  buildPreviewPayload,
  emptyTriggerForm,
  fieldsForType,
  summarizeTrigger,
  triggerFormFrom,
  validateTriggerForm,
  type TriggerFormState,
} from './triggerTypes'
import type { TriggerConfig } from '@/shared/api/types'

function form(overrides: Partial<TriggerFormState> = {}): TriggerFormState {
  return { ...emptyTriggerForm(), ...overrides }
}

describe('campos de cada tipo', () => {
  it('INTERVAL exige intervalo e proíbe dia do mês', () => {
    expect(fieldsForType('INTERVAL')).toEqual(['interval_value', 'interval_unit'])
  })

  it('CALENDAR_MONTHLY só exige o dia', () => {
    expect(fieldsForType('CALENDAR_MONTHLY')).toEqual(['day_of_month'])
  })

  it('POST_COMPLETION exige também de onde contar', () => {
    expect(fieldsForType('POST_COMPLETION')).toEqual([
      'interval_value',
      'interval_unit',
      'recalculate_base',
    ])
  })

  it('ESCALATED exige os offsets de aviso', () => {
    expect(fieldsForType('ESCALATED')).toEqual([
      'interval_value',
      'interval_unit',
      'custom_offsets',
    ])
  })
})

describe('typeFieldsPayload', () => {
  /**
   * O 422 mais comum desta tela nasce aqui: o formulário guarda o `day_of_month`
   * que o morador digitou e troca de tipo. O campo proibido não pode viajar no
   * payload, ou o backend recusa com "prohibited".
   */
  it('descarta o dia do mês quando o tipo passa a ser intervalo', () => {
    const payload = buildConfigPayload(
      form({ type: 'INTERVAL', day_of_month: 15, interval_value: 30, interval_unit: 'days' }),
    )
    expect(payload).not.toHaveProperty('day_of_month')
    expect(payload).toMatchObject({ interval_value: 30, interval_unit: 'days' })
  })

  it('descarta o intervalo quando o tipo passa a ser dia fixo do mês', () => {
    const payload = buildConfigPayload(
      form({
        type: 'CALENDAR_MONTHLY',
        interval_value: 30,
        interval_unit: 'days',
        day_of_month: 10,
      }),
    )
    expect(payload).not.toHaveProperty('interval_value')
    expect(payload).not.toHaveProperty('interval_unit')
    expect(payload).toMatchObject({ day_of_month: 10 })
  })

  it('não manda campo vazio como null', () => {
    const payload = buildConfigPayload(
      form({ type: 'ESCALATED', interval_value: 90, interval_unit: 'days', custom_offsets: [-7] }),
    )
    expect(payload).not.toHaveProperty('recalculate_base')
    expect(payload).not.toHaveProperty('day_of_month')
  })

  it('leva a âncora só no payload de preview', () => {
    const base = form({ title: 'Regra', base: '2026-01-15' })
    expect(buildPreviewPayload(base).base).toBe('2026-01-15')
    expect(buildConfigPayload(base)).not.toHaveProperty('base')
  })

  it('descarta a âncora vazia em vez de mandar string vazia', () => {
    expect(buildPreviewPayload(form({ base: '' })).base).toBeNull()
  })
})

describe('validateTriggerForm', () => {
  it('aceita o caminho de 30 dias sem nenhum ajuste', () => {
    expect(
      validateTriggerForm(
        form({ title: 'Limpar o filtro', interval_value: 30, interval_unit: 'days' }),
      ),
    ).toEqual({})
  })

  it('exige o nome da regra', () => {
    expect(validateTriggerForm(form({ title: '   ' }))).toHaveProperty('title')
  })

  it('exige a base de contagem do POST_COMPLETION', () => {
    const errors = validateTriggerForm(
      form({ type: 'POST_COMPLETION', interval_value: 15, interval_unit: 'days' }),
    )
    expect(errors).toHaveProperty('recalculate_base')
  })

  it('exige ao menos um aviso no ESCALATED', () => {
    const errors = validateTriggerForm(
      form({ type: 'ESCALATED', interval_value: 90, interval_unit: 'days', custom_offsets: [] }),
    )
    expect(errors).toHaveProperty('custom_offsets')
  })

  it('recusa intervalo fora da faixa do backend', () => {
    expect(validateTriggerForm(form({ interval_value: 0 }))).toHaveProperty('interval_value')
    expect(validateTriggerForm(form({ interval_value: 70000 }))).toHaveProperty('interval_value')
  })

  it('recusa dia do mês fora de 1..31', () => {
    expect(
      validateTriggerForm(form({ type: 'CALENDAR_MONTHLY', day_of_month: 32 })),
    ).toHaveProperty('day_of_month')
  })
})

describe('triggerFormFrom', () => {
  const stored: TriggerConfig = {
    id: 'cfg-1',
    tenant_id: 't1',
    subject_type: 'asset',
    subject_id: 'a1',
    title: 'Trocar o filtro',
    description: 'o azul vira cinza',
    is_active: false,
    type: 'POST_COMPLETION',
    interval_value: 15,
    interval_unit: 'days',
    day_of_month: null,
    advance_notice_days: 2,
    recalculate_base: 'COMPLETION',
    custom_offsets: null,
    preferred_hour: '08:30',
    last_base_date: '2026-02-01',
    next_due_at: '2026-02-16T11:30:00-03:00',
    created_at: null,
    updated_at: null,
  }

  it('reidrata o formulário a partir da regra salva', () => {
    const rehydrated = triggerFormFrom(stored)
    expect(rehydrated.title).toBe('Trocar o filtro')
    expect(rehydrated.type).toBe('POST_COMPLETION')
    expect(rehydrated.recalculate_base).toBe('COMPLETION')
    expect(rehydrated.isActive).toBe(false)
    expect(rehydrated.base).toBe('2026-02-01')
  })

  it('reenvia só os campos do tipo, mesmo reidratando de uma regra completa', () => {
    const payload = buildConfigPayload(triggerFormFrom(stored))
    expect(payload).not.toHaveProperty('day_of_month')
    expect(payload).not.toHaveProperty('custom_offsets')
    expect(payload).toMatchObject({ interval_value: 15, recalculate_base: 'COMPLETION' })
  })
})

describe('summarizeTrigger', () => {
  it('escreve o intervalo em português', () => {
    expect(
      summarizeTrigger({
        type: 'INTERVAL',
        interval_value: 30,
        interval_unit: 'days',
        day_of_month: null,
        custom_offsets: null,
      }),
    ).toBe('A cada 30 dias')
  })

  it('distingue dia do mês de intervalo — os dois cardam igual sem isso', () => {
    expect(
      summarizeTrigger({
        type: 'CALENDAR_MONTHLY',
        interval_value: 30,
        interval_unit: 'days',
        day_of_month: 30,
        custom_offsets: null,
      }),
    ).toBe('Todo dia 30 do mês')
  })

  it('conta os offsets de aviso do ESCALATED', () => {
    expect(
      summarizeTrigger({
        type: 'ESCALATED',
        interval_value: 90,
        interval_unit: 'days',
        day_of_month: null,
        custom_offsets: [-7, 0, 7],
      }),
    ).toBe('A cada 90 dias, avisando 7d antes, no dia, 7d depois')
  })
})
