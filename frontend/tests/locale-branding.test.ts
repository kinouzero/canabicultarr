import assert from 'node:assert/strict';
import { test } from 'node:test';
import { resolveLocale } from '../src/lib/locale.ts';
import { applyBranding, siteBranding } from '../src/lib/branding.ts';

test('locale selection honors explicit choices, browser preferences and the supported fallback', () => {
 assert.deepEqual([resolveLocale(null, ['de-DE', 'fr-FR']), resolveLocale(null, ['en-GB', 'fr-FR']), resolveLocale(null, ['es-ES']), resolveLocale('fr', ['en-US']), resolveLocale('unsupported', ['FR-ca']), resolveLocale(null, [])], ['fr', 'en', 'en', 'fr', 'fr', 'en']);
});

test('late public branding cannot replace newer authenticated settings', () => {
 applyBranding({ siteTitle: 'Current title', siteSubtitle: null, version: 2 });
 const current = siteBranding.value;
 applyBranding({ siteTitle: 'Stale public response', siteSubtitle: 'Stale', version: 1 });
 assert.equal(siteBranding.value, current);
});
