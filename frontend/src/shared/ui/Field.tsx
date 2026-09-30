import { useId, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react'

export interface FieldProps {
  label: string
  hint?: ReactNode
  /** Mensagens do `error.details` do backend (422 `validation_failed`). */
  errors?: string[]
  children: (props: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode
}

/**
 * Casca de campo: liga label, dica e erro ao controle por id, para que leitor
 * de tela anuncie o motivo da recusa e não só "campo inválido".
 */
export function Field({ label, hint, errors, children }: FieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const invalid = Boolean(errors?.length)
  const describedBy = [hint ? hintId : null, invalid ? errorId : null].filter(Boolean).join(' ')

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-xs font-medium text-ink-muted">
        {label}
      </label>
      {children({ id, describedBy: describedBy || undefined, invalid })}
      {hint ? (
        <p id={hintId} className="text-xs text-ink-subtle">
          {hint}
        </p>
      ) : null}
      {invalid ? (
        <p id={errorId} role="alert" className="text-xs text-danger">
          {errors!.join(' ')}
        </p>
      ) : null}
    </div>
  )
}

const CONTROL =
  'w-full rounded-control border bg-surface-2 px-3 py-2 text-sm text-ink placeholder:text-ink-subtle transition-colors'

export function Input({
  invalid,
  className = '',
  ...rest
}: InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }) {
  return (
    <input
      className={`${CONTROL} ${invalid ? 'border-danger/60' : 'border-line'} ${className}`}
      aria-invalid={invalid || undefined}
      {...rest}
    />
  )
}

export function Textarea({
  invalid,
  className = '',
  ...rest
}: TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }) {
  return (
    <textarea
      className={`${CONTROL} min-h-20 resize-y ${invalid ? 'border-danger/60' : 'border-line'} ${className}`}
      aria-invalid={invalid || undefined}
      {...rest}
    />
  )
}

export function Select({
  invalid,
  className = '',
  ...rest
}: InputHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }) {
  return (
    <select
      className={`${CONTROL} ${invalid ? 'border-danger/60' : 'border-line'} ${className}`}
      aria-invalid={invalid || undefined}
      {...rest}
    />
  )
}
