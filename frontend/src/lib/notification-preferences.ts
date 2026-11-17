import type { NotificationPreferences } from '../api/notifications';

// Browser registration can update consent/version while the personal form has edits.
export function mergeNotificationPreferences(draft: NotificationPreferences, previous: NotificationPreferences, current: NotificationPreferences): NotificationPreferences {
 return {
  email_enabled: draft.email_enabled !== previous.email_enabled ? draft.email_enabled : current.email_enabled,
  push_enabled: draft.push_enabled !== previous.push_enabled ? draft.push_enabled : current.push_enabled,
  locale: draft.locale !== previous.locale ? draft.locale : current.locale,
  version: current.version,
 };
}
