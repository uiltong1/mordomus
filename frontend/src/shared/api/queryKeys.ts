export const ROOT_KEY = 'mordomus' as const

export function tenantKey(
  tenantId: string | null,
  resource: string,
  ...params: readonly (string | number | boolean | null)[]
): readonly unknown[] {
  return [ROOT_KEY, resource, tenantId, ...params]
}

export const authKeys = {
  profile: () => [ROOT_KEY, 'profile'] as const,
  tenants: () => [ROOT_KEY, 'tenants'] as const,
} as const

export const resourceKeys = {
  tenant: (tenantId: string | null) => tenantKey(tenantId, 'tenant'),
  devices: (tenantId: string | null) => tenantKey(tenantId, 'devices'),
  rooms: (tenantId: string | null) => tenantKey(tenantId, 'rooms'),
  room: (tenantId: string | null, roomId: string) => tenantKey(tenantId, 'rooms', roomId),
  assets: (tenantId: string | null, roomId?: string | null) =>
    roomId ? tenantKey(tenantId, 'assets', roomId) : tenantKey(tenantId, 'assets'),
  occurrences: (tenantId: string | null, ...filters: readonly (string | null)[]) =>
    tenantKey(tenantId, 'occurrences', ...filters),
  triggerConfigs: (tenantId: string | null, subjectId?: string | null) =>
    subjectId
      ? tenantKey(tenantId, 'trigger-configs', subjectId)
      : tenantKey(tenantId, 'trigger-configs'),
  bills: (
    tenantId: string | null,
    kind?: string | null,
    isActive?: boolean | null,
    category?: string | null,
  ) =>
    tenantKey(
      tenantId,
      'bills',
      ...[kind, isActive, category].filter((v) => v !== undefined && v !== null),
    ),
  bill: (tenantId: string | null, billId: string) => tenantKey(tenantId, 'bills', billId),
  billOccurrences: (
    tenantId: string | null,
    month?: string | null,
    billId?: string | null,
    status?: string | null,
  ) =>
    tenantKey(
      tenantId,
      'bill-occurrences',
      ...[month, billId, status].filter((v) => v !== undefined && v !== null),
    ),
  splitRules: (tenantId: string | null, billId?: string | null) =>
    tenantKey(tenantId, 'split-rules', ...[billId].filter((v) => v !== undefined && v !== null)),
  occurrenceSplit: (tenantId: string | null, occurrenceId: string) =>
    tenantKey(tenantId, 'occurrence-split', occurrenceId),
  summary: (tenantId: string | null, month?: string | null) =>
    tenantKey(tenantId, 'summary', ...[month].filter((v) => v !== undefined && v !== null)),
} as const
