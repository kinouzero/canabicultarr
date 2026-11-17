import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

test('collection URLs restore criteria and pages through reload, history and reset without writing records', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const requests: URL[] = []; const writes: string[] = [];
 await page.route('**/api/v1/plants?*', route => {
  const request = route.request(); const url = new URL(request.url()); requests.push(url);
  if (request.method() !== 'GET') writes.push(request.method());
  return route.fulfill({ json: { data: [], meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 4, total: 65, per_page: Number(url.searchParams.get('per_page') ?? 20) } } });
 });
 await page.goto('/plants?q=Fictional&status=planned&sort=name_desc&page=3&perPage=20');
 await expect(page.locator('#plant-search')).toHaveValue('Fictional');
 await expect(page.locator('#plant-status')).toHaveValue('planned');
 await expect.poll(() => requests.at(-1)?.searchParams.get('page')).toBe('3');
 const beforeReload = requests.length;
 await page.reload();
 await expect.poll(() => requests.length).toBe(beforeReload + 1);
 await expect.poll(() => requests.at(-1)?.searchParams.get('page')).toBe('3');
 const before = requests.length;
 await (await revealTabbedControl(page.locator('#plant-search'))).fill('Next');
 await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('Next');
 await expect.poll(() => requests.at(-1)?.searchParams.get('page')).toBe('1');
 await page.goBack();
 await expect(page.locator('#plant-search')).toHaveValue('Fictional');
 await expect.poll(() => requests.at(-1)?.searchParams.get('page')).toBe('3');
 await page.waitForTimeout(400);
 expect(new URL(page.url()).searchParams.get('page')).toBe('3');
 expect(requests.length - before).toBe(2);
 await page.goForward();
 await expect(page.locator('#plant-search')).toHaveValue('Next');
 await expect.poll(() => requests.at(-1)?.searchParams.get('page')).toBe('1');
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click();
 await expect(page.locator('#plant-search')).toHaveValue('');
 expect(writes).toEqual([]); expect(errors).toEqual([]);
});

test('inventory and strain criteria remain independent and invalid query values use bounded defaults', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const calls: URL[] = [];
 await page.route('**/api/v1/inventory/items?*', route => { calls.push(new URL(route.request().url())); return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 }, canEditCatalog: false } }); });
 await page.goto('/inventory?q=Seeds&type=seeds&low=true&sort=quantity_desc&page=-999&perPage=999999');
 await expect(page.locator('#inventory-search')).toHaveValue('Seeds');
 await expect(page.locator('#inventory-type-filter')).toHaveValue('seeds');
 await expect.poll(() => calls.at(-1)?.searchParams.get('page')).toBe('1');
 expect(calls.at(-1)?.searchParams.get('per_page')).toBe('20');
 expect(calls.at(-1)?.searchParams.get('low_stock')).toBe('1');
 await openModule(page, 'plants');
 await expect(page.locator('#plant-search')).toHaveValue('');
 await page.goBack();
 await expect(page.locator('#inventory-search')).toHaveValue('Seeds');
 expect(errors).toEqual([]);
});

