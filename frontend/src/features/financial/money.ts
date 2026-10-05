/**
 * Montante em texto decimal ("1234.50") para a moeda da tela.
 *
 * O valor chega do backend como texto e é formatado **sem** passar por `Number`:
 * a regra R5 fecha a soma em centavos e o binário de ponto flutuante erraria —
 * `0.1 + 0.2` viraria `R$ 0,30` num total que o servidor somou como `0.30`. A
 * formatação é por texto: agrupa o inteiro e troca o separador.
 */

/** Aceita o que o backend emite: inteiro com duas casas opcionais, sinal opcional. */
const DECIMAL = /^-?\d+(?:\.\d{1,2})?$/

const THOUSANDS = /\B(?=(\d{3})+(?!\d))/g

interface Parts {
  negative: boolean
  integer: string
  cents: string
}

function split(amount: string): Parts | null {
  const text = amount.trim()
  if (!DECIMAL.test(text)) return null

  const negative = text.startsWith('-')
  const [integer = '0', fraction = ''] = text.replace('-', '').split('.')

  return { negative, integer, cents: fraction.padEnd(2, '0') }
}

/** `R$ 1.234,50` — para cartão e total de destaque. */
export function formatMoney(amount: string | null | undefined, currency = 'BRL'): string {
  if (amount === null || amount === undefined || amount === '') return '—'

  const parts = split(String(amount))
  if (!parts) return String(amount).trim()

  const grouped = parts.integer.replace(THOUSANDS, '.')
  const value = `${parts.negative ? '-' : ''}${grouped},${parts.cents}`

  return currency === 'BRL' ? `R$ ${value}` : `${currency} ${value}`
}

/** `1.234,50` sem símbolo — para linha de tabela, onde o R$ se repete à toa. */
export function formatAmount(amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === '') return '—'

  const parts = split(String(amount))
  if (!parts) return String(amount).trim()

  return `${parts.negative ? '-' : ''}${parts.integer.replace(THOUSANDS, '.')},${parts.cents}`
}
