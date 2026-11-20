import { revealTabbedControl } from './navigation';
import { dashboardPayload } from './dashboard-payload-fixture';
import { test, expect, type Page } from '../coverage/fixture';
import { insightRoutes, insightMeta } from './insights-fixture';

async function dashboard(page: Page, zoneCount = 60, kind: 'zone' | 'room' | 'site' = 'zone', withReadings = false, controlKind: 'switch' | 'pump' = 'switch', extraChannels = 7, totalTiles = extraChannels + 1) {
 const state = await insightRoutes(page);
 const transport = { previews: [] as string[][], metadataCards: [] as number[], fail: '', hold: '', release: () => {}, readingValue: '0' as string | null, readingObservedAt: '2026-11-16T12:00:00Z' as string | null, sourceRevision: null as string | null };
 const plants = Array.from({ length: zoneCount * 10 }, (_, index) => {
  const zone = Math.floor(index / 10); const room = Math.floor(zone / 30);
  return {
   id: `plant-${index}`, name: `Plant ${index}`, canManage: true, canDelete: true,
   strain: { id: 'strain-one', name: 'Fictional strain', classification: 'hybrid', genetics: null },
   phase: 'maturation', phaseStartedAt: null, phaseDueAt: null, day: null, week: null, isOverdue: false, nextAction: null,
   photoUrl: null, measurements: [], summarySensorIds: [],
   watering: { status: 'unavailable', last: null, nextWithProducts: null, flushStartsAt: null, flushDays: 14 },
   location: { type: kind, id: kind === 'site' ? 'site-one' : kind === 'room' ? `room-${room}` : `zone-${zone}`, site: { id: 'site-one', name: 'Fictional site' }, room: kind === 'site' ? null : { id: `room-${room}`, name: `Room ${room}` }, zone: kind === 'zone' ? { id: `zone-${zone}`, name: `Zone ${String(zone).padStart(2, '0')}` } : null },
  };
 });
 await page.route('**/api/v1/sites/*/plant-dashboard?*', async route => {
  const url = new URL(route.request().url()); const keys = url.searchParams.getAll('preview_groups[]');
  if (keys.length) {
   transport.previews.push(keys);
   if (keys.includes(transport.fail)) return route.fulfill({ status: 503, json: { error: { code: 'untrustedProviderError' } } });
   if (keys.includes(transport.hold)) await new Promise<void>(resolve => { transport.release = resolve; });
  }
  const matching = url.searchParams.get('status') === 'planned' ? [] : plants;
  const payload = dashboardPayload(matching, url);
  if (url.searchParams.get('metadata_only') === '1') transport.metadataCards.push(payload.data.length);
  if (keys.length) return route.fulfill({ json: { ...payload, meta: { ...insightMeta, total: payload.data.length }, site: { id: 'site-one', name: 'Fictional site' } } });
  return route.fulfill({ json: {
   ...payload, ...(withReadings ? { locationReadingsMeta: { 'zone:zone-0': { total: totalTiles, current_page: 1, per_page: 20 } }, locationReadings: {
    'room:room-0': [{ sensorId: 'sensor-2', sensorName: 'Room temperature', device: { id: 'room-probe', name: 'Room probe' }, measurementType: { name: 'Temperature', nature: 'temperature', decimalPlaces: 1 }, value: '22.5', unit: '°C', observedAt: '2026-11-16T12:00:00Z', isEnabled: true, isStale: false }],
    'zone:zone-0': [{ sensorId: 'sensor-0', sensorName: 'Zone switch', switchControl: { kind: controlKind, canControl: true }, device: { id: 'zone-outlet', name: 'Outlet' }, measurementType: { name: 'Switch', nature: 'binary', decimalPlaces: 0 }, sourceRevision: transport.sourceRevision, value: transport.readingValue, unit: null, observedAt: transport.readingObservedAt, isEnabled: true, isStale: true }, ...Array.from({ length: extraChannels }, (_, index) => ({ sensorId: `zone-extra-${index}`, sensorName: `Zone channel ${index}`, device: { id: index === 0 || extraChannels > 7 ? 'zone-outlet' : 'zone-probe', name: index === 0 || extraChannels > 7 ? 'Outlet' : 'Zone probe' }, measurementType: { name: 'Temperature', nature: 'temperature', decimalPlaces: 1 }, value: '23', unit: '°C', observedAt: '2026-11-16T12:00:00Z', isEnabled: true, isStale: false }))],
   } } : {}), meta: { ...insightMeta, total: matching.length }, site: { id: 'site-one', name: 'Fictional site' },
   summary: { plants: plants.length, planned: 0, due: 0, strains: 1 }, filterOptions: { strains: [{ id: 'strain-one', name: 'Fictional strain' }] },
  } });
 });
 await page.reload();
 await expect(page.getByTestId('home-plants')).toHaveText(String(plants.length));
 return { ...state, transport };
}

