/**
 * Query keys namespaced por residência (T4.1.4, regra R6).
 *
 * Toda chave de dado de domínio começa pelo recurso e carrega o `tenantId` na
 * segunda posição: `['rooms', '01J8...']`. Duas residências nunca disputam a
 * mesma entrada de cache, e a troca de tenant pode invalidar por prefixo
 * (`['rooms', novoTenant]`) sem tocar no que a outra tela leu.
 *
 * O prefixo `['mordomus']` é a raiz: `queryClient.clear()` e a limpeza da
 * sessão usam só ele.
 */
export const ROOT_KEY = 'mordomus' as const

export function tenantKey(
  tenantId: string | null,
  resource: string,
  ...params: readonly (string | number | boolean | null)[]
): readonly unknown[] {
  return [ROOT_KEY, resource, tenantId, ...params]
}

/** Chaves de sessão e perfil: não são dados de residência, logo não levam tenant. */
export const authKeys = {
  profile: () => [ROOT_KEY, 'profile'] as const,
  tenants: () => [ROOT_KEY, 'tenants'] as const,
} as const

export const resourceKeys = {
  tenant: (tenantId: string | null) => tenantKey(tenantId, 'tenant'),
  devices: (tenantId: string | null) => tenantKey(tenantId, 'devices'),
  rooms: (tenantId: string | null) => tenantKey(tenantId, 'rooms'),
  room: (tenantId: string | null, roomId: string) => tenantKey(tenantId, 'rooms', roomId),
  assets: (tenantId: string | null, roomId: string | null = null) =>
    tenantKey(tenantId, 'assets', roomId),
  occurrences: (tenantId: string | null, ...filters: readonly (string | null)[]) =>
    tenantKey(tenantId, 'occurrences', ...filters),
  triggerConfigs: (tenantId: string | null, subjectId: string | null = null) =>
    tenantKey(tenantId, 'trigger-configs', subjectId),
} as const
