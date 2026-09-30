import { useEffect, useRef, useState } from 'react'
import { ApiError } from '@/shared/api/errors'
import { Spinner } from '@/shared/ui'
import { useToast } from '@/shared/ui'
import { useTenant } from './TenantProvider'

/**
 * Seletor global de residência ativa (T4.1.3).
 *
 * Trocar de casa é uma operação de token, não de filtro local: o backend emite
 * um JWT com o `tid` novo e o `TenantProvider` limpa o cache (R6). Enquanto o
 * token não volta, o seletor trava — mostrar dados do lar anterior durante a
 * troca seria exatamente o que a regra proíbe.
 */
export function TenantSwitcher() {
  const { activeTenant, tenants, isSwitching, switchTenant } = useTenant()
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const toast = useToast()

  useEffect(() => {
    if (!open) return
    const onClickAway = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onClickAway)
      document.removeEventListener('keydown', onEscape)
    }
  }, [open])

  if (tenants.length === 0) return null

  const choose = async (tenantId: string) => {
    setOpen(false)
    try {
      await switchTenant(tenantId)
    } catch (error) {
      toast.error(
        error instanceof ApiError ? error.message : 'Não foi possível trocar de residência.',
      )
    }
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        disabled={isSwitching}
        aria-expanded={open}
        aria-haspopup="listbox"
        aria-label="Residência ativa"
        data-testid="tenant-switcher"
        className="flex h-9 max-w-48 items-center gap-2 rounded-control border border-line bg-surface-2 px-3 text-xs text-ink transition-colors hover:bg-line/50 disabled:opacity-60"
      >
        {isSwitching ? <Spinner className="size-3.5" /> : <span aria-hidden="true">⌂</span>}
        <span className="truncate font-medium">{activeTenant?.name ?? 'Sem residência'}</span>
        <span aria-hidden="true" className="text-ink-subtle">
          ▾
        </span>
      </button>

      {open ? (
        <ul
          role="listbox"
          aria-label="Residências"
          data-testid="tenant-switcher-list"
          className="absolute left-0 z-40 mt-2 w-64 rounded-card border border-line bg-surface p-1 shadow-pop"
        >
          {tenants.map((tenant) => {
            const selected = tenant.id === activeTenant?.id
            return (
              <li key={tenant.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={selected}
                  onClick={() => void choose(tenant.id)}
                  className={[
                    'flex w-full items-center justify-between gap-2 rounded-control px-3 py-2 text-left text-xs transition-colors',
                    selected
                      ? 'bg-brand/15 text-brand'
                      : 'text-ink-muted hover:bg-surface-2 hover:text-ink',
                  ].join(' ')}
                >
                  <span className="truncate">{tenant.name}</span>
                  <span className="shrink-0 text-[10px] uppercase tracking-wide text-ink-subtle">
                    {tenant.role}
                  </span>
                </button>
              </li>
            )
          })}
        </ul>
      ) : null}
    </div>
  )
}