test('back to top appears after scrolling and returns focus to the main content', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 await page.emulateMedia({ reducedMotion: 'reduce' });
 const { errors } = await dashboard(page, 20);
 const button = page.getByRole('button', { name: 'Retour en haut', exact: true });
 await expect(button).toHaveCount(0);
 await page.evaluate(() => window.scrollTo(0, 650));
 await expect(button).toBeInViewport();
 await button.focus();
 await page.keyboard.press('Enter');
 await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
 await expect(button).toHaveCount(0);
 await expect(page.locator('#main-content')).toBeFocused();

 await page.setViewportSize({ width: 1280, height: 900 });
 await page.emulateMedia({ reducedMotion: 'no-preference' });
 await page.evaluate(() => window.scrollTo(0, 650));
 await expect(button).toBeInViewport();
 await button.click();
 await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
 expect(errors).toEqual([]);
});

test('large dashboard mounts only expanded galleries and preserves selection and remembered sections', async ({ page }) => {
 const { errors } = await dashboard(page);
 await expect(page.locator('.photo-plant')).toHaveCount(10);
 const rooms = page.locator('.home-room');
 const zones = rooms.nth(0).locator('.home-zone');
 await (await revealTabbedControl(page.getByTestId('plant-card-plant-0').getByRole('checkbox'))).check();
 await zones.nth(0).locator('summary').click();
 await expect(page.locator('.photo-plant')).toHaveCount(0);
 await zones.nth(0).locator('summary').click();
 await expect(page.getByTestId('plant-card-plant-0').getByRole('checkbox')).toBeChecked();
 await zones.nth(1).locator('summary').click();
 await expect(page.locator('.photo-plant')).toHaveCount(20);
 await rooms.nth(0).locator(':scope > summary').click();
 await expect(page.locator('.photo-plant')).toHaveCount(0);
 await rooms.nth(1).locator(':scope > summary').click();
 await expect(page.locator('.photo-plant')).toHaveCount(10);
 await expect(page.getByTestId('plant-card-plant-300')).toBeVisible();
 await page.reload();
 await expect(page.getByTestId('plant-card-plant-300')).toBeVisible();
 await expect(page.locator('.photo-plant')).toHaveCount(10);
 expect(errors).toEqual([]);
});

for (const kind of ['room', 'site'] as const) {
 test(`direct ${kind} galleries follow the parent disclosure and preserve selection`, async ({ page }) => {
  const { errors } = await dashboard(page, 2, kind);
  await expect(page.locator('.photo-plant')).toHaveCount(10);
  const selected = page.getByTestId('plant-card-plant-0').getByRole('checkbox');
  await (await revealTabbedControl(selected)).check();
  const heading = page.locator('.home-room > summary');
  await heading.click();
  await expect(page.locator('.photo-plant')).toHaveCount(0);
  await heading.click();
  await expect(selected).toBeChecked();
  await expect(page.locator('.photo-plant')).toHaveCount(10);
  await expect(page.locator('.zone-gallery-footer button')).toBeVisible();
  expect(errors).toEqual([]);
 });
}

test('closed groups send no cards until opened and failed previews can be retried', async ({ page }) => {
 const { errors, transport } = await dashboard(page);
 await expect(page.locator('.photo-plant')).toHaveCount(10);
 expect(transport.metadataCards).toEqual([0]);
 expect(transport.previews).toEqual([['zone:zone-0']]);
 await expect(page.getByTestId('home-plants')).toHaveText('600');
 const zone = page.locator('.home-room').first().locator('.home-zone').nth(1);
 transport.fail = 'zone:zone-1';
 await zone.locator('summary').click();
 await expect(zone.getByRole('alert')).toBeVisible();
 await expect(page.getByTestId('plant-card-plant-0')).toBeVisible();
 transport.fail = '';
 await zone.getByRole('button', { name: 'Réessayer', exact: true }).click();
 await expect(page.getByTestId('plant-card-plant-10')).toBeVisible();
 expect(transport.previews).toEqual([['zone:zone-0'], ['zone:zone-1'], ['zone:zone-1']]);
 expect(errors).toEqual([]);
});

