import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { ToastProvider } from '@/shared/ui'
import { ThemeProvider } from '@/shared/theme'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { TenantProvider } from '@/tenants/TenantProvider'
import { ErrorBoundary } from './ErrorBoundary'

/**
 * Um cliente por aplicação. `retry` não repete erro de domínio: 401 já passa
 * pelo refresh rotativo e 403/404 não melhoram com nova tentativa.
 */
function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 60_000,
        refetchOnWindowFocus: true,
        retry: (failureCount, error) => {
          const status = (error as { status?: number }).status ?? 0
          if (status >= 400 && status < 500) return false
          return failureCount < 1
        },
      },
      mutations: { retry: false },
    },
  })
}

export function AppProviders({ children }: { children: ReactNode }) {
  // criado uma única vez: um cliente novo a cada render jogaria o cache fora
  const [queryClient] = useState(createQueryClient)

  return (
    // O tema é o provider mais externo porque a própria tela de erro do
    // ErrorBoundary é feita com os tokens — se o atributo chegasse depois dela,
    // o fallback apareceria na paleta errada.
    <ThemeProvider>
      <ErrorBoundary>
        <QueryClientProvider client={queryClient}>
          <ToastProvider>
            <AuthProvider>
              <TenantProvider>{children}</TenantProvider>
            </AuthProvider>
          </ToastProvider>
        </QueryClientProvider>
      </ErrorBoundary>
    </ThemeProvider>
  )
}
