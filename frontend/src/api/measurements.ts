import { apiRequest } from './auth';
import type { LocationContext, Page } from './types';
import type { AssetCategory, DeviceAssignment } from './assets';
import type { Sensor } from './sensors';
export interface EntityOption {
 id: string; name: string; category: AssetCategory; manufacturer: string | null; model: string | null;
 available: boolean; imported: boolean; entityCount: number; importedCount: number;
 entities: { id: string; name: string; unit: string | null }[];
}
export interface RemoteSyncStatus { eventsEnabled: boolean; enabled: boolean; configurationValid: boolean; intervalSeconds: number | null; linksCount: number; lastAttemptAt: string | null; lastSuccessAt: string | null; nextEligibleAt: string | null }
export interface RemoteSensorLink { nextSyncAt?: string | null; id: string; entityId: string; sensor: Sensor; isEnabled: boolean; version: number; lastAttemptAt: string | null; lastSuccessAt: string | null; lastError: string | null }
export interface MeasurementProvenance { status: 'recorded' | 'unknown' | 'legacy'; revisionId: number | null; assetId?: string; sensorId?: string; source?: { kind: 'home_assistant' | 'homebridge' | 'http' | 'mqtt' | 'bluetooth'; id: string; instanceKey: string | null }; assignments: (DeviceAssignment & { asset: { id: string; name: string } }) | null }
export type MeasurementQuality = 'unknown' | 'good' | 'suspect' | 'invalid';
export interface Measurement { quality?: MeasurementQuality; provenance?: MeasurementProvenance; id: string; value: string; unit: string | null; observedAt: string; receivedAt: string; location: LocationContext | null }
export interface MeasurementPage extends Page<Measurement> { summary: { sensor: Sensor; latest: Measurement | null; status: 'disabled' | 'missing' | 'unknown' | 'stale' | 'fresh' } }
export interface ImportResult { devicesCreated: number; sensorsCreated: number; typesCreated: number; alreadyImported: number }
export function remoteIntegrationApi(provider: 'home-assistant' | 'homebridge') {
 const root = `integrations/${provider}`;
 return {
 status: () => apiRequest<{ configured: boolean; sync: RemoteSyncStatus }>(root),
 check: () => apiRequest<{ connected: boolean }>(`${root}/check`, {}),
 entities: (search: string, page = 1, perPage = 20) => apiRequest<Page<EntityOption>>(`${root}/entities?${new URLSearchParams({ search, page: String(page), per_page: String(perPage) })}`, undefined, { raw: true }),
 import: (entities: string[]) => apiRequest<ImportResult>(`${root}/import`, { device_ids: entities }),
 links: (page = 1, perPage = 20, search = '', state = '') => apiRequest<Page<RemoteSensorLink>>(`${root}/links?${new URLSearchParams({ page: String(page), per_page: String(perPage), ...(search ? { search } : {}), ...(state ? { state } : {}) })}`, undefined, { raw: true }),
 remove: (link: RemoteSensorLink) => apiRequest<void>(`${root}/links/${link.id}`, { version: link.version }, { method: 'DELETE' }),
 toggle: (link: RemoteSensorLink) => apiRequest<RemoteSensorLink>(`${root}/links/${link.id}`, { version: link.version, is_enabled: !link.isEnabled }, { method: 'PATCH' }),
 collect: (id: string) => apiRequest<{ outcome: 'queued' | 'skipped'; code: string | null }>(`${root}/links/${id}/collect`, {}),
 };
}
export const homebridgeApi = remoteIntegrationApi('homebridge');
export const measurementsApi = {
 ...remoteIntegrationApi('home-assistant'),
 readings: (id: string, filters: Record<string, string>, page = 1) => apiRequest<MeasurementPage>(`sensors/${encodeURIComponent(id)}/measurements?${new URLSearchParams({ ...filters, page: String(page) })}`, undefined, { raw: true }),
};