test('direct stock, file and hardware links enforce read-only resolution and cannot reopen after Back', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const stock = { id: 'stock-one', name: 'Fictional stock', type: 'seeds', unit: 'piece', totalQuantity: '4', threshold: null, decimalPlaces: 0, isLowStock: false, isArchived: false, version: 1, canManage: false, description: null, sku: null };
 const file = { id: 'file-one', title: 'Fictional image', originalName: 'image.png', mediaType: 'image/png', format: 'image', version: 1, sizeBytes: 100, subject: null, uploader: { id: 'reader', name: 'Reader' }, createdAt: '2026-11-16T00:00:00Z', updatedAt: '2026-11-16T00:00:00Z', canEdit: false, isArchived: false };
 const device = { id: 'asset-one', name: 'Fictional hardware', category: 'sensor', status: 'active', assetTag: null, description: null, locations: [], plants: [], channelsCount: 0, enabledChannelsCount: 0, pumpControl: null };
 const queries: URL[] = []; const mutations: string[] = []; let pending: (() => void) | undefined; let delayMetadata = false;
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname; const method = route.request().method(); queries.push(new URL(route.request().url()));
  if (method !== 'GET' && !path.endsWith('/account/ui-preferences')) mutations.push(`${method} ${path}`);
  const data = (value: unknown) => route.fulfill({ json: { data: value } });
  const list = (value: unknown[]) => route.fulfill({ json: { data: value, meta: { current_page: 1, last_page: 1, total: value.length, per_page: 20 } } });
  if (path.endsWith('/inventory/items')) return list([stock]);
  if (path.endsWith('/inventory/items/stock-one')) return data(stock);
  if (/\/inventory\/items\/stock-one\/(balances|movements)$/.test(path)) return list([]);
  if (path.endsWith('/documents/storage-options')) return data({ default: 'documents', disks: ['documents'] });
  if (path.endsWith('/documents')) return list([file]);
  if (path.endsWith('/documents/file-one')) {
   if (delayMetadata) await new Promise<void>(resolve => { pending = resolve; });
   return data(file);
  }
  if (path.endsWith('/documents/file-one/image-preview')) return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=', 'base64') });
  if (path.endsWith('/assets/asset-one')) return data(device);
  if (path.endsWith('/assets/gone')) return route.fulfill({ status: 404, json: { error: { code: 'auth.errors.requestFailed' } } });
  return route.fallback();
 });
 await page.goto('/inventory?stock=stock-one&stockBalances.page=3&stockHistory.page=2');
 const detail = page.getByRole('dialog', { name: 'Fictional stock', exact: true });
 await expect(detail).toBeVisible();
 await expect.poll(() => queries.find(url => url.pathname.endsWith('/stock-one/balances'))?.searchParams.get('page')).toBe('3');
 expect(queries.find(url => url.pathname.endsWith('/stock-one/movements'))?.searchParams.get('page')).toBe('2');
 await page.reload(); await expect(detail).toBeVisible();
 await detail.getByRole('button', { name: 'Fermer les détails', exact: true }).click();
 await expect(detail).toHaveCount(0); expect(new URL(page.url()).searchParams.has('stock')).toBe(false);
 await page.goto('/hardware?asset=asset-one');
 await expect(page.getByRole('dialog', { name: 'Fictional hardware', exact: true })).toBeVisible();
 await page.setViewportSize({ width: 390, height: 844 });
 await page.goto('/files');
 delayMetadata = true;
 await page.getByRole('button', { name: 'Visionner image.png', exact: true }).click();
 await expect.poll(() => !!pending).toBe(true);
 await page.goBack(); pending!();
 await page.waitForTimeout(150);
 await expect(page.getByRole('dialog', { name: 'image.png', exact: true })).toHaveCount(0);
 delayMetadata = false;
 await page.goForward();
 const preview = page.getByRole('dialog', { name: 'image.png', exact: true });
 await expect(preview).toBeVisible(); await expect.poll(() => preview.locator('img').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(1);
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

test('Back retains an inventory edit until discard is explicitly accepted', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const item = { id: 'stock-one', name: 'Guarded stock', type: 'seeds', unit: 'piece', unitLocked: false, totalQuantity: '4', threshold: null, decimalPlaces: 0, isLowStock: false, isArchived: false, version: 1, canManage: true, canEditCatalog: true, description: null, sku: null };
 const writes: string[] = [];
 await page.route('**/api/v1/**', route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'insight-reader', name: 'Stock manager', email: 'stock@example.test', role: 'admin', permissions: ['administration.view', 'inventory.manage'], isActive: true, oidcLinked: false } } });
  if (path.includes('/inventory/items')) {
   if (route.request().method() !== 'GET') writes.push(route.request().method());
   if (path.endsWith('/stock-one')) return route.fulfill({ json: { data: item } });
   return route.fulfill({ json: { data: path.endsWith('/items') ? [item] : [], canEditCatalog: true, meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
  }
  return route.fallback();
 });
 await page.goto('/inventory');
 await page.getByRole('button', { name: 'Stocks et historique de Guarded stock', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Guarded stock', exact: true });
 await detail.getByRole('button', { name: 'Modifier Guarded stock', exact: true }).click();
 await (await revealTabbedControl(page.locator('#inventory-name'))).fill('Unsaved name');
 await page.goBack();
 const discard = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await expect(discard).toBeVisible();
 await discard.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(page.locator('#inventory-name')).toHaveValue('Unsaved name');
 expect(new URL(page.url()).searchParams.get('stock')).toBe('stock-one');
 await page.goBack(); await expect(discard).toBeVisible();
 await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(page.locator('#inventory-name')).toHaveCount(0);
 expect(new URL(page.url()).searchParams.has('stock')).toBe(false);
 expect(writes).toEqual([]); expect(errors).toEqual([]);
});

test('location trails resolve exact parents and unavailable scopes never fall back to all sites', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const site = { id: 'site-one', kind: 'site', name: 'Navigation site', parentId: null, childrenCount: 1, canSelect: true, canManage: false };
 const room = { id: 'room-one', kind: 'room', name: 'Navigation room', parentId: site.id, childrenCount: 1, canSelect: true, canManage: false };
 const zone = { id: 'zone-one', kind: 'zone', name: 'Navigation zone', parentId: room.id, childrenCount: 0, canSelect: true, canManage: false };
 const calls: URL[] = [];
 await page.route('**/api/v1/**', route => {
  const url = new URL(route.request().url()); const path = url.pathname;
  const list = (data: unknown[]) => route.fulfill({ json: { data, meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 3, total: data.length, per_page: 20 } } });
  if (path.endsWith('/sites')) { calls.push(url); return list(url.searchParams.get('search') && url.searchParams.get('search') !== site.id ? [] : [site]); }
  if (path.endsWith('/sites/site-one/rooms')) { calls.push(url); return list([room]); }
  if (path.endsWith('/sites/site-one/rooms/room-one/zones')) { calls.push(url); return list([zone]); }
  if (path.endsWith('/locations/zone/zone-one')) return route.fulfill({ json: { data: zone } });
  if (path.endsWith('/locations/zone/zone-one/plants') || path.endsWith('/locations/zone/zone-one/photos')) return list([]);
  return route.fallback();
 });
 await page.goto('/sites?site=site-one&room=room-one&page=2');
 await expect.poll(() => calls.at(-1)?.pathname.endsWith('/zones')).toBe(true);
 expect(calls.at(-1)?.searchParams.get('page')).toBe('2');
 await expect(page.locator('.location-breadcrumbs')).toContainText(room.name);
 await expect(page.getByRole('region', { name: room.name, exact: true })).toHaveCount(0);
 await page.getByRole('button', { name: 'Photos et informations de Navigation zone', exact: true }).click();
 await expect(page.getByRole('dialog', { name: zone.name, exact: true })).toBeVisible();
 expect(new URL(page.url()).searchParams.get('location')).toBe('zone:zone-one');
 await page.reload(); await expect(page.getByRole('dialog', { name: zone.name, exact: true })).toBeVisible();
 await page.keyboard.press('Escape');
 await page.locator('.location-breadcrumbs').getByRole('button', { name: site.name, exact: true }).click();
 await expect.poll(() => calls.at(-1)?.pathname.endsWith('/rooms')).toBe(true);
 await expect(page.locator('.location-breadcrumbs')).toContainText(site.name);
 await expect(page.getByRole('region', { name: site.name, exact: true })).toHaveCount(0);
 const previous = calls.length;
 await page.goto('/sites?site=missing'); await expect(page.getByRole('alert')).toBeVisible();
 expect(calls.slice(previous).some(url => url.pathname.endsWith('/zones') || !url.searchParams.get('search'))).toBe(false);
 await expect(page.getByRole('button', { name: 'Photos et informations de Navigation zone', exact: true })).toHaveCount(0);
 expect(errors).toEqual([]);
});

