import type { ConsumableProduct, FertilizerWateringInput, SubstrateUsage, ContainerUsage } from '../lib/plant-inventory';
import type { LifecycleAction, LifecycleStage } from '../lib/lifecycle';
import { apiRequest } from './auth';
import type { LocationContext, Page } from './types';
import type { MeasurementNature } from './sensors';
import type { Durations, Strain } from './strains';
export type PlantPhase = string;
export type PlantAction = string;
export interface WateringNeed { needed: boolean; reason: 'elapsed' | 'moisture' | null; alertDays?: number; moistureThreshold?: number; measurement?: { percent: number; observedAt: string } | null; timerDueAt?: string | null }
export interface WateringState {
 need?: WateringNeed;
 status: 'unavailable' | 'choose' | 'alternate' | 'flush';
 last: { occurredAt: string; withProducts: boolean } | null;
 nextWithProducts: boolean | null; flushStartsAt: string | null; flushDays: number;
}
export interface PlantMeasurement { sourceRevision?: string | null; switchControl?: { kind: 'switch' | 'pump'; canControl: boolean } | null; quality?: import('./measurements').MeasurementQuality | null;
 sensorId: string; sensorName: string; device: { id: string; name: string; category?: import('./assets').AssetCategory };
 measurementType: { name: string; nature: MeasurementNature; decimalPlaces: number };
 value: string | null; unit: string | null; observedAt: string | null; isEnabled: boolean; isStale: boolean;
}
export interface Plant {
 container?: { inventoryItemId: string; name: string; capacityLiters: string } | null;
 measurements: PlantMeasurement[]; summarySensorIds: string[] | null;
 harvestWeightGrams: string | null;
 watering: WateringState;
 hasOwnPhoto: boolean; photoUrl: string | null; id: string; name: string; givenName: string | null; notes: string | null; strain: Pick<Strain, 'id' | 'name' | 'classification' | 'genetics'>;
 phase: PlantPhase; startedAt: string | null; phaseStartedAt: string | null; phaseDueAt: string | null;
 day: number | null; week: number | null; isOverdue: boolean; nextAction: PlantAction | null;
 durationSnapshot: { durations: Durations; settings_version: number; strain_version: number };
 location: LocationContext; devices: { id: string; name: string; status: string; category?: string; canActivate?: boolean }[]; version: number; canManage: boolean; canDelete?: boolean;
}
export interface PlantInput {
 name?: string | null; notes: string | null; location: Pick<LocationContext, 'id' | 'type'>; device_ids: string[];
 summary_sensor_ids?: string[] | null; planned?: boolean; photo_document_id?: string | null; request_id?: string; strain_id?: string; strain_change_reason?: string; started_at?: string | null; version?: number;
}
export interface PlantAttachment { id: string; title: string; originalName: string; mediaType: string; sizeBytes: number }
export type MaintenanceType = string;
export interface PlantEvent {
 harvestWeightGrams: string | null;
 attachments: PlantAttachment[]; maintenance: { type: MaintenanceType } | null;
 id: string; kind: PlantAction | 'imported' | 'trello_comment' | 'created' | 'updated' | 'matured' | 'watered' | 'maintained'; fromPhase: PlantPhase | null; toPhase: PlantPhase;
 container?: { current: { inventoryItemId: string; name: string; capacityLiters: string }; previous: { inventoryItemId: string; name: string; capacityLiters: string } | null; previousDisposition: string | null } | null;
 substrates?: { inventoryItemId: string; name: string; unit: string; quantity: string; movementId: string }[];
 watering: { pump?: { activationId: string; assetId: string; name: string; occurredAt: string } | null; withProducts: boolean; duringFlush: boolean | null; waterLiters?: string | null; fertilizers?: { inventoryItemId: string; name: string; unit: string; dosePerLiter: string; quantity: string; movementId: string }[] } | null;
 comment: string | null; actorId: string | null; occurredAt: string; recordedAt: string;
 snapshot: { strain_change?: { previous: { id: string; name: string }; current: { id: string; name: string }; reason: string }; automatic_relocation?: boolean; lifecycle_action?: LifecycleAction | null; lifecycle_stage?: LifecycleStage | null; name: string; strain_id: string; location: LocationContext; previous_location: LocationContext | null }; fields: string[];
}
export interface PlantListScope { location: LocationContext; status: 'active' | 'planned' }

