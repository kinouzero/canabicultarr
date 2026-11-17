import { apiRequest } from './auth';
export type ConnectionGroup = 'authentication' | 'storage' | 'mail' | 'home_assistant' | 'homebridge' | 'mqtt' | 'oidc' | 'webhook' | 'push';
export interface ConnectionField {
 name: string; type: 'text' | 'url' | 'port' | 'email' | 'secret' | 'boolean' | 'select' | 'interval'; min?: number; max?: number; step?: number; options: string[];
 locked: boolean; hasValue: boolean; value: string | number | boolean | null; overridden: boolean;
}
export interface ConnectionSettings { version: number; fields: ConnectionField[] }
export const connectionSettingsApi = {
 get: (group: ConnectionGroup) => apiRequest<ConnectionSettings>(`connection-settings/${group}`),
 save: (group: ConnectionGroup, version: number, values: Record<string, unknown>) => apiRequest<ConnectionSettings>(`connection-settings/${group}`, { version, values }, { method: 'PATCH' }),
 check: (group: ConnectionGroup, locale: string) => apiRequest<{ message: string }>(`connection-settings/${group}/check`, { locale }),
};
