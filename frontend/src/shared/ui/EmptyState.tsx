import type { ReactNode } from 'react'

export interface EmptyStateProps {
  title: string
  description?: string
  icon?: ReactNode
  action?: ReactNode
}

export function EmptyState({ title, description, icon, action }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-card border border-dashed border-line px-6 py-12 text-center">
      <div aria-hidden="true" className="text-3xl text-ink-subtle">
        {icon ?? '⌂'}
      </div>
      <div>
        <p className="text-sm font-medium text-ink">{title}</p>
        {description ? (
          <p className="mx-auto mt-1 max-w-sm text-xs text-ink-muted">{description}</p>
        ) : null}
      </div>
      {action}
    </div>
  )
}

export interface ErrorStateProps {
  title?: string
  message: string
  requestId?: string | null
  onRetry?: () => void
}

export function ErrorState({
  title = 'Algo deu errado',
  message,
  requestId,
  onRetry,
}: ErrorStateProps) {
  return (
    <div
      role="alert"
      className="flex flex-col items-center gap-3 rounded-card border border-danger/30 bg-danger/10 px-6 py-10 text-center"
    >
      <p className="text-sm font-medium text-danger">{title}</p>
      <p className="max-w-md text-xs text-ink-muted">{message}</p>
      {requestId ? (
        <p className="font-mono text-[11px] text-ink-subtle">referência: {requestId}</p>
      ) : null}
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="rounded-control border border-line px-3 py-1.5 text-xs text-ink hover:bg-surface-2"
        >
          Tentar de novo
        </button>
      ) : null}
    </div>
  )
}
