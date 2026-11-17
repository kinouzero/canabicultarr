import { apiRequest } from './auth';
import type { LocationContext, Page } from './types';

export const assetCategories = ['sensor', 'socket', 'humidifier', 'dehumidifier', 'light', 'fan', 'pump', 'other'] as const;
export type AssetCategory = typeof assetCategories[number];
export type AssetIntegration = 'home_assistant' | 'homebridge' | 'bluetooth' | 'mqtt' | 'http';
export type AssetStatus = 'active' | 'maintenance' | 'retired';
export interface LinkedPlant { id: string; name: string; location: LocationContext | null }
export interface DeviceAssignment { id?: string; plantsCount?: number; locations: LocationContext[]; plants: LinkedPlant[] }
export interface PowerSettings { provider: 'home_assistant' | 'homebridge'; entity: string; mode: 'manual' | 'auto'; on_at: string | null; off_at: string | null; timezone: string | null }
export interface PowerCommand { id: string; isOn: boolean; status: 'pending' | 'confirmed' | 'uncertain'; createdAt: string; confirmedAt: string | null }
export interface PowerContext { isOn: boolean | null; lastCommand: PowerCommand | null }
export interface PumpSettings { provider: 'home_assistant' | 'homebridge'; entity: string; mode: 'auto' | 'timer' | 'manual'; seconds: number | null }
export interface PumpActivation { id: string; plantId: string; occurredAt: string | null; mode: PumpSettings['mode']; stopDueAt: string | null; stoppedAt: string | null; stopStatus: string | null }
export interface PumpStatus { stoppable: Omit<PumpActivation, 'plantId' | 'occurredAt'> | null; mode: PumpSettings['mode']; seconds: number | null; running: Omit<PumpActivation, 'plantId' | 'occurredAt'> | null }
export interface PumpContext extends PumpStatus { plants: { id: string; name: string; activation: PumpActivation | null }[]; plantsMeta: { current_page: number; last_page: number; total: number; per_page: number } }
export interface Asset extends DeviceAssignment {
  integrations?: AssetIntegration[];
  importedControlProvider?: PowerSettings['provider'] | null;
  powerControl?: PowerSettings | null; canControlPower?: boolean; canConfigurePower?: boolean;
  pumpControl?: PumpSettings | null; canActivate?: boolean;
  channelsCount: number; enabledChannelsCount: number;
  id: string; category: AssetCategory; name: string; status: AssetStatus;
  assetTag: string | null; manufacturer: string | null; model: string | null;
  serialNumber: string | null; description: string | null;
}
export interface AssetInput {
  power_control?: PowerSettings | null;
  pump_control?: PumpSettings | null;
  name: string; category?: AssetCategory; status: AssetStatus; asset_tag: string | null;
  manufacturer: string | null; model: string | null; serial_number: string | null;
  description: string | null; locations: Pick<LocationContext, 'type' | 'id'>[];
}
export interface Assignment { id: string; assignedAt: string; changedBy: string | null; locations: LocationContext[] }
export const assetsApi = {
 setStatus: (id: string, status: AssetStatus) => apiRequest<Asset>(`assets/${encodeURIComponent(id)}`, { status }, { method: 'PATCH' }),
  plants: (id: string, page = 1, signal?: AbortSignal) => apiRequest<Page<LinkedPlant>>(`assets/${encodeURIComponent(id)}/plants?${new URLSearchParams({ page: String(page), per_page: '20' })}`, undefined, { raw: true, signal }),
  powerTargets: (provider: string, assetId?: string) => apiRequest<{ id: string; name: string; disabled: boolean }[]>(`power-targets?${new URLSearchParams({ provider, ...(assetId ? { asset_id: assetId } : {}) })}`),
  powerContext: (id: string, signal?: AbortSignal) => apiRequest<PowerContext>(`assets/${encodeURIComponent(id)}/power`, undefined, { signal }),
  power: (id: string, isOn: boolean, requestId: string) => apiRequest<PowerCommand>(`assets/${encodeURIComponent(id)}/power`, { is_on: isOn, request_id: requestId }),
  show: (id: string) => apiRequest<Asset>(`assets/${encodeURIComponent(id)}`),
  pumpTargets: (provider: string, assetId?: string) => apiRequest<{ id: string; name: string; disabled: boolean }[]>(`pump-targets?${new URLSearchParams({ provider, ...(assetId ? { asset_id: assetId } : {}) })}`),
  pumpContext: (id: string, criteria: { search?: string; page?: string; plant_id?: string } = {}) => apiRequest<PumpContext>(`assets/${encodeURIComponent(id)}/pump-context${Object.keys(criteria).length ? `?${new URLSearchParams(criteria)}` : ''}`),
  pumpStatus: (id: string) => apiRequest<PumpStatus>(`assets/${encodeURIComponent(id)}/pump-status`),
  activatePump: (id: string, plantId: string, requestId: string) => apiRequest<PumpActivation>(`assets/${encodeURIComponent(id)}/activate`, { plant_id: plantId, request_id: requestId }),
  stopPump: (id: string, activationId: string) => apiRequest<PumpActivation>(`assets/${encodeURIComponent(id)}/pump-activations/${encodeURIComponent(activationId)}/stop`, {}),
  list: (search = '', category = '', status = '', page = 1, sort = 'name_asc', perPage = 20) => apiRequest<Page<Asset>>(`assets?${new URLSearchParams({ search, status, category, page: String(page), sort, per_page: String(perPage) })}`, undefined, { raw: true }),
  addLocation: (id: string, location: Pick<LocationContext, 'type' | 'id'>) => apiRequest<Asset>(`assets/${encodeURIComponent(id)}`, { add_location: location }, { method: 'PATCH' }),
  create: (input: AssetInput) => apiRequest<Asset>('assets', input),
  update: (id: string, input: Partial<AssetInput>) => apiRequest<Asset>(`assets/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
  history: (id: string, page = 1) => apiRequest<Page<Assignment>>(`assets/${encodeURIComponent(id)}/assignments?page=${page}`, undefined, { raw: true }),
};