export const plantsApi = {
 remove: (record: Plant) => apiRequest<void>(`plants/${encodeURIComponent(record.id)}`, { version: record.version }, { method: 'DELETE' }),
 consumables: (id: string, action?: string, at?: string) => apiRequest<{ schedule?: import('../lib/fertilizer-periods').FertilizerPlan | null; container: ConsumableProduct | null; currentContainer: { inventoryItemId: string; name: string; capacityLiters: string } | null; waterLiters: string | null; canConsume: boolean; products: ConsumableProduct[] }>(`plants/${encodeURIComponent(id)}/consumables${action || at ? `?${new URLSearchParams({ ...(action ? { action } : {}), ...(at ? { at } : {}) })}` : ''}`),
 maintenanceTypes: () => apiRequest<string[]>('plants/maintenance-types'),
 list: (search = '', page = 1, status = 'active', phase = '', sort = 'name_asc', perPage = 20, location?: LocationContext | null) => apiRequest<Page<Plant>>(`plants?${new URLSearchParams({ search, page: String(page), status, phase, sort, per_page: String(perPage), ...(location ? { location_type: location.type, location_id: location.id } : {}) })}`, undefined, { raw: true }),
 get: (id: string) => apiRequest<Plant>(`plants/${encodeURIComponent(id)}`),
 create: (input: PlantInput) => apiRequest<Plant>('plants', input),
 update: (id: string, input: Partial<PlantInput>) => apiRequest<Plant>(`plants/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
 photo: (plant: Plant, id: string | null) => apiRequest<Plant>(`plants/${encodeURIComponent(plant.id)}`, { version: plant.version, photo_document_id: id }, { method: 'PATCH' }),
 water: (plant: Plant, withProducts: boolean, requestId: string, comment: string | null, occurredAt: string | null, documentIds: string[] = [], fertilizer: FertilizerWateringInput = {}) => apiRequest<Plant>(`plants/${encodeURIComponent(plant.id)}/waterings`, { version: plant.version, request_id: requestId, with_products: withProducts, comment, occurred_at: occurredAt, document_ids: documentIds, ...fertilizer }),
 maintain: (plant: Plant, type: MaintenanceType, requestId: string, comment: string | null, occurredAt: string | null, documentIds: string[]) => apiRequest<Plant>(`plants/${encodeURIComponent(plant.id)}/maintenance`, { version: plant.version, request_id: requestId, type, comment, occurred_at: occurredAt, document_ids: documentIds }),
 events: (id: string, page = 1) => apiRequest<Page<PlantEvent>>(`plants/${encodeURIComponent(id)}/events?page=${page}`, undefined, { raw: true }),
 action: (plant: Plant, action: PlantAction, requestId: string, comment: string | null, occurredAt: string | null, documentIds: string[] = [], harvestWeightGrams?: string | number, substrates: SubstrateUsage[] = [], container: ContainerUsage | null = null) => apiRequest<Plant>(`plants/${encodeURIComponent(plant.id)}/actions`, { version: plant.version, request_id: requestId, action, comment, occurred_at: occurredAt, document_ids: documentIds, substrates, container, ...(harvestWeightGrams === undefined ? {} : { harvest_weight_grams: harvestWeightGrams }) }),
};

export interface DashboardGroupPage { location: LocationContext; current_page: number; last_page: number; total: number; per_page: number }
export interface DashboardHeaderOptions { metadataOnly?: boolean; roomPage?: number; zonePages?: Record<string, number>; signal?: AbortSignal }
export interface DashboardHeaderPage { current_page: number; last_page: number; total: number; per_page: number }
export interface PlantDashboardPage extends Page<Pick<Plant, 'canDelete' | 'canManage' | 'id' | 'name' | 'givenName' | 'strain' | 'phase' | 'phaseStartedAt' | 'phaseDueAt' | 'day' | 'week' | 'isOverdue' | 'nextAction' | 'location' | 'photoUrl' | 'watering' | 'measurements' | 'summarySensorIds'>> { locationReadingsMeta?: Record<string, { current_page: number; per_page: number; total: number }>; locationReadings?: Record<string, PlantMeasurement[]>; rooms?: Record<string, DashboardHeaderPage>; room_meta?: DashboardHeaderPage; groups?: Record<string, DashboardGroupPage>; site: { id: string; name: string }; summary: { plants: number; planned: number; due: number; strains: number }; filterOptions: { strains: { id: string; name: string }[] } }
export const dashboardSorts = ['name_asc', 'name_desc', 'phase_asc', 'phase_desc', 'due_asc', 'due_desc'] as const;
export interface DashboardFilters { status: 'active' | 'planned'; phase: string; strainId: string; dueOnly: boolean; sort: typeof dashboardSorts[number] }
export type PlantDashboardPreview = Omit<PlantDashboardPage, 'summary' | 'filterOptions'>;
function readDashboard<T>(siteId: string, filters: DashboardFilters | undefined, options: DashboardHeaderOptions & { groups?: string[] } = {}) {
  const query = new URLSearchParams({ per_page: '10', grouped: '1' });
  if (options.metadataOnly) query.set('metadata_only', '1');
  if (options.roomPage) query.set('room_page', String(options.roomPage));
  Object.entries(options.zonePages ?? {}).forEach(([id, page]) => query.set(`zone_pages[${id}]`, String(page)));
  options.groups?.forEach(key => query.append('preview_groups[]', key));
  if (filters) query.set('status', filters.status);
  if (filters?.phase) query.set('phase', filters.phase);
  if (filters?.strainId) query.set('strain_id', filters.strainId);
  if (filters?.dueOnly) query.set('due_only', '1');
  if (filters) query.set('sort', filters.sort);
  return apiRequest<T>(`sites/${encodeURIComponent(siteId)}/plant-dashboard?${query}`, undefined, { raw: true, signal: options.signal });
}
export const plantDashboardApi = {
 tiles: (siteId: string, type: string, id: string, page: number, signal?: AbortSignal) => apiRequest<Page<PlantMeasurement>>(`sites/${encodeURIComponent(siteId)}/dashboard-tiles?${new URLSearchParams({ type, id, page: String(page) })}`, undefined, { raw: true, signal }),
 read: (siteId: string, filters?: DashboardFilters, options: DashboardHeaderOptions = {}) => readDashboard<PlantDashboardPage>(siteId, filters, options),
 previews: (siteId: string, groups: string[], filters: DashboardFilters, signal?: AbortSignal) => readDashboard<PlantDashboardPreview>(siteId, filters, { groups, signal }),
};
