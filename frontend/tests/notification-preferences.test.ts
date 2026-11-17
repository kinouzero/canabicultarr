import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mergeNotificationPreferences } from '../src/lib/notification-preferences.ts';
import type { NotificationPreferences } from '../src/api/notifications';

test('browser registration adopts new consent and version without replacing unsaved email or language', () => {
 const previous: NotificationPreferences = { email_enabled: false, push_enabled: false, locale: 'en', version: 1 };
 const draft: NotificationPreferences = { ...previous, email_enabled: true, locale: 'fr' };
 const current: NotificationPreferences = { ...previous, push_enabled: true, version: 2 };
 assert.deepEqual(mergeNotificationPreferences(draft, previous, current), { email_enabled: true, push_enabled: true, locale: 'fr', version: 2 });
 assert.deepEqual(draft, { ...previous, email_enabled: true, locale: 'fr' });
 assert.deepEqual(previous, { email_enabled: false, push_enabled: false, locale: 'en', version: 1 });
});

test('an unsaved push opt-out stays explicit while untouched fields adopt concurrent preferences', () => {
 const previous: NotificationPreferences = { email_enabled: true, push_enabled: true, locale: 'en', version: 2 };
 const current: NotificationPreferences = { email_enabled: false, push_enabled: true, locale: 'fr', version: 4 };
 assert.deepEqual(mergeNotificationPreferences({ ...previous, push_enabled: false }, previous, current), { ...current, push_enabled: false });
 assert.deepEqual(mergeNotificationPreferences(previous, previous, current), current);
});