test('file subject scopes resolve before reads and an invalid scope requires an explicit reset', async ({ page }) => {
 const { errors } = await insightRoutes(page); const reads: URL[] = [];
 await page.route('**/api/v1/**', route => {
  const url = new URL(route.request().url()); const path = url.pathname;
  if (path.endsWith('/locations/site/missing/context')) return route.fulfill({ status: 404, json: { error: { code: 'documents.validation.subjectInvalid' } } });
  if (path.endsWith('/documents')) { reads.push(url); return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } }); }
  return route.fallback();
 });
 await page.goto('/files?subjectType=site&subject=missing&group=location');
 await expect(page.getByRole('alert')).toBeVisible(); expect(reads).toEqual([]);
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click();
 await expect.poll(() => reads.length).toBe(1);
 expect(new URL(page.url()).searchParams.has('subject')).toBe(false);
 expect(reads[0]!.searchParams.get('site_id')).toBe(null); expect(errors).toEqual([]);
});

test('shared history dates preserve UTC boundaries in another browser time zone', async ({ browser }, testInfo) => {
 const context = await browser.newContext({ timezoneId: 'America/New_York', baseURL: String(testInfo.project.use.baseURL) });
 try {
  const page = await context.newPage(); const { requests, errors } = await insightRoutes(page);
  const params = new URLSearchParams({ sensor: 'sensor-0', 'history.period': 'custom', 'history.from': '2026-11-01T06:30:00.000Z', 'history.to': '2026-11-02T06:30:00.000Z', 'history.page': '2' });
  await page.goto(`/measurements?${params}`);
  const dialog = page.getByRole('dialog', { name: 'Historique · Moisture one', exact: true });
  await expect(dialog.getByLabel('Du', { exact: true })).toHaveValue('2026-11-01T01:30');
  const reports = () => requests.filter(url => url.pathname.endsWith('/reports/sensors/sensor-0'));
  await expect.poll(() => reports().at(-1)?.searchParams.get('date_from')).toBe('2026-11-01T06:30:00.000Z');
  expect(reports().at(-1)?.searchParams.get('date_to')).toBe('2026-11-02T06:30:00.000Z');
  await expect(dialog.getByTestId('report-mean')).toContainText('42');
  const originalReads = reports().length;
  await (await revealTabbedControl(dialog.locator('select.chart-kind'))).selectOption('bar');
  await expect.poll(() => new URL(page.url()).searchParams.get('history.chart')).toBe('bar');
  expect(new URL(page.url()).searchParams.get('history.from')).toBe('2026-11-01T06:30:00.000Z');
  expect(new URL(page.url()).searchParams.get('history.page')).toBe('2');
  expect(reports()).toHaveLength(originalReads);
  await (await revealTabbedControl(dialog.getByLabel('Du', { exact: true }))).fill('');
  await expect.poll(() => new URL(page.url()).searchParams.get('history.from')).toBe('');
  await expect(dialog.getByLabel('Du', { exact: true })).toHaveValue('');
  await expect(page.getByRole('alert')).toBeVisible();
  expect(errors).toEqual([]);
 } finally { await context.close(); }
});

