import { useState } from 'react'
import { Link, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '@/features/auth/AuthProvider'
import { NotificationBell } from '@/features/notifications/NotificationBell'
import { TenantSwitcher } from '@/tenants/TenantSwitcher'
import { useTenant } from '@/tenants/TenantProvider'
import { CreateTenantPage } from '@/features/settings/CreateTenantPage'

/**
 * Moldura da área logada. A troca de residência fica no cabeçalho porque é uma
 * decisão global: vale para cômodos, agenda, contas e notificações.
 */
export function AppLayout() {
  const { user, logout } = useAuth()
  const { needsTenant } = useTenant()
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)

  const onLogout = async () => {
    await logout()
    navigate('/entrar', { replace: true })
  }

  return (
    <div className="flex min-h-full flex-col">
      <header className="sticky top-0 z-30 border-b border-line bg-canvas/90 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4">
          <Link to="/" className="flex items-center gap-2 text-sm font-semibold text-ink">
            <span aria-hidden="true" className="text-brand">
              ⌂
            </span>
            Mordomus
          </Link>

          <div className="ml-auto flex items-center gap-2">
            {needsTenant ? null : <TenantSwitcher />}
            <NotificationBell />
            <div className="hidden items-center gap-2 sm:flex">
              <span className="max-w-40 truncate text-xs text-ink-muted" title={user?.email}>
                {user?.name}
              </span>
            </div>
            <button
              type="button"
              onClick={() => void onLogout()}
              className="rounded-control px-2 py-1.5 text-xs text-ink-muted transition-colors hover:bg-surface-2 hover:text-ink"
            >
              Sair
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
        {needsTenant && !creating ? (
          <div className="mx-auto max-w-md px-6 py-16 text-center">
            <p className="text-2xl" aria-hidden="true">
              🏠
            </p>
            <h1 className="mt-4 text-lg font-semibold text-ink">Nenhuma residência escolhida</h1>
            <p className="mt-2 text-sm text-ink-muted">
              Para ver cômodos, contas e agenda, o Mordomus precisa saber de qual casa estamos
              falando.
            </p>
            <button
              type="button"
              onClick={() => setCreating(true)}
              className="mt-6 inline-flex h-10 items-center rounded-control bg-brand px-4 text-sm font-medium text-brand-ink hover:bg-brand-strong"
            >
              Criar minha residência
            </button>
          </div>
        ) : creating ? (
          <CreateTenantPage onDone={() => setCreating(false)} />
        ) : (
          <Outlet />
        )}
      </main>
    </div>
  )
}
