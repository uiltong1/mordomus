import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { Spinner } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'

/**
 * Porta de entrada das rotas privadas. Guarda a URL pretendida para devolver o
 * morador aonde ele ia depois de entrar, em vez de sempre cair no painel.
 */
export function RequireAuth() {
  const { isAuthenticated, isLoadingProfile } = useAuth()
  const location = useLocation()

  if (!isAuthenticated) {
    return <Navigate to="/entrar" replace state={{ from: location.pathname }} />
  }

  if (isLoadingProfile) {
    return (
      <div className="flex min-h-full items-center justify-center gap-3 text-sm text-ink-muted">
        <Spinner className="size-4" />
        Carregando...
      </div>
    )
  }

  return <Outlet />
}
