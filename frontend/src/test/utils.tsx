import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, type RenderOptions } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { ReactElement, ReactNode } from 'react'
import { ToastProvider } from '@/shared/ui'
import { ThemeProvider } from '@/shared/theme'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { TenantProvider } from '@/tenants/TenantProvider'

/** Cliente limpo por teste: cache compartilhado farão vazar estado entre casos. */
export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  })
}

/** Espelha o `AppProviders` real, sem BrowserRouter nem ErrorBoundary. */
export function TestProviders({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider>
      <QueryClientProvider client={createTestQueryClient()}>
        <ToastProvider>
          <AuthProvider>
            <TenantProvider>{children}</TenantProvider>
          </AuthProvider>
        </ToastProvider>
      </QueryClientProvider>
    </ThemeProvider>
  )
}

export function renderWithProviders(ui: ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
  return render(ui, {
    wrapper: ({ children }: { children: ReactNode }) => <TestProviders>{children}</TestProviders>,
    ...options,
  })
}

/**
 * Para telas que usam `Link` ou `useNavigate`: sem um router por cima, o
 * `Link` do React Router estoura ao tentar ler o contexto de navegação.
 */
export function renderWithRouter(ui: ReactElement, route = '/') {
  return renderWithProviders(
    <MemoryRouter initialEntries={[route]}>
      <Routes>
        <Route path="*" element={ui} />
      </Routes>
    </MemoryRouter>,
  )
}

/**
 * Declara a rota para o `useParams` resolver o id. O `path` da tela real é o
 * que importa aqui: o parâmetro só existe se a rota estiver declarada.
 */
export function renderRoute(ui: ReactElement, path: string, route: string) {
  return renderWithProviders(
    <MemoryRouter initialEntries={[route]}>
      <Routes>
        <Route path={path} element={ui} />
      </Routes>
    </MemoryRouter>,
  )
}

/** `Response` mínimo com o envelope de erro do monólito. */
export function errorResponse(
  status: number,
  body: { code: string; message: string; details?: unknown; request_id?: string },
): Response {
  return new Response(JSON.stringify({ error: body }), {
    status,
    headers: { 'Content-Type': 'application/json', 'X-Request-Id': body.request_id ?? 'req-test' },
  })
}

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}
