import { describe, expect, it } from 'vitest'
import {
  daysBetween,
  describeDue,
  formatDayLong,
  formatDayShort,
  formatInstant,
  instantToInputValue,
  shiftInputValue,
  toDateInputValue,
  todayInputValue,
} from './datetime'

describe('dias de calendário', () => {
  it('formata sem passar por UTC — o bug do dia volando um', () => {
    // `new Date('2026-10-15')` é meia-noite UTC e em São Paulo seria 14/10
    expect(formatDayShort('2026-10-15')).toBe('15/10')
    expect(formatDayLong('2026-10-15')).toBe('15 de outubro de 2026')
  })

  it('devolve travessão quando não há data', () => {
    expect(formatDayShort(null)).toBe('—')
    expect(formatDayLong(null)).toBe('—')
  })
})

describe('instantes', () => {
  it('formata dia e hora no fuso do aparelho', () => {
    const instant = new Date(2026, 9, 15, 9, 0)
    expect(formatInstant(instant.toISOString())).toBe('15/10 às 09:00')
  })

  it('lê o dia local do instante, não o dia UTC', () => {
    const instant = new Date(2026, 0, 1, 23, 30)
    expect(instantToInputValue(instant.toISOString())).toBe('2026-01-01')
  })

  it('não quebra com data inválida', () => {
    expect(formatInstant('lixo')).toBe('—')
    expect(instantToInputValue('lixo')).toBe('')
  })
})

describe('aritmética de dia', () => {
  it('soma dias sem atravessar a virada de fuso', () => {
    const start = todayInputValue()
    expect(daysBetween(start, shiftInputValue(start, 30))).toBe(30)
    expect(daysBetween(start, shiftInputValue(start, -30))).toBe(-30)
  })

  it('atravessa a virada do mês e do ano', () => {
    expect(shiftInputValue('2026-12-20', 30)).toBe('2027-01-19')
    expect(shiftInputValue('2026-03-01', -1)).toBe('2026-02-28')
  })

  it('formata a data local sem UTC', () => {
    expect(toDateInputValue(new Date(2026, 3, 1))).toBe('2026-04-01')
  })
})

describe('describeDue', () => {
  const today = todayInputValue()

  it('trata o que vence hoje como hoje, e não como restante de horas', () => {
    expect(describeDue(today)).toEqual({ label: 'Hoje', tone: 'today' })
  })

  it('distingue ontem do atraso de vários dias', () => {
    expect(describeDue(shiftInputValue(today, -1))).toEqual({
      label: 'Atrasado há 1 dia',
      tone: 'overdue',
    })
    expect(describeDue(shiftInputValue(today, -5)).label).toBe('Atrasado há 5 dias')
  })

  it('trata amanhã e a semana como próximos', () => {
    expect(describeDue(shiftInputValue(today, 1))).toEqual({ label: 'Amanhã', tone: 'tomorrow' })
    expect(describeDue(shiftInputValue(today, 3))).toEqual({ label: 'Em 3 dias', tone: 'soon' })
  })

  it('além da semana mostra a data em vez de contar dias', () => {
    expect(describeDue(shiftInputValue(today, 40)).label).toMatch(/\d{2}\/\d{2}/)
  })

  it('não quebra sem data de calendário', () => {
    expect(describeDue(null)).toEqual({ label: 'Sem data', tone: 'later' })
  })
})
