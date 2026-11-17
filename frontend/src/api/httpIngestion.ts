import { apiRequest } from './auth';
import type { Sensor } from './sensors';
import type { Page } from './types';
export interface HttpSource { id: string; sensor: Sensor; isEnabled: boolean; version: number; expiresAt: string; credentialCurrent: boolean; lastReceivedAt: string | null }
export interface HttpSourceResult { source: HttpSource; token: string | null }
export const httpIngestionApi = {
 list: (page = 1, perPage = 20, search = '', state = '') => apiRequest<Page<HttpSource>>(`integrations/http/sources?${new URLSearchParams({ page: String(page), per_page: String(perPage), ...(search ? { search } : {}), ...(state ? { state } : {}) })}`, undefined, { raw: true }),
 create: (sensor_id: string, expires_days: number) => apiRequest<HttpSourceResult>('integrations/http/sources', { sensor_id, expires_days }),
 rotate: (source: HttpSource, expires_days: number) => apiRequest<HttpSourceResult>(`integrations/http/sources/${source.id}/rotate`, { version: source.version, expires_days }),
 remove: (source: HttpSource) => apiRequest<void>(`integrations/http/sources/${source.id}`, { version: source.version }, { method: 'DELETE' }),
 toggle: (source: HttpSource) => apiRequest<HttpSourceResult>(`integrations/http/sources/${source.id}`, { version: source.version, is_enabled: !source.isEnabled }, { method: 'PATCH' }),
 revoke: (source: HttpSource) => apiRequest<HttpSourceResult>(`integrations/http/sources/${source.id}/revoke`, { version: source.version }),
};
