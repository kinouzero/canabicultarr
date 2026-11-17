import { apiRequest } from './auth';
export type SourceKind = 'home_assistant' | 'homebridge' | 'http' | 'mqtt';
export type SourceState = 'enabled' | 'paused' | 'sensor_disabled' | 'device_retired' | 'credential_invalid' | 'source_unavailable';
export type SourceFreshness = 'fresh' | 'stale' | 'missing' | 'unknown';
export interface DiagnosticSource { id: string; kind: SourceKind; sensorId: string; sensorName: string; deviceName: string; state: SourceState; freshness: SourceFreshness; credentialCurrent: boolean | null; lastObservedAt: string | null; latestReceivedAt: string | null; lastAttemptAt: string | null; lastSuccessAt: string | null; lastAcceptedAt: string | null; lastError: string | null; hasUnknownError: boolean }
export interface SourceDiagnostics { data: DiagnosticSource[]; meta: { current_page: number; last_page: number; per_page: number; total: number }; summary: { total: number; homeAssistant: number; homebridge: number; http: number; mqtt: number; states: Record<SourceState, number>; readings: Record<SourceFreshness, number> }; asOf: string; generatedAt: string }
export interface SourceFilters { per_page?: string; sort: string; search: string; kind: SourceKind | ''; state: SourceState | ''; freshness: SourceFreshness | '' }
export const sourceDiagnosticsApi = { read: (filters: SourceFilters, page = 1) => apiRequest<SourceDiagnostics>(`integrations/sources?${new URLSearchParams({ ...filters, page: String(page) })}`, undefined, { raw: true }) };
