import { test } from 'node:test';
import { expect } from '@playwright/test';
import type { DateFormat } from '../src/lib/date-time.ts';
process.env.TZ = 'Europe/Paris';
globalThis.document = { documentElement: { style: { setProperty() {} } }, createElement: () => ({}), querySelector: () => null } as unknown as Document;
globalThis.localStorage = { getItem: () => null, setItem() {} } as unknown as Storage;
globalThis.window = { matchMedia: () => ({ matches: false }) } as unknown as Window & typeof globalThis;
test('display settings and template dates preserve locale, time zone and monotonic versions', async () => {
 const result = await (async () => {
  const dates = await import('../src/lib/date-time.ts');
  const { commentVariables } = await import('../src/lib/commentTemplateVariables.ts');
  dates.applyDisplaySettings({ dateFormat: 'd/m/Y', timeFormat: '24', version: 2 });
  const date = new Date('2026-11-08T22:05:00Z');
  const numeric = [dates.formatDateTime(date, 'fr'), dates.formatDateTime(date, 'en'), dates.formatDateTime('2026-11-07T23:00:00Z', 'en', 'time')];
  const variables = commentVariables({}, 'fr', date);
  dates.applyDisplaySettings({ dateFormat: 'Y-m-d', timeFormat: '12', version: 3 });
  const alternate = dates.formatDateTime(date, 'en');
  dates.applyDisplaySettings({ dateFormat: 'm/d/Y', timeFormat: '24', version: 1 });
  const staleIgnored = dates.formatDateTime(date, 'en');
  dates.applyDisplaySettings({ dateFormat: 'm/d/Y', timeFormat: '24', version: 4 });
  const us = dates.formatDateTime(date, 'en', 'date');
  dates.applyDisplaySettings({ dateFormat: 'localized', timeFormat: '24', version: 5 });
  const localized = dates.formatDateTime(date, 'fr', 'date');
  const presets = dates.dateFormats.map((dateFormat: DateFormat) => ({
   dateFormat,
   fr: dates.formatDateTime(date, 'fr', 'date', { dateFormat, timeFormat: '24' }),
   en: dates.formatDateTime(date, 'en', 'date', { dateFormat, timeFormat: '24' }),
  }));
  dates.applyDisplaySettings({ dateFormat: 'localized-long', timeFormat: '24', version: 6 });
  const longVariables = commentVariables({}, 'fr', date);
  return { numeric, variables, alternate, staleIgnored, us, localized, presets, longVariables };
 })();
 expect(result.numeric).toEqual(['08/11/2026 23:05', '08/11/2026 23:05', '00:00']);
 expect(result.variables['date.today']).toBe('08/11/2026'); expect(result.variables['date.now']).toBe('08/11/2026 23:05'); expect(result.variables['date.iso']).toBe('2026-11-08T22:05:00.000Z');
 expect(result.alternate).toMatch(/2026-11-08 11:05 PM/i); expect(result.staleIgnored).toBe(result.alternate);
 expect(result.presets).toEqual([
  { dateFormat: 'd/m/Y', fr: '08/11/2026', en: '08/11/2026' },
  { dateFormat: 'd.m.Y', fr: '08.11.2026', en: '08.11.2026' },
  { dateFormat: 'd-m-Y', fr: '08-11-2026', en: '08-11-2026' },
  { dateFormat: 'm/d/Y', fr: '11/08/2026', en: '11/08/2026' },
  { dateFormat: 'Y-m-d', fr: '2026-11-08', en: '2026-11-08' },
  { dateFormat: 'localized-short', fr: '08/11/2026', en: '11/8/26' },
  { dateFormat: 'localized', fr: '8 nov. 2026', en: 'Nov 8, 2026' },
  { dateFormat: 'localized-long', fr: '8 novembre 2026', en: 'November 8, 2026' },
  { dateFormat: 'localized-full', fr: 'dimanche 8 novembre 2026', en: 'Sunday, November 8, 2026' },
 ]);
 expect(result.longVariables['date.today']).toBe('8 novembre 2026');
 expect(result.longVariables['date.now']).toBe('8 novembre 2026 23:05');
 expect(result.longVariables['date.iso']).toBe('2026-11-08T22:05:00.000Z');
 expect(result.us).toBe('11/08/2026'); expect(result.localized).toContain('nov.');
});
