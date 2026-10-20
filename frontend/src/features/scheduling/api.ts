import { api, unwrap } from '@/shared/api/client'
import type {
  IntervalUnit,
  Occurrence,
  Paginated,
  RecalculateBase,
  SchedulingOccurrenceStatus,
  SubjectType,
  TriggerConfig,
  TriggerPreview,
  TriggerType,
} from '@/shared/api/types'

export interface OccurrenceFilters {
  from?: string | null
  to?: string | null
  subjectType?: SubjectType | null
  status?: SchedulingOccurrenceStatus | null
}

export interface TriggerConfigFilters {
  subjectType?: SubjectType | null
  subjectId?: string | null
}

export type TriggerTypeFields = {
  interval_value?: number
  interval_unit?: IntervalUnit
  day_of_month?: number
  recalculate_base?: RecalculateBase
  custom_offsets?: number[]
}

export type TriggerConfigInput = {
  type?: TriggerType
  subject_type?: SubjectType
  subject_id?: string
  title?: string
  description?: string | null
  is_active?: boolean
  advance_notice_days?: number
  preferred_hour?: string | null
} & TriggerTypeFields

export type TriggerPreviewInput = {
  type: TriggerType
  base?: string | null
  preferred_hour?: string | null
} & TriggerTypeFields

export function fetchOccurrences(
  signal: AbortSignal | undefined,
  filters: OccurrenceFilters,
): Promise<Paginated<Occurrence>> {
  return api.get<Paginated<Occurrence>>('/scheduling/occurrences', {
    signal,
    query: {
      page: 1,
      per_page: 100,
      from: filters.from ?? undefined,
      to: filters.to ?? undefined,
      subject_type: filters.subjectType ?? undefined,
      status: filters.status ?? undefined,
    },
  })
}

export function completeOccurrence(occurrenceId: string): Promise<Occurrence> {
  return api
    .post<{ data: Occurrence }>(`/scheduling/occurrences/${occurrenceId}/complete`)
    .then(unwrap)
}

export function skipOccurrence(occurrenceId: string): Promise<Occurrence> {
  return api.post<{ data: Occurrence }>(`/scheduling/occurrences/${occurrenceId}/skip`).then(unwrap)
}

export function completeAssetOccurrence(occurrenceId: string): Promise<Occurrence> {
  return api
    .post<{ data: Occurrence }>(`/maintenance/occurrences/${occurrenceId}/complete`)
    .then(unwrap)
}

export function fetchTriggerConfigs(
  signal?: AbortSignal,
  filters: TriggerConfigFilters = {},
): Promise<Paginated<TriggerConfig>> {
  return api.get<Paginated<TriggerConfig>>('/scheduling/trigger-configs', {
    signal,
    query: {
      page: 1,
      per_page: 100,
      subject_type: filters.subjectType ?? undefined,
      subject_id: filters.subjectId ?? undefined,
    },
  })
}

export function previewNextDue(input: TriggerPreviewInput): Promise<TriggerPreview> {
  return api.post<{ data: TriggerPreview }>('/scheduling/preview', input).then(unwrap)
}

export function createTriggerConfig(input: TriggerConfigInput): Promise<TriggerConfig> {
  return api.post<{ data: TriggerConfig }>('/scheduling/trigger-configs', input).then(unwrap)
}

export function updateTriggerConfig(
  configId: string,
  input: TriggerConfigInput,
): Promise<TriggerConfig> {
  return api
    .patch<{ data: TriggerConfig }>(`/scheduling/trigger-configs/${configId}`, input)
    .then(unwrap)
}

export function saveAssetSchedule(
  assetId: string,
  input: TriggerConfigInput,
): Promise<TriggerConfig> {
  return api
    .post<{ data: TriggerConfig }>(`/maintenance/assets/${assetId}/schedule`, input)
    .then(unwrap)
}

export function deleteTriggerConfig(configId: string): Promise<TriggerConfig> {
  return api.delete<{ data: TriggerConfig }>(`/scheduling/trigger-configs/${configId}`).then(unwrap)
}
