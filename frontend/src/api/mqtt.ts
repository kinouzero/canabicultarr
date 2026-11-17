import { apiRequest } from './auth';
import type { Sensor } from './sensors';
import type { Page } from './types';
export interface MqttSource { id: string; sensor: Sensor; topic: string; isEnabled: boolean; version: number; instanceCurrent: boolean; lastReceivedAt: string | null; lastAttemptAt: string | null; lastError: string | null }
export const mqttApi = {
 status: () => apiRequest<{ configured: boolean }>('integrations/mqtt'),
 list: (page = 1, perPage = 20, search = '', state = '') => apiRequest<Page<MqttSource>>(`integrations/mqtt/sources?${new URLSearchParams({ page: String(page), per_page: String(perPage), ...(search ? { search } : {}), ...(state ? { state } : {}) })}`, undefined, { raw: true }),
 create: (sensor_id: string, topic: string) => apiRequest<MqttSource>('integrations/mqtt/sources', { sensor_id, topic }),
 remove: (source: MqttSource) => apiRequest<void>(`integrations/mqtt/sources/${source.id}`, { version: source.version }, { method: 'DELETE' }),
 toggle: (source: MqttSource) => apiRequest<MqttSource>(`integrations/mqtt/sources/${source.id}`, { version: source.version, is_enabled: !source.isEnabled }, { method: 'PATCH' }),
};
