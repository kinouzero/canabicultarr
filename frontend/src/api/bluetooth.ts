import { apiRequest } from './auth';
import type { Page } from './types';
export interface BluetoothChannel { id: string; sensorId: string; kind: string; name: string; version: number; isEnabled: boolean; lastSeenAt: string | null }
export interface BluetoothRecord { id: string; assetId: string; name: string; version: number; isEnabled: boolean; channels: BluetoothChannel[] }
export interface BluetoothCandidate { id: string; name: string; rssi: number | null }
export interface BluetoothOperation { id: string; kind: 'discover' | 'pair' | 'read'; status: 'pending' | 'completed' | 'failed'; data: BluetoothCandidate[] | BluetoothRecord | { stored: number; duplicates: number } | null; error: string | null }
const root = 'integrations/bluetooth';
export const bluetoothApi = {
 status: () => apiRequest<{ enabled: boolean; adapter: string; transport?: 'bluez' | 'relay'; operationId: string | null }>(`${root}/status`),
 list: (page = 1, search = '', state = '') => apiRequest<Page<BluetoothRecord>>(`${root}/devices?${new URLSearchParams({ page: String(page), per_page: '20', ...(search ? { search } : {}), ...(state ? { state } : {}) })}`, undefined, { raw: true }),
 discover: (requestId: string) => apiRequest<BluetoothOperation>(`${root}/discovery`, { request_id: requestId }),
 operation: (id: string) => apiRequest<BluetoothOperation>(`${root}/operations/${id}`),
 pair: (discoveryId: string, candidateId: string, requestId: string) => apiRequest<BluetoothOperation>(`${root}/devices`, { discovery_id: discoveryId, candidate_id: candidateId, request_id: requestId }),
 read: (device: BluetoothRecord, requestId: string) => apiRequest<BluetoothOperation>(`${root}/devices/${device.id}/readings`, { version: device.version, request_id: requestId }),
 remove: (device: BluetoothRecord) => apiRequest<void>(`${root}/devices/${device.id}`, { version: device.version }, { method: 'DELETE' }),
 toggle: (channel: BluetoothChannel) => apiRequest<void>(`${root}/links/${channel.id}`, { version: channel.version, is_enabled: !channel.isEnabled }, { method: 'PATCH' }),
};
