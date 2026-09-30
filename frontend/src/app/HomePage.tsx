import { useQuery } from '@tanstack/react-query'
import { resourceKeys } from '@/shared/api/queryKeys'
import { Card, CardBody, CardHeader, ErrorState, Spinner } from '@/shared/ui'
import { ApiError } from '@/shared/api/errors'
import { fetchTenant } from '@/features/auth/api'
import { useTenant } from '@/tenants/TenantProvider'

/**
 * Tela inicial da T4.1. O painel do imóvel é da T4.2; aqui o que importa é a
 * residence ativa já resolvendo dado pelo client central, com a chave de cache
 * carregando o `tenantId` (R6).
 */
export function HomePage() {
  const { activeTenantId, activeTenant } = useTenant()

  const tenantQuery = useQuery({
    queryKey: resourceKeys.tenant(activeTenantId),
    queryFn: ({ signal }) => fetchTenant(activeTenantId!, signal),
    enabled: activeTenantId !== null,
  })

  const tenant = tenantQuery.data

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={activeTenant?.name ?? 'Residência'}
          description={activeTenant ? `Você entra como ${activeTenant.role}.` : undefined}
        />
        <CardBody>
          {tenantQuery.isPending ? (
            <div className="flex items-center gap-2 text-sm text-ink-muted">
              <Spinner className="size-4" />
              Carregando a residência...
            </div>
          ) : tenantQuery.isError ? (
            <ErrorState
              message={
                tenantQuery.error instanceof ApiError
                  ? tenantQuery.error.message
                  : 'Falha ao carregar.'
              }
              requestId={tenantQuery.error instanceof ApiError ? tenantQuery.error.requestId : null}
              onRetry={() => void tenantQuery.refetch()}
            />
          ) : (
            <dl className="grid gap-3 sm:grid-cols-3">
              <div>
                <dt className="text-xs text-ink-subtle">Fuso horário</dt>
                <dd className="mt-0.5 text-sm text-ink">{tenant?.timezone}</dd>
              </div>
              <div>
                <dt className="text-xs text-ink-subtle">Horário preferido</dt>
                <dd className="mt-0.5 text-sm text-ink">{tenant?.preferred_hour}</dd>
              </div>
              <div>
                <dt className="text-xs text-ink-subtle">Permissões</dt>
                <dd className="mt-0.5 text-sm text-ink">
                  {activeTenant ? `${activeTenant.capabilities.length} ativas` : '—'}
                </dd>
              </div>
            </dl>
          )}
        </CardBody>
      </Card>

      <p className="px-1 text-xs text-ink-subtle">
        Painel do imóvel, cômodos e agenda chegam na T4.2 — a base de tela, cache por residência e
        notificações já estão no lugar.
      </p>
    </div>
  )
}
