import { describe, expect, it } from 'vitest'
import { formatAmount, formatMoney } from './money'

describe('formatMoney', () => {
  it('formata texto decimal sem passar por float', () => {
    expect(formatMoney('1234.50')).toBe('R$ 1.234,50')
  })

  it('completa a centena que veio com uma casa só', () => {
    expect(formatMoney('10.5')).toBe('R$ 10,50')
    expect(formatMoney('10')).toBe('R$ 10,00')
  })

  it('agrupa os milhares do inteiro', () => {
    expect(formatMoney('1000000.00')).toBe('R$ 1.000.000,00')
  })

  it('mantém o sinal do negativo na posição que o real escreve', () => {
    expect(formatMoney('-45.90')).toBe('R$ -45,90')
  })

  it('preserva os centavos que o float perderia', () => {
    // 0.1 + 0.2 em binário é 0.30000000000000004: o texto do backend é 0.30 e
    // é ele que precisa aparecer na tela (regra R5)
    expect(formatMoney('0.30')).toBe('R$ 0,30')
    expect(formatMoney('0.07')).toBe('R$ 0,07')
    expect(formatMoney('0.01')).toBe('R$ 0,01')
  })

  it('devolve o traço para valor ausente e o texto cru para o inesperado', () => {
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney(undefined)).toBe('—')
    expect(formatMoney('')).toBe('—')
    expect(formatMoney('sem valor')).toBe('sem valor')
  })

  it('aceita outra moeda sem símbolo de real', () => {
    expect(formatMoney('1234.50', 'USD')).toBe('USD 1.234,50')
  })
})

describe('formatAmount', () => {
  it('formata sem o símbolo, para linha de tabela', () => {
    expect(formatAmount('1234.50')).toBe('1.234,50')
  })

  it('trata ausente e inesperado como no formatMoney', () => {
    expect(formatAmount(null)).toBe('—')
    expect(formatAmount('abc')).toBe('abc')
  })
})
