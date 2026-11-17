import { apiRequest } from './auth';
import type { Page } from './types';
export interface NotificationPreferences { email_enabled: boolean; push_enabled: boolean; locale: 'en' | 'fr'; version: number }
export interface NotificationSettings extends NotificationPreferences { webhook_enabled: boolean; include_recovery: boolean; minimum_severity: 'info' | 'warning' | 'critical' }
export interface NotificationStatus {
  settings: NotificationSettings; canManage: boolean;
  channels: { email: { configured: boolean }; push: { configured: boolean; publicKey: string | null }; webhook: { configured: boolean; format: 'generic' | 'ntfy' | 'apprise' } };
}
export interface BrowserSubscription { id: string; createdAt: string; lastUsedAt: string | null; currentKey: boolean; accountCurrent: boolean }
export interface Delivery { id: string; episodeId: string | null; event: 'opened' | 'recovered' | 'test'; channel: 'email' | 'push' | 'webhook'; status: 'pending' | 'sending' | 'sent' | 'failed' | 'cancelled'; attempts: number; errorCode: string | null; createdAt: string; sentAt: string | null; nextAttemptAt: string }
export interface InboxNotification { id: string; name: string; sensorName: string | null; severity: 'info' | 'warning' | 'critical'; event: 'opened' | 'recovered'; occurredAt: string; unread: boolean }
export interface NotificationInboxFeed { unreadCount: number; readThrough: string; items: InboxNotification[]; page: number; lastPage: number }
export const notificationsApi = {
  inbox: (page = 1, summary = false) => apiRequest<NotificationInboxFeed>(`notifications/inbox?page=${page}&summary=${summary ? 1 : 0}`),
  readInbox: (through: string) => apiRequest<void>('notifications/inbox/read', { read_through: through }),
  status: () => apiRequest<NotificationStatus>('notifications/settings'),
  updateSettings: (data: NotificationSettings) => apiRequest<NotificationStatus>('notifications/settings', data, { method: 'PATCH' }),
  preferences: () => apiRequest<NotificationPreferences>('notifications/preferences'),
  updatePreferences: (data: NotificationPreferences) => apiRequest<NotificationPreferences>('notifications/preferences', data, { method: 'PATCH' }),
  subscriptions: () => apiRequest<BrowserSubscription[]>('notifications/subscriptions'),
  subscribe: (data: PushSubscriptionJSON) => apiRequest<{ id: string; preferences: NotificationPreferences }>('notifications/subscriptions', { endpoint: data.endpoint, keys: data.keys }),
  remove: (id: string) => apiRequest<void>(`notifications/subscriptions/${encodeURIComponent(id)}`, {}, { method: 'DELETE' }),
  removeBrowser: (endpoint: string) => apiRequest<void>('notifications/subscriptions/remove-browser', { endpoint }),
  deliveries: (page = 1, perPage = 20) => apiRequest<Page<Delivery>>(`notifications/deliveries?page=${page}&per_page=${perPage}`, undefined, { raw: true }),
};
