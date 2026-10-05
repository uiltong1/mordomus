/**
 * Tipos do contrato da API, derivados dos schemas do OpenAPI do monólito
 * (`make openapi`). Mudou o schema, muda o tipo.
 */

export interface User {
  id: string
  name: string
  email: string
  locale: string
}

export interface TenantSummary {
  id: string
  name: string
  slug: string
  role: string
  role_key: string
  capabilities: string[]
}

export interface RoleRef {
  id: string
  key: string
  name: string
}

export interface Tenant {
  id: string
  name: string
  slug: string
  timezone: string
  preferred_hour: string
  archived: boolean
  archived_at: string | null
  role: RoleRef | null
  capabilities: string[]
  created_at: string | null
}

export interface Member {
  id: string
  user: { id: string; name: string; email: string }
  role: RoleRef
  status: 'active' | 'invited' | 'archived'
  capabilities: string[]
}

export interface PageMeta {
  page: number
  per_page: number
  total: number
  last_page: number
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export interface Profile {
  user: User
  active_tenant: string | null
  tenants: TenantSummary[]
  capabilities: string[]
}

/** `POST /auth/login`, `/auth/register`, `/auth/refresh`, `/auth/switch-tenant`. */
export interface Session {
  token_type: string
  access_token: string
  expires_in: number
  active_tenant: string | null
  refresh_token: string
  refresh_expires_in: number
  user: User
  tenants: TenantSummary[]
}

/**
 * `POST /invitations/{token}/accept` devolve um terceiro formato de sessão:
 * sem `refresh_expires_in` e com o `tenant` convidado à parte.
 */
export interface AcceptedInvitation {
  token_type: string
  access_token: string
  expires_in: number
  active_tenant: string | null
  refresh_token: string
  tenant: { id: string; name: string; slug: string; role: string }
  user: { id: string; name: string; email: string }
  tenants: TenantSummary[]
}

/** `POST /tenants` devolve sessão + o tenant criado, mas sem `user`/`tenants`. */
export interface TenantCreatedSession {
  token_type: string
  access_token: string
  expires_in: number
  refresh_token: string
  refresh_expires_in: number
  active_tenant: string
  data: Tenant
}

/* Maintenance — cômodos e inventário. `archived` chega materializado: o recurso
 * traduz `archived_at !== null`, então a UI filtra por ele em vez de adivinhar. */

export interface Room {
  id: string
  tenant_id: string
  name: string
  icon: string | null
  sort_order: number
  archived: boolean
  archived_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface Asset {
  id: string
  tenant_id: string
  room_id: string
  name: string
  category: string | null
  brand: string | null
  model: string | null
  acquired_at: string | null
  warranty_until: string | null
  metadata: Record<string, unknown> | null
  archived: boolean
  archived_at: string | null
  created_at: string | null
  updated_at: string | null
}

/* Scheduling — motor de regras e agenda. */

/** Alvo do ciclo. O monólito só agenda `asset` aqui; `bill` pertence à Fase 5. */
export type SubjectType = 'asset' | 'bill'

export const SUBJECT_TYPES = ['asset', 'bill'] as const

export type TriggerType = 'INTERVAL' | 'CALENDAR_MONTHLY' | 'POST_COMPLETION' | 'ESCALATED'

export const TRIGGER_TYPES = [
  'INTERVAL',
  'CALENDAR_MONTHLY',
  'POST_COMPLETION',
  'ESCALATED',
] as const

export type IntervalUnit = 'days' | 'weeks' | 'months'

export const INTERVAL_UNITS = ['days', 'weeks', 'months'] as const

export type RecalculateBase = 'DUE_DATE' | 'COMPLETION'

export const RECALCULATE_BASES = ['DUE_DATE', 'COMPLETION'] as const

export interface TriggerConfig {
  id: string
  tenant_id: string
  subject_type: SubjectType
  subject_id: string
  title: string
  description: string | null
  is_active: boolean
  type: TriggerType
  interval_value: number | null
  interval_unit: IntervalUnit | null
  day_of_month: number | null
  advance_notice_days: number
  recalculate_base: RecalculateBase | null
  custom_offsets: number[] | null
  preferred_hour: string | null
  last_base_date: string | null
  next_due_at: string | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Scheduling — status da ocorrência de manutenção. O Financeiro tem o seu
 * próprio (`FinancialOccurrenceStatus`, com `open`/`paid`/`overdue`/`cancelled`),
 * e os dois vocabularios não podem se misturar: `overdue` existe nos dois com
 * significados que não se cruzam.
 */
export type SchedulingOccurrenceStatus =
  'pending' | 'notified' | 'completed' | 'skipped' | 'overdue'

export const SCHEDULING_OCCURRENCE_STATUSES = [
  'pending',
  'notified',
  'completed',
  'skipped',
  'overdue',
] as const

/** Status que ainda aceitam check-in. Concluir uma finalize devolve 409. */
export const OPEN_SCHEDULING_OCCURRENCE_STATUSES: readonly SchedulingOccurrenceStatus[] = [
  'pending',
  'notified',
  'overdue',
]

export interface Occurrence {
  id: string
  tenant_id: string
  trigger_config_id: string
  subject_type: SubjectType
  subject_id: string
  title: string
  scheduled_for: string | null
  due_at: string | null
  status: SchedulingOccurrenceStatus
  notified_at: string | null
  completed_at: string | null
  completed_by: string | null
  created_at: string | null
  updated_at: string | null
}

export type FinancialOccurrenceStatus = 'open' | 'paid' | 'overdue' | 'cancelled'
export const FINANCIAL_OCCURRENCE_STATUSES = ['open', 'paid', 'overdue', 'cancelled'] as const

export type BillKind = 'fixed' | 'variable'
export const BILL_KINDS = ['fixed', 'variable'] as const

export type PaymentMethod =
  'pix' | 'boleto' | 'debit_card' | 'credit_card' | 'cash' | 'transfer' | 'other'
export const PAYMENT_METHODS = [
  'pix',
  'boleto',
  'debit_card',
  'credit_card',
  'cash',
  'transfer',
  'other',
] as const

export type SplitMode = 'EQUAL' | 'WEIGHTED' | 'PERCENT' | 'CUSTOM'
export const SPLIT_MODES = ['EQUAL', 'WEIGHTED', 'PERCENT', 'CUSTOM'] as const

export interface Bill {
  id: string
  tenant_id: string
  name: string
  kind: BillKind
  category: string | null
  amount: string | null
  currency: string
  is_active: boolean
  schedule: {
    id: string
    day_of_month: number | null
    advance_notice_days: number
    preferred_hour: string
    is_active: boolean
    next_due_at: string | null
  } | null
  created_by: string
  created_at: string | null
  updated_at: string | null
}

export interface PaymentRecord {
  id: string
  bill_occurrence_id: string
  user_id: string
  amount: string
  method: PaymentMethod
  paid_at: string
  receipt_url: string | null
}

export interface BillOccurrence {
  id: string
  tenant_id: string
  bill_id: string
  bill_name: string
  bill_kind: BillKind
  category: string | null
  schedule_id: string | null
  due_date: string
  amount: string
  status: FinancialOccurrenceStatus
  paid_at: string | null
  paid_by: string | null
  payments: PaymentRecord[]
  created_at: string | null
  updated_at: string | null
}

export interface BillSummary {
  month: string
  timezone: string
  totals: {
    due: string
    paid: string
    open: string
    overdue: string
    cancelled: string
  }
  counts: {
    occurrences: number
    paid: number
    open: number
    overdue: number
    cancelled: number
  }
  by_status: Record<FinancialOccurrenceStatus, number>
  by_category: { category: string; amount: string }[]
}

export interface SplitEntry {
  id: string
  user_id: string
  user_name: string
  weight: number | null
  percent: number | null
  fixed_amount: string | null
}

export interface SplitRule {
  id: string
  tenant_id: string
  bill_id: string | null
  bill_name: string | null
  mode: SplitMode
  is_active: boolean
  is_house_default: boolean
  entries: SplitEntry[]
}

export interface Share {
  user_id: string
  user_name: string
  share_amount: string
  settled: boolean
  settled_at: string | null
}

export interface OccurrenceSplit {
  bill_occurrence_id: string
  bill_id: string
  bill_name: string
  due_date: string
  amount: string
  status: FinancialOccurrenceStatus
  split_rule_id: string
  mode: SplitMode
  is_house_default: boolean
  total: string
  scope: 'all' | 'own'
  shares: Share[]
}

/**
 * `POST /scheduling/preview`. `next_due_at` sai em UTC enquanto o recurso da
 * regra devolve o mesmo instante no fuso da residência — a comparação da tela
 * passa pelo `scheduled_for`, que é dia de calendário nos dois caminhos.
 */
export interface TriggerPreview {
  type: TriggerType
  scheduled_for: string | null
  next_due_at: string | null
  preferred_hour: string
  timezone: string
}

/** Capacidades do RBAC (ADR-007); `role_permissions` no monólito. */
export const CAPABILITIES = [
  'tenant.manage',
  'members.manage',
  'rooms.manage',
  'assets.manage',
  'rules.edit',
  'occurrences.complete',
  'occurrences.skip',
  'bills.manage',
  'bills.pay',
  'splits.manage',
  'splits.view_own',
  'notifications.manage',
] as const

export type Capability = (typeof CAPABILITIES)[number]
