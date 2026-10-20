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

export function formatMoney(amount: string | null | undefined, currency = 'BRL'): string {
  if (amount === null || amount === undefined || amount === '') return '—'

  const parts = split(String(amount))
  if (!parts) return String(amount).trim()

  const grouped = parts.integer.replace(THOUSANDS, '.')
  const value = `${parts.negative ? '-' : ''}${grouped},${parts.cents}`

  return currency === 'BRL' ? `R$ ${value}` : `${currency} ${value}`
}

export function formatAmount(amount: string | null | undefined): string {
  if (amount === null || amount === undefined || amount === '') return '—'

  const parts = split(String(amount))
  if (!parts) return String(amount).trim()

  return `${parts.negative ? '-' : ''}${parts.integer.replace(THOUSANDS, '.')},${parts.cents}`
}
