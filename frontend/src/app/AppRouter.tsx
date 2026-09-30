import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppProviders } from './AppProviders'
import { AppLayout } from './AppLayout'
import { RequireAuth } from './RequireAuth'
import { AcceptInvitationPage } from '@/features/auth/AcceptInvitationPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { AuthLayout } from '@/features/auth/AuthLayout'
import { DashboardPage } from '@/features/maintenance/DashboardPage'
import { RoomPage } from '@/features/maintenance/RoomPage'
import { TimelinePage } from '@/features/scheduling/TimelinePage'
import { Button } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'

function GuestOnly({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth()
  if (isAuthenticated) return <Navigate to="/" replace />
  return <>{children}</>
}

function NotFoundPage() {
  return (
    <AuthLayout title="Página não encontrada" subtitle="O endereço que você abriu não existe.">
      <Button block variant="secondary" onClick={() => window.location.assign('/')}>
        Voltar ao início
      </Button>
    </AuthLayout>
  )
}

export function AppRouter() {
  return (
    <BrowserRouter>
      <AppProviders>
        <Routes>
          <Route
            path="/entrar"
            element={
              <GuestOnly>
                <LoginPage />
              </GuestOnly>
            }
          />
          <Route
            path="/registro"
            element={
              <GuestOnly>
                <RegisterPage />
              </GuestOnly>
            }
          />
          <Route path="/convite/:token" element={<AcceptInvitationPage />} />

          <Route element={<RequireAuth />}>
            <Route element={<AppLayout />}>
              <Route index element={<DashboardPage />} />
              <Route path="comodos/:roomId" element={<RoomPage />} />
              <Route path="agenda" element={<TimelinePage />} />
            </Route>
          </Route>

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </AppProviders>
    </BrowserRouter>
  )
}