test('catalog and provider lists restore their own namespaced criteria without running checks or imports', async ({ page }) => {
 const { errors } = await insightRoutes(page); const calls: URL[] = []; const writes: string[] = [];
 await page.route('**/api/v1/**', route => {
  const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'insight-reader', name: 'Integration manager', email: 'integration@example.test', role: 'admin', permissions: ['administration.view', 'integrations.manage', 'sensors.manage'], isActive: true, oidcLinked: false } } });
  if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: true } } });
  if (path.endsWith('/measurement-types') || path.startsWith('/api/v1/integrations/home-assistant')) {
   calls.push(url); if (request.method() !== 'GET') writes.push(path);
   if (path.endsWith('/integrations/home-assistant')) return route.fulfill({ json: { data: { configured: true, sync: { enabled: true, eventsEnabled: false, configurationValid: true, intervalSeconds: 600, linksCount: 0, lastAttemptAt: null, lastSuccessAt: null, nextEligibleAt: null } } } });
   return route.fulfill({ json: { data: [], meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 3, total: 0, per_page: Number(url.searchParams.get('per_page') ?? 20) } } });
  }
  return route.fallback();
 });
 await page.goto('/settings/catalogs?catalog=measurements&measurementTypes.q=Substrate&measurementTypes.page=2&measurementTypes.perPage=10');
 await expect(page.locator('#sensors-search')).toHaveValue('Substrate');
 const types = () => calls.filter(url => url.pathname.endsWith('/measurement-types'));
 await expect.poll(() => types().at(-1)?.searchParams.get('page')).toBe('2');
 expect(types().at(-1)?.searchParams.get('per_page')).toBe('10');
 const links = () => calls.filter(url => url.pathname.endsWith('/links'));
 await page.goto('/integrations/home-assistant?links.q=Probe&links.state=disabled&links.page=2');
 const search = page.getByRole('textbox', { name: 'Rechercher des canaux' });
 await expect(search).toHaveValue('Probe');
 await expect.poll(() => links().at(-1)?.searchParams.get('state')).toBe('disabled');
 expect(links().at(-1)?.searchParams.get('page')).toBe('2');
 await search.fill('Relay');
 await expect.poll(() => links().at(-1)?.searchParams.get('search')).toBe('Relay');
 expect(links().at(-1)?.searchParams.get('page')).toBe('1');
 await page.getByRole('button', { name: 'Réinitialiser les filtres' }).click();
 await expect.poll(() => links().at(-1)?.searchParams.get('search')).toBeNull();
 expect(links().at(-1)?.searchParams.get('state')).toBeNull();
 await page.getByRole('combobox', { name: 'Statut du canal' }).click();
 await page.getByRole('option', { name: 'Activé', exact: true }).click();
 await expect.poll(() => links().at(-1)?.searchParams.get('state')).toBe('enabled');

 await page.goto('/integrations/home-assistant?links.page=2&discovery.open=true&discovery.q=Soil&discovery.page=3');
 await expect.poll(() => calls.filter(url => url.pathname.endsWith('/entities')).at(-1)?.searchParams.get('search')).toBe('Soil');
 expect(calls.filter(url => url.pathname.endsWith('/entities')).at(-1)?.searchParams.get('page')).toBe('3');
 expect(calls.filter(url => url.pathname.endsWith('/links')).at(-1)?.searchParams.get('page')).toBe('2');
 expect(writes).toEqual([]); expect(errors).toEqual([]);
});

