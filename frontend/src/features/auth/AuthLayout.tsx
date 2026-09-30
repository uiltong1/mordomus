import type { ReactNode } from 'react'
import { ThemeToggle } from '@/shared/theme'

/** Moldura comum das telas de entrada: mesma coluna, mesma promessa. */
export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string
  subtitle: string
  children: ReactNode
  footer?: ReactNode
}) {
  return (
    <div className="relative flex min-h-full items-center justify-center px-5 py-12">
      {/* antes do login não háresidência nem sino: o tema é a única coisa que a
          tela oferece, e quem odeia o tema escuro do aparelho precisa poder
          trocar sem entrar na conta primeiro */}
      <div className="absolute right-4 top-4">
        <ThemeToggle />
      </div>
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <p className="text-[11px] font-medium uppercase tracking-[0.3em] text-brand">Mordomus</p>
          <h1 className="mt-2 text-xl font-semibold text-ink">{title}</h1>
          <p className="mt-1.5 text-sm text-ink-muted">{subtitle}</p>
        </div>
        <div className="rounded-card border border-line bg-surface p-6 shadow-card">{children}</div>
        {footer ? <div className="mt-5 text-center text-xs text-ink-muted">{footer}</div> : null}
      </div>
    </div>
  )
}

/** `error.details` de um 422: campo → lista de mensagens do backend. */
export type FormErrors = Record<string, string[]>
