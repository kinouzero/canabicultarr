import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { openModule } from './navigation';
import { insightRoutes, insightSensors } from './insights-fixture';
import { uiPreferencesFixture } from './ui-preferences-fixture';

for (const permission of ['assets.manage', 'sensors.manage']) {
 test(`hardware categories and contextual channels preserve ${permission} permission boundaries`, async ({ page }) => {
  const preferences = uiPreferencesFixture('fictional-user');
  const failures: string[] = []; const requestedDevices: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  const categories = ['sensor', 'socket', 'humidifier', 'dehumidifier', 'light', 'fan', 'pump', 'other'];
  const assets = categories.map((category, index) => ({ id: `fictional-hardware-${index}`, name: `Fictional ${category}`, category, status: 'active', assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null, locations: [], plants: [] }));
  await page.route('**/api/v1/**', async route => {
   const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
   if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
   if (request.method() !== 'GET') throw new Error('Synthetic hardware checks must not write');
   const data = (value: unknown) => route.fulfill({ json: { data: value } });
   const list = (value: unknown[]) => route.fulfill({ json: { data: value, meta: { current_page: 1, last_page: 1, total: value.length, per_page: 20 } } });
   if (path.endsWith('/setup')) return data({ requiresSetup: false });
   if (path.endsWith('/auth/oidc')) return data({ enabled: false, result: null });
   if (path.endsWith('/auth/user')) return data({ id: 'fictional-user', name: 'Fictional User', email: 'fictional@example.test', role: 'member', permissions: [permission], oidcLinked: false, isActive: true, locationAccess: null });
   if (/\/assets\/fictional-hardware-\d+$/.test(path)) return data(assets.find(row => path.endsWith(row.id)));
   if (path.endsWith('/assets')) return list(assets.filter(asset => !url.searchParams.get('category') || asset.category === url.searchParams.get('category')));
   if (path.endsWith('/sensors')) {
    const device = url.searchParams.get('device_id') ?? ''; requestedDevices.push(device);
    return list(device === assets[0]!.id ? [{ id: 'fictional-channel', name: 'Fictional temperature', channelKey: 'temperature', isEnabled: true, expectedIntervalSeconds: 60, description: null, device: assets[0], measurementType: { id: 'fictional-type', name: 'Temperature', code: 'temperature', unit: '°C', decimalPlaces: 2 } }] : []);
   }
   return list([]);
  });
  await page.addInitScript(() => localStorage.setItem('locale', 'fr')); await page.goto('/'); await openModule(page, 'assets');
  await expect(page.locator('#asset-kind')).toHaveCount(0);
  await expect(page.locator('.asset-node')).toHaveCount(8);
  await (await revealTabbedControl(page.locator('#assets-category-filter'))).selectOption('fan');
  await expect(page.locator('.asset-node')).toHaveCount(1); await expect(page.getByRole('heading', { name: 'Ouvrir Fictional fan', exact: true })).toBeVisible();
  await (await revealTabbedControl(page.locator('#assets-category-filter'))).selectOption('');
  await expect(page.locator('.asset-node')).toHaveCount(8);
  if (permission === 'assets.manage') {
   await page.getByRole('button', { name: 'Modifier Fictional fan', exact: true }).click();
   await expect(page.locator('#asset-category')).toBeEnabled(); await (await revealTabbedControl(page.locator('#asset-category'))).selectOption('dehumidifier');
   await page.getByRole('button', { name: 'Annuler', exact: true }).click();
   await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
  } else await expect(page.getByRole('button', { name: 'Ajouter du matériel', exact: true })).toHaveCount(0);
  if (process.env.PLAYWRIGHT_HARDWARE_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_HARDWARE_SCREENSHOT + '-desktop.png', fullPage: true });
  await page.getByRole('button', { name: 'Canaux de mesure de Fictional sensor', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Fictional sensor', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Fictional temperature', exact: true })).toBeVisible();
  if (permission === 'sensors.manage') {
   await page.getByRole('button', { name: 'Ajouter un canal', exact: true }).click();
   await expect(page.locator('#sensor-device')).toBeDisabled();
   await expect(page.locator('#sensor-device option:checked')).toHaveText('Fictional sensor');
   await page.getByRole('button', { name: 'Annuler', exact: true }).click();
  } else await expect(page.getByRole('button', { name: 'Ajouter un canal', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Fermer la fiche matériel', exact: true }).click();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: 'Canaux de mesure de Fictional fan', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fictional temperature', exact: true })).toHaveCount(0);
  await expect(page.getByRole('dialog', { name: 'Fictional fan', exact: true })).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  if (process.env.PLAYWRIGHT_HARDWARE_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_HARDWARE_SCREENSHOT + '-mobile.png', fullPage: true });
  expect(requestedDevices).toContain(assets[0]!.id); expect(requestedDevices).toContain(assets[5]!.id);
  expect(failures).toEqual([]);
 });
}


