import { apiRequest } from './auth';
import type { LocationContext, Page } from './types';
export type EventCategory = 'observation' | 'maintenance' | 'incident' | 'note' | 'custom';
export type EventSeverity = 'info' | 'warning' | 'critical';
export type SubjectType = 'site' | 'room' | 'zone' | 'asset' | 'sensor';
export interface EventSubject { type: SubjectType; id: string; name: string; location: LocationContext | null; deviceName: string | null }
export interface EventSnapshot { eventType?: import('./event-types').EventTypeSnapshot | null; title: string; body: string; category: EventCategory; severity: EventSeverity; occurredAt: string; subject: EventSubject | null }
export interface EventEntry extends EventSnapshot {
  id: string; version: number; author: { id: string; name: string | null };
  createdAt: string; updatedAt: string; canEdit: boolean;
}
export interface EventRevision { id: string; version: number; recordedAt: string; editor: { id: string; name: string | null }; snapshot: EventSnapshot }
export interface EventInput { event_type_id?: string | null; title: string; body: string; category: EventCategory; severity: EventSeverity; occurred_at: string; subject: Pick<EventSubject, 'type' | 'id'> | null; version?: number }
export const eventsApi = {
  list: (filters: Record<string, string>, page = 1) => apiRequest<Page<EventEntry>>(`events?${new URLSearchParams({ ...filters, page: String(page) })}`, undefined, { raw: true }),
  create: (input: EventInput) => apiRequest<EventEntry>('events', input),
  update: (id: string, input: EventInput) => apiRequest<EventEntry>(`events/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
  show: (id: string) => apiRequest<EventEntry>(`events/${encodeURIComponent(id)}`),
  revisions: (id: string, page = 1) => apiRequest<Page<EventRevision>>(`events/${encodeURIComponent(id)}/revisions?page=${page}`, undefined, { raw: true }),
};