test('an obsolete preview cannot repopulate the dashboard after a filter change', async ({ page }) => {
 const { errors, transport } = await dashboard(page);
 await expect(page.locator('.photo-plant')).toHaveCount(10);
 transport.hold = 'zone:zone-1';
 await page.locator('.home-room').first().locator('.home-zone').nth(1).locator('summary').click();
 await expect.poll(() => transport.previews.some(keys => keys.includes('zone:zone-1'))).toBe(true);
 await page.getByRole('button', { name: 'Afficher les plantes à semer', exact: true }).click();
 await expect(page.locator('.home-room')).toHaveCount(0);
 transport.release();
 await expect(page.locator('.photo-plant')).toHaveCount(0);
 expect(errors).toEqual([]);
});


test('room and zone tiles precede galleries, retain binary zero and open sensor history', async ({ page }) => {
 const { errors } = await dashboard(page, 2, 'zone', true);
 const room = page.locator('.home-room').first();
 const roomTiles = room.locator(':scope > .location-readings');
 const zone = room.locator('.home-zone').first();
 const zoneTiles = zone.locator(':scope > .location-readings');
 await expect(roomTiles).toContainText('Room temperature');
 await expect(roomTiles).toContainText(/22[,.]5/);
 await expect(zoneTiles).toContainText('Zone switch');
 await expect(zoneTiles.locator('.reading-value').first()).toHaveText(/Off|Éteint/);
 expect(await zoneTiles.evaluate(node => Boolean(node.compareDocumentPosition(node.parentElement!.querySelector('.zone-gallery')!) & Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
 await zone.locator(':scope > summary').click();
 await expect(zoneTiles).toHaveCount(0);
 await zone.locator(':scope > summary').click();
 await expect(zoneTiles).toContainText(/Off|Éteint/);
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(roomTiles).toBeVisible();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await roomTiles.getByRole('button', { name: 'Historique · Room temperature', exact: true }).click();
 await expect(page.getByRole('dialog')).toBeVisible();
 expect(errors).toEqual([]);
});


test('location channels collapse independently from the plant gallery and remember their state', async ({ page }) => {
 const { errors, preferences } = await dashboard(page, 2, 'zone', true);
 const room = page.locator('.home-room').first();
 const roomTiles = room.locator(':scope > .location-readings');
 const zone = room.locator('.home-zone').first();
 const zoneTiles = zone.locator(':scope > .location-readings');
 const summary = zoneTiles.locator(':scope > summary');
 const firstTile = zoneTiles.locator('.plant-reading').first();
 const gallery = zone.locator('.zone-gallery');

 await expect(firstTile).toBeVisible();
 await summary.focus();
 await page.keyboard.press('Enter');
 await expect(firstTile).toBeHidden();
 await expect(roomTiles.locator('.plant-reading').first()).toBeVisible();
 await expect(gallery).toBeVisible();
 await expect.poll(() => preferences.values()['disclosures:dashboard-channels:dashboard:zone:zone-0']).toBe(false);

 await page.reload();
 await expect(summary).toBeVisible();
 await expect(firstTile).toBeHidden();
 await expect(gallery).toBeVisible();
 await summary.click();
 await expect(firstTile).toBeVisible();
 await expect(zoneTiles.getByRole('button', { name: 'Choisir les tuiles' })).toBeVisible();
 expect(errors).toEqual([]);
});

async function selectOnlyZoneSwitch(page: Page) {
 const dialog = page.getByRole('dialog');
 await dialog.locator('.n-base-clear').click();
 await dialog.locator('.n-base-selection').click();
 await page.getByRole('option', { name: 'Outlet · Zone switch', exact: true }).click();
 await page.keyboard.press('Escape');
}

test('location tile choices persist independently, allow hiding all and keep the gallery horizontally scrollable', async ({ page }) => {
 const { preferences, errors } = await dashboard(page, 2, 'zone', true);
 const room = page.locator('.home-room').first();
 const roomTiles = room.locator(':scope > .location-readings');
 const zoneTiles = room.locator('.home-zone').first().locator(':scope > .location-readings');
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(8);
 await page.setViewportSize({ width: 390, height: 844 });
 const rail = zoneTiles.locator('.readings-grid');
 expect(await rail.evaluate(node => node.scrollWidth > node.clientWidth)).toBe(true);
 await rail.evaluate(node => { node.scrollLeft = node.scrollWidth; });
 expect(await rail.evaluate(node => node.scrollLeft)).toBeGreaterThan(0);
 await page.setViewportSize({ width: 1280, height: 900 });
 await zoneTiles.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 const dialog = page.getByRole('dialog');
 await expect(dialog).toBeVisible();
 await dialog.locator('.n-base-clear').click();
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(0);
 await expect(roomTiles.locator('.plant-reading')).toHaveCount(1);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click();
 await expect.poll(() => Object.values(preferences.values()).filter(value => value === false).length).toBeGreaterThanOrEqual(8);
 await page.reload();
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(0);
 await expect(zoneTiles.getByRole('button', { name: 'Choisir les tuiles', exact: true })).toBeVisible();
 await zoneTiles.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 await dialog.getByRole('button', { name: 'Tout afficher', exact: true }).click();
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(8);
 await expect.poll(() => preferences.values()['culture:dashboard-tiles:dashboard:zone:zone-0:sensor-0']).toBe(true);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click();
 await zoneTiles.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 await dialog.locator('.n-base-selection').click();
 await page.getByRole('option', { name: 'Outlet · Zone switch', exact: true }).click();
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(7);
 await expect.poll(() => preferences.values()['culture:dashboard-tiles:dashboard:zone:zone-0:sensor-0']).toBe(false);
 await page.keyboard.press('Escape');
 await page.keyboard.press('Escape');
 await page.reload();
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(7);
 await expect(roomTiles.locator('.plant-reading')).toHaveCount(1);
 await page.setViewportSize({ width: 390, height: 844 });
 await zoneTiles.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 await selectOnlyZoneSwitch(page);
 await expect(zoneTiles.locator('.plant-reading')).toHaveCount(1);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click();
 expect(errors).toEqual([]);
});


test('switch tiles request the displayed target state without opening statistics or replaying an uncertain command', async ({ page }) => {
 const { requests, errors } = await dashboard(page, 2, 'zone', true);
 let state = true; let hold = true; let fail = false; let release = () => {};
 const commands: { is_on: boolean; request_id: string }[] = [];
 await page.route('**/api/v1/assets/zone-outlet/live-readings?*', route => route.fulfill({ status: 503, json: { error: { code: 'measurements.errors.unavailable' } } }));
 await page.route('**/api/v1/sensors/sensor-0/switch', async route => {
  if (route.request().method() === 'POST') {
   const command = route.request().postDataJSON(); commands.push(command);
   if (hold) await new Promise<void>(resolve => { release = resolve; });
   if (fail) return route.fulfill({ status: 502, json: { error: { code: 'assets.power.uncertain' } } });
   state = command.is_on;
   return route.fulfill({ json: { data: { status: 'confirmed', isOn: state } } });
  }
  return route.fulfill({ json: { data: { isOn: state } } });
 });
 const tile = page.locator('.home-zone').first().getByRole('button', { name: 'Commander · Zone switch', exact: true });
 const action = tile;
 await tile.click({ position: { x: 12, y: 12 } });
 await expect(action).toBeDisabled();
 await action.evaluate((node: HTMLButtonElement) => node.click());
 await expect.poll(() => commands.length).toBe(1);
 expect(commands[0]!.is_on).toBe(true); // The displayed state was off, even though the device was already on.
 release(); hold = false;
 await expect(action).toContainText('Allumé');
 await expect(action).toBeEnabled();
 await action.click();
 await expect(action).toContainText('Éteint');
 expect(commands[1]!.is_on).toBe(false);
 await expect(action).toBeEnabled();
 fail = true;
 await action.click();
 await expect(action).toBeEnabled();
 await expect(action).toContainText('Éteint');
 await action.click(); await expect(action).toBeEnabled();
 expect(commands.slice(2).map(command => command.is_on)).toEqual([true, true]);
 expect(commands[2]!.request_id).not.toBe(commands[3]!.request_id);
 await expect(page.getByRole('dialog')).toHaveCount(0);
 expect(requests.filter(url => url.pathname.endsWith('/reports/sensors/sensor-0'))).toHaveLength(0);
 expect(errors).toEqual([]);
});


test('an automatic pump can restart without watering and be stopped manually', async ({ page }) => {
 const { requests, errors } = await dashboard(page, 2, 'zone', true, 'pump');
 let activation: { id: string; plantId: string; occurredAt: string; mode: string; stopDueAt: null; stoppedAt: string | null; stopStatus: string | null } | null = null;
 const commands: { request_id: string; plant_id: string }[] = [];
 await page.route('**/api/v1/assets/zone-outlet/pump-context**', route => route.fulfill({ json: { data: { plantsMeta: { current_page: 1, last_page: 1, total: 1, per_page: 50 }, mode: 'auto', seconds: null, running: null, stoppable: activation && !activation.stoppedAt ? activation : null, plants: [{ id: 'plant-0', name: 'Plant 0', activation }] } } }));
 await page.route('**/api/v1/assets/zone-outlet/activate', route => {
  const command = route.request().postDataJSON(); commands.push(command);
  activation = { id: command.request_id, plantId: command.plant_id, occurredAt: new Date().toISOString(), mode: 'auto', stopDueAt: null, stoppedAt: null, stopStatus: null };
  return route.fulfill({ json: { data: activation } });
 });
 const liveQueries: string[][] = [];
 await page.route('**/api/v1/assets/zone-outlet/live-readings?*', route => {
  liveQueries.push(new URL(route.request().url()).searchParams.getAll('sensor_ids[]'));
  const on = liveQueries.length > 1 && !!activation && !activation.stoppedAt;
  return route.fulfill({ json: { data: [
   { sensorId: 'sensor-0', value: on ? '1' : '0', unit: null, observedAt: new Date().toISOString(), quality: 'good', isStale: false },
   { sensorId: 'zone-extra-0', value: on ? '25' : '20', unit: '°C', observedAt: new Date().toISOString(), quality: 'good', isStale: false },
  ] } });
 });
 let stops = 0;
 await page.route('**/api/v1/assets/zone-outlet/pump-activations/*/stop', route => {
  stops++;
  activation = { ...activation!, stoppedAt: new Date().toISOString(), stopStatus: 'confirmed' };
  return route.fulfill({ json: { data: activation } });
 });
 const tile = page.locator('.home-zone').first().locator('.plant-reading').first();
 await tile.click({ position: { x: 12, y: 12 } });
 const dialog = page.getByRole('dialog');
 await dialog.getByRole('button', { name: 'Démarrer', exact: true }).click();
 await expect(tile.locator('.reading-value')).toHaveText('Allumé');
 await expect(tile.locator('..').locator('.plant-reading').nth(1).locator('.reading-value')).toContainText('25');
 await expect(dialog.getByRole('button', { name: 'Déclarer l’arrosage', exact: true })).toBeVisible();
 await expect(dialog.getByRole('button', { name: 'Démarrer', exact: true })).toBeEnabled();
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).last().click();
 await expect(dialog).toHaveCount(0);
 await tile.click();
 await dialog.getByRole('button', { name: 'Démarrer', exact: true }).click();
 await expect.poll(() => commands.length).toBe(2);
 expect(commands[0]!.request_id).not.toBe(commands[1]!.request_id);
 await expect(dialog.getByRole('button', { name: 'Déclarer l’arrosage', exact: true })).toBeVisible();
 await dialog.getByRole('button', { name: 'Arrêter', exact: true }).click();
 await expect(dialog.getByRole('button', { name: 'Arrêter', exact: true })).toHaveCount(0);
 await expect(tile.locator('.reading-value')).toHaveText('Éteint');
 await expect(tile.locator('..').locator('.plant-reading').nth(1).locator('.reading-value')).toContainText('20');
 expect(liveQueries.length).toBeGreaterThanOrEqual(2);
 expect(liveQueries.every(ids => ids.length === 2 && ids.includes('sensor-0') && ids.includes('zone-extra-0'))).toBe(true);
 expect(stops).toBe(1);
 expect(commands).toHaveLength(2);
 expect(requests.filter(url => url.pathname.endsWith('/sensors/sensor-0/switch') || url.pathname.endsWith('/waterings'))).toHaveLength(0);
 expect(errors).toEqual([]);
});

test('dashboard tile order persists per location and survives visibility changes on mobile', async ({ page }) => {
 const { preferences, requests, errors } = await dashboard(page, 2, 'zone', true);
 const room = page.locator('.home-room').first().locator(':scope > .location-readings');
 const zone = page.locator('.home-zone').first().locator(':scope > .location-readings');
 const names = zone.locator('.reading-name');
 await zone.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 const dialog = page.getByRole('dialog');
 await expect(dialog.getByRole('button', { name: 'Monter Zone switch', exact: true })).toBeDisabled();
 await dialog.getByRole('button', { name: 'Descendre Zone switch', exact: true }).click();
 await expect(names.first()).toHaveText('Zone channel 0');
 await expect(names.nth(1)).toHaveText('Zone switch');
 await expect.poll(() => preferences.values()['culture:dashboard-tile-order:dashboard:zone:zone-0:sensor-0']).toBe(1);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click();
 await page.reload();
 await expect(names.first()).toHaveText('Zone channel 0');
 await expect(room.locator('.reading-name').first()).toHaveText('Room temperature');
 await page.setViewportSize({ width: 390, height: 844 });
 await zone.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 await selectOnlyZoneSwitch(page);
 await expect(names).toHaveCount(1);
 await expect(dialog.getByRole('button', { name: 'Descendre Zone switch', exact: true })).toBeDisabled();
 await dialog.getByRole('button', { name: 'Tout afficher', exact: true }).click();
 await expect(names.first()).toHaveText('Zone channel 0');
 await dialog.getByRole('button', { name: 'Monter Zone switch', exact: true }).focus();
 await page.keyboard.press('Enter');
 await expect(names.first()).toHaveText('Zone switch');
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click();
 await page.reload();
 await expect(names.first()).toHaveText('Zone switch');
 expect(requests.filter(url => url.pathname.endsWith('/switch'))).toHaveLength(0);
 expect(errors).toEqual([]);
});


test('unavailable live tiles recover in place and reject replies from a replaced source', async ({ page }) => {
 const { errors, transport } = await dashboard(page, 2, 'zone', true);
 const refresh = page.getByRole('button', { name: 'Actualiser les plantes', exact: true });
 async function reload() {
  await Promise.all([page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/plant-dashboard') && new URL(response.url()).searchParams.get('metadata_only') === '1'), refresh.click()]);
  await expect(refresh).toBeEnabled();
 }
 transport.sourceRevision = 'source-a';
 await reload();
 const tile = page.locator('.home-zone').first().locator('.plant-reading').first();
 let on = false; let revision = 'source-a'; let unavailable = true; let held = false;
 let release = () => {}; let reads = 0;
 await page.route('**/api/v1/sensors/sensor-0/switch', route => {
  if (route.request().method() === 'POST') {
   on = route.request().postDataJSON().is_on;
   if (unavailable) return route.fulfill({ status: 502, json: { error: { code: 'assets.power.uncertain' } } });
   return route.fulfill({ json: { data: { status: 'confirmed', isOn: on } } });
  }
  return route.fulfill({ json: { data: { isOn: on } } });
 });
 await page.route('**/api/v1/assets/zone-outlet/live-readings?*', async route => {
  reads++;
  const row = { sensorId: 'sensor-0', sourceRevision: revision, value: unavailable ? null : on ? '1' : '0', unit: null, observedAt: unavailable ? null : '2026-11-17T13:00:00Z', quality: unavailable ? 'invalid' : 'good', isStale: unavailable };
  if (held) { row.value = '1'; await new Promise<void>(resolve => { release = resolve; }); }
  return route.fulfill({ json: { data: [row] } });
 });
 await tile.click();
 await expect(tile.locator('.reading-value')).toHaveText('État actuel indisponible');
 // Keep a DOM reference to prove recovery does not depend on remounting the tile.
 const original = await tile.elementHandle();
 unavailable = false;
 transport.readingValue = '1'; transport.readingObservedAt = '2026-11-17T12:00:00Z';
 await reload();
 await expect(tile.locator('.reading-value')).toHaveText('Allumé');
 expect(await original!.evaluate(node => node.isConnected)).toBe(true);
 unavailable = false; held = true;
 const before = reads;
 await tile.click();
 await expect.poll(() => reads).toBeGreaterThan(before);
 transport.sourceRevision = 'source-b'; revision = 'source-b';
 transport.readingValue = '0'; transport.readingObservedAt = '2026-11-17T12:10:00Z';
 await reload();
 await expect(tile.locator('.reading-value')).toHaveText('Éteint');
 held = false; release();
 // A later reconciliation must not let the old source's held on-state overwrite the new source.
 await expect.poll(() => reads).toBeGreaterThan(before + 1);
 await expect(tile.locator('.reading-value')).toHaveText('Éteint');
 expect(errors).toEqual([]);
});


test('a failed live batch preserves successful sibling channel updates', async ({ page }) => {
 const { errors } = await dashboard(page, 2, 'zone', true, 'switch', 12);
 let on = false;
 await page.route('**/api/v1/sensors/sensor-0/switch', route => {
  if (route.request().method() === 'POST') { on = route.request().postDataJSON().is_on; return route.fulfill({ json: { data: { status: 'confirmed', isOn: on } } }); }
  return route.fulfill({ json: { data: { isOn: on } } });
 });
 let successes = 0; let failures = 0;
 await page.route('**/api/v1/assets/zone-outlet/live-readings?*', route => {
  const ids = new URL(route.request().url()).searchParams.getAll('sensor_ids[]');
  expect(ids.length).toBeLessThanOrEqual(10);
  if (ids.includes('zone-extra-11')) { failures++; return route.fulfill({ status: 503, json: { error: { code: 'measurements.errors.providerUnavailable' } } }); }
  successes++;
  return route.fulfill({ json: { data: ids.map(sensorId => ({ sensorId, sourceRevision: null, value: sensorId === 'sensor-0' ? '1' : '29', unit: sensorId === 'sensor-0' ? null : '°C', observedAt: '2026-11-17T13:00:00Z', quality: 'good', isStale: false })) } });
 });
 const tiles = page.locator('.home-zone').first().locator('.plant-reading');
 await tiles.first().click();
 await expect.poll(() => failures).toBeGreaterThan(0);
 await expect(tiles.nth(1).locator('.reading-value')).toContainText('29');
 await expect(tiles.nth(12).locator('.reading-value')).toContainText('23');
 expect(successes).toBeGreaterThan(0);
 expect(errors).toEqual([]);
});


test('dashboard loads additional location tiles explicitly without resetting hidden choices', async ({ page }) => {
 const { errors, preferences } = await dashboard(page, 2, 'zone', true, 'switch', 19, 26);
 let pages = 0;
 await page.route('**/api/v1/sites/site-one/dashboard-tiles?*', route => {
  pages++;
  expect(new URL(route.request().url()).searchParams.get('page')).toBe('2');
  return route.fulfill({ json: { data: Array.from({ length: 6 }, (_, index) => ({ sensorId: `additional-${index}`, sensorName: `Additional ${index}`, device: { id: 'extra-device', name: 'Extra device' }, measurementType: { name: 'Temperature', nature: 'temperature', decimalPlaces: 1 }, value: '26', unit: '°C', observedAt: '2026-11-16T12:00:00Z', isEnabled: true, isStale: false })), meta: { current_page: 2, per_page: 20, total: 26 } } });
 });
 const zone = page.locator('.home-zone').first().locator(':scope > .location-readings');
 await expect(zone.locator('.reading-name')).toHaveCount(20);
 await page.setViewportSize({ width: 390, height: 844 });
 await zone.getByRole('button', { name: 'Choisir les tuiles', exact: true }).click();
 await selectOnlyZoneSwitch(page);
 await page.getByRole('dialog').getByRole('button', { name: 'Fermer', exact: true }).click();
 await expect(zone.locator('.reading-name')).toHaveCount(1);
 await zone.getByRole('button', { name: 'Afficher plus', exact: true }).click();
 await expect(zone.locator('.reading-name')).toHaveCount(7);
 expect(pages).toBe(1);
 expect(preferences.values()['culture:dashboard-tiles:dashboard:zone:zone-0:zone-extra-0']).toBe(false);
 expect(errors).toEqual([]);
});
