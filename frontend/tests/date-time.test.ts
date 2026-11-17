import assert from 'node:assert/strict';
import { test } from 'node:test';

process.env.TZ = 'UTC';
globalThis.window = { matchMedia: () => ({ matches: false }) } as unknown as Window & typeof globalThis;
const { formatDateTime, applyDisplaySettings, instanceDisplaySettings, resetDisplaySettings } = await import('../src/lib/date-time.ts');

test('numeric date formats and 12/24 hour clocks preserve midnight and year boundaries', () => {
 const date = '2026-12-31T00:05:00Z';
 for (const [dateFormat, expected] of [['d/m/Y', '31/12/2026'], ['d.m.Y', '31.12.2026'], ['d-m-Y', '31-12-2026'], ['m/d/Y', '12/31/2026'], ['Y-m-d', '2026-12-31']] as const) {
  assert.equal(formatDateTime(date, 'en-US', 'date', { dateFormat, timeFormat: '24' }), expected);
 }
 assert.equal(formatDateTime(date, 'en-US', 'time', { dateFormat: 'Y-m-d', timeFormat: '24' }), '00:05');
 assert.equal(formatDateTime(date, 'en-US', 'time', { dateFormat: 'Y-m-d', timeFormat: '12' }), '12:05 AM');
 assert.equal(formatDateTime(date, 'en-US', 'datetime', { dateFormat: 'Y-m-d', timeFormat: '24' }), '2026-12-31 00:05');
});

test('localized long formats retain the selected language', () => {
 assert.match(formatDateTime('2026-12-31T00:05:00Z', 'fr-FR', 'date', { dateFormat: 'localized-long', timeFormat: '24' }), /31 décembre 2026/);
 assert.match(formatDateTime('2026-12-31T00:05:00Z', 'en-US', 'date', { dateFormat: 'localized-full', timeFormat: '24' }), /Thursday, December 31, 2026/);
});

test('invalid display settings cannot overwrite the last accepted settings', () => {
 resetDisplaySettings(); const previous = instanceDisplaySettings.value;
 for (const input of [null, false, 'invalid', {}, { dateFormat: 'Y-m-d', timeFormat: '24', version: 0 }, { dateFormat: 'invalid', timeFormat: '24', version: 1 }, { dateFormat: 'Y-m-d', timeFormat: 'invalid', version: 1 }]) {
  applyDisplaySettings(input); assert.equal(instanceDisplaySettings.value, previous);
 }
});
