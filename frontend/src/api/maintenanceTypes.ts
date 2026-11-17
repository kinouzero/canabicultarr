import { apiRequest } from './auth';
export interface MaintenanceTypeRecord { id: string; name: string; version: number }
export const maintenanceTypesApi = {
 list: () => apiRequest<MaintenanceTypeRecord[]>('maintenance-types'),
 create: (name: string) => apiRequest<MaintenanceTypeRecord>('maintenance-types', { name }),
 update: (record: MaintenanceTypeRecord, name: string) => apiRequest<MaintenanceTypeRecord>(`maintenance-types/${encodeURIComponent(record.id)}`, { name, version: record.version }, { method: 'PATCH' }),
 remove: (record: MaintenanceTypeRecord) => apiRequest<void>(`maintenance-types/${encodeURIComponent(record.id)}`, { version: record.version }, { method: 'DELETE' }),
};
