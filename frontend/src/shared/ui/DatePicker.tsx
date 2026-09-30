import { useId } from 'react'
import { toDateInputValue } from '@/shared/datetime'

export interface DatePickerProps {
  label?: string
  value: string
  onChange: (value: string) => void
  min?: string
  max?: string
  error?: string
  disabled?: boolean
  id?: string
}

/** `YYYY-MM-DD` — o formato que o backend valida e que o `<input type="date">` usa. */
export const toApiDate = toDateInputValue

/**
 * Data em `YYYY-MM-DD`. É controlado de propósito: espelhar o prop em estado
 * local exigiria um efeito de sincronização, e o navegador já deixa o campo
 * vazio quando o morador apaga o que digitou.
 */
export function DatePicker({
  label,
  value,
  onChange,
  min,
  max,
  error,
  disabled = false,
  id,
}: DatePickerProps) {
  const fallbackId = useId()
  const fieldId = id ?? fallbackId

  return (
    <div className="space-y-1.5">
      {label ? (
        <label htmlFor={fieldId} className="block text-xs font-medium text-ink-muted">
          {label}
        </label>
      ) : null}
      <input
        id={fieldId}
        type="date"
        value={value}
        min={min}
        max={max}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${fieldId}-error` : undefined}
        className={[
          'w-full rounded-control border bg-surface-2 px-3 py-2 text-sm text-ink',
          'disabled:cursor-not-allowed disabled:opacity-50',
          error ? 'border-danger/60' : 'border-line',
        ].join(' ')}
      />
      {error ? (
        <p id={`${fieldId}-error`} role="alert" className="text-xs text-danger">
          {error}
        </p>
      ) : null}
    </div>
  )
}
