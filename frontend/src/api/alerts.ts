import { apiRequest } from './auth';
import type { Page } from './types';
import type { Sensor } from './sensors';
export type AlertKind = 'threshold' | 'missing';
export type AlertSeverity = 'info' | 'warning' | 'critical';
export interface AlertRule { id: string; name: string; kind: AlertKind; severity: AlertSeverity; sensor: Sensor; minimum: string | null; maximum: string | null; delaySeconds: number | null; maxAgeSeconds: number | null; isEnabled: boolean; version: number; activatedAt: string; lastEvaluatedAt: string | null; lastResult: string; canManage: boolean }
export interface AlertRuleInput { name: string; kind: AlertKind; severity: AlertSeverity; sensor_id: string; minimum?: string | null; maximum?: string | null; max_age_seconds?: number; delay_seconds?: number; is_enabled: boolean; version?: number }
export interface AlertObservation { id: string; value: string; unit: string | null; observedAt: string }
export interface AlertSnapshot { id: string; version: number; name: string; kind: AlertKind; severity: AlertSeverity; minimum: string | null; maximum: string | null; delaySeconds: number | null; maxAgeSeconds: number | null; sensor: { id: string; name: string; deviceName: string; unit: string | null } }
export interface AlertEpisode { id: string; rule: AlertSnapshot; severity: AlertSeverity; reason: string; firstObservation: AlertObservation | null; lastObservation: AlertObservation | null; resolutionObservation: AlertObservation | null; openedAt: string; lastDetectedAt: string; resolvedAt: string | null; resolution: string | null; acknowledgedAt: string | null; acknowledger: { id: string; name: string | null } | null }
export const alertsApi = {
 list: (filters: Record<string, string>, page = 1) => apiRequest<Page<AlertEpisode>>(`alerts?${new URLSearchParams({ ...filters, page: String(page) })}`, undefined, { raw: true }),
 rules: (search = '', page = 1, sort = 'name_asc', perPage = 20) => apiRequest<Page<AlertRule>>(`alert-rules?${new URLSearchParams({ search, page: String(page), sort, per_page: String(perPage) })}`, undefined, { raw: true }),
 create: (data: AlertRuleInput) => apiRequest<AlertRule>('alert-rules', data),
 update: (id: string, data: AlertRuleInput) => apiRequest<AlertRule>(`alert-rules/${encodeURIComponent(id)}`, data, { method: 'PATCH' }),
 show: (id: string) => apiRequest<AlertRule>(`alert-rules/${encodeURIComponent(id)}`),
 evaluate: (id: string) => apiRequest<AlertRule>(`alert-rules/${encodeURIComponent(id)}/evaluate`, {}),
 acknowledge: (id: string) => apiRequest<AlertEpisode>(`alerts/${encodeURIComponent(id)}/acknowledge`, {}),
};
