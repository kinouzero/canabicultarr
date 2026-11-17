import { apiRequest } from './auth';
import type { Page } from './types';
export const systemEventTypes = { observation: 'eye', maintenance: 'scissors', incident: 'warning', note: 'message' } as const;
export type EventContextKind = 'none' | 'site' | 'room' | 'zone' | 'asset' | 'sensor';
export interface EventTypeSnapshot { version?: number; contexts?: EventContextKind[]; id: string; name: string; icon: string }
export interface EventType extends EventTypeSnapshot { contexts: EventContextKind[]; is_archived: boolean; version: number }
export interface EventTypePage extends Page<EventType> { canManage: boolean; icons: string[]; contexts: EventContextKind[] }
export interface EventTypeInput { name: string; icon: string; contexts: EventContextKind[]; is_archived: boolean; version?: number }
export const eventTypesApi = {
 list: (search = '', state = 'all', page = 1, perPage = 20, sort = 'name_asc') => apiRequest<EventTypePage>(`event-types?${new URLSearchParams({ search, state, page: String(page), per_page: String(perPage), sort })}`, undefined, { raw: true }),
 create: (input: EventTypeInput) => apiRequest<EventType>('event-types', input),
 update: (type: EventType, input: EventTypeInput) => apiRequest<EventType>(`event-types/${encodeURIComponent(type.id)}`, { ...input, version: type.version }, { method: 'PATCH' }),
};
