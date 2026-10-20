const DAY_MONTH = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' })

const DAY_MONTH_YEAR = new Intl.DateTimeFormat('pt-BR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

const DAY_MONTH_LONG = new Intl.DateTimeFormat('pt-BR', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

const DAY_LONG_WEEKDAY = new Intl.DateTimeFormat('pt-BR', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
})

const TIME = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' })

export function toDateInputValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function todayInputValue(): string {
  return toDateInputValue(new Date())
}

export function shiftInputValue(value: string, days: number): string {
  const [year, month, day] = value.split('-').map(Number)
  const base = new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1)
  base.setDate(base.getDate() + days)
  return toDateInputValue(base)
}

export function daysBetween(from: string, to: string): number {
  const [fy, fm, fd] = from.split('-').map(Number)
  const [ty, tm, td] = to.split('-').map(Number)
  const start = new Date(fy ?? 1970, (fm ?? 1) - 1, fd ?? 1).getTime()
  const end = new Date(ty ?? 1970, (tm ?? 1) - 1, td ?? 1).getTime()
  return Math.round((end - start) / 86_400_000)
}

function parts(value: string): { year: number; month: number; day: number } {
  const [year, month, day] = value.split('-').map(Number)
  return { year: year ?? 1970, month: (month ?? 1) - 1, day: day ?? 1 }
}

export function parseCalendarDay(value: string): Date {
  const { year, month, day } = parts(value)
  return new Date(year, month, day)
}

export function formatDayShort(iso: string | null): string {
  if (!iso) return '—'
  const { year, month, day } = parts(iso)
  return DAY_MONTH.format(new Date(year, month, day))
}

export function formatDayNumeric(iso: string | null): string {
  if (!iso) return '—'
  const { year, month, day } = parts(iso)
  return DAY_MONTH_YEAR.format(new Date(year, month, day))
}

export function formatDayLong(iso: string | null): string {
  if (!iso) return '—'
  const { year, month, day } = parts(iso)
  return DAY_MONTH_LONG.format(new Date(year, month, day))
}

export function formatDayHeading(iso: string | null): string {
  if (!iso) return 'Sem data'
  const { year, month, day } = parts(iso)
  const formatted = DAY_LONG_WEEKDAY.format(new Date(year, month, day))
  return formatted.charAt(0).toUpperCase() + formatted.slice(1)
}

export function formatTime(iso: string | null): string {
  if (!iso) return '—'
  const instant = new Date(iso)
  return Number.isNaN(instant.getTime()) ? '—' : TIME.format(instant)
}

export function formatInstant(iso: string | null): string {
  if (!iso) return '—'
  const instant = new Date(iso)
  if (Number.isNaN(instant.getTime())) return '—'
  const day = new Date(instant.getFullYear(), instant.getMonth(), instant.getDate())
  return `${DAY_MONTH.format(day)} às ${TIME.format(instant)}`
}

export function instantToInputValue(iso: string | null): string {
  if (!iso) return ''
  const instant = new Date(iso)
  return Number.isNaN(instant.getTime()) ? '' : toDateInputValue(instant)
}

export type DueTone = 'overdue' | 'today' | 'tomorrow' | 'soon' | 'later' | 'done'

export interface DueDescription {
  label: string
  tone: DueTone
}

export function describeDue(scheduledFor: string | null): DueDescription {
  if (!scheduledFor) return { label: 'Sem data', tone: 'later' }

  const offset = daysBetween(todayInputValue(), scheduledFor)

  if (offset < 0) {
    const days = Math.abs(offset)
    return { label: days === 1 ? 'Atrasado há 1 dia' : `Atrasado há ${days} dias`, tone: 'overdue' }
  }
  if (offset === 0) return { label: 'Hoje', tone: 'today' }
  if (offset === 1) return { label: 'Amanhã', tone: 'tomorrow' }
  if (offset <= 7) return { label: `Em ${offset} dias`, tone: 'soon' }
  return { label: formatDayShort(scheduledFor), tone: 'later' }
}

export function describeClosedDue(scheduledFor: string | null): DueDescription {
  if (!scheduledFor) return { label: 'Sem data', tone: 'done' }
  const offset = daysBetween(todayInputValue(), scheduledFor)
  if (offset === 0) return { label: 'Hoje', tone: 'done' }
  if (offset === 1) return { label: 'Amanhã', tone: 'done' }
  return { label: formatDayShort(scheduledFor), tone: 'done' }
}
