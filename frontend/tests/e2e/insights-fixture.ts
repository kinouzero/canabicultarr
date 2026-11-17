import { uiPreferencesFixture } from './ui-preferences-fixture';
import type { Page } from '@playwright/test';
import { lifecycleStages, lifecycleIcons } from './lifecycle-fixture';
export const insightMeta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
export const insightSensors = ['Moisture one', 'Moisture two', 'Temperature', 'Battery'].map((name, index) => ({ id: `sensor-${index}`, name, channelKey: 'probe', isEnabled: true, expectedIntervalSeconds: 3600, description: null, device: { id: `device-${index}`, name: 'Fictional probe', status: 'active', locations: [], plants: [] }, measurementType: { id: `type-${index}`, name: index === 2 ? 'Temperature' : 'Moisture', code: `test-${index}`, nature: index === 2 ? 'temperature' : index === 3 ? 'battery' : 'substrate_moisture', unit: index === 2 ? '°C' : '%', decimalPlaces: 1 } }));
export function insightReport(url: URL) {
 const sensor = insightSensors.find(row => url.pathname.endsWith(`/${row.id}`)) ?? insightSensors[0]!;
 const to = url.searchParams.get('date_to') ?? new Date().toISOString();
 const from = url.searchParams.get('date_from') ?? new Date(+new Date(to) - 86400000).toISOString();
 const observation = { id: 'sample', value: '42', unit: sensor.measurementType.unit, observedAt: to, receivedAt: to };
 return { sensor, window: { from, to, asOf: url.searchParams.get('as_of') ?? to, groupBy: url.searchParams.get('group_by') ?? 'hour' }, statistics: { count: 2, minimum: '40', maximum: '44', mean: '42', first: { ...observation, value: '40', observedAt: from }, last: { ...observation, value: '44' }, largestGapSeconds: 1800, gapCount: 0, gapThresholdSeconds: 7200 }, buckets: [{ startAt: from, endAt: to, count: 2, minimum: '40', maximum: '44', mean: '42' }, { startAt: to, endAt: to, count: 0, minimum: null, maximum: null, mean: null }], alerts: { overlapping: 1, opened: 1, resolved: 0, acknowledged: 0, activeAtEnd: 1 }, exportLimit: 20000 };
}
export async function insightRoutes(page: Page, locale: 'en' | 'fr' = 'fr') {
 const preferences = uiPreferencesFixture('insight-reader');
 const requests: URL[] = []; const errors: string[] = [];
 page.on('pageerror', error => errors.push(error.stack ?? error.message));
 await page.route('**/api/v1/**', async route => {
  const url = new URL(route.request().url()); const path = url.pathname; requests.push(url);
  if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (route.request().method() !== 'GET') throw new Error('Insights must not mutate data');
  const data = (value: unknown) => route.fulfill({ json: { data: value } });
  const list = (value: unknown[]) => route.fulfill({ json: { data: value, meta: { ...insightMeta, total: value.length } } });
  if (path.endsWith('/setup')) return data({ requiresSetup: false });
  if (path.endsWith('/auth/oidc')) return data({ enabled: false, result: null });
  if (path.endsWith('/auth/user')) return data({ id: 'insight-reader', name: 'Insights reader', email: 'insights@example.test', role: 'member', permissions: [], isActive: true, oidcLinked: false });
  if (path.endsWith('/instance-settings')) return data({ siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: false });
  if (path.endsWith('/lifecycle-settings')) return data({ stages: lifecycleStages, icons: lifecycleIcons, durations: { flowering: 56 }, version: 1, canManage: false });
  if (path.endsWith('/plant-dashboard')) return route.fulfill({ json: { data: [], meta: { ...insightMeta, total: 0 }, summary: { active: 0, planned: 0, due: 0, total: 0 }, filterOptions: { strains: [] }, groups: {} } });
  if (path.endsWith('/sites')) return list([{ id: 'site-one', name: 'Fictional site' }]);
  if (path.endsWith('/sensors')) return list(insightSensors);
  if (path.endsWith('/measurement-types')) return list([{ id: 'type-0', name: 'Moisture', unit: '%' }]);
  if (/^\/api\/v1\/sensors\/sensor-\d$/.test(path)) return data(insightSensors.find(sensor => path.endsWith(sensor.id)));
  if (/\/reports\/sensors\/sensor-\d$/.test(path)) return data(insightReport(url));
  if (/\/reports\/sensors\/sensor-\d\/export$/.test(path)) return route.fulfill({ contentType: 'text/csv', headers: { 'Content-Disposition': 'attachment; filename="fictional-readings.csv"' }, body: 'observed_at,value\n2026-11-09T10:00:00Z,42\n' });
  if (/\/sensors\/sensor-\d\/measurements$/.test(path)) {
   const sensor = insightSensors.find(row => path.includes(`/${row.id}/`))!;
   const latest = { id: 'latest', value: '44', unit: sensor.measurementType.unit, observedAt: new Date().toISOString(), receivedAt: new Date().toISOString(), location: null, provenance: { status: 'recorded', revisionId: 1, source: { kind: 'http', id: 'fictional-source', instanceKey: null }, assignments: { asset: { id: 'fictional-device', name: 'Original probe' }, locations: [{ type: 'site', id: 'original-site', site: { id: 'original-site', name: 'Original measurement site' }, room: null, zone: null }], plants: [] } } };
   return route.fulfill({ json: { data: [{ ...latest, id: `sample-${url.searchParams.get('page') ?? '1'}` }], meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 2, total: 51, per_page: 50 }, summary: { sensor, latest, status: 'fresh' } } });
  }
  if (path.endsWith('/measurements/overview')) {
   const status = url.searchParams.get('status') || 'fresh';
   return route.fulfill({ json: { data: [{ sensor: insightSensors[0], latest: { id: 'sample', value: '44', unit: '%', observedAt: new Date().toISOString(), receivedAt: new Date().toISOString(), location: null }, status }], summary: { total: 3, fresh: 1, stale: 1, missing: 1, unknown: 0, disabled: 0 }, meta: insightMeta, generatedAt: new Date().toISOString() } });
  }
  if (path.endsWith('/culture/overview')) return data({ generatedAt: new Date().toISOString(), days: Number(url.searchParams.get('days') ?? 7), plants: { total: 5, active: url.searchParams.get('site_id') ? 2 : 3, planned: 1, archived: 1, wateringDue: 1, lifecycleDue: 1, due: 1, harvested: 1, harvestWeightGrams: '125.50', byPhase: { planned: 1, growth: 3, archived: 1 } }, locations: [{ key: 'site-one', location: { type: 'site', id: 'site-one', site: { id: 'site-one', name: 'Fictional site' }, room: null, zone: null }, active: 3, planned: 1, due: 1 }], strains: [{ id: 'strain-one', name: 'Fictional strain', active: 3, harvested: 1, harvestWeightGrams: '125.50' }], upcoming: [], activity: [{ day: '2026-11-08', waterings: 2, maintenance: 1, transitions: 0 }, { day: '2026-11-09', waterings: 1, maintenance: 0, transitions: 1 }] });
  return list([]);
 });
 await page.addInitScript(value => localStorage.setItem('locale', value), locale);
 await page.goto('/');
 return { requests, errors, preferences };
}
