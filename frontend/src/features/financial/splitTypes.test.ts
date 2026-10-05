import { describe, expect, it } from 'vitest'
import {
  emptySplitEntry,
  emptySplitRule,
  entriesPayload,
  requiredFieldFor,
  splitRuleFromRule,
  validateSplitRule,
  type SplitRuleForm,
} from './splitTypes'
import type { SplitEntry, SplitMode } from '@/shared/api/types'

const USERS = ['u1', 'u2', 'u3']

function formWith(mode: SplitMode, patch: Partial<SplitRuleForm> = {}): SplitRuleForm {
  return { ...emptySplitRule(USERS), mode, ...patch }
}

describe('requiredFieldFor', () => {
  it('devolve o campo que cada regime exige', () => {
    expect(requiredFieldFor('WEIGHTED')).toBe('weight')
    expect(requiredFieldFor('PERCENT')).toBe('percent')
    expect(requiredFieldFor('CUSTOM')).toBe('fixed_amount')
  })

  it('não exige campo nenhum no regime igual', () => {
    expect(requiredFieldFor('EQUAL')).toBeNull()
  })
})

describe('entriesPayload', () => {
  it('EQUAL não manda campo de regime nenhum', () => {
    const form = formWith('EQUAL', {
      entries: USERS.map((id) => ({ ...emptySplitEntry(id), weight: 2, percent: 50 })),
    })

    expect(entriesPayload(form)).toEqual([{ user_id: 'u1' }, { user_id: 'u2' }, { user_id: 'u3' }])
  })

  it('WEIGHTED manda só o peso, mesmo com percentual e valor no estado', () => {
    const form = formWith('WEIGHTED', {
      entries: [
        { ...emptySplitEntry('u1'), weight: 2, percent: 50, fixed_amount: '10.00' },
        { ...emptySplitEntry('u2'), weight: 1 },
      ],
    })

    expect(entriesPayload(form)).toEqual([
      { user_id: 'u1', weight: 2 },
      { user_id: 'u2', weight: 1 },
    ])
  })

  it('PERCENT manda só o percentual', () => {
    const form = formWith('PERCENT', {
      entries: [
        { ...emptySplitEntry('u1'), percent: 60, weight: 3 },
        { ...emptySplitEntry('u2'), percent: 40 },
      ],
    })

    expect(entriesPayload(form)).toEqual([
      { user_id: 'u1', percent: 60 },
      { user_id: 'u2', percent: 40 },
    ])
  })

  it('CUSTOM manda o valor como texto decimal, não number', () => {
    const form = formWith('CUSTOM', {
      entries: [{ ...emptySplitEntry('u1'), fixed_amount: '120.50' }],
    })

    const [entry] = entriesPayload(form)
    expect(entry).toEqual({ user_id: 'u1', fixed_amount: '120.50' })
    expect(typeof entry?.fixed_amount).toBe('string')
  })

  it('não manda o campo que ficou em branco', () => {
    const form = formWith('WEIGHTED', {
      entries: [{ ...emptySplitEntry('u1'), weight: null }],
    })

    expect(entriesPayload(form)).toEqual([{ user_id: 'u1' }])
  })

  it('trocar de regime limpa o campo anterior — round-trip sem campo proibido', () => {
    const weighted = formWith('WEIGHTED', {
      entries: USERS.map((id, index) => ({ ...emptySplitEntry(id), weight: index + 1 })),
    })
    // o mesmo estado de formulário, com o regime trocado e os campos zerados:
    // é o que o builder faz ao trocar o select, e o que evita o 422 prohibited
    const switched: SplitRuleForm = {
      ...weighted,
      mode: 'EQUAL',
      entries: weighted.entries.map((entry) => ({
        ...entry,
        weight: null,
        percent: null,
        fixed_amount: null,
      })),
    }

    expect(entriesPayload(switched)).toEqual(USERS.map((user_id) => ({ user_id })))
  })
})

describe('splitRuleFromRule', () => {
  it('carrega o campo do regime gravado e deixa os outros em null', () => {
    const entries: SplitEntry[] = [
      {
        id: 'e1',
        user_id: 'u1',
        user_name: 'Ana',
        weight: 2,
        percent: null,
        fixed_amount: null,
      },
    ]

    const form = splitRuleFromRule({ mode: 'WEIGHTED', is_active: true, entries })

    expect(form.entries[0]).toEqual({
      user_id: 'u1',
      weight: 2,
      percent: null,
      fixed_amount: null,
    })
  })
})

describe('validateSplitRule', () => {
  it('aceita regra igual com os moradores escolhidos', () => {
    expect(validateSplitRule(formWith('EQUAL'))).toEqual({})
  })

  it('recusa regra sem ninguém escolhido', () => {
    expect(validateSplitRule({ ...formWith('EQUAL'), entries: [] })).toEqual({
      entries: ['Escolha quem participa da divisão.'],
    })
  })

  it('aponta o campo de cada participante que ficou em branco', () => {
    const form = formWith('WEIGHTED', {
      entries: [
        { ...emptySplitEntry('u1'), weight: 1 },
        emptySplitEntry('u2'),
        { ...emptySplitEntry('u3'), weight: 2 },
      ],
    })

    expect(validateSplitRule(form)).toEqual({
      'entries.1.weight': ['Preencha o valor desta pessoa.'],
    })
  })

  it('exige que os percentuais fechem em 100', () => {
    const form = formWith('PERCENT', {
      entries: [
        { ...emptySplitEntry('u1'), percent: 50 },
        { ...emptySplitEntry('u2'), percent: 30 },
        { ...emptySplitEntry('u3'), percent: 10 },
      ],
    })

    expect(validateSplitRule(form)).toEqual({
      entries: ['Os percentuais somam 90%. Ajuste para fechar em 100%.'],
    })
  })

  it('aceita percentuais que fecham em 100 com sobra de centavo', () => {
    const form = formWith('PERCENT', {
      entries: [
        { ...emptySplitEntry('u1'), percent: 33.33 },
        { ...emptySplitEntry('u2'), percent: 33.33 },
        { ...emptySplitEntry('u3'), percent: 33.34 },
      ],
    })

    expect(validateSplitRule(form)).toEqual({})
  })

  it('não exige soma em CUSTOM — quem fecha com o total é o servidor', () => {
    const form = formWith('CUSTOM', {
      entries: [{ ...emptySplitEntry('u1'), fixed_amount: '10.00' }],
    })

    expect(validateSplitRule(form)).toEqual({})
  })
})
