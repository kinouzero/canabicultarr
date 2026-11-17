import { revealTabbedControl } from './navigation';
import { dashboardPayload } from './dashboard-payload-fixture';
import { uiPreferencesFixture } from './ui-preferences-fixture';
import { lifecycleStages, lifecycleIcons } from './lifecycle-fixture';
import { openModule } from './navigation';
import { test, expect } from '../coverage/fixture';

// Synthetic transport responses exercise navigation/races; PHP tests cover actual scope and photo access.
test('home groups plants by site/room/zone, ignores stale responses and supports a keyboard-accessible mobile menu', async ({ page }) => {
 const siteA = '00000000-0000-4000-8000-000000000001', siteB = '00000000-0000-4000-8000-000000000002';
 const now = new Date().toISOString();
 const location = (site: string, room: string | null, zone: string | null) => ({ type: zone ? 'zone' : room ? 'room' : 'site', id: zone ?? room ?? site, site: { id: site, name: site === siteA ? 'First Site' : 'Second Site' }, room: room ? { id: room, name: 'Main Room' } : null, zone: zone ? { id: zone, name: zone === 'zone-one' ? 'North Zone' : 'South Zone' } : null });
 const plants = [
  { id: '10000000-0000-4000-8000-000000000001', name: 'Plant Alpha', location: location(siteA, 'room-one', 'zone-one') },
  { id: '10000000-0000-4000-8000-000000000002', name: 'Plant Beta', location: location(siteA, 'room-one', null) },
  { id: '10000000-0000-4000-8000-000000000003', name: 'Plant Gamma', location: location(siteA, null, null) },
  { id: '10000000-0000-4000-8000-000000000004', name: 'Plant Delta', location: location(siteB, null, null) },
  { id: '10000000-0000-4000-8000-000000000005', name: 'Plant Epsilon', location: location(siteA, 'room-one', 'zone-two') },
 ].map(row => ({ ...row, strain: { id: 'strain-one', name: 'Fictional Strain' }, phase: 'growth', phaseStartedAt: now, phaseDueAt: now, day: 8, week: 2, isOverdue: true, nextAction: 'repot_flowering', photoUrl: null, watering: { status: 'alternate', last: { occurredAt: now, withProducts: true }, nextWithProducts: false, flushStartsAt: null, flushDays: 14 } }));
 const meta = { current_page: 1, last_page: 1, total: 4, per_page: 20 };
 const preferences = uiPreferencesFixture('synthetic-member');
 let delayB = true, releaseB: () => void = () => {};
 const pendingB = new Promise<void>(resolve => { releaseB = resolve; });
 let markBComplete: () => void = () => {};
 const completedB = new Promise<void>(resolve => { markBComplete = resolve; });
 const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/application-info')) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (path.endsWith('/lifecycle-settings')) return route.fulfill({ json: { data: { stages: lifecycleStages, icons: lifecycleIcons, durations: {}, version: 1, canManage: false } } });
  if (path.endsWith('/notifications/inbox')) return route.fulfill({ json: { data: [], unreadCount: 0, meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
  if (path.endsWith('/branding')) return route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, version: 1 } } });
  if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: false } } });
  if (path.endsWith('/photos')) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'synthetic-member', name: 'Fictional Reader', email: 'reader@example.test', role: 'member', permissions: [], oidcLinked: false, isActive: true } } });
  if (path.endsWith('/sites')) {
   return route.fulfill({ json: { data: [{ id: siteA, name: 'First Site' }, { id: siteB, name: 'Second Site' }], meta: { current_page: 1, last_page: 1, total: 2, per_page: 20 } } });
  }
  if (path.endsWith('/plant-dashboard')) {
   const id = path.includes(siteA) ? siteA : siteB;
   if (id === siteB && delayB) { await pendingB; }
   const data = plants.filter(row => row.location.site.id === id).reverse();
   await route.fulfill({ json: { ...dashboardPayload(data, new URL(route.request().url())), meta: { ...meta, total: data.length }, site: { id, name: id === siteA ? 'First Site' : 'Second Site' }, summary: { plants: data.length, due: data.length, strains: 1 } } });
   if (id === siteB) markBComplete?.(); return;
  }
  if (path.endsWith('/plants')) return route.fulfill({ json: { data: plants, meta } });
  if (path.endsWith('/events')) return route.fulfill({ json: { data: [], meta: { ...meta, total: 0 } } });
  const row = plants.find(plant => path.endsWith('/'+plant.id));
  if (row) return route.fulfill({ json: { data: { ...row, notes: null, devices: [], version: 1, canManage: false, startedAt: now, durationSnapshot: { durations: { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 }, settings_version: 1, strain_version: 1 } } } });
  if (path.includes('/strains/')) return route.fulfill({ status: 404, json: { error: { code: 'auth.errors.requestFailed' } } });
  throw new Error(`Unexpected synthetic home request: ${path}`);
 });
 try {
  await page.addInitScript(() => { if (!localStorage.getItem('locale')) localStorage.setItem('locale', 'fr'); }); await page.goto('/');
  const home = page.getByTestId('plant-home');
  await expect(home.getByTestId('home-plants')).toHaveText('4');
  await expect(home.getByTestId(`plant-card-${plants[0]!.id}`).locator('.photo-age')).toHaveText('S2 · J8');
  await expect(home.locator('#dashboard-site').locator('option')).toHaveText(['First Site', 'Second Site']);
  await expect(page.getByTestId(`plant-card-${plants[0]!.id}`)).toContainText('Prochain : sans fertilisant');
  await expect(home.getByRole('heading', { name: 'Main Room', exact: true }).first()).toBeVisible();
  await expect(home.getByRole('heading', { name: 'North Zone', exact: true })).toBeVisible();
  expect(await home.locator('.photo-plant').evaluateAll(rows => rows.map(row => row.getAttribute('data-testid')))).toEqual([plants[0]!, plants[1]!].map(row => `plant-card-${row.id}`));
  await expect(home.getByRole('heading', { name: 'Sans pièce ni zone', exact: true })).toBeVisible();
  const rooms = home.locator('details.home-room');
  await expect(rooms).toHaveCount(2);
  await expect(rooms.first()).toHaveAttribute('open', '');
  await expect(rooms.nth(1)).not.toHaveAttribute('open');
  const zones = rooms.first().locator('details.home-zone');
  await expect(zones).toHaveCount(2);
  await expect(zones.first()).toHaveAttribute('open', '');
  await expect(zones.nth(1)).not.toHaveAttribute('open');
  await expect(home.getByTestId(`plant-card-${plants[4]!.id}`)).not.toBeVisible();
  await zones.first().locator('summary').focus(); await page.keyboard.press('Space');
  await expect(zones.first()).not.toHaveAttribute('open');
  await rooms.first().locator(':scope > summary').focus(); await page.keyboard.press('Enter');
  await expect(rooms.first()).not.toHaveAttribute('open');
  await home.getByRole('button', { name: 'Actualiser les plantes', exact: true }).click();
  await expect(home.getByTestId('home-plants')).toHaveText('4');
  await expect(rooms.first()).not.toHaveAttribute('open');
  await rooms.first().locator(':scope > summary').click();
  await expect(zones.first()).not.toHaveAttribute('open');
  await zones.first().locator('summary').click();
  await expect(home.getByTestId(`plant-card-${plants[0]!.id}`)).toBeVisible();
  await zones.nth(1).locator('summary').click();
  await expect(home.getByTestId(`plant-card-${plants[4]!.id}`)).toBeVisible();
  await rooms.nth(1).locator(':scope > summary').click();
  await expect(home.getByTestId(`plant-card-${plants[2]!.id}`)).toBeVisible();
  await expect(home.getByRole('button', { name: 'Mesures et Home Assistant', exact: true })).toHaveCount(0);
  if (process.env.PLAYWRIGHT_HOME_DESKTOP_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_HOME_DESKTOP_SCREENSHOT, fullPage: true });
  await (await revealTabbedControl(home.locator('#dashboard-site'))).selectOption(siteB);
  await expect(home).toBeVisible();
  await expect(page.getByTestId(`plant-card-${plants[0]!.id}`)).toHaveCount(0);
  await (await revealTabbedControl(page.locator('#dashboard-site'))).selectOption(siteA);
  await expect(page.getByTestId('home-plants')).toHaveText('4');
  releaseB?.(); await completedB; await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
  await expect(page.getByTestId('home-plants')).toHaveText('4'); await expect(page.getByTestId(`plant-card-${plants[3]!.id}`)).toHaveCount(0);
  await expect(home.locator('details.home-room').first().locator('details.home-zone').nth(1)).toHaveAttribute('open', '');
  delayB = false;
  await (await revealTabbedControl(page.locator('#dashboard-site'))).selectOption(siteB);
  await expect(page.getByTestId('home-plants')).toHaveText('1'); await page.getByTestId(`plant-card-${plants[3]!.id}`).locator('.open-plant').click();
  await expect(page.getByRole('dialog', { name: 'Plant Delta', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Plant Delta', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Fermer la fiche', exact: true }).click(); await expect(page.getByTestId('home-plants')).toHaveText('1');
  await expect(page.locator('#dashboard-site')).toHaveValue(siteB);
  await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await (await revealTabbedControl(page.locator('#dashboard-site'))).selectOption(siteA); await expect(page.getByTestId('home-plants')).toHaveText('4');
  await expect(page.getByTestId('plant-home')).toBeVisible();
  if (process.env.PLAYWRIGHT_HOME_MOBILE_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_HOME_MOBILE_SCREENSHOT, fullPage: true });
  const menu = page.getByRole('button', { name: 'Ouvrir le menu', exact: true }); await menu.click();
  const dialog = page.getByRole('dialog', { name: 'Navigation principale', exact: true });
  await expect(dialog).toBeVisible(); await expect(dialog.getByRole('button', { name: 'Gérer les utilisateurs', exact: true })).toHaveCount(0);
  await page.keyboard.press('Shift+Tab'); await expect(dialog.getByRole('button', { name: 'Se déconnecter', exact: true })).toBeFocused();
  await page.keyboard.press('Tab'); await expect(dialog.getByRole('button', { name: 'Fermer le menu', exact: true })).toBeFocused();
  await page.keyboard.press('Escape'); await expect(menu).toBeFocused(); await expect(page.getByRole('dialog')).toHaveCount(0);
  await menu.click(); if (await dialog.getByTestId('nav-culture').getAttribute('aria-expanded') !== 'true') await dialog.getByTestId('nav-culture').click();
  await dialog.getByTestId('nav-item-plants').click(); await expect(page.getByRole('heading', { name: 'Suivi des plantes', exact: true })).toBeVisible();
  await expect(page.getByRole('dialog')).toHaveCount(0); await expect(page.locator('#main-content')).toBeFocused();
  expect(errors).toEqual([]);
 } finally { releaseB?.(); }
});