test('linked plant assignments replace the unassigned label in hardware, channels and measurements', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const location = { type: 'zone', id: 'zone-linked', site: { id: 'site-one', name: 'Plant site' }, room: { id: 'room-linked', name: 'Plant room' }, zone: { id: 'zone-linked', name: 'Plant zone' } };
 const assigned = { id: 'device-linked', name: 'Linked probe', category: 'sensor', status: 'active', locations: [], plants: [{ id: 'plant-linked', name: 'Linked plant', location }], channelsCount: 1, enabledChannelsCount: 1, assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null };
 const manual = { ...assigned, id: 'device-manual', name: 'Manual fan', category: 'fan', plants: [], channelsCount: 0, enabledChannelsCount: 0 };
 const sensor = { ...insightSensors[0], device: assigned };
 const meta = { current_page: 1, last_page: 1, per_page: 20, total: 1 };
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'insight-reader', name: 'Assignment administrator', email: 'assignment@example.test', role: 'admin', permissions: [], isActive: true, oidcLinked: false } } }));
 await page.route('**/api/v1/assets/device-linked', route => route.fulfill({ json: { data: assigned } }));
 await page.route('**/api/v1/assets?*', route => route.fulfill({ json: { data: [assigned, manual], meta: { ...meta, total: 2 } } }));
 await page.route('**/api/v1/sensors?*', route => route.fulfill({ json: { data: [sensor], meta } }));
 await page.route('**/api/v1/measurements/overview?*', route => route.fulfill({ json: { data: [{ sensor, latest: null, status: 'missing' }], meta, summary: { total: 1, fresh: 0, stale: 0, missing: 1, unknown: 0, disabled: 0 }, generatedAt: new Date().toISOString() } }));
 await page.reload(); await openModule(page, 'assets');
 const card = page.locator('.asset-node').filter({ has: page.getByRole('button', { name: 'Ouvrir Linked probe', exact: true }) });
 await expect(card).toContainText('Plante : Linked plant');
 await expect(card.getByRole('link', { name: 'Plant site', exact: true })).toBeVisible(); await expect(card.getByRole('link', { name: 'Plant room', exact: true })).toBeVisible(); await expect(card.getByRole('link', { name: 'Plant zone', exact: true })).toBeVisible();
 await expect(card).not.toContainText('Sans affectation');
 await expect(page.locator('.asset-node').filter({ hasText: 'Manual fan' })).toContainText('Sans affectation');
 await card.getByRole('button', { name: 'Ouvrir Linked probe', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Linked probe', exact: true });
 await expect(detail.locator('.device-detail-summary')).toContainText('Plante : Linked plant');
 await expect(detail.locator('.sensor-grid')).toContainText('Plante : Linked plant');
 await expect(detail.locator('.sensor-grid')).not.toContainText('Sans affectation');
 await detail.getByRole('button', { name: 'Fermer la fiche matériel', exact: true }).click();
 await openModule(page, 'sensors');
 await expect(page.locator('.sensor-grid')).toContainText('Plante : Linked plant');
 await openModule(page, 'measurements');
 await expect(page.locator('.reading-list')).toContainText('Plante : Linked plant');
 await expect(page.locator('.reading-list')).toContainText('Plant site'); await expect(page.locator('.reading-list')).toContainText('Plant room'); await expect(page.locator('.reading-list')).toContainText('Plant zone');
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 expect(errors).toEqual([]);
});
