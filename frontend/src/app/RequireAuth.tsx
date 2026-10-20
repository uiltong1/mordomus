import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { Spinner } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'

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
