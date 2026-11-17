import type { LifecycleStage } from '../lib/lifecycle';
import { apiRequest } from './auth';
import type { Page } from './types';

export type DurationKey = string;
export type Durations = Record<string, number>;
export interface WateringDefaults { alertDays: number; moistureThreshold: number }
export interface LifecycleSettings { automaticRelocation?: boolean; stages: LifecycleStage[]; icons: string[]; wateringDefaults?: WateringDefaults; durations: Durations; version: number; canManage: boolean }
export const strainSorts = ['name_asc', 'name_desc', 'flowering_asc', 'flowering_desc', 'stock_asc', 'stock_desc', 'yield_asc', 'yield_desc'] as const;
export interface Strain {
 theoreticalYield: string | null;
 seedStock: { id: string; name: string; quantity: string; isArchived: boolean; scoped: boolean } | null;
 id: string; name: string; sourceUrl: string | null; classification: 'indica' | 'sativa' | 'hybrid' | null;
 genetics: string | null; lineage: string | null; thc: string | null; cbd: string | null;
 aroma: string | null; reportedEffects: string | null; notes: string | null;
 durationOverrides: Partial<Durations>; isArchived: boolean; version: number; canManage: boolean;
}
export interface StrainInput {
 theoretical_yield?: string | null;
 seed_inventory_item_id?: string | null;
 name: string; source_url: string | null; classification: string | null; genetics: string | null;
 lineage: string | null; thc: string | null; cbd: string | null; aroma: string | null;
 reported_effects: string | null; notes: string | null; duration_overrides: Partial<Record<DurationKey, number | null>>;
 is_archived: boolean; version?: number;
}
export const strainsApi = {
 setArchived: (record: Strain, archived: boolean) => apiRequest<Strain>(`strains/${encodeURIComponent(record.id)}`, { version: record.version, is_archived: archived }, { method: 'PATCH' }),
 remove: (record: Strain) => apiRequest<void>(`strains/${encodeURIComponent(record.id)}`, { version: record.version }, { method: 'DELETE' }),
 photo: (record: Strain, id: string) => apiRequest<Strain>(`strains/${encodeURIComponent(record.id)}`, { version: record.version, photo_document_id: id }, { method: 'PATCH' }),
 get: (id: string) => apiRequest<Strain>(`strains/${encodeURIComponent(id)}`),
 list: (search = '', page = 1, status = 'active', sort: typeof strainSorts[number] = 'name_asc', perPage = 20) => apiRequest<Page<Strain>>(`strains?${new URLSearchParams({ search, page: String(page), status, sort, per_page: String(perPage) })}`, undefined, { raw: true }),
 create: (input: StrainInput) => apiRequest<Strain>('strains', input),
 update: (id: string, input: StrainInput) => apiRequest<Strain>(`strains/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
 settings: () => apiRequest<LifecycleSettings>('lifecycle-settings'),
 saveSettings: (version: number, durations: Durations, watering?: WateringDefaults, stages?: LifecycleStage[], automaticRelocation?: boolean) => apiRequest<LifecycleSettings>('lifecycle-settings', { version, durations, ...(automaticRelocation === undefined ? {} : { automatic_relocation: automaticRelocation }), ...(stages ? { stages } : {}), ...(watering ? { watering_alert_days: watering.alertDays, watering_moisture_threshold: watering.moistureThreshold } : {}) }, { method: 'PATCH' }),
};