test('post-harvest cards and details hide watering and hide maintenance during and after drying', async ({ page }) => {
 const preferences = uiPreferencesFixture('fictional-admin');
 const now = new Date().toISOString();
 const site = { id: '00000000-0000-4000-8000-000000000001', name: 'Fictional Postharvest Site' };
 const rows = ['growth', 'hanging_drying', 'rack_drying', 'curing', 'maturation', 'archived'].map((phase, index) => ({
  id: `10000000-0000-4000-8000-00000000000${index + 1}`, name: `Fictional ${phase}`, phase,
  canManage: true, strain: { id: 'strain', name: 'Fictional Strain' }, location: { type: 'site', id: site.id, site, room: null, zone: null },
  phaseStartedAt: now, phaseDueAt: null, day: 8, week: 2, isOverdue: false, nextAction: phase === 'maturation' ? 'archive' : null, photoUrl: index === 0 ? '/phase-badge-fixture.svg' : null,
  watering: { status: phase === 'growth' ? 'alternate' : 'unavailable', last: { occurredAt: now, withProducts: true }, nextWithProducts: phase === 'growth' ? false : null, flushStartsAt: null, flushDays: 14 },
  notes: null, devices: [], version: 1, startedAt: now, durationSnapshot: { durations: { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 }, settings_version: 1, strain_version: 1 },
 }));
 const meta = { current_page: 1, last_page: 1, total: rows.length, per_page: 20 };
 const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
 await page.route('**/phase-badge-fixture.svg', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><defs><linearGradient id="g"><stop stop-color="#fff"/><stop offset="1" stop-color="#12241b"/></linearGradient></defs><path fill="url(#g)" d="M0 0h600v400H0z"/></svg>' }));
 await page.route('**/api/v1/**', route => {
  if (new URL(route.request().url()).pathname.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (new URL(route.request().url()).pathname.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  expect(route.request().method()).toBe('GET');
  if (new URL(route.request().url()).pathname.endsWith('/lifecycle-settings')) return route.fulfill({ json: { data: { stages: lifecycleStages, icons: lifecycleIcons, durations: {}, version: 1, canManage: true } } });
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/application-info')) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  if (path.endsWith('/notifications/inbox')) return route.fulfill({ json: { data: [], unreadCount: 0, meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
  if (path.endsWith('/branding')) return route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, version: 1 } } });
  if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: false } } });
  if (path.endsWith('/photos')) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'fictional-admin', name: 'Fictional Administrator', email: 'fictional@example.test', permissionRoles: [], permissions: ['plants.manage'], oidcLinked: false, isActive: true } } });
  if (path.endsWith('/sites')) return route.fulfill({ json: { data: [site], meta: { ...meta, total: 1 } } });
  if (path.endsWith('/plant-dashboard')) return route.fulfill({ json: { ...dashboardPayload(rows, new URL(route.request().url())), site, meta, summary: { plants: rows.length, planned: 0, due: 0, strains: 1 }, filterOptions: { strains: [rows[0]!.strain] } } });
  if (path.endsWith('/plants')) return route.fulfill({ json: { data: rows, meta } });
  if (path.endsWith('/events')) return route.fulfill({ json: { data: [], meta: { ...meta, total: 0 } } });
  const row = rows.find(row => path.endsWith('/' + row.id));
  if (row) return route.fulfill({ json: { data: row } });
  if (path.includes('/strains/')) return route.fulfill({ status: 404, json: { error: { code: 'auth.errors.requestFailed' } } });
  throw new Error(`Unexpected postharvest request ${path}`);
 });
 await page.addInitScript(() => { if (!localStorage.getItem('locale')) localStorage.setItem('locale', 'fr'); }); await page.goto('/');
 const home = page.getByTestId('plant-home');
 const growth = home.getByTestId(`plant-card-${rows[0]!.id}`);
 await expect(growth.locator('.plant-maintenance')).toBeVisible();
 await expect(growth.locator('.card-watering')).toContainText('Avec fertilisant');
 await expect(growth.locator('button[data-action-icon="water"]')).toBeVisible();
 for (const row of rows.slice(1)) {
  const card = home.getByTestId(`plant-card-${row.id}`);
  const maintenanceCount = 0;
  await expect(card.locator('.plant-maintenance')).toHaveCount(maintenanceCount);
  await expect(card.locator('.plant-edit')).toBeVisible();
  await expect(card.locator('.card-watering')).toHaveCount(0);
  await expect(card.getByRole('button', { name: /^Enregistrer un arrosage pour/ })).toHaveCount(0);
  await expect(card.getByRole('button', { name: /^Archiver / })).toHaveCount(row.phase === 'maturation' ? 1 : 0);
  await card.locator('.open-plant').click();
  await expect(page.getByRole('dialog', { name: row.name, exact: true })).toBeVisible();
  await expect(page.getByRole('dialog', { name: row.name, exact: true }).getByRole('button', { name: 'Enregistrer un entretien', exact: true })).toHaveCount(maintenanceCount);
  await expect(page.locator('.plant-watering')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Enregistrer un arrosage', exact: true })).toHaveCount(0);
  await page.getByRole('dialog', { name: row.name, exact: true }).getByRole('button', { name: 'Fermer la fiche', exact: true }).click();
  await expect(home).toBeVisible();
 }
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(home.getByTestId(`plant-card-${rows[4]!.id}`).getByRole('button', { name: /^Archiver / })).toBeVisible();
 await openModule(page, 'plants');
 for (const row of rows) {
  const card = page.getByTestId(`plant-row-${row.id}`);
  await expect(card.locator('.plant-maintenance')).toHaveCount(row.phase === 'growth' ? 1 : 0);
  await expect(card.locator('.plant-edit')).toBeVisible();
 }
 expect(errors).toEqual([]);
});
