import { apiRequest } from './auth';
import type { Page, LocationContext } from './types';
import type { Sensor } from './sensors';
import type { Measurement, MeasurementPage } from './measurements';
import type { PlantPhase } from './plants';
export type ReadingStatus = MeasurementPage['summary']['status'];
export interface MeasurementOverview extends Page<{ sensor: Sensor; latest: Measurement | null; status: ReadingStatus }> { summary: Record<ReadingStatus | 'total', number>; generatedAt: string }
export interface CultureOverview {
 generatedAt: string; days: number;
 plants: { total: number; active: number; planned: number; archived: number; wateringDue: number; lifecycleDue: number; due: number; harvested: number; harvestWeightGrams: string; byPhase: Record<PlantPhase, number> };
 locations: { key: string; location: LocationContext | null; active: number; planned: number; due: number }[];
 strains: { id: string; name: string; active: number; harvested: number; harvestWeightGrams: string }[];
 upcoming: { id: string; name: string; phase: PlantPhase; dueAt: string; location: LocationContext | null }[];
 activity: { day: string; waterings: number; maintenance: number; transitions: number }[];
}
export const insightsApi = {
 measurements: (filters: Record<string, string>, ids: string[]) => { const query = new URLSearchParams(filters); ids.forEach(id => query.append('sensor_ids[]', id)); return apiRequest<MeasurementOverview>(`measurements/overview?${query}`, undefined, { raw: true }); },
 culture: (site: string, days: number) => apiRequest<CultureOverview>(`culture/overview?${new URLSearchParams({ ...(site ? { site_id: site } : {}), days: String(days) })}`),
};