test('clearing a remembered filter remains explicit in shared URLs and survives reload', async ({ page }) => {
 const { requests, errors } = await insightRoutes(page);
 await page.route('**/api/v1/account/ui-preferences', route => route.request().method() === 'GET'
  ? route.fulfill({ json: { data: { 'measurements:status': 'stale' } } })
  : route.fulfill({ status: 204 }));
 await page.goto('/measurements');
 const reads = () => requests.filter(url => url.pathname.endsWith('/measurements/overview'));
 await expect.poll(() => reads().at(-1)?.searchParams.get('status')).toBe('stale');
 await page.locator('.insight-metric').first().click();
 await expect.poll(() => new URL(page.url()).searchParams.get('status')).toBe('');
 await expect.poll(() => reads().at(-1)?.searchParams.get('status')).toBe(null);
 const beforeReload = reads().length;
 await page.reload(); await expect.poll(() => reads().length).toBe(beforeReload + 1);
 expect(reads().at(-1)?.searchParams.get('status')).toBe(null);
 expect(errors).toEqual([]);
});


test('location breadcrumb menus load bounded choices, search and navigate exact parents without slash separators', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const calls: URL[] = [];
 const siteA = { id: 'site-a', kind: 'site', name: 'Navigation A', parentId: null, childrenCount: 1, canSelect: false, canManage: false, description: null };
 const siteB = { ...siteA, id: 'site-b', name: 'Navigation B' };
 const room = { ...siteA, id: 'room-b', kind: 'room', name: 'Groom B', code: 'R-B', parentId: siteB.id };
 const zone = { ...siteA, id: 'zone-b', kind: 'zone', name: 'Zone B', parentId: room.id, canSelect: true, childrenCount: 0 };
 const blocked = { ...zone, id: 'zone-blocked', name: 'Unavailable zone', canSelect: false };
 let failNextSites = false;
 await page.route('**/api/v1/**', route => {
  const url = new URL(route.request().url()); const path = url.pathname;
  const list = (data: unknown[], last = 1) => route.fulfill({ json: { data, meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: last, total: data.length, per_page: Number(url.searchParams.get('per_page') ?? 20) } } });
  const filter = <T extends { id: string; name: string }>(rows: T[]) => { const q = url.searchParams.get('search') ?? ''; return rows.filter(row => !q || row.id === q || row.name.toLowerCase().includes(q.toLowerCase())); };
  if (path.endsWith('/sites')) {
   calls.push(url);
   if (failNextSites) { failNextSites = false; return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } }); }
   const search = url.searchParams.get('search');
   return search ? list(filter([siteA, siteB])) : list(url.searchParams.get('page') === '2' ? [siteB] : [siteA], 2);
  }
  if (path.endsWith('/sites/site-b/rooms')) { calls.push(url); return list(filter([room])); }
  if (path.endsWith('/sites/site-b/rooms/room-b/zones')) { calls.push(url); return list(filter([zone, blocked])); }
  if (path.endsWith('/locations/zone/zone-b')) return route.fulfill({ json: { data: zone } });
  if (path.endsWith('/locations/zone/zone-b/photos') || path.endsWith('/locations/zone/zone-b/plants')) return list([]);
  return route.fallback();
 });
 await page.goto('/sites');
 const trail = page.getByRole('navigation', { name: 'Hiérarchie des lieux', exact: true });
 const sites = trail.getByRole('button', { name: 'Choisir un site', exact: true });
 await expect(sites).toBeEnabled();
 const beforeOpen = calls.length;
 failNextSites = true;
 await sites.focus(); await page.keyboard.press('ArrowDown');
 await expect(sites).toHaveAttribute('aria-expanded', 'true');
 await expect(page.getByRole('menu').getByRole('alert')).toBeVisible();
 expect(new URL(page.url()).searchParams.has('site')).toBe(false);
 await page.getByRole('menu').getByRole('button', { name: 'Réessayer', exact: true }).click();
 await page.getByRole('menu').getByRole('button', { name: 'Afficher plus', exact: true }).click();
 await page.getByRole('menuitem', { name: 'Navigation B', exact: true }).click();
 await expect.poll(() => new URL(page.url()).searchParams.get('site')).toBe(siteB.id);
 expect(calls.length).toBeGreaterThan(beforeOpen);
 expect(calls.filter(url => url.pathname.endsWith('/sites') && url.searchParams.get('page') === '2')).toHaveLength(1);
 await trail.getByRole('button', { name: 'Choisir une pièce', exact: true }).click();
 await (await revealTabbedControl(page.getByRole('menu').getByRole('searchbox'))).fill('Groom');
 await expect.poll(() => calls.some(url => url.pathname.endsWith('/rooms') && url.searchParams.get('search') === 'Groom')).toBe(true);
 await page.getByRole('menu').getByRole('searchbox').press('Escape');
 await expect(trail.getByRole('button', { name: 'Choisir une pièce', exact: true })).toBeFocused();
 await trail.getByRole('button', { name: 'Choisir une pièce', exact: true }).click();
 await expect(page.getByRole('menu').getByRole('searchbox')).toHaveValue('Groom');
 await page.getByRole('menuitem', { name: 'Groom B · R-B', exact: true }).click();
 await expect.poll(() => new URL(page.url()).searchParams.get('room')).toBe(room.id);
 await trail.getByRole('button', { name: 'Choisir une zone', exact: true }).click();
 const choice = page.getByRole('menuitem', { name: 'Zone B', exact: true });
 await expect(choice).toBeVisible();
 await expect(page.getByRole('menuitem', { name: 'Unavailable zone', exact: true })).toHaveAttribute('aria-disabled', 'true');
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  await expect(choice).toBeInViewport();
  await expect.poll(() => choice.evaluate(el => { let opacity = 1; let current: Element | null = el; while (current) { opacity *= Number(getComputedStyle(current).opacity); current = current.parentElement; } return opacity; })).toBe(1);
  expect(await trail.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
  expect(await page.getByRole('menu').evaluate(el => { const rect = el.getBoundingClientRect(); return rect.left >= -1 && rect.right <= innerWidth + 1; })).toBe(true);
  await page.screenshot({ path: test.info().outputPath(`location-breadcrumb-${width}.png`) });
 }
 expect(await page.locator('.app-breadcrumb .n-breadcrumb-item__separator').evaluateAll(nodes => nodes.every(node => getComputedStyle(node).display === 'none'))).toBe(true);
 await choice.click();
 await expect(page.getByRole('dialog', { name: zone.name, exact: true })).toBeVisible();
 expect(new URL(page.url()).searchParams.get('site')).toBe(siteB.id);
 expect(new URL(page.url()).searchParams.get('room')).toBe(room.id);
 expect(new URL(page.url()).searchParams.get('location')).toBe(`zone:${zone.id}`);
 expect(calls.every(url => Number(url.searchParams.get('per_page') ?? 20) <= 20)).toBe(true);
 expect(errors).toEqual([]);
});
