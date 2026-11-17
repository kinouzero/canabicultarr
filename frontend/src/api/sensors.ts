import { apiRequest } from './auth';
import type { Page } from './types';
import type { AssetCategory, AssetStatus, DeviceAssignment } from './assets';

export const measurementNatures = ["other", "temperature", "air_humidity", "substrate_moisture", "illuminance", "carbon_dioxide", "ph", "conductivity", "pressure", "power", "energy", "water_level", "battery", "binary"] as const;
export type MeasurementNature = typeof measurementNatures[number];

export interface ReferenceOption { id: string; name: string; detail: string; disabled?: boolean }
export interface MeasurementType {
  id: string; name: string; code: string; unit: string | null;
  nature: MeasurementNature; decimalPlaces: number; description: string | null; sensorsCount: number;
}
export interface MeasurementTypeInput { nature: MeasurementNature; name: string; code: string; unit: string | null; decimal_places: number; description: string | null }
export type MeasurementTypeSummary = Pick<MeasurementType, 'id' | 'name' | 'code' | 'unit' | 'nature' | 'decimalPlaces'>;
export interface SensorDevice extends DeviceAssignment { id: string; name: string; category: AssetCategory; status: AssetStatus }
export interface Sensor {
  switchControl?: { kind: 'switch' | 'pump'; canControl: boolean } | null;
  id: string; name: string; channelKey: string; isEnabled: boolean;
  expectedIntervalSeconds: number | null; description: string | null;
  device: SensorDevice; measurementType: MeasurementTypeSummary;
}
export interface SensorInput {
  name: string; device_id?: string; measurement_type_id?: string; channel_key?: string;
  is_enabled: boolean; expected_interval_seconds: number | null; description: string | null;
}
export const measurementTypesApi = {
  list: (search = '', page = 1, sort = 'name_asc', perPage = 20) => apiRequest<Page<MeasurementType>>(`measurement-types?${new URLSearchParams({ search, page: String(page), sort, per_page: String(perPage) })}`, undefined, { raw: true }),
  create: (input: MeasurementTypeInput) => apiRequest<MeasurementType>('measurement-types', input),
  update: (id: string, input: MeasurementTypeInput) => apiRequest<MeasurementType>(`measurement-types/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
};
export const sensorsApi = {
  switchPower: (id: string, on: boolean, requestId: string) => apiRequest<{ status: string; isOn: boolean }>(`sensors/${encodeURIComponent(id)}/switch`, { is_on: on, request_id: requestId }, { method: 'POST' }),
 setEnabled: (id: string, enabled: boolean) => apiRequest<Sensor>(`sensors/${encodeURIComponent(id)}`, { is_enabled: enabled }, { method: 'PATCH' }),
  get: (id: string) => apiRequest<Sensor>(`sensors/${encodeURIComponent(id)}`),
  list: (search = '', enabled = '', page = 1, deviceId = '', sort = 'name_asc', perPage = 20) => apiRequest<Page<Sensor>>(`sensors?${new URLSearchParams({ search, device_id: deviceId, is_enabled: enabled, page: String(page), sort, per_page: String(perPage) })}`, undefined, { raw: true }),
  create: (input: SensorInput) => apiRequest<Sensor>('sensors', input),
  update: (id: string, input: SensorInput) => apiRequest<Sensor>(`sensors/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
};
