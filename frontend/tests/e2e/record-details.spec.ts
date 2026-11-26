import { applicationInfoFixture } from './application-info-fixture';
import { dashboardPayload } from './dashboard-payload-fixture';
import { locationOptionPage, locationOptions, type FixtureLocationNode } from './location-options-fixture';
import { uiPreferencesFixture } from './ui-preferences-fixture';
import { insightReport, insightSensors, insightMeta } from './insights-fixture';
import type { Plant, PlantMeasurement } from '../../src/api/plants';
import { lifecycleStages, lifecycleIcons } from './lifecycle-fixture';
import { test, expect, type Page } from '../coverage/fixture';
import { openModule, revealTabbedControl, selectOptionByText } from './navigation';

function previewPdf() {
 const stream = 'BT /F1 24 Tf 40 120 Td (Fictional PDF preview) Tj ET';
 const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 360 180] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
 let pdf = '%PDF-1.4\n'; const offsets = [0];
 objects.forEach((object, index) => { offsets.push(Buffer.byteLength(pdf)); pdf += `${index + 1} 0 obj\n${object}\nendobj\n`; });
 const xref = Buffer.byteLength(pdf); pdf += `xref\n0 6\n0000000000 65535 f \n${offsets.slice(1).map(offset => `${String(offset).padStart(10, '0')} 00000 n `).join('\n')}\ntrailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
 return Buffer.from(pdf);
}
const durations = { curing: 14, flowering: 56, flush: 14, germination: 7, growth: 21, hanging_drying: 7, rack_drying: 7, sowing: 3 };
const recordLocations: FixtureLocationNode[] = [{ id: 'site-one', kind: 'site', name: 'Fictional Site', canSelect: true, children: [{ id: 'room-one', kind: 'room', name: 'Fictional Room', canSelect: true, children: [{ id: 'zone-one', kind: 'zone', name: 'Fictional Zone', canSelect: true, children: [] }] }] }];

async function session(page: Page, canManage = true, rich = false, age: { day?: number | null; week?: number | null; phase?: string; nextAction?: string; notes?: string; photoUrl?: string; hasOwnPhoto?: boolean; measurements?: PlantMeasurement[]; summarySensorIds?: string[] | null; devices?: { id: string; name: string; status: string; category?: string; canActivate?: boolean }[] } & Partial<Pick<Plant, 'startedAt' | 'phaseStartedAt' | 'phaseDueAt' | 'container' | 'harvestWeightGrams'>> = {}, cardCount = 1, configuredStages = lifecycleStages, strainLineage = 'Fictional lineage') {
 const preferences = uiPreferencesFixture('reader');
 const errors: string[] = []; const requests: string[] = []; const mutations: { path: string; input: Record<string, unknown> }[] = [];
 page.on('pageerror', error => errors.push(error.message));
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const strain = { id: 'strain-one', name: 'Fictional Strain', theoreticalYield: '550.50', sourceUrl: 'https://example.test/strain', classification: 'hybrid', genetics: 'Fictional genetics', lineage: strainLineage, thc: '20%', cbd: '1%', aroma: 'Fictional aroma', reportedEffects: 'Fictional effects', notes: 'Fictional strain notes', seedStock: { id: 'stock-one', name: 'Seed stock', quantity: '12', isArchived: false, scoped: false }, durationOverrides: { flowering: 70 }, isArchived: false, version: 1, canManage };
 let plant = { id: 'plant-one', name: 'Fictional Plant', givenName: 'Fictional Plant', notes: 'Fictional plant notes', strain: { id: strain.id, name: strain.name, classification: strain.classification, genetics: strain.genetics }, phase: 'growth', startedAt: '2026-11-01T12:00:00Z', phaseStartedAt: '2026-11-01T12:00:00Z', phaseDueAt: '2026-11-22T12:00:00Z', day: 8, week: 2, isOverdue: false, nextAction: 'repot_flowering', harvestWeightGrams: null, durationSnapshot: { durations, settings_version: 1, strain_version: 1 }, location: { type: 'zone', id: 'zone-one', site: { id: 'site-one', name: 'Fictional Site' }, room: { id: 'room-one', name: 'Fictional Room' }, zone: { id: 'zone-one', name: 'Fictional Zone' } }, devices: [], summarySensorIds: null as string[] | null, version: 1, canManage, canDelete: canManage, hasOwnPhoto: false, photoUrl: null, watering: { status: 'alternate', last: rich ? { occurredAt: '2026-11-05T12:00:00Z', withProducts: true } : null, nextWithProducts: false, flushStartsAt: null, flushDays: 14, need: rich ? { needed: true, reason: 'elapsed', alertDays: 3 } : undefined }, ...age };
 const plantRows = () => Array.from({ length: cardCount }, (_, index) => ({ ...plant, id: index ? `plant-${index}` : plant.id, name: index ? `Fictional Plant ${index} ${'Long name '.repeat(index % 3 * 4)}` : plant.name, ...(index % 2 ? { phase: 'maturation', nextAction: 'archive', watering: { ...plant.watering, status: 'unavailable' } } : {}) }));
 const strainRows = () => Array.from({ length: cardCount }, (_, index) => ({ ...strain, id: index ? `strain-${index}` : strain.id, name: index ? `Fictional Strain ${index} ${'Long name '.repeat(index % 3 * 4)}` : strain.name }));
 await page.route('**/api/v1/**', async route => {
  const url = new URL(route.request().url()); const path = url.pathname; const method = route.request().method(); requests.push(`${method} ${path}`);
  if (method === 'PATCH' && path.endsWith('/strains/strain-one')) {
   const input = route.request().postDataJSON(); mutations.push({ path, input });
   Object.assign(strain, { ...('theoretical_yield' in input ? { theoreticalYield: input.theoretical_yield } : {}), version: strain.version + 1 });
   return route.fulfill({ json: { data: strain } });
  }
  if (method === 'PATCH' && path.endsWith('/plants/plant-one')) {
   const input = route.request().postDataJSON(); mutations.push({ path, input });
  plant = { ...plant, ...('name' in input ? { name: input.name || `Fictional Strain · #${plant.id.slice(0, 8)}`, givenName: input.name } : {}), ...('notes' in input ? { notes: input.notes } : {}), ...('summary_sensor_ids' in input ? { summarySensorIds: input.summary_sensor_ids } : {}), ...('photo_document_id' in input ? { hasOwnPhoto: !!input.photo_document_id, photoUrl: input.photo_document_id ? `/api/v1/documents/${input.photo_document_id}/preview` : null } : {}), version: plant.version + 1 };
   return route.fulfill({ json: { data: plant } });
  }
  if (method === 'POST' && /\/plants\/plant-one\/(waterings|maintenance|actions)$/.test(path)) {
   mutations.push({ path, input: route.request().postDataJSON() }); plant = { ...plant, version: plant.version + 1 };
   return route.fulfill({ json: { data: plant } });
  }
  if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (method !== 'GET') throw new Error(`Unexpected synthetic write: ${method} ${path}`);
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (path.endsWith('/application-info')) return route.fulfill({ json: { data: applicationInfoFixture() } });
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'reader', name: 'Fictional User', email: 'reader@example.test', role: canManage ? 'admin' : 'member', permissions: canManage ? ['administration.view', 'plants.manage', 'strains.manage', 'locations.manage', 'assets.manage', 'sensors.manage', 'inventory.manage'] : [], isActive: true, oidcLinked: false } } });
  if (path.includes('/documents/') && /\/(preview|thumbnail|image-preview)$/.test(path)) {
   if (path.includes('/report/')) {
    const pdf = Buffer.from(previewPdf()); const probe = route.request().headers().range === 'bytes=0-0';
    return route.fulfill({ status: probe ? 206 : 200, contentType: 'application/pdf', headers: { 'Content-Disposition': 'inline', 'Accept-Ranges': 'bytes', 'Content-Security-Policy': "sandbox; default-src 'none'", ...(probe ? { 'Content-Range': `bytes 0-0/${pdf.length}` } : {}) }, body: probe ? pdf.subarray(0, 1) : pdf });
   }
   if (path.includes('/note/')) return route.fulfill({ contentType: 'text/plain', body: 'Fictional text preview <script>window.previewInjected = true</script>' });
   if (path.includes('/large/')) return route.fulfill({ contentType: 'text/csv', headers: { 'X-Preview-Truncated': 'true' }, body: 'a'.repeat(1024 * 1024) });
   if (path.includes('/failed/')) return route.fulfill({ status: 404, json: { error: { code: 'documents.errors.fileUnavailable' } } });
   if (path.includes('photo-three')) return route.fulfill({ status: 404, body: '' });
   return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=', 'base64') });
  }
  const libraryDocuments = [
   ['report', 'report.pdf', 'application/pdf', 'pdf'], ['note', 'note.txt', 'text/plain', 'text'], ['large', 'large.csv', 'text/csv', 'text'], ['failed', 'missing.png', 'image/png', 'image'], ['photo-two', 'Photo 2.png', 'image/png', 'image'],
  ].map(([id, originalName, mediaType, format]) => ({ id, originalName, title: originalName, mediaType, format, sizeBytes: 100, sha256: '', version: 1, isArchived: false, subject: null, uploader: { id: 'reader', name: 'Fictional User' }, createdAt: '2026-11-08T12:00:00Z', updatedAt: '2026-11-08T12:00:00Z', canEdit: canManage }));
  if (path.endsWith('/sensors/sensor-0')) return route.fulfill({ json: { data: insightSensors[0] } });
  if (path.endsWith('/documents')) return route.fulfill({ json: { data: libraryDocuments, meta: { ...meta, total: libraryDocuments.length } } });
  const libraryDocument = libraryDocuments.find(row => path === `/api/v1/documents/${row.id}`);
  if (libraryDocument) return route.fulfill({ json: { data: libraryDocument } });
  if (path.includes('/documents/') && path.endsWith('/download')) return route.fulfill({ contentType: 'application/pdf', body: 'Fictional attachment' });
  if (path.endsWith('/photos')) {
   const all = ['photo-one', 'photo-two', 'photo-three', 'photo-four'].map((id, index) => ({ id, name: `Photo ${index + 1}.png`, url: `/api/v1/documents/${id}/preview`, isCover: false, createdAt: '2026-11-08T12:00:00Z' }));
   const current = Number(url.searchParams.get('page') ?? 1);
   return route.fulfill({ json: { data: rich ? all.slice((current - 1) * 3, current * 3) : [], meta: { current_page: current, last_page: rich ? 2 : 1, total: rich ? 4 : 0, per_page: 3 } } });
  }
  if (path.endsWith('/sites')) return route.fulfill({ json: { data: [{ id: 'site-one', kind: 'site', name: 'Fictional Site', parentId: null, childrenCount: 0, canSelect: true }], meta } });
  if (path.endsWith('/locations/options')) return route.fulfill({ json: locationOptionPage(recordLocations, url) });
  if (/\/locations\/(site|room|zone)\/[^/]+\/context$/.test(path)) { const parts = path.split('/'); const row = locationOptions(recordLocations).find(row => row.context.type === parts.at(-3) && row.context.id === parts.at(-2) && !row.disabled); return route.fulfill({ status: row ? 200 : 404, json: { data: row ?? null } }); }
  if (path.endsWith('/plant-dashboard')) return route.fulfill({ json: { ...dashboardPayload(plantRows(), url), meta: { ...meta, total: cardCount }, summary: { active: 1, planned: 0, due: 0, total: 1 }, filterOptions: { strains: [{ id: strain.id, name: strain.name }] } } });
  if (path.endsWith('/consumables')) return route.fulfill({ json: { data: { products: [], waterLiters: null, canConsume: true, container: null, currentContainer: null } } });
  if (path.endsWith('/plants/plant-one')) return route.fulfill({ json: { data: plant } });
  if (path.endsWith('/plants/plant-one/events')) {
   const event = { fromPhase: 'growth', toPhase: 'growth', actorId: 'reader', recordedAt: '2026-11-08T12:00:00Z', snapshot: { name: plant.name, location: plant.location, previous_location: null, strain_id: strain.id }, fields: [], harvestWeightGrams: null, maintenance: null, watering: null, attachments: [] };
   return route.fulfill({ json: { data: rich ? [
    { ...event, id: 'old', kind: 'created', occurredAt: '2026-11-01T12:00:00Z', comment: 'Oldest observation' },
    { ...event, id: 'recent', kind: 'maintained', occurredAt: '2026-11-08T12:00:00Z', comment: 'Most recent observation', maintenance: { type: 'pruning' }, attachments: [{ id: 'photo-two', originalName: 'Photo 2.png', title: 'Observation photo', mediaType: 'image/png', sizeBytes: 68 }, { id: 'report', originalName: 'report.pdf', title: 'Report', mediaType: 'application/pdf', sizeBytes: 20 }] },
    { ...event, id: 'middle', kind: 'watered', occurredAt: '2026-11-05T12:00:00Z', comment: 'Middle observation', watering: { withProducts: false, duringFlush: false } },
   ] : [], meta: { ...meta, last_page: rich ? 1 : 2 } } });
  }
  if (path.endsWith('/plants')) return route.fulfill({ json: { data: plantRows(), meta: { ...meta, total: cardCount } } });
  if (path.endsWith('/strains/strain-one')) return route.fulfill({ json: { data: strain } });
  if (path.endsWith('/strains')) return route.fulfill({ json: { data: strainRows(), meta: { ...meta, total: cardCount } } });
  if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage } } });
  if (path.endsWith('/lifecycle-settings')) return route.fulfill({ json: { data: { stages: configuredStages, icons: lifecycleIcons, durations, version: 1, canManage } } });
  return route.fulfill({ json: { data: [], meta } });
 });
 await page.addInitScript(() => { if (window === window.top && !localStorage.getItem('locale')) localStorage.setItem('locale', 'fr'); }); await page.goto('/');
 await expect(page.getByTestId('plant-home')).toBeVisible();
 return { errors, requests, mutations, preferences, plants: plantRows() };
}

test('dashboard plant dialog keeps site, filters and disclosures, restores focus and refreshes edits', async ({ page }) => {
 const { errors, requests } = await session(page);
 const home = page.getByTestId('plant-home');
 await home.locator('#dashboard-sort').selectOption('name_desc');
 const card = home.getByTestId('plant-card-plant-one'); const trigger = card.locator('.open-plant');
 await expect(trigger).toBeVisible(); await expect(card.locator('.genetics-tag')).toHaveText('Hybride'); await expect(card.locator('.photo-strain')).toHaveText('Fictional Strain'); await expect(card.locator('.plant-card-reference')).toHaveText('#plant-on'); await trigger.click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail).toBeVisible(); await expect(detail.locator('.plant-detail > .record-badges .genetics-tag')).toHaveText('Hybride'); await expect(detail.locator('.plant-detail > .record-badges .genetics-tag')).toHaveAttribute('title', 'Génétique : Hybride'); await expect(detail.getByText('Fictional plant notes', { exact: true })).toBeVisible();
 await expect(page.getByTestId('nav-home')).toHaveAttribute('aria-current', 'page');
 expect(requests.filter(path => path === 'GET /api/v1/plants')).toHaveLength(0);
 await detail.getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click(); await page.locator('#plant-notes').fill('Updated in the modal');
 await page.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect(detail.getByText('Updated in the modal', { exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0);
 await expect(home.locator('#dashboard-site')).toHaveValue('site-one');
 await expect(home.locator('#dashboard-sort')).toHaveValue('name_desc');
 await expect(home.locator('.home-room').first()).toHaveAttribute('open', '');
 await expect(trigger).toBeFocused();
 expect(errors).toEqual([]);
});

test('registry plant detail supports history, action cancellation and mobile closing', async ({ page }) => {
 const { errors } = await session(page);
 await openModule(page, 'plants');
 const trigger = page.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true });
 await trigger.click(); const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail).toBeVisible();
 await detail.getByRole('navigation', { name: 'Pages de l’historique', exact: true }).getByRole('button', { name: 'Suivant', exact: true }).click();
 await expect(detail).toBeVisible();
 await detail.getByRole('button', { name: 'Enregistrer un arrosage', exact: true }).click();
 await page.keyboard.press('Escape'); await expect(detail).toBeVisible();
 await detail.getByRole('button', { name: 'Enregistrer un entretien', exact: true }).click();
 await page.keyboard.press('Escape'); await expect(detail).toBeVisible();
 await detail.getByLabel('Gérer les photos', { exact: true }).click(); await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
 const library = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
 await library.getByRole('button', { name: 'Agrandir Photo 2.png', exact: true }).click();
 await expect(page.getByRole('dialog', { name: 'Photo 2.png', exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expect(library).toBeVisible();
 await page.keyboard.press('Escape'); await expect(library).toHaveCount(0); await expect(detail).toBeVisible();
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await detail.getByRole('button', { name: 'Fermer la fiche', exact: true }).click();
 await expect(trigger).toBeFocused(); expect(errors).toEqual([]);
});

test('strain detail exposes metadata, seed stock and durations with edit cancellation and reader access', async ({ page }) => {
 const { errors } = await session(page, false);
 await openModule(page, 'catalog'); await page.locator('#strain-search').fill('Fictional');
 const trigger = page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }); await trigger.click();
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(detail.locator('.genetics-tag')).toHaveText('Hybride');
 await expect(detail.getByText('Fictional genetics', { exact: true })).toHaveCount(0);
 await expect(detail.getByText('Fictional strain notes', { exact: true })).toBeVisible();
 await expect(detail.locator('.seed-stock')).toContainText('12');
 await detail.locator('summary').click(); await expect(detail).toContainText('10 semaines');
 await expect(detail.getByRole('button', { name: 'Modifier Fictional Strain', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0); await expect(trigger).toBeFocused();
 await expect(page.locator('#strain-search')).toHaveValue('Fictional');
 await expect(page.getByRole('button', { name: 'Gérer les photos', exact: true })).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'Supprimer Fictional Strain', exact: true })).toHaveCount(0);
 await expect(page.getByTestId('strain-row-strain-one').getByRole('button', { name: 'Ajouter une plante', exact: true })).toHaveCount(0);
 expect(errors).toEqual([]);
});

test('strain edit returns to detail and a closed pending plant request never reopens its modal', async ({ page }) => {
 const { errors } = await session(page);
 await openModule(page, 'catalog'); await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await detail.getByRole('button', { name: 'Modifier Fictional Strain', exact: true }).click();
 await page.keyboard.press('Escape'); await expect(detail).toBeVisible(); await page.keyboard.press('Escape');
 await expect(page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true })).toBeFocused();
 await openModule(page, 'home');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; });
 await page.route('**/api/v1/plants/plant-one', async route => { await pending; await route.fulfill({ status: 404, json: { error: { code: 'auth.errors.notFound' } } }); });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const loading = page.getByRole('dialog'); await expect(loading).toBeVisible();
 await page.keyboard.press('Escape'); await expect(loading).toHaveCount(0);
 const response = page.waitForResponse('**/api/v1/plants/plant-one'); release(); await response;
 await expect(page.getByTestId('plant-home')).toBeVisible();
 await expect(page.getByRole('dialog')).toHaveCount(0); expect(errors).toEqual([]);
});


test('plant gallery supports pagination, missing files and a newest-first timeline', async ({ page }) => {
 const { errors } = await session(page, true, true);
 await page.setViewportSize({ width: 1440, height: 1100 });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 const actions = detail.getByRole('navigation', { name: 'Actions de la fiche', exact: true });
 await expect(actions.getByRole('button', { name: 'Modifier Fictional Plant', exact: true })).toBeVisible();
 await expect(actions.getByRole('button', { name: 'Enregistrer un arrosage', exact: true })).toBeVisible();
 const gallery = detail.getByRole('region', { name: 'Galerie photo', exact: true });
 await expect(gallery.locator('.gallery-thumbnails button')).toHaveCount(3);
 await gallery.getByRole('button', { name: 'Photo suivante', exact: true }).click();
 await expect(gallery.locator('.gallery-enlarge img')).toHaveAttribute('src', '/api/v1/documents/photo-two/thumbnail');
 await gallery.getByRole('button', { name: 'Agrandir Photo 2.png', exact: true }).click();
 const preview = page.getByRole('dialog', { name: 'Photo 2.png', exact: true }); await expect(preview).toBeVisible();
 await page.keyboard.press('Escape'); await expect(preview).toHaveCount(0); await expect(detail).toBeVisible();
 expect(await detail.locator('.timeline-event').evaluateAll(rows => rows.map(row => row.getAttribute('data-event-id')))).toEqual(['recent', 'middle', 'old']);
 await detail.getByRole('button', { name: 'Agrandir Photo 2.png', exact: true }).last().click();
 await expect(preview).toBeVisible(); await preview.getByRole('button', { name: 'Fermer l’aperçu', exact: true }).click();
 const download = page.waitForEvent('download');
 await detail.getByRole('button', { name: 'Télécharger report.pdf', exact: true }).click();
 expect((await download).suggestedFilename()).toBe('report.pdf');
 await gallery.getByRole('button', { name: 'Plus de photos', exact: true }).click();
 await expect(gallery.locator('.gallery-thumbnails button')).toHaveCount(4);
 await gallery.getByRole('button', { name: 'Photo 3', exact: true }).click();
 await expect(gallery.getByText('Photo indisponible', { exact: true })).toBeVisible();
 await gallery.getByRole('button', { name: 'Photo 1', exact: true }).click();
 await detail.locator('.n-card-content').evaluate(element => { element.scrollTop = 0; });
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 expect(errors).toEqual([]);
});

test('inherited strain photos appear on plant cards and details without a removal action', async ({ page }) => {
 const url = '/api/v1/documents/photo-one/preview?version=1';
 const { errors, mutations, requests } = await session(page, true, false, { photoUrl: url, hasOwnPhoto: false });
 await page.route('**/api/v1/plants/plant-one/photos?*', route => route.fulfill({ json: {
  data: [{ id: 'photo-one', name: 'Strain photo.png', url, createdAt: null, isCover: true }],
  meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 },
 } }));
 const card = page.getByTestId('plant-card-plant-one');
 await expect(card.locator('.plant-image > img')).toHaveAttribute('src', url.replace('/preview', '/thumbnail'));
 await card.locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail.locator('.gallery-enlarge > img')).toHaveAttribute('src', url.replace('/preview', '/thumbnail'));
 await expect(detail.locator('.gallery-thumbnails button')).toHaveCount(0);
 expect(requests.some(path => path === 'GET /api/v1/documents/photo-one/preview')).toBe(false);
 await detail.locator('.gallery-enlarge').click();
 const enlarged = page.getByRole('dialog', { name: 'Strain photo.png', exact: true });
 await expect(enlarged.locator('img')).toHaveAttribute('src', url.replace('/preview', '/image-preview'));
 await expect.poll(() => requests.includes('GET /api/v1/documents/photo-one/image-preview')).toBe(true);
 expect(requests.includes('GET /api/v1/documents/photo-one/preview')).toBe(false);
 await enlarged.getByRole('button', { name: 'Taille originale', exact: true }).click();
 await expect(enlarged.locator('img')).toHaveAttribute('src', url);
 await expect.poll(() => requests.includes('GET /api/v1/documents/photo-one/preview')).toBe(true);
 await page.keyboard.press('Escape');
 await detail.getByRole('button', { name: 'Gérer les photos', exact: true }).click();
 const photoManager = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
 await expect(photoManager.getByRole('button', { name: 'Retirer la photo principale', exact: true })).toHaveCount(0);
 await photoManager.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.keyboard.press('Escape');
 await openModule(page, 'plants');
 await expect(page.locator('.photo-plant .plant-image > img').first()).toHaveAttribute('src', url.replace('/preview', '/thumbnail'));
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('Files previews PDF, escaped text, images, truncation and errors without downloading', async ({ page }) => {
 const { errors, requests } = await session(page, false, true);
 let downloads = 0; page.on('download', () => { downloads++; });
 const pdfFetches: string[] = [];
 page.on('request', request => { if (request.url().endsWith('/documents/report/preview') && request.resourceType() === 'fetch') pdfFetches.push(request.headers().range ?? ''); });
 await openModule(page, 'documents');
 await page.getByRole('button', { name: 'Visionner note.txt', exact: true }).click();
 const note = page.getByRole('dialog', { name: 'note.txt', exact: true });
 await expect(note.locator('pre')).toContainText('<script>window.previewInjected = true</script>');
 expect(await page.evaluate(() => 'previewInjected' in window)).toBe(false);
 await page.keyboard.press('Escape'); await expect(note).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'Visionner note.txt', exact: true })).toBeFocused();
 await page.getByRole('button', { name: 'Visionner report.pdf', exact: true }).click();
 const pdf = page.getByRole('dialog', { name: 'report.pdf', exact: true });
 await expect(pdf.locator('iframe')).toHaveAttribute('src', '/api/v1/documents/report/preview');
 await expect.poll(() => page.frames().filter(frame => frame.url().includes('/api/v1/documents/report/preview')).length).toBeGreaterThan(0);
 await page.waitForTimeout(1200);
 expect(pdfFetches).toEqual(['bytes=0-0']);
 await pdf.getByRole('button', { name: 'Fermer l’aperçu', exact: true }).click();
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('button', { name: 'Visionner Photo 2.png', exact: true }).click();
 const photo = page.getByRole('dialog', { name: 'Photo 2.png', exact: true });
 await expect.poll(() => photo.locator('img').evaluate((img: HTMLImageElement) => img.naturalWidth)).toBe(1);
 expect(requests.includes('GET /api/v1/documents/photo-two/image-preview')).toBe(true);
 expect(await photo.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 await page.keyboard.press('Escape');
 await page.getByRole('button', { name: 'Visionner large.csv', exact: true }).click();
 const large = page.getByRole('dialog', { name: 'large.csv', exact: true });
 await expect(large.getByText(/Seul le premier Mo/)).toBeVisible();
 expect(await large.locator('pre').textContent()).toHaveLength(1024 * 1024);
 await page.keyboard.press('Escape');
 await page.getByRole('button', { name: 'Visionner missing.png', exact: true }).click();
 const missing = page.getByRole('dialog', { name: 'missing.png', exact: true });
 await expect(missing.page().getByRole('alert').filter({ hasText: 'Ce fichier ne peut pas être affiché' }).first()).toContainText('Ce fichier ne peut pas être affiché');
 await missing.getByRole('button', { name: 'Réessayer', exact: true }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'Ce fichier ne peut pas être affiché' }).first()).toBeVisible(); await page.keyboard.press('Escape');
 expect(downloads).toBe(0); expect(requests.some(path => path.endsWith('/download'))).toBe(false);
 expect(errors).toEqual([]);
});

test('plant history attachment PDF opens above the record and Escape retains the plant', async ({ page }) => {
 const { errors } = await session(page, true, true);
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 const trigger = detail.getByRole('button', { name: 'Visionner report.pdf', exact: true });
 await trigger.click(); const pdf = page.getByRole('dialog', { name: 'report.pdf', exact: true });
 await expect(pdf.locator('iframe')).toHaveAttribute('src', '/api/v1/documents/report/preview');
 await page.keyboard.press('Escape'); await expect(pdf).toHaveCount(0); await expect(detail).toBeVisible(); await expect(trigger).toBeFocused();
 expect(errors).toEqual([]);
});

test('compact action footer submits its associated form and keeps values during inline selection', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.route('**/api/v1/plants/plant-one/events?*', route => route.fulfill({ json: { data: ['repot_growth', 'repot_flowering'].map((kind, index) => ({ id: `repot-${index}`, kind, fromPhase: 'growth', toPhase: 'growth', occurredAt: '2026-11-08T12:00:00Z', recordedAt: '2026-11-08T12:00:00Z', snapshot: { location: null }, fields: [], attachments: [], comment: null })), meta: { current_page: 1, last_page: 1 } } }));

 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.getByRole('button', { name: 'Enregistrer un arrosage', exact: true }).click();
 const dialog = page.locator('.form-dialog'); const form = dialog.locator('form.dialog-form');
 await expect(dialog.locator('.n-card__footer button[type=submit]')).toHaveAttribute('form', (await form.getAttribute('id'))!);
 await expect(dialog.locator('#plant-watering-comment-template')).toHaveCount(0);
 await expect(dialog.getByRole('tab', { name: 'Action', exact: true })).toHaveAttribute('aria-selected', 'true');
 await dialog.getByRole('tab', { name: 'Commentaire', exact: true }).click();
 await expect(dialog.locator('#plant-watering-comment')).toBeVisible();
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without'); await (await revealTabbedControl(dialog.locator('#plant-watering-comment'))).fill('Fictional compact watering');
 await dialog.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
 await dialog.getByLabel('Choisir un document', { exact: true }).and(dialog.locator('.n-select')).click(); await page.keyboard.press('Escape');
 await expect(dialog.locator('#plant-watering-comment')).toHaveValue('Fictional compact watering');
 await dialog.locator('.n-card-content').evaluate(el => { el.scrollTop = el.scrollHeight; });
 await expect(dialog.locator('.n-card__footer button[type=submit]')).toBeInViewport();
 await dialog.locator('.n-card__footer button[type=submit]').click(); await expect(dialog).toHaveCount(0); await expect(detail).toBeVisible();
 expect(mutations[0]?.input).toMatchObject({ with_products: false, comment: 'Fictional compact watering', document_ids: [] });
 await detail.getByRole('button', { name: 'Enregistrer un entretien', exact: true }).click();
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-type'))).selectOption('topping'); await (await revealTabbedControl(dialog.locator('#plant-maintenance-comment'))).fill('Fictional compact maintenance');
 await dialog.locator('.n-card__footer button[type=submit]').click(); await expect(dialog).toHaveCount(0); await expect(detail).toBeVisible();
 expect(mutations[1]?.input).toMatchObject({ type: 'topping', comment: 'Fictional compact maintenance' });
 const repot = detail.getByRole('button', { name: 'Rempoter avant la floraison — Fictional Plant', exact: true });
 await repot.click();
 await dialog.locator('.n-card__footer button[type=submit]').click(); await expect(dialog).toHaveCount(0); await expect(detail).toBeVisible();
 expect(mutations[2]?.input).toMatchObject({ action: 'repot_flowering' });

 expect(errors).toEqual([]);
});

test('plant record includes populated strain facts and recovers a missing summary', async ({ page }) => {
 const { errors } = await session(page, false);
 let unavailable = true;
 await page.route('**/api/v1/strains/strain-one', async route => {
  if (unavailable) return route.fulfill({ status: 404, json: { error: { code: 'auth.errors.requestFailed' } } });
  return route.fulfill({ json: { data: { id: 'strain-one', name: 'Fictional Strain', classification: 'hybrid', genetics: '60% indica /40% sativa', lineage: 'Fictional parents', thc: '20%', cbd: '1%', aroma: 'Citrus', reportedEffects: null, notes: 'Fictional strain notes', sourceUrl: null } } });
 });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.locator('.plant-strain-details > summary').click();
 const summary = detail.getByRole('region', { name: 'Informations sur la variété', exact: true });
 await expect(summary.getByText('Les informations de la variété sont momentanément indisponibles.', { exact: true })).toBeVisible();
 unavailable = false; await summary.getByRole('button', { name: 'Réessayer', exact: true }).click();
 await expect(summary.locator('dd')).toHaveText(['Fictional parents', '20%', '1%', 'Citrus']);
 await expect(summary.getByRole('button', { name: 'Réessayer', exact: true })).toHaveCount(0);
 await page.setViewportSize({ width: 390, height: 844 }); await summary.scrollIntoViewIfNeeded(); expect(await detail.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0); expect(errors).toEqual([]);
});

test('catalog and select searches update automatically and attachment removal preserves the action form', async ({ page }) => {
 const { errors, mutations } = await session(page);
 {
  await openModule(page, 'catalog');
  const content = page.locator('#workspace-content'); const row = content.getByTestId('strain-row-strain-one');
  const open = row.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true });
  const edit = row.getByRole('button', { name: 'Modifier Fictional Strain', exact: true });
  await expect(content.getByRole('button', { name: 'Rechercher', exact: true })).toHaveCount(0);
  const request = page.waitForRequest(req => new URL(req.url()).pathname.endsWith('/strains') && new URL(req.url()).searchParams.get('search') === 'Fictional');
  await content.locator('#strain-search').fill('Fictional'); await request; await expect(row).toBeVisible();
  await open.focus(); await page.keyboard.press('Enter'); await expect(page.getByRole('dialog', { name: 'Fictional Strain', exact: true })).toBeVisible();
  await page.keyboard.press('Escape'); await expect(open).toBeFocused();
  await edit.click(); await expect(page.locator('.form-dialog #strain-name')).toHaveValue('Fictional Strain'); await page.keyboard.press('Escape'); await expect(edit).toBeFocused();
 }
 await openModule(page, 'home'); await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).getByRole('button', { name: 'Enregistrer un arrosage', exact: true }).click();
 const watering = page.locator('.form-dialog');
 await watering.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
 const documents = watering.getByLabel('Choisir un document', { exact: true }).and(watering.locator('select'));
 await watering.getByLabel('Choisir un document', { exact: true }).and(watering.locator('.n-select')).click(); await page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }).fill('report');
 await page.getByRole('listbox').getByRole('option', { name: /report.pdf/ }).click();
 const remove = watering.getByRole('button', { name: 'Retirer report.pdf de cette entrée', exact: true });
 await expect(remove.locator('svg')).toBeVisible(); await expect(remove).toHaveText('');
 await remove.click(); await expect(remove).toHaveCount(0); await expect(watering).toBeVisible();
 await documents.selectOption('report'); await expect(remove).toBeVisible(); await remove.click();
 expect(mutations).toEqual([]); await page.keyboard.press('Escape');
 expect(errors).toEqual([]);
});

test('strain genetics has one classification selector and consistent record values', async ({ page }) => {
 const { errors } = await session(page);
 const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/strains/strain-one', async route => {
  if (route.request().method() !== 'PATCH') return route.fallback();
  const input = route.request().postDataJSON(); writes.push(input);
  return route.fulfill({ json: { data: { id: 'strain-one', name: input.name, classification: input.classification, genetics: input.genetics, lineage: input.lineage, thc: input.thc, cbd: input.cbd, aroma: input.aroma, reportedEffects: input.reported_effects, notes: input.notes, sourceUrl: input.source_url, seedStock: null, durationOverrides: input.duration_overrides, isArchived: false, version: 2, canManage: true } } });
 });
 await openModule(page, 'catalog'); await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(detail.locator('.genetics-tag')).toHaveText('Hybride');
 await expect(detail.getByText('Fictional genetics', { exact: true })).toHaveCount(0);
 await detail.getByRole('button', { name: 'Modifier Fictional Strain', exact: true }).click();
 const editor = page.locator('.form-dialog'); const genetics = editor.getByLabel('Génétique', { exact: true }).and(editor.locator('select'));
 await expect(genetics).toHaveCount(1); await expect(genetics).toHaveJSProperty('tagName', 'SELECT'); await expect(genetics).toHaveValue('hybrid');
 await expect(editor.getByLabel('Classification', { exact: true })).toHaveCount(0);
 await genetics.selectOption('sativa'); await editor.locator('.n-card__footer button[type=submit]').click();
 await expect(editor).toHaveCount(0); expect(writes).toHaveLength(1);
 expect(writes[0]!.classification).toBe('sativa'); expect(writes[0]!.genetics).toBe('Fictional genetics');
 expect(errors).toEqual([]);
});

for (const planned of [false, true]) test(`dashboard creates a ${planned ? 'planned' : 'sown'} plant in its current site without leaving home`, async ({ page }) => {
 const { errors, mutations, requests } = await session(page);
 let saved = false;
 await page.route('**/api/v1/plants', async route => {
  if (route.request().method() !== 'POST') return route.fallback();
  const input = route.request().postDataJSON(); mutations.push({ path: '/api/v1/plants', input });
  saved = true;
  return route.fulfill({ json: { data: { id: 'new-plant', name: input.name, phase: planned ? 'planned' : 'sowing' } } });
 });
 const trigger = page.getByRole('button', { name: 'Ajouter une plante', exact: true });
 await trigger.click();
 let editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await expect(editor.getByTestId('plant-location')).toHaveText('Fictional Site');
 await editor.locator('#plant-name').fill('Canceled draft');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(editor).toHaveCount(0); await expect(trigger).toBeFocused(); expect(mutations).toEqual([]);
 await trigger.click();
 editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await expect(editor.locator('#plant-name')).toHaveValue('');
 await editor.locator('#plant-name').fill('Fictional dashboard plant');
 await editor.locator('#plant-strain-select').selectOption('strain-one');
 await expect(editor.locator('#plant-name')).toHaveValue('Fictional dashboard plant');
 if (planned) await editor.getByRole('switch').check();
 const refresh = page.waitForRequest(request => request.url().includes('/plant-dashboard') && saved);
 await editor.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 const request = await refresh;
 expect(new URL(request.url()).searchParams.get('status')).toBe(planned ? 'planned' : 'active');
 await expect(editor).toHaveCount(0); await expect(trigger).toBeFocused();
 await expect(page.getByRole('status').filter({ hasText: 'La plante a été créée.' })).toBeVisible();
 await expect(page.getByTestId('plant-home')).toBeVisible();
 expect(mutations).toHaveLength(1);
 expect(mutations[0]!.input).toMatchObject({ name: 'Fictional dashboard plant', strain_id: 'strain-one', location: { type: 'site', id: 'site-one' }, planned, device_ids: [] });
 expect(mutations[0]!.input.request_id).toBeTruthy();
 expect(requests.some(request => request === 'GET /api/v1/plants')).toBe(false);
 expect(errors).toEqual([]);
});

test('dashboard refresh times advance only after success and retain their last success on failure', async ({ page }) => {
 const initial = '2026-11-14T12:00:00.000Z'; const later = '2026-11-14T12:05:00.000Z';
 await page.clock.setFixedTime(new Date(initial));
 const { errors } = await session(page);
 await expect(page.locator('.metric-updated time')).toHaveCount(4);
 let fail = true; let hold: Promise<void> | null = null; let release: (() => void) | undefined;
 await page.route('**/api/v1/sites/*/plant-dashboard?**', async route => {
  if (new URL(route.request().url()).searchParams.get('metadata_only') !== '1') return route.fallback();
  if (hold) await hold;
  return fail ? route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } }) : route.fallback();
 });
 await page.reload();
 const home = page.getByTestId('plant-home'); const times = home.locator('.metric-updated time');
 await expect(page.getByRole('alert').first()).toBeVisible(); await expect(times).toHaveCount(0);
 await expect(home).toContainText('Pas encore actualisé.');
 fail = false;
 await home.getByRole('button', { name: 'Actualiser les plantes', exact: true }).click();
 await expect(times).toHaveCount(4); await expect(times.first()).toHaveAttribute('datetime', initial);
 const expectedTime = await page.evaluate(value => new Intl.DateTimeFormat('fr', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(value)), initial);
 await expect(times.first()).toHaveText(expectedTime);
 await page.clock.setFixedTime(new Date(later));
 hold = new Promise(resolve => { release = resolve; });
 await home.getByRole('button', { name: 'Actualiser les plantes', exact: true }).click();
 await expect(home.getByRole('button', { name: 'Actualiser les plantes', exact: true })).toBeDisabled();
 await expect(times.first()).toHaveAttribute('datetime', initial);
 fail = true; release!(); hold = null;
 await expect(page.getByRole('alert').first()).toBeVisible();
 await expect(times.first()).toHaveAttribute('datetime', initial);
 await expect(home.locator('.metric-updated').first()).toContainText('Échec');
 fail = false;
 await home.getByRole('button', { name: 'Actualiser les plantes', exact: true }).click();
 await expect(times.first()).toHaveAttribute('datetime', later);
 await expect(home.getByRole('button', { name: 'Actualiser les plantes', exact: true })).toBeEnabled();
 await expect(home.locator('.metric-updated').first()).not.toContainText('Échec');
 if (process.env.DASHBOARD_REFRESH_REVIEW) for (const width of [1280, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await home.locator('.home-metrics').screenshot({ path: test.info().outputPath(`canabicultarr-dashboard-refresh-${width}.png`), animations: 'disabled' });
 }
 expect(errors).toEqual([]);
});

test('dashboard pages room zones independently and preserves disclosures across bounded room pages', async ({ page }) => {
 const { errors } = await session(page);
 await page.route('**/api/v1/sites/*/plant-dashboard?**', async route => {
  const query = new URL(route.request().url()).searchParams;
  const rootPage = Math.min(Number(query.get('room_page') ?? 1), 2);
  const rooms: Record<string, unknown> = {}; const groups: Record<string, unknown> = {};
  for (let r = (rootPage - 1) * 10; r < Math.min(rootPage * 10, 12); r++) {
   const roomId = `room-${r}`; const current = Math.min(Number(query.get(`zone_pages[${roomId}]`) ?? 1), 2);
   rooms[roomId] = { current_page: current, last_page: 2, total: 12, per_page: 10 };
   for (let z = (current - 1) * 10; z < Math.min(current * 10, 12); z++) {
    const id = `zone-${r}-${z}`;
    groups[`zone:${id}`] = { location: { type: 'zone', id, site: { id: 'site-one', name: 'Fictional Site' }, room: { id: roomId, name: `Room ${String(r).padStart(2, '0')}` }, zone: { id, name: `Zone ${String(z).padStart(2, '0')}` } }, current_page: 1, last_page: 1, total: 1, per_page: 10 };
   }
  }
  await route.fulfill({ json: { data: [], groups, rooms, room_meta: { current_page: rootPage, last_page: 2, total: 12, per_page: 10 }, site: { id: 'site-one', name: 'Fictional Site' }, summary: { plants: 144, planned: 0, due: 0, strains: 1 }, filterOptions: { strains: [] }, meta: { current_page: 1, last_page: 1, total: 144, per_page: 10 } } });
 });
 await page.reload(); const home = page.getByTestId('plant-home');
 const rooms = home.locator('.home-room');
 await expect(rooms).toHaveCount(10);
 const first = rooms.filter({ has: page.getByRole('heading', { name: 'Room 00', exact: true }) });
 const second = rooms.filter({ has: page.getByRole('heading', { name: 'Room 01', exact: true }) });
 await expect(first.locator('.home-zone')).toHaveCount(10);
 const firstZone = first.locator('.home-zone').first(); await expect(firstZone).toHaveAttribute('open', '');
 await firstZone.locator(':scope > summary').click(); await expect(firstZone).not.toHaveAttribute('open');
 await first.getByRole('button', { name: 'Zones suivantes de Room 00', exact: true }).click();
 await expect(first.locator('.home-zone')).toHaveCount(2); await expect(first).toHaveAttribute('open', '');
 await expect(second.locator('.home-zone')).toHaveCount(10);
 await expect(second).not.toHaveAttribute('open');
 await second.getByRole('button', { name: 'Zones suivantes de Room 01', exact: true }).click();
 await expect(second.locator('.home-zone')).toHaveCount(2); await expect(second).not.toHaveAttribute('open');
 await first.getByRole('button', { name: 'Zones précédentes de Room 00', exact: true }).click();
 await expect(first.locator('.home-zone')).toHaveCount(10); await expect(first.locator('.home-zone').first()).not.toHaveAttribute('open');
 const pagination = home.getByRole('navigation', { name: 'Pièces', exact: true });
 await pagination.getByRole('button').last().click(); await expect(rooms).toHaveCount(2);
 await pagination.getByRole('button').first().click(); await expect(rooms).toHaveCount(10);
 await expect(second.locator('.home-zone')).toHaveCount(2);
 if (process.env.DASHBOARD_PAGES_REVIEW) for (const width of [1280, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await first.locator(':scope > summary').screenshot({ path: test.info().outputPath(`canabicultarr-dashboard-room-pages-${width}.png`), animations: 'disabled' });
 }
 expect(errors).toEqual([]);
});

test('dashboard metric tiles filter from their corners and support the keyboard', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 900 });
 const { errors, mutations } = await session(page);
 for (const [name, param, value, keyboard] of [
  ['Afficher les plantes à semer', 'status', 'planned', false],
  ['Afficher les plantes actives', 'status', 'active', true],
  ['Afficher les plantes avec des actions attendues', 'due_only', '1', false],
 ] as const) {
  const tile = page.getByRole('button', { name, exact: true });
  await expect(tile).toBeEnabled();
  const request = page.waitForRequest(row => row.url().includes('/plant-dashboard'));
  if (keyboard) { await tile.focus(); await page.keyboard.press('Enter'); }
  else await tile.click({ position: { x: 6, y: 6 } });
  expect(new URL((await request).url()).searchParams.get(param)).toBe(value);
  await expect(tile).toHaveAttribute('aria-pressed', 'true');
  await expect(tile).toBeEnabled();
 }
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('read-only dashboard does not offer plant creation', async ({ page }) => {
 const { errors, mutations } = await session(page, false);
 await expect(page.getByRole('button', { name: 'Ajouter une plante', exact: true })).toHaveCount(0);
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('strain detail creates a plant with its strain preselected and retains the record', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.route('**/api/v1/plants', async route => {
  if (route.request().method() !== 'POST') return route.fallback();
  const input = route.request().postDataJSON(); mutations.push({ path: '/api/v1/plants', input });
  return route.fulfill({ json: { data: { id: 'new-plant', name: input.name, phase: 'planned' } } });
 });
 await openModule(page, 'catalog');
 await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 const strain = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 const trigger = strain.getByRole('button', { name: 'Ajouter une plante', exact: true });
 await trigger.click();
 const editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await expect(editor.locator('#plant-strain-select')).toHaveValue('strain-one');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await expect(strain).toBeVisible(); await expect(trigger).toBeFocused();
 await trigger.click();
 await editor.locator('#plant-name').fill('Fictional strain plant');
 await editor.getByRole('switch').check();
 await expect(editor.locator('#plant-location-select')).toBeEnabled();
 await editor.locator('.location-tree-select').click();
 await page.getByRole('treeitem', { name: 'Fictional Site', exact: true }).getByText('Fictional Site', { exact: true }).click();
 await expect(editor.locator('#plant-strain-select')).toHaveValue('strain-one');
 await editor.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect(editor).toHaveCount(0); await expect(strain).toBeVisible(); await expect(trigger).toBeFocused();
 await expect(strain.page().getByRole('status').filter({ hasText: 'La plante a été créée.' }).first()).toContainText('La plante a été créée.');
 expect(mutations).toHaveLength(1); expect(mutations[0]!.input).toMatchObject({ strain_id: 'strain-one', location: { type: 'site', id: 'site-one' }, name: 'Fictional strain plant', planned: true });
 expect(errors).toEqual([]);
});

test('plant selectors search all strain pages and location descendants without nested dialogs', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.route('**/api/v1/strains?*', route => {
  const url = new URL(route.request().url()); const current = url.searchParams.get('search') ? 2 : Number(url.searchParams.get('page'));
  return route.fulfill({ json: { data: current === 1 ? [{ id: 'strain-one', name: 'Fictional Strain' }] : [{ id: 'strain-two', name: 'Second-page Strain' }], meta: { current_page: current, last_page: 2, total: 2, per_page: 1 } } });
 });
 await page.getByRole('button', { name: 'Ajouter une plante', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await editor.locator('#plant-name').fill('Fictional unsaved plant');
 const strainSelect = editor.locator('#plant-strain-select'); await expect(strainSelect).toBeEnabled(); await editor.locator('[data-ui-select="plant-strain-select"]').click();
 await page.locator('[data-select-popup] input').fill('second-page');
 await page.getByRole('listbox', { name: 'Variété' }).getByRole('option', { name: 'Second-page Strain', exact: true }).click();
 await expect(strainSelect).toHaveValue('strain-two');
 await editor.locator('.location-tree-select').click();
 const panel = page.locator('.n-tree-select-menu');
 await panel.locator('input').pressSequentially('Fictional Zone');
 await expect(page.getByRole('treeitem', { name: 'Fictional Zone', exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expect(panel).toHaveCount(0); await expect(editor).toBeVisible();
 await editor.locator('.location-tree-select').click();
 await panel.locator('input').pressSequentially('Fictional Zone');
 await expect(page.getByRole('treeitem', { name: 'Fictional Zone', exact: true })).toHaveAttribute('aria-disabled', 'false');
 await page.getByRole('treeitem', { name: 'Fictional Zone', exact: true }).getByText('Fictional Zone', { exact: true }).click();
 await expect(editor.getByTestId('plant-location')).toHaveText('Fictional Site / Fictional Room / Fictional Zone');
 await expect(editor.locator('#plant-name')).toHaveValue('Fictional unsaved plant');
 await expect(page.locator('.module-dialog')).toHaveCount(0);
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('plant editing preserves strain identity and uses styled hierarchical location choices on mobile', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByTestId('plant-card-plant-one').locator('.plant-edit').click();
 const editor = page.getByRole('dialog', { name: 'Modifier la plante', exact: true });
 await expect(editor.locator('#plant-strain-select')).toHaveValue('strain-one');
 await expect(editor.locator('#plant-strain-select')).toBeEnabled();
 const location = editor.getByTestId('plant-location');
 await expect(location).toHaveText('Fictional Site / Fictional Room / Fictional Zone');
 await location.click();
 await editor.getByRole('treeitem', { name: 'Fictional Room', exact: true }).getByText('Fictional Room', { exact: true }).click();
 await editor.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect(editor).toHaveCount(0);
 expect(mutations).toHaveLength(1); expect(mutations[0]!.input.location).toEqual({ type: 'room', id: 'room-one' });
 expect(mutations[0]!.input).not.toHaveProperty('strain_id'); expect(errors).toEqual([]);
});

for (const state of [
 { day: null, week: null, phase: 'planned', label: 'À semer' },
 { day: null, week: null, phase: 'growth', label: null },
 { day: 0, week: 1, phase: 'growth', label: 'Semaine 1 · Jour 0' },
 { day: 2, week: null, phase: 'growth', label: 'Jour 2' },
]) test(`plant photo age preserves ${state.label ?? 'no age badge for missing dates'} without a photo`, async ({ page }) => {
 const { errors } = await session(page, true, false, state);
 const card = page.getByTestId('plant-card-plant-one');
 await expect(card.locator('.photo-age')).toHaveCount(state.label === null ? 0 : 1);
 await expect(card).not.toContainText('Date de début inconnue');
 await card.locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 if (state.label === null) await expect(detail.getByTestId('plant-age-badge')).toHaveCount(0);
 else await expect(detail.getByTestId('plant-age-badge')).toHaveText(state.label);
 await expect(detail).not.toContainText('Date de début inconnue');
 await expect(detail.locator('.gallery-empty')).toBeVisible();
 expect(errors).toEqual([]);
});


test('dashboard icon-only actions open maintenance and editing for the selected plant', async ({ page }) => {
 const { errors, mutations, requests } = await session(page);
 const card = page.getByTestId('plant-card-plant-one');
 const buttons = card.locator('.card-actions button');
 for (const button of await buttons.all()) { await expect(button).toHaveAttribute('title', /.+/); await expect(button).toHaveAccessibleName(/.+/); }
 await card.getByRole('button', { name: 'Entretien — Fictional Plant', exact: true }).click();
 const maintenance = page.getByRole('dialog', { name: 'Entretien — Fictional Plant', exact: true });
 await expect(maintenance).toBeVisible();
 await expect(page.getByRole('dialog')).toHaveCount(1);
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(maintenance).toHaveCount(0);
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(card.locator('.plant-maintenance')).toBeFocused();
 await card.getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 const edit = page.getByRole('dialog', { name: 'Modifier la plante', exact: true });
 await expect(edit).toBeVisible(); await expect(page.locator('#plant-name')).toHaveValue('Fictional Plant');
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click(); await page.locator('#plant-notes').fill('Edited from dashboard icon');
 await edit.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect(edit).toHaveCount(0);
 await expect(page.getByRole('dialog')).toHaveCount(0);
 expect(requests.some(request => request.includes('/events'))).toBe(false);
 expect(requests).toContain('PATCH /api/v1/plants/plant-one'); await expect(card.locator('.plant-edit')).toBeFocused();
 expect(mutations).toHaveLength(1); expect(mutations[0]?.path).toBe('/api/v1/plants/plant-one'); expect(errors).toEqual([]);
 await page.setViewportSize({ width: 390, height: 844 });
});

for (const state of [
 { phase: 'planned', nextAction: 'sow', action: 'Démarrer le semis' },
 { phase: 'germination', nextAction: 'repot_growth', action: 'Rempoter avant la croissance' },
 { phase: 'growth', nextAction: 'repot_flowering', action: 'Rempoter avant la floraison' },
]) test(`sow and repot shortcuts confirm ${state.nextAction} without opening plant details`, async ({ page }) => {
 const { errors, mutations, requests } = await session(page, true, false, state);
 for (const location of ['home', 'plants'] as const) {
  await openModule(page, location);
  const card = page.getByTestId(location === 'home' ? 'plant-card-plant-one' : 'plant-row-plant-one');
  const trigger = card.getByRole('button', { name: `${state.action} — Fictional Plant`, exact: true });
  await trigger.click();
  const form = page.getByRole('dialog', { name: `Enregistrer : ${state.action}`, exact: true });
  await expect(form).toBeVisible(); await expect(page.getByRole('dialog')).toHaveCount(1);
  await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
  await form.getByRole('button', { name: 'Enregistrer l’action', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  expect(mutations.at(-1)?.input.action).toBe(state.nextAction);
 }
 expect(requests.some(request => request.includes('/events'))).toBe(false);
 expect(errors).toEqual([]);
});


test('searchable select preserves the maintenance dialog and Escape closes only its dropdown', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: /entretien/i }).click();
 const dialog = page.getByRole('dialog', { name: 'Entretien — Fictional Plant', exact: true });
 await expect(dialog).toBeVisible();
 const select = dialog.locator('#plant-maintenance-type');
 await expect(select.locator('option[value=other]')).toHaveCount(0);
 await expect(select.locator('option[value=topping]')).toHaveCount(1);
 await expect(select.locator('option[value=pruning]')).toHaveCount(1);
 await dialog.locator('[data-ui-select="plant-maintenance-type"]').click();
 const popup = page.locator('[data-select-popup]');
 const filter = popup.getByRole('textbox');
 await expect(filter).toBeFocused();
 await dialog.locator('[data-ui-select="plant-maintenance-type"]').click();
 await expect(popup).toHaveCount(0); await expect(select).toHaveValue('');
 await dialog.locator('[data-ui-select="plant-maintenance-type"]').click();
 await expect(filter).toBeFocused();
 await filter.fill('ETET');
 await expect(popup.getByRole('option')).toHaveCount(1);
 await filter.press('Escape');
 await expect(popup).toHaveCount(0); await expect(dialog).toBeVisible(); await expect(dialog.locator('[data-ui-select="plant-maintenance-type"] .n-base-selection-label')).toBeFocused();
 await dialog.locator('[data-ui-select="plant-maintenance-type"]').click(); await popup.getByRole('textbox').fill('taill');
 await popup.getByRole('option').click(); await expect(select).toHaveValue('pruning');
 await expect(dialog).toBeVisible();
 await dialog.locator('[data-ui-select="plant-maintenance-type"]').click(); await popup.getByRole('textbox').press('Escape'); await dialog.locator('[data-ui-select="plant-maintenance-type"] .n-base-selection-label').press('Escape');
 const discard = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(dialog).toHaveCount(0);
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});


test('masonry preserves sorted card order and packs variable heights on mobile and desktop', async ({ page }) => {
 const { errors, mutations } = await session(page, true, false, {}, 12);
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  await openModule(page, 'plants');
  const grid = page.locator('.photo-card-grid').first();
  await expect(grid.locator('.photo-plant')).toHaveCount(12);
  await expect(grid).toHaveClass(/masonry-ready/);
  await expect.poll(() => grid.locator(':scope > li').first().evaluate(card => card.style.position)).toBe('absolute');
  await expect.poll(() => grid.locator('.photo-plant').evaluateAll(cards => new Set(cards.map(card => Math.round(card.getBoundingClientRect().left))).size)).toBeGreaterThanOrEqual(width === 390 ? 1 : 2);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
  if (width === 390) await expect.poll(() => grid.locator('.photo-plant').evaluateAll(cards => new Set(cards.map(card => Math.round(card.getBoundingClientRect().left))).size)).toBe(1);
   await expect.poll(() => grid.evaluate(element => {
    const gap = parseFloat(getComputedStyle(element).rowGap);
    const columns = new Map<number, DOMRect[]>();
    for (const card of element.querySelectorAll('.photo-plant')) { const r = card.getBoundingClientRect(); const key = Math.round(r.left); columns.set(key, [...columns.get(key) ?? [], r]); }
    return Math.max(0, ...Array.from(columns.values()).flatMap(column => column.slice(1).map((r, i) => Math.abs(r.top - column[i]!.bottom - gap))));
   })).toBeLessThan(2);
  const layout = await grid.evaluate(element => ({
   cards: Array.from(element.querySelectorAll('.photo-plant'), card => {
    const rect = card.getBoundingClientRect(); return { left: rect.left, top: rect.top, bottom: rect.bottom };
   }),
   overflow: document.documentElement.scrollWidth > innerWidth,
  }));
  expect(layout.overflow).toBe(false);
  if (width === 390) expect(new Set(layout.cards.map(card => Math.round(card.left))).size).toBe(1);
  else expect(layout.cards[1]!.left).toBeGreaterThan(layout.cards[0]!.left);
  for (let index = 1; index < layout.cards.length; index++) {
   const previous = layout.cards[index - 1]!; const current = layout.cards[index]!;
   expect(current.top).toBeGreaterThanOrEqual(previous.top - 1);
  }
 }
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});


test('notes preserve Markdown through source and visual modes and render in plant records', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click();
 const editor = page.locator('[data-notes-id="plant-notes"]');
 await expect(editor.locator('.ql-editor')).toHaveText('Fictional plant notes');
 await editor.locator('.notes-mode-markdown').click();
 const source = '## Observation\n\n**Healthy** and _green_\n\n- First leaf\n- Second leaf\n\n[Reference](https://example.test/)';
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click(); await page.locator('#plant-notes').fill(source);
 await editor.locator('.notes-mode-visual').click();
 await expect(editor.locator('.ql-editor h2')).toHaveText('Observation');
 await expect(editor.locator('.ql-editor strong')).toHaveText('Healthy');
 await editor.locator('.notes-mode-markdown').click();
 await expect(page.locator('#plant-notes')).toHaveValue(source);
 await page.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.notes).toBe(source);
 await expect(detail.locator('.plant-notes h2')).toHaveText('Observation');
 await expect(detail.locator('.plant-notes strong')).toHaveText('Healthy');
 await expect(detail.locator('.plant-notes li')).toHaveCount(2);
 await expect(detail.locator('.plant-notes a')).toHaveAttribute('href', 'https://example.test/');
 expect(errors).toEqual([]);
});

test('visual notes save Markdown formatting, reject oversized input and fit dark mobile dialogs', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.getByTestId('plant-card-plant-one').locator('.plant-edit').click();
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click();
 const editor = page.locator('[data-notes-id="plant-notes"]');
 const input = page.locator('#plant-notes'); await input.fill('Healthy');
 await input.press('ControlOrMeta+a'); await editor.locator('.ql-bold').click();
 await expect(editor.locator('.ql-editor strong')).toHaveText('Healthy');
 await editor.locator('.notes-mode-markdown').click();
 await expect(input).toHaveValue('**Healthy**');
 await editor.locator('.notes-mode-visual').click();
 await input.fill('x'.repeat(2001));
 await expect(page.getByRole('alert').first()).toBeVisible();
 await expect(input).toHaveText('Healthy');
 await expect(editor.locator('.notes-count')).toHaveText('11 / 2000');
 await page.setViewportSize({ width: 390, height: 844 });
 await page.evaluate(() => document.documentElement.classList.add('app-dark'));
 await editor.scrollIntoViewIfNeeded();
 expect(await editor.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 // Regression: the editable area must inherit the dark content surface.
 expect(await editor.locator('.ql-editor').evaluate(el => getComputedStyle(el).backgroundColor === getComputedStyle(el.parentElement!).backgroundColor)).toBe(true);
 await page.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.notes).toBe('**Healthy**'); expect(errors).toEqual([]);
});

test('advanced Markdown remains editable without losing tables and unsafe HTML stays inert', async ({ page }) => {
 const notes = '| Trait | Value |\n| --- | --- |\n| Color | **Green** |\n\n<script>window.notesInjected = true</script>\n\n[Unsafe](javascript:alert(1))\n\n![Photo](https://example.test/remote.png)';
 const { errors } = await session(page, true, false, { notes });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail.locator('.plant-notes td strong')).toHaveText('Green');
 await expect(detail.locator('.plant-notes script, .plant-notes img, .plant-notes a[href]')).toHaveCount(0);
 expect(await page.evaluate(() => 'notesInjected' in window)).toBe(false);
 await detail.getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click();
 const editor = page.locator('[data-notes-id="plant-notes"]');
 await expect(editor.locator('.notes-mode-visual')).toBeDisabled();
 await expect(page.locator('#plant-notes')).toHaveValue(notes);
 await expect(page.locator('#plant-notes')).toHaveAttribute('maxlength', '2000');
 await editor.locator('summary').click(); await expect(editor.locator('.notes-preview table')).toBeVisible();
 expect(errors).toEqual([]);
});


test('strain records and plant recaps render formatted strain notes', async ({ page }) => {
 const { errors } = await session(page);
 await page.route('**/api/v1/strains/strain-one', async route => {
  if (route.request().method() !== 'GET') return route.fallback();
  return route.fulfill({ json: { data: { id: 'strain-one', name: 'Fictional Strain', classification: 'hybrid', sourceUrl: 'https://example.test/strain', notes: '## Observations\n\n**Fictional strain note**', durationOverrides: {}, version: 1, canManage: true, isArchived: false, seedStock: null } } });
 });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.locator('.plant-strain-details > summary').click();
 await expect(detail.locator('.strain-summary').getByRole('link', { name: 'Lien de référence', exact: true })).toHaveAttribute('href', 'https://example.test/strain');
 await detail.locator('.strain-summary details summary').click();
 await expect(detail.locator('.strain-notes h2')).toHaveText('Observations');
 await expect(detail.locator('.strain-notes strong')).toHaveText('Fictional strain note');
 await page.keyboard.press('Escape'); await openModule(page, 'catalog');
 await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 const strain = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(strain.locator('.record-note h2')).toHaveText('Observations');
 await expect(strain.locator('.record-note strong')).toHaveText('Fictional strain note');
 expect(errors).toEqual([]);
});


test('visual lists and code blocks retain spaces and Markdown formatting', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.getByTestId('plant-card-plant-one').locator('.plant-edit').click();
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click();
 const editor = page.locator('[data-notes-id="plant-notes"]'); const input = page.locator('#plant-notes');
 await input.fill('First observation'); await input.press('ControlOrMeta+a'); await editor.locator('.ql-list[value="bullet"]').click();
 await editor.locator('.notes-mode-markdown').click();
 await expect(input).toHaveValue(/^- +First observation$/);
 await editor.locator('.notes-mode-visual').click();
 await input.press('ControlOrMeta+a'); await editor.locator('.ql-code-block').click();
 await editor.locator('.notes-mode-markdown').click();
 await expect(input).toHaveValue('```\nFirst observation\n```');
 await page.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.notes).toBe('```\nFirst observation\n```');
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).locator('.plant-notes pre code')).toHaveText('First observation');
 expect(errors).toEqual([]);
});

test('strain catalog retains theoretical yield sorting and shows weeks and reference yield', async ({ page }) => {
 const { mutations, errors } = await session(page);
 await openModule(page, 'catalog');
 const sort = page.locator('#strain-sort');
 await expect(sort).toBeEnabled();
 const request = page.waitForRequest(request => new URL(request.url()).searchParams.get('sort') === 'yield_desc');
 await sort.selectOption('yield_desc');
 await request;
 const card = page.getByTestId('strain-row-strain-one');
 await expect(card).toContainText('10 semaines');
 await expect(card).toContainText('550,5 g/m²');
 await card.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(detail.locator('.record-metrics')).toContainText('Rendement théorique');
 await expect(detail.locator('.record-metrics')).toContainText('550,5 g/m²');
 await detail.locator('details summary').click();
 await expect(detail.locator('.record-duration-grid')).toContainText('10 semaines');
 await detail.getByRole('button', { name: 'Modifier Fictional Strain', exact: true }).click();
 await expect(page.locator('#strain-yield')).toHaveValue('550.50');
 await page.locator('#strain-yield').fill('650.25');
 await page.getByRole('button', { name: /^(?:Enregistrer la variété|Créer)$/, exact: true }).click();
 await expect(detail.locator('.record-metrics')).toContainText('650,25 g/m²');
 expect(mutations.at(-1)?.input.theoretical_yield).toBe('650.25');
 expect(errors).toEqual([]);
});

test('custom lifecycle actions appear in the plant record and submit through the existing action dialog', async ({ page }) => {
 const stages = structuredClone(lifecycleStages);
 stages.find(stage => stage.id === 'growth')!.actions.push({ id: 'inspection', name: 'Fictional inspection', icon: 'camera', target: 'growth', requires_harvest_weight: false });
 const { errors, mutations } = await session(page, true, false, {}, 1, stages);
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const record = page.getByRole('dialog');
 await record.getByRole('button', { name: /Fictional inspection/ }).click();
 const action = page.getByRole('dialog'); await expect(action.getByTestId('lifecycle-form')).toBeVisible();
 await action.getByRole('button', { name: 'Enregistrer l’action', exact: true }).click();
 await expect.poll(() => mutations.filter(row => row.path.endsWith('/actions')).length).toBe(1);
 expect(mutations.find(row => row.path.endsWith('/actions'))?.input.action).toBe('inspection');
 expect(errors).toEqual([]);
});

test('newly assigned channels stay visible on cards and records before their first measurement', async ({ page }) => {
 const missing: PlantMeasurement = { sensorId: 'moisture', sensorName: 'Waiting moisture', device: { id: 'probe-one', name: 'Fictional probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture', decimalPlaces: 1 }, value: null, unit: '%', observedAt: null, isEnabled: true, isStale: false };
 const { errors, mutations } = await session(page, true, false, { measurements: [missing, { ...missing, sensorId: 'temperature', sensorName: 'Waiting temperature' }], summarySensorIds: ['moisture'] });
 const card = page.getByTestId('plant-card-plant-one');
 await expect(card.locator('.plant-reading')).toHaveCount(1);
 await expect(card.locator('.plant-reading')).toContainText('Waiting moisture');
 await expect(card.locator('.plant-reading')).toContainText('Aucune mesure');
 await expect(card.locator('.reading-number')).toHaveText('—');
 await expect(card.locator('time')).toHaveCount(0);
 await card.locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail.locator('.plant-reading')).toHaveCount(2);
 await expect(detail.locator('.plant-reading').filter({ hasText: 'Waiting temperature' })).toContainText('Aucune mesure');
 await expect(detail.locator('.reading-number')).toHaveText(['—', '—']);
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('linked readings appear on plant cards and details while strain information stays grouped', async ({ page }) => {
 const base = { device: { id: 'probe-one', name: 'Fictional probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture' as const, decimalPlaces: 1 }, value: '32.400000000', unit: '%', observedAt: '2026-11-09T12:00:00Z', isEnabled: true, isStale: false };
 const measurements: PlantMeasurement[] = [
  { ...base, sensorId: 'moisture', sensorName: 'Pot moisture' },
  { ...base, sensorId: 'temperature', sensorName: 'Temperature', measurementType: { name: 'Temperature', nature: 'temperature', decimalPlaces: 1 }, value: '0.000000000', unit: '°C' },
  { ...base, sensorId: 'old', sensorName: 'Old reading', isStale: true },
  { ...base, sensorId: 'empty', sensorName: 'Empty channel', value: null, observedAt: null },
  { ...base, sensorId: 'paused', sensorName: 'Paused channel', isEnabled: false },
  { ...base, sensorId: 'additional', sensorName: 'Additional channel', device: { id: 'probe-two', name: 'Secondary probe' } },
 ];
 const { errors, mutations } = await session(page, true, false, { measurements, devices: [{ id: 'probe-one', name: 'Fictional probe', status: 'active' }, { id: 'probe-two', name: 'Secondary probe', status: 'active' }] });
 const card = page.getByTestId('plant-card-plant-one');
 await expect(card.locator('.plant-reading')).toHaveCount(4); await expect(card.locator('.plant-readings')).toContainText('32,4');
 await expect(card.locator('.plant-readings')).toContainText('0°C'); await expect(card.locator('.plant-readings')).toContainText('Empty channel');
 await expect(card.locator('.plant-reading').filter({ hasText: 'Empty channel' })).toContainText('Aucune mesure');
 await card.getByRole('button', { name: '+2 autres canaux', exact: true }).click();
 await expect(card.locator('.plant-reading')).toHaveCount(6); await expect(page.getByRole('dialog')).toHaveCount(0);
 await card.getByRole('button', { name: 'Réduire', exact: true }).click(); await expect(card.locator('.plant-reading')).toHaveCount(4);
 await card.locator('.readings-heading').click();
 await expect(card.locator('.plant-reading').first()).not.toBeVisible(); await expect(page.getByRole('dialog')).toHaveCount(0);
 await card.locator('.readings-heading').click(); await expect(card.locator('.plant-reading').first()).toBeVisible();
 await card.locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail.locator('.plant-reading')).toHaveCount(6); await expect(detail.locator('.plant-readings')).toContainText('En pause');
 await expect(detail.locator('.reading-date').first()).toBeVisible();
 const strain = detail.locator('.plant-strain-details'); await expect(strain).not.toHaveAttribute('open'); await strain.locator(':scope > summary').click();
 await expect(strain.locator('.strain-summary')).toContainText('Fictional lineage'); await expect(strain.locator('.record-duration-grid')).toBeVisible();
 await expect(strain.locator('.record-duration-grid dt')).toHaveText(['Semis', 'Germination', 'Croissance', 'Floraison', 'Séchage suspendu', 'Séchage filets', 'Curage', 'Nettoyage']);
 await detail.getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click(); await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Notes', exact: true }).click(); await page.locator('#plant-notes').fill('Edited with linked readings');
 await page.getByRole('dialog', { name: 'Modifier la plante', exact: true }).getByRole('tab', { name: 'Matériels', exact: true }).click();
 const channels = page.locator('.summary-channel-selection');
 const probe = channels.getByRole('region', { name: 'Fictional probe', exact: true });
 await expect(probe.getByRole('listitem').filter({ hasText: 'Temperature' })).toContainText('Temperature · °C');
 await expect(probe.getByRole('listitem').filter({ hasText: 'Old reading' })).toContainText('Mesure ancienne');
 await expect(probe.getByRole('listitem').filter({ hasText: 'Empty channel' })).toContainText('Aucune mesure');
 await expect(probe.getByRole('listitem').filter({ hasText: 'Paused channel' })).toContainText('En pause');
 await channels.getByRole('switch', { name: /Sélection automatique/ }).uncheck();
 await expect(channels.getByRole('status')).toHaveText('4 canaux sélectionnés');
 for (const name of ['Temperature', 'Old reading', 'Empty channel']) await probe.getByRole('switch', { name, exact: true }).uncheck();
 await probe.getByRole('switch', { name: 'Paused channel', exact: true }).check();
 const filter = channels.getByRole('textbox', { name: 'Rechercher un canal ou un matériel', exact: true });
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => channels.evaluate(node => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
 await filter.fill('Secondary');
 await expect(probe).toHaveCount(0);
 await expect(channels.getByRole('region', { name: 'Secondary probe', exact: true }).getByRole('switch', { name: 'Additional channel', exact: true })).not.toBeChecked();
 await expect(channels.getByRole('status')).toHaveText('2 canaux sélectionnés');
 await filter.clear();
 await page.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect(page.getByRole('status').filter({ hasText: 'La plante a été modifiée.' }).first()).toBeVisible(); await expect(detail.locator('.plant-reading')).toHaveCount(6);
 expect(mutations[0]?.input).toMatchObject({ device_ids: ['probe-one', 'probe-two'], notes: 'Edited with linked readings', summary_sensor_ids: ['moisture', 'paused'] });
 await expect(card.locator('.plant-reading')).toHaveCount(2);
 await expect(card.locator('.plant-readings')).toContainText('Paused channel');
 await expect(card.locator('.plant-readings')).not.toContainText('Temperature');
 await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => detail.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 expect(errors).toEqual([]);
});

for (const mobile of [false, true]) test(`comment template select appends expanded values and validates missing fields on ${mobile ? 'mobile' : 'desktop'}`, async ({ page }) => {
 const { errors, mutations } = await session(page);
 let unavailable = true;
 const requested: number[] = [];
 await page.route('**/api/v1/comment-templates?*', route => {
  if (unavailable) return route.fulfill({ status: 500, json: { error: { code: 'auth.errors.requestFailed' } } });
  const criteria = new URL(route.request().url()).searchParams;
  const current = Number(criteria.get('page'));
  requested.push(current);
  if (criteria.get('search')) return route.fulfill({ json: { data: [{ id: 'watering', name: 'Watering details', body: '{{ plant.name }} / {{ plant.strain.aroma }} / {{ date.today }}', isArchived: false }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
  const data = current === 1
   ? [{ id: 'missing', name: 'Missing field', body: '{{ plant.nonexistent }}', isArchived: false }]
   : [{ id: 'watering', name: 'Watering details', body: '{{ plant.name }} / {{ plant.strain.aroma }} / {{ date.today }}', isArchived: false }, { id: 'archived', name: 'Archived template', body: 'Archived', isArchived: true }];
  return route.fulfill({ json: { data, meta: { current_page: current, last_page: 2, total: 3, per_page: 2 }, canManage: true } });
 });
 if (mobile) await page.setViewportSize({ width: 390, height: 844 });
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: /^Enregistrer un arrosage pour/ }).click();
 const dialog = page.getByTestId('watering-form');
 const comment = dialog.locator('#plant-watering-comment');
 const templates = dialog.locator('#plant-watering-comment-template');
 await (await revealTabbedControl(comment)).fill('Existing draft');
 await expect(dialog.page().getByRole('alert').filter({ hasText: 'La demande n’a pas pu aboutir.' }).first()).toContainText('La demande n’a pas pu aboutir.'); await expect(templates).toHaveCount(0); unavailable = false;
 await dialog.getByRole('button', { name: 'Recharger la liste', exact: true }).click();
 await expect(templates).toBeEnabled(); expect(requested).toEqual([1]);
 await expect(templates).toHaveAttribute('data-select-mode', 'custom');
 await dialog.getByRole('combobox', { name: 'Choisir un modèle de commentaire', exact: true }).click();
 const loadMore = page.getByRole('button', { name: 'Afficher plus', exact: true });
 await loadMore.focus();
 await loadMore.click();
 await expect(page.locator('[data-select-popup]')).toBeVisible();
 await page.keyboard.press('Escape');
 await expect(page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true })).toHaveCount(0);
 await expect(templates.locator('option[value=watering]')).toBeAttached(); expect(requested).toEqual([1, 2]);
 await expect(templates.locator('option[value="archived"]')).toHaveCount(0);

 await templates.selectOption('missing');
 const insert = dialog.getByRole('button', { name: 'Utiliser le modèle', exact: true });
 await insert.click(); await expect(dialog.page().getByRole('alert').filter({ hasText: 'plant.nonexistent' }).first()).toContainText('plant.nonexistent');
 await expect(comment).toHaveValue('Existing draft');
 await templates.selectOption('watering');
 await expect(dialog.locator('.template-preview')).toContainText('Fictional Plant / Fictional aroma /');
 if (process.env.PLAYWRIGHT_SELECT_SCREENSHOTS) await page.screenshot({ path: test.info().outputPath(`canabicultarr-template-select-${mobile ? 'mobile' : 'desktop'}.png`), fullPage: true, animations: 'disabled' });
 await insert.click(); await expect(comment).toHaveValue(/^Existing draft\nFictional Plant \/ Fictional aroma \/ /);
 await expect(templates).toHaveValue('');
 await comment.fill('x'.repeat(1999)); await templates.selectOption('watering'); await insert.click();
 await expect(dialog.page().getByRole('alert').filter({ hasText: '2 000' }).first()).toContainText('2 000'); await expect(comment).toHaveValue('x'.repeat(1999));
 await comment.fill('Final draft'); await insert.click();
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without');
 await page.locator('.form-dialog').getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }).click();
 await expect(dialog).toHaveCount(0);
 expect(mutations[0]?.input).toMatchObject({ with_products: false, comment: expect.stringMatching(/^Final draft\nFictional Plant \/ Fictional aroma \/ /) });
 expect(errors).toEqual([]);
});

test('comment variables use live action drafts, paginated history and photos with a searchable reference', async ({ page }) => {
 const { errors, mutations } = await session(page);
 const pages: number[] = []; let photoReads = 0; let historyUnavailable = true;
 await page.route('**/api/v1/comment-templates?*', route => route.fulfill({ json: { data: [
  { id: 'simple', name: 'Simple action', body: '{{ plant.name }} / {{ action.withProducts }}', isArchived: false },
  { id: 'full', name: 'Full context', body: '{{ action.name }} / {{ action.withProducts }} / {{ action.occurredAt }} / {{ plant.strain.seedStock.quantity }} / {{ plant.events.1.comment }} / {{ plant.events.length }} / {{ plant.photos.0.name }} / {{ plant.photos.length }}', isArchived: false },
 ], meta: { current_page: 1, last_page: 1, total: 2, per_page: 20 } } }));
 await page.route('**/api/v1/plants/plant-one/events?*', route => {
  const current = Number(new URL(route.request().url()).searchParams.get('page')); pages.push(current);
  if (historyUnavailable) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  return route.fulfill({ json: { data: [{ id: `event-${current}`, comment: current === 1 ? 'Newest comment' : 'Older comment', attachments: [], snapshot: {} }], meta: { current_page: current, last_page: 2, total: 2, per_page: 1 } } });
 });
 await page.route('**/api/v1/plants/plant-one/photos?*', route => { photoReads++; return route.fulfill({ json: { data: [{ id: 'photo', name: 'Real gallery photo', url: '/photo' }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }); });
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: /^Enregistrer un arrosage pour/ }).click();
 const dialog = page.getByTestId('watering-form'); const select = dialog.locator('#plant-watering-comment-template');
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('with'); await (await revealTabbedControl(select)).selectOption('simple');
 await expect(dialog.locator('.template-preview')).toContainText('Fictional Plant / true'); expect(pages).toEqual([]); expect(photoReads).toBe(0);
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without'); await revealTabbedControl(select); await expect(dialog.locator('.template-preview')).toContainText('Fictional Plant / false');
 await (await revealTabbedControl(dialog.locator('#plant-watering-date'))).fill('2026-11-09T10:30'); await (await revealTabbedControl(select)).selectOption('full');
 await expect(dialog.getByRole('button', { name: 'Recharger les données', exact: true })).toBeVisible();
 await dialog.getByRole('button', { name: 'Utiliser le modèle', exact: true }).click(); await expect(dialog.page().getByRole('alert').filter({ hasText: 'plant.events' }).first()).toContainText('plant.events'); await expect(dialog.locator('#plant-watering-comment')).toHaveValue('');
 historyUnavailable = false; await dialog.getByRole('button', { name: 'Recharger les données', exact: true }).click();
 await expect(dialog.locator('.template-preview')).toContainText(/false.*12.*Older comment.*2.*Real gallery photo.*1/); expect(pages).toEqual([1, 1, 2]); expect(photoReads).toBe(1);
 const reference = dialog.locator('.variable-reference'); await reference.locator('summary').click();
 await reference.getByLabel('Catégorie', { exact: true }).and(reference.locator('select')).selectOption('action'); await reference.getByLabel('Rechercher une variable', { exact: true }).fill('withProducts');
 await expect(reference.getByRole('button', { name: 'Insérer action.withProducts', exact: true })).toBeVisible(); await expect(reference).toContainText('Valeur actuelle: false');
 await dialog.getByRole('button', { name: 'Utiliser le modèle', exact: true }).click();
 await expect(dialog.locator('#plant-watering-comment')).toHaveValue(/Arrosage \/ false \/ .* \/ 12 \/ Older comment \/ 2 \/ Real gallery photo \/ 1/);
 await (await revealTabbedControl(select)).selectOption('full'); await expect(dialog.locator('.template-preview')).toContainText('Older comment'); expect(pages).toEqual([1,1,2]); expect(photoReads).toBe(1); expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

test('masonry cards preserve a readable minimum width and fit narrow containers', async ({ page }) => {
 const { errors, mutations } = await session(page);
 for (const module of ['plants', 'catalog']) {
  await openModule(page, module);
  const grid = page.locator('.photo-card-grid'); await expect(grid.locator('.photo-plant')).toBeVisible();
  for (const width of [1280, 1024, 768, 390, 320]) {
   await page.setViewportSize({ width, height: 844 });
   await expect.poll(() => grid.evaluate(element => {
    const outer = element.getBoundingClientRect(); const card = element.firstElementChild!.getBoundingClientRect();
    const minimum = 20 * parseFloat(getComputedStyle(document.documentElement).fontSize);
    return card.width >= Math.min(minimum, outer.width) - 1 && card.right <= outer.right + 1 && card.left >= outer.left - 1;
   }), { message: `${module} cards at ${width}px` }).toBe(true);
  }
 }
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});


test('custom maintenance labels save with the entry and can be reused after reopening on mobile', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.route('**/api/v1/plants/maintenance-types', route => route.fulfill({ json: { data: mutations.filter(row => row.path.endsWith('/maintenance')).map(row => row.input.type) } }));
 await page.route('**/api/v1/plants/plant-one/events?*', route => route.fulfill({ json: { data: mutations.filter(row => row.path.endsWith('/maintenance')).map((row, index) => ({ id: `custom-${index}`, kind: 'maintained', fromPhase: 'growth', toPhase: 'growth', comment: row.input.comment, actorId: 'reader', occurredAt: '2026-11-09T12:00:00Z', recordedAt: '2026-11-09T12:00:00Z', maintenance: { type: row.input.type }, watering: null, attachments: [], snapshot: { name: 'Fictional Plant', strain_id: 'strain-one', location: null, previous_location: null }, fields: [] })), meta: { current_page: 1, last_page: 1, total: mutations.length, per_page: 20 } } }));
 await openModule(page, 'plants'); await page.locator('.photo-plant .open-plant').first().click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.getByRole('button', { name: 'Enregistrer un entretien', exact: true }).click();
 const dialog = page.locator('.form-dialog');
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-type'))).selectOption('__new__');
 const submit = dialog.locator('.n-card__footer button[type=submit]'); await expect(submit).toBeDisabled();
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-custom-type'))).fill('   '); await expect(submit).toBeDisabled();
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-custom-type'))).fill('Leaf inspection');
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-comment'))).fill('Fictional observation'); await submit.click();
 await expect(dialog).toHaveCount(0); expect(mutations[0]?.input).toMatchObject({ type: 'Leaf inspection', comment: 'Fictional observation' });
 await expect(detail.locator('.timeline-tags')).toContainText('Leaf inspection');
 await page.setViewportSize({ width: 390, height: 844 });
 await detail.getByRole('button', { name: 'Enregistrer un entretien', exact: true }).click();
 await expect(dialog.locator('#plant-maintenance-type option', { hasText: 'Leaf inspection' })).toHaveCount(1);
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-type'))).selectOption({ label: 'Leaf inspection' });
 await expect(dialog.locator('#plant-maintenance-custom-type')).toHaveCount(0); await submit.click();
 await expect(dialog).toHaveCount(0); expect(mutations[1]?.input).toMatchObject({ type: 'Leaf inspection', comment: null });
 expect(errors).toEqual([]);
});


test('plant reading opens channel history without opening the plant and also works inside plant details', async ({ page }) => {
 const { errors } = await session(page, false, false, { measurements: [{ sensorId: 'sensor-0', sensorName: 'Moisture one', device: { id: 'device-0', name: 'Fictional probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture', decimalPlaces: 1 }, value: '44', unit: '%', observedAt: '2026-11-09T11:00:00Z', isEnabled: true, isStale: false }] });
 await page.route('**/api/v1/**', async route => {
  const url = new URL(route.request().url());
  if (url.pathname.includes('/reports/sensors/')) return route.fulfill({ json: { data: insightReport(url) } });
  if (url.pathname.endsWith('/sensors')) return route.fulfill({ json: { data: insightSensors, meta: insightMeta } });
  if (url.pathname.endsWith('/sensors/sensor-0/measurements')) return route.fulfill({ json: { data: [], meta: { ...insightMeta, total: 0 }, summary: { sensor: insightSensors[0], latest: null, status: 'missing' } } });
  return route.fallback();
 });
 const card = page.getByTestId('plant-card-plant-one');
 const reading = card.getByRole('button', { name: 'Historique · Moisture one', exact: true });
 await expect(reading.locator('time')).toHaveAttribute('datetime', '2026-11-09T11:00:00Z');
 await expect(reading.locator('time')).toBeVisible();
 const describedBy = await card.locator('.open-plant').getAttribute('aria-describedby');
 expect(describedBy).toBeTruthy();
 await expect(card.locator('.plant-readings')).toHaveAttribute('id', describedBy!);
 await reading.click();
 const history = page.getByRole('dialog', { name: 'Historique · Moisture one', exact: true });
 await expect(history.getByTestId('report-mean')).toContainText('42');
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(reading).toBeFocused();
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.getByRole('button', { name: 'Historique · Moisture one', exact: true }).click();
 await expect(history.getByTestId('report-mean')).toContainText('42');
 await page.keyboard.press('Escape'); await expect(history).toHaveCount(0); await expect(detail).toBeVisible();
 expect(errors).toEqual([]);
});

test('plant disclosures and dashboard zones remember independent choices across reopening, reload and accounts', async ({ page }) => {
 const { errors, preferences } = await session(page);
 const home = page.getByTestId('plant-home');
 const room = home.locator('.home-room').first();
 await expect(room).toHaveAttribute('open', '');
 await room.locator(':scope > summary').click();
 await expect(room).not.toHaveAttribute('open');
 await expect.poll(() => Object.values(preferences.values())).toContain(false);
 await page.reload();
 await expect(room).not.toHaveAttribute('open');
 await room.locator(':scope > summary').click();
 if (process.env.DISCLOSURE_REVIEW) {
  for (const width of [1280, 390]) { await page.setViewportSize({ width, height: 900 }); await room.screenshot({ path: test.info().outputPath(`canabicultarr-disclosure-dashboard-${width}.png`), animations: 'disabled' }); }
  await page.setViewportSize({ width: 1280, height: 900 });
 }
 await home.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 const strain = detail.locator('.plant-strain-details');
 const watering = detail.locator('.plant-watering details');
 await expect(strain).not.toHaveAttribute('open');
 await strain.locator(':scope > summary').click();
 await watering.locator(':scope > summary').click();
 await expect(strain).toHaveAttribute('open', '');
 await expect(watering).toHaveAttribute('open', '');
 if (process.env.DISCLOSURE_REVIEW) await strain.screenshot({ path: test.info().outputPath('canabicultarr-disclosure-record.png'), animations: 'disabled' });
 await expect.poll(() => preferences.values()['disclosures:PlantRegistry:plant-one:watering']).toBe(true);
 await page.keyboard.press('Escape');
 await home.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 await expect(strain).toHaveAttribute('open', '');
 await expect(watering).toHaveAttribute('open', '');
 await page.reload();
 await expect(detail).toBeVisible();
 await expect(strain).toHaveAttribute('open', '');
 await expect(watering).toHaveAttribute('open', '');
 await strain.locator(':scope > summary').click();
 await expect(strain).not.toHaveAttribute('open');
 await expect.poll(() => preferences.values()['disclosures:PlantRegistry:plant-one:strain']).toBe(false);
 await page.reload();
 await expect(detail).toBeVisible();
 await expect(strain).not.toHaveAttribute('open');
 await expect(watering).toHaveAttribute('open', '');
 preferences.account = 'other-reader';
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'other-reader', name: 'Other reader', email: 'other@example.test', role: 'admin', permissions: [], isActive: true, oidcLinked: false } } }));
 await page.reload();
 await expect(detail).toBeVisible();
 await expect(strain).not.toHaveAttribute('open');
 await expect(watering).not.toHaveAttribute('open');
 expect(errors).toEqual([]);
});

test('expected plant actions share lifecycle and watering cues while post-drying plants omit watering', async ({ page }) => {
 const { errors } = await session(page, true, true, {}, 2);
 const card = page.getByTestId('plant-card-plant-one');
 await expect(card.getByRole('region', { name: 'À venir', exact: true })).toContainText('Rempoter');
 await expect(card.getByTestId('watering-needed')).toHaveCount(1);
 await expect(page.getByTestId('plant-card-plant-1').getByTestId('watering-needed')).toHaveCount(0);
 await expect(page.getByTestId('plant-card-plant-1').getByRole('region', { name: 'À venir', exact: true })).toHaveCount(0);
 await card.locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail.getByRole('region', { name: 'À venir', exact: true })).toContainText('Rempoter');
 await expect(detail.getByTestId('watering-needed')).toHaveCount(1);

 expect(errors).toEqual([]);
});


test('dashboard galleries cap each zone at ten cards and open the complete location list', async ({ page }) => {
 const queries: URL[] = [];
 page.on('request', request => { if (request.url().includes('/api/v1/')) queries.push(new URL(request.url())); });
 const { errors } = await session(page, true, false, {}, 12);
 const home = page.getByTestId('plant-home');
 const gallery = home.getByRole('region', { name: 'Plantes de Fictional Zone', exact: true });
 await expect(gallery.locator('.photo-plant')).toHaveCount(10);
 expect(queries.find(url => url.pathname.endsWith('/plant-dashboard'))?.searchParams.get('per_page')).toBe('10');
 await expect(home.locator('.collection-pagination')).toHaveCount(0);
 await gallery.evaluate(element => { element.scrollLeft = element.scrollWidth; });
 await expect.poll(() => gallery.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
 const complete = home.getByRole('button', { name: 'Tout voir (12) · Fictional Zone', exact: true });
 await gallery.scrollIntoViewIfNeeded();
 await complete.click();
 await expect(home).toHaveCount(0);
 await expect(page.locator('.plant-location-scope')).toContainText('Fictional Site / Fictional Room / Fictional Zone');
 await expect.poll(() => queries.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.get('location_id')).toBe('zone-one');
 expect(queries.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.get('location_type')).toBe('zone');
 await expect(page.locator('.photo-card-grid .photo-plant')).toHaveCount(12);
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click();
 await expect(page.locator('.plant-location-scope')).toHaveCount(0);
 await expect.poll(() => queries.filter(url => url.pathname.endsWith('/plants')).at(-1)?.searchParams.has('location_id')).toBe(false);
 await openModule(page, 'home');
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(gallery.locator('.photo-plant')).toHaveCount(10);
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await gallery.evaluate(element => { element.scrollLeft = element.scrollWidth; });
 await expect.poll(() => gallery.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
 await gallery.scrollIntoViewIfNeeded();
 await home.getByRole('button', { name: 'Afficher les plantes à semer', exact: true }).click();
 await complete.click();
 await expect(page.locator('#plant-status')).toHaveValue('planned');
 expect(errors).toEqual([]);
});

test('file explorer keeps long filenames and their actions usable on narrow screens', async ({ page }) => {
 const { errors } = await session(page, false, true);
 const originalName = `${'Fictional-photo-reference-'.repeat(9)}.png`;
 const title = 'Fictional plant observation with a long descriptive title '.repeat(2);
 await page.route('**/api/v1/documents?**', route => route.fulfill({ json: { data: [{ id: 'photo-two', originalName, title, mediaType: 'image/png', format: 'image', disk: 'documents', sizeBytes: 1234567, version: 1, isArchived: false, subject: null, uploader: { id: 'reader', name: 'Fictional User' }, createdAt: '2026-11-08T12:00:00Z', updatedAt: '2026-11-08T12:00:00Z', canEdit: true }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await openModule(page, 'documents');
 const row = page.locator('.document-list tbody tr').first();
 await expect(row.getByRole('button', { name: `Visionner ${originalName}`, exact: true })).toBeVisible();
 await expect.poll(() => row.locator('img.file-thumbnail').evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
 for (const width of [1280, 900, 390]) {
  await page.setViewportSize({ width, height: 844 });
  if (process.env.PLAYWRIGHT_EXPLORER_REVIEW) await page.locator('.document-list').screenshot({ path: test.info().outputPath(`canabicultarr-file-layout-${width}.png`), animations: 'disabled' });
  expect(await row.evaluate(el => el.getBoundingClientRect().height)).toBeLessThan(160);
  expect(await page.locator('.document-list').evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
  const action = row.getByRole('button', { name: `Modifier ${title}`, exact: true });
  await expect(action).toBeVisible();
  expect(await action.evaluate(el => el.getBoundingClientRect().right <= innerWidth)).toBe(true);
 }
 await row.getByRole('button', { name: `Visionner ${originalName}`, exact: true }).click();
 await expect(page.getByRole('dialog').locator('img')).toBeVisible();
 expect(errors).toEqual([]);
});


test('record URLs restore dashboard and registry dialogs through refresh and browser history', async ({ page }) => {
 const { errors, requests } = await session(page);
 const home = page.getByTestId('plant-home');
 await home.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 await expect(page).toHaveURL(/\?site=site-one&plant=plant-one$/);
 const plant = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(plant).toBeVisible();
 await page.goBack(); await expect(plant).toHaveCount(0);
 await page.goForward(); await expect(plant).toBeVisible();
 await page.reload(); await expect(plant).toBeVisible();
 await plant.getByRole('button', { name: 'Fermer la fiche', exact: true }).click();
 await expect(plant).toHaveCount(0); await expect(page).not.toHaveURL(/plant=/);
 await openModule(page, 'plants');
 await page.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true }).click();
 await expect(page).toHaveURL(/\/plants\?plant=plant-one$/);
 await page.reload(); await expect(plant).toBeVisible();
 await plant.getByRole('button', { name: 'Fermer la fiche', exact: true }).click();
 await expect(page).toHaveURL(/\/plants$/);
 await page.goto('/strains?strain=strain-one');
 const strain = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(strain).toBeVisible(); await page.reload(); await expect(strain).toBeVisible();
 await strain.getByRole('button', { name: 'Fermer la fiche variété', exact: true }).click();
 await expect(page).toHaveURL(/\/strains$/); await expect(strain).toHaveCount(0);
 await page.goBack(); await expect(page).toHaveURL(/\/plants$/);
 expect(requests.some(path => path.includes('/strains/strain-one'))).toBe(true);
 expect(errors).toEqual([]);
});

test('direct plant lists resolve authorized location scopes and reject missing scopes', async ({ page }) => {
 const { errors, requests } = await session(page);
 const scopedRequest = page.waitForRequest(request => new URL(request.url()).pathname === '/api/v1/plants');
 await page.goto('/plants?locationType=zone&location=zone-one&status=planned');
 await expect(page.locator('#plant-search')).toBeVisible();
 const query = new URL((await scopedRequest).url()).searchParams;
 expect(query.get('location_id')).toBe('zone-one'); expect(query.get('location_type')).toBe('zone'); expect(query.get('status')).toBe('planned');
 await expect(page.locator('#plant-status')).toHaveValue('planned');
 await page.locator('#plant-status').selectOption('all'); await expect(page).toHaveURL(/status=all/);
 await page.reload(); await expect(page.locator('#plant-status')).toHaveValue('all');
 await page.getByRole('button', { name: 'Réinitialiser les filtres', exact: true }).click(); await expect.poll(() => new URL(page.url()).searchParams.get('status')).toBe('active');
 await expect.poll(() => new URL(page.url()).searchParams.get('location')).toBe('zone-one');
 await expect(page.locator('#plant-status')).toHaveValue('active');
 await expect.poll(() => requests.filter(path => path === 'GET /api/v1/plants').length).toBeGreaterThan(0);
 const before = requests.filter(path => path === 'GET /api/v1/plants').length;
 await page.goto('/plants?locationType=zone&location=missing');
 await expect(page.getByRole('alert')).toBeVisible();
 expect(requests.filter(path => path === 'GET /api/v1/plants')).toHaveLength(before);
 await page.goto('/plants?locationType=zone'); await expect(page.getByRole('alert')).toBeVisible();
 expect(requests.filter(path => path === 'GET /api/v1/plants')).toHaveLength(before);
 expect(errors).toEqual([]);
});

test('a signed-out record link resumes the requested modal after login', async ({ page }) => {
 const { errors } = await session(page);
 let authenticated = false;
 const user = { id: 'reader', name: 'Fictional User', email: 'reader@example.test', role: 'member', permissions: [], isActive: true, oidcLinked: false };
 await page.route('**/api/v1/auth/user', route => route.fulfill(authenticated ? { json: { data: user } } : { status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } }));
 await page.route('**/api/v1/auth/login', route => { authenticated = true; return route.fulfill({ json: { data: user } }); });
 await page.goto('/strains?strain=strain-one');
 await expect(page.locator('#login-email')).toBeVisible(); await expect(page).toHaveURL(/\/strains\?strain=strain-one$/);
 await page.locator('#login-email').fill(user.email); await page.locator('#login-password').fill('Fictional-password-123');
 await page.locator('form').filter({ has: page.locator('#login-email') }).locator('[type=submit]').click();
 await expect(page.getByRole('dialog', { name: 'Fictional Strain', exact: true })).toBeVisible();
 await expect(page).toHaveURL(/\/strains\?strain=strain-one$/); expect(errors).toEqual([]);
});


test('plant photo actions open a unified dropzone, library and storage dialog and retain retries', async ({ page }) => {
 const { errors, requests, mutations } = await session(page);
 const uploads: string[] = [];
 await page.route('**/api/v1/documents/storage-options', route => route.fulfill({ json: { data: { default: 'documents', disks: ['documents', 'documents_s3'] } } }));
 await page.route('**/api/v1/documents', route => {
  if (route.request().method() !== 'POST') return route.fallback();
  uploads.push(route.request().postDataBuffer()!.toString());
  if (uploads.length === 1) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  return route.fulfill({ json: { data: { id: 'imported-photo', title: 'Imported photo', originalName: 'photo.png', mediaType: 'image/png', sizeBytes: 68 } } });
 });
 await page.goto('/plants?plant=plant-one');
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 const photos = detail.getByRole('button', { name: 'Gérer les photos', exact: true });
 expect(requests.filter(path => path === 'GET /api/v1/documents')).toHaveLength(0);
 await photos.click();
 const manager = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
 await expect(manager.getByTestId('upload-selection')).toBeVisible();
 await expect(manager.locator('.n-select[aria-label="Stockage"]')).toBeVisible();
 await expect(manager.getByText('Choisir des fichiers', { exact: true })).toHaveCount(0);
 await expect(manager.getByRole('button', { name: 'Choose', exact: true })).toHaveCount(0);
 await expect(manager.getByRole('button', { name: 'Appareil photo', exact: true })).toHaveCount(0);
 await manager.getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
 await manager.getByRole('button', { name: 'Choisir Photo 2.png', exact: true }).click();
 await expect(manager).toHaveCount(0); await expect.poll(() => mutations.at(-1)?.input.photo_document_id).toBe('photo-two');
 const raster = await page.evaluate(() => {
  const canvas = document.createElement('canvas'); canvas.width = 320; canvas.height = 160;
  const context = canvas.getContext('2d')!; context.fillStyle = '#315b3c'; context.fillRect(0, 0, 320, 160);
  context.fillStyle = '#ffffff'; context.font = '24px sans-serif'; context.fillText('Photo preview', 70, 85);
  return canvas.toDataURL('image/png').split(',')[1]!;
 });
 const png = { name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from(raster, 'base64') };
 for (const [index, mobile] of [false, true].entries()) {
  await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1280, height: 720 });
  await photos.click();
  const input = manager.locator('input[type=file]');
  await expect(input).not.toHaveAttribute('capture');
  await expect(input).toHaveAttribute('accept', 'image/*');
  const chooser = page.waitForEvent('filechooser');
  await manager.locator('.upload-drop-target').click();
  await (await chooser).setFiles(png);
  await expect(manager.getByRole('img', { name: 'photo.png', exact: true })).toBeVisible();
  await expect.poll(() => manager.getByRole('img', { name: 'photo.png', exact: true }).evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(320);
  await manager.getByRole('button', { name: 'Aperçu de photo.png', exact: true }).click();
  const imagePreview = page.getByRole('dialog', { name: 'photo.png', exact: true });
  await expect.poll(() => imagePreview.getByRole('img', { name: 'photo.png', exact: true }).evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(320);
  await imagePreview.getByRole('button', { name: 'Fermer l’aperçu', exact: true }).click();
  await expect(manager.getByText('Prêt à envoyer', { exact: true })).toBeVisible();
  expect(uploads).toHaveLength(index === 0 ? 0 : 2);
  if (!mobile) { await manager.locator('.n-select[aria-label="Stockage"] .n-base-selection').click(); await page.getByRole('option', { name: 'RustFS / S3', exact: true }).click(); }
  if (process.env.UPLOAD_PREVIEW_SCREENSHOT && mobile) { await page.waitForTimeout(250); await page.screenshot({ path: process.env.UPLOAD_PREVIEW_SCREENSHOT, animations: 'disabled' }); }
  await manager.getByRole('button', { name: 'Importer', exact: true }).click();
  if (!mobile) {
   await expect(manager.locator('[data-upload-state=error]')).toHaveCount(1);
   await expect(manager.getByRole('img', { name: 'photo.png', exact: true })).toBeVisible();
   await manager.getByRole('button', { name: 'Importer', exact: true }).click();
  }
  await expect(manager).toHaveCount(0);
 }
 expect(uploads).toHaveLength(3);
 for (const upload of uploads) { expect(upload).toContain('name="subject[id]"'); expect(upload).toContain('zone-one'); }
 expect(uploads[0]).toContain('documents_s3'); expect(uploads[1]).toContain('documents_s3'); expect(uploads[2]).not.toContain('name="disk"');
 await photos.click(); await manager.getByRole('button', { name: 'Retirer la photo principale', exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.photo_document_id).toBeNull();
 await expect(detail).toBeVisible(); expect(errors).toEqual([]);
});

test('strain photos support library linking and global uploads in their own gallery', async ({ page }) => {
 const { errors, mutations } = await session(page);
 const uploads: string[] = [];
 await page.route('**/api/v1/documents', async route => {
  if (route.request().method() !== 'POST') return route.fallback();
  uploads.push(route.request().postDataBuffer()!.toString());
  return route.fulfill({ status: 201, json: { data: { id: 'strain-photo' } } });
 });
 await page.goto('/strains?strain=strain-one');
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await detail.getByLabel('Gérer les photos', { exact: true }).click(); await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
 await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('button', { name: 'Choisir Photo 2.png', exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.photo_document_id).toBe('photo-two');
 const chooser = page.waitForEvent('filechooser');
 await detail.getByRole('button', { name: 'Gérer les photos', exact: true }).click();
 await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).locator('.upload-drop-target').click();
 await (await chooser).setFiles({ name: 'strain.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c+XcAAAAASUVORK5CYII=', 'base64') });
 await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('button', { name: 'Importer', exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.photo_document_id).toBe('strain-photo');
 expect(uploads[0]).toContain('name="subject[type]"\r\n\r\nstrain');
 expect(uploads[0]).not.toContain('name="disk"');
 expect(errors).toEqual([]);
});

test('record deletion confirms, keeps failures open and closes successful plant and strain details', async ({ page }) => {
 const { errors } = await session(page);
 for (const kind of ['plant', 'strain'] as const) {
  const name = kind === 'plant' ? 'Fictional Plant' : 'Fictional Strain';
  const path = kind === 'plant' ? '/plants/plant-one' : '/strains/strain-one';
  let attempts = 0;
  await page.route(`**/api/v1${path}`, route => {
   if (route.request().method() !== 'DELETE') return route.fallback();
   expect(route.request().postDataJSON()).toEqual({ version: 1 }); attempts++;
   return attempts === 1
    ? route.fulfill({ status: 409, json: { error: { code: kind === 'plant' ? 'plants.errors.conflict' : 'catalog.errors.inUse' } } })
    : route.fulfill({ status: 204, body: '' });
  });
  await page.goto(kind === 'plant' ? '/plants?plant=plant-one' : '/strains?strain=strain-one');
  const detail = page.getByRole('dialog', { name, exact: true });
  const remove = detail.getByRole('button', { name: `Supprimer ${name}`, exact: true });
  await remove.click();
  const confirmation = page.getByRole('dialog', { name: `Supprimer ${name} ?`, exact: true });
  await page.keyboard.press('Escape'); await expect(confirmation).toHaveCount(0); expect(attempts).toBe(0);
  await expect(detail).toBeVisible(); await remove.click();
  await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
  await expect(page.locator('.feedback-stack [data-feedback=error]')).toBeVisible(); await expect(detail).toBeVisible();
  await confirmation.getByRole('button', { name: 'Annuler', exact: true }).click();
  await remove.click(); await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
  await expect(confirmation).toHaveCount(0); await expect(detail).toHaveCount(0);
  await expect(page.locator('.feedback-stack [data-feedback=success]')).toContainText(kind === 'plant' ? 'La plante a été supprimée.' : 'La variété a été supprimée.');
  expect(attempts).toBe(2);
  await expect.poll(() => new URL(page.url()).searchParams.get(kind)).toBeNull();
 }
 expect(errors).toEqual([]);
});

test('readers cannot delete plant or strain records', async ({ page }) => {
 await session(page, false);
 for (const [url, name] of [['/plants?plant=plant-one', 'Fictional Plant'], ['/strains?strain=strain-one', 'Fictional Strain']]) {
  await page.goto(url!);
  const detail = page.getByRole('dialog', { name: name!, exact: true }); await expect(detail).toBeVisible();
  await expect(detail.getByRole('button', { name: `Supprimer ${name}`, exact: true })).toHaveCount(0);
 }
});


test('plant cards use record photo management and retain direct confirmed deletion', async ({ page }) => {
 const { errors, requests, mutations } = await session(page);
 let deletes = 0;
 await page.route('**/api/v1/plants/plant-one', route => {
  if (route.request().method() !== 'DELETE') return route.fallback();
  expect(route.request().postDataJSON()).toHaveProperty('version'); deletes++;
  return route.fulfill({ status: 204 });
 });
 for (const url of ['/', '/plants']) {
  await page.goto(url);
  const card = page.locator('.photo-plant').first();
  await expect(card).toBeVisible();
  await expect(card.getByLabel('Gérer les photos', { exact: true })).toHaveCount(0);
  await card.getByRole('button', { name: /^Ouvrir Fictional Plant(?: \(|$)/ }).click();
  const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
  if (process.env.RECORD_CARD_REVIEW && url === '/') {
   for (const width of [1280, 390]) { await page.setViewportSize({ width, height: 900 }); await detail.screenshot({ path: test.info().outputPath(`canabicultarr-photo-overlay-${width}.png`), animations: 'disabled' }); }
   await page.setViewportSize({ width: 1280, height: 900 });
  }
  await detail.getByLabel('Gérer les photos', { exact: true }).click(); await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
  const library = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
  await library.getByRole('button', { name: 'Choisir Photo 2.png', exact: true }).click();
  await expect.poll(() => mutations.filter(row => row.input.photo_document_id === 'photo-two').length).toBeGreaterThan(0);
  await detail.getByRole('button', { name: 'Fermer la fiche', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await card.getByRole('button', { name: 'Supprimer Fictional Plant', exact: true }).click();
  const confirmation = page.getByRole('dialog', { name: 'Supprimer Fictional Plant ?', exact: true });
  await expect(confirmation).toBeVisible();
  await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
  await confirmation.getByRole('button', { name: 'Annuler', exact: true }).click();
  expect(deletes).toBe(url === '/' ? 0 : 1);
  await card.getByRole('button', { name: 'Supprimer Fictional Plant', exact: true }).click();
  await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
  await expect(confirmation).toHaveCount(0);
 }
 expect(deletes).toBe(2); expect(requests.some(request => request.includes('/plant-dashboard'))).toBe(true); expect(errors).toEqual([]);
});

for (const kind of ['plant', 'strain'] as const) test(`${kind} edits protect drafts on close and Back, preserve failed saves and allow reverted forms`, async ({ page }) => {
 const { errors, mutations } = await session(page);
 const plant = kind === 'plant'; const id = plant ? 'plant-name' : 'strain-name';
 const name = plant ? 'Fictional Plant' : 'Fictional Strain';
 const title = plant ? 'Modifier la plante' : 'Modifier la variété';
 const save = plant ? 'Enregistrer la plante' : 'Enregistrer la variété';
 await openModule(page, plant ? 'plants' : 'catalog');
 await page.getByRole('button', { name: `Ouvrir ${name}`, exact: true }).click();
 const detail = page.getByRole('dialog', { name, exact: true });
 const editor = page.getByRole('dialog', { name: title, exact: true });
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await detail.getByRole('button', { name: `Modifier ${name}`, exact: true }).click();
 await editor.locator(`#${id}`).fill('Temporary name'); await editor.locator(`#${id}`).fill(name);
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await expect(editor).toHaveCount(0); await expect(warning).toHaveCount(0);
 await detail.getByRole('button', { name: `Modifier ${name}`, exact: true }).click();
 await editor.locator(`#${id}`).fill('Unsaved name');
 await page.keyboard.press('Escape'); await expect(warning).toBeVisible();
 await page.keyboard.press('Escape'); await expect(warning).toHaveCount(0);
 await expect(editor.locator(`#${id}`)).toHaveValue('Unsaved name');
 await page.goBack(); await expect(warning).toBeVisible();
 await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(page).toHaveURL(plant ? /plant=plant-one/ : /strain=strain-one/);
 const endpoint = plant ? '**/api/v1/plants/plant-one' : '**/api/v1/strains/strain-one';
 await page.route(endpoint, route => route.request().method() === 'PATCH'
  ? route.fulfill({ status: 409, json: { error: { code: plant ? 'plants.errors.conflict' : 'catalog.errors.conflict' } } }) : route.fallback());
 await editor.getByRole('button', { name: save, exact: true }).click(); await expect(page.getByRole('alert').first()).toBeVisible();
 await expect(editor.locator(`#${id}`)).toHaveValue('Unsaved name');
 await editor.getByRole('button', { name: plant ? 'Recharger les plantes' : 'Recharger le catalogue', exact: true }).click();
 await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(editor.locator(`#${id}`)).toHaveValue('Unsaved name');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(editor).toHaveCount(0); await expect(detail).toBeVisible();
 await detail.getByRole('button', { name: `Modifier ${name}`, exact: true }).click();
 await editor.locator(`#${id}`).fill('Discard through Back'); await page.goBack(); await expect(warning).toBeVisible();
 await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(detail).toHaveCount(0); await expect(editor).toHaveCount(0); await expect(warning).toHaveCount(0);
 await expect(page).toHaveURL(plant ? /\/plants$/ : /\/strains$/);
 await page.getByRole('button', { name: plant ? 'Ajouter une plante' : 'Ajouter une variété', exact: true }).click();
 const creation = page.getByRole('dialog', { name: plant ? 'Nouvelle plante' : 'Nouvelle variété', exact: true });
 await creation.locator(`#${id}`).fill('Unsaved creation'); await creation.getByRole('button', { name: 'Annuler', exact: true }).click();
 await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(creation).toHaveCount(0); expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

test('dashboard watering protects attachment-only drafts and retains retries across conflicts and field errors', async ({ page }) => {
 const { errors } = await session(page); const attempts: Record<string, unknown>[] = [];
 await openModule(page, 'plants'); await openModule(page, 'home');
 const trigger = page.getByTestId('plant-card-plant-one').getByRole('button', { name: /^Enregistrer un arrosage pour/ });
 const dialog = page.getByRole('dialog', { name: 'Arrosage — Fictional Plant', exact: true });
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await trigger.click(); await (await revealTabbedControl(dialog.getByLabel('Choisir un document', { exact: true }).and(dialog.locator('select')))).selectOption('report');
 await page.keyboard.press('Escape'); await expect(warning).toBeVisible();
 await page.keyboard.press('Escape');
 await expect(dialog.getByRole('button', { name: 'Retirer report.pdf de cette entrée', exact: true })).toBeVisible();
 await dialog.getByRole('button', { name: 'Retirer report.pdf de cette entrée', exact: true }).click();
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expect(warning).toHaveCount(0);
 await trigger.click(); await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without');
 await (await revealTabbedControl(dialog.locator('#plant-watering-comment'))).fill('Retained watering'); await (await revealTabbedControl(dialog.getByLabel('Choisir un document', { exact: true }).and(dialog.locator('select')))).selectOption('report');
 await page.goBack(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(page).toHaveURL(/\/(?:\?.*)?$/);
 await page.route('**/api/v1/plants/plant-one/waterings', route => {
  attempts.push(route.request().postDataJSON());
  if (attempts.length === 1) return route.fulfill({ status: 409, json: { error: { code: 'plants.errors.conflict' } } });
  if (attempts.length === 2) return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { occurred_at: ['private server detail'] } } });
  return route.fallback();
 });
 const submit = dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }); await submit.click();
 await dialog.getByRole('button', { name: 'Recharger les plantes', exact: true }).click();
 await expect(warning).toHaveCount(0); await expect(dialog.locator('#plant-watering-comment')).toHaveValue('Retained watering');
 await dialog.getByRole('tab', { name: 'Action', exact: true }).click();
 await expect(dialog.getByLabel('Volume d’eau (L par plante)')).toBeVisible();
 await dialog.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
 await submit.click(); await expect(dialog.getByRole('tab', { name: 'Action', exact: true })).toHaveAttribute('aria-selected', 'true');
 await expect(dialog.locator('#plant-watering-date')).toBeFocused();
 await expect(dialog.locator('#plant-watering-date')).toHaveAttribute('aria-invalid', 'true');
 await expect(dialog.locator('#plant-watering-date-error')).toBeVisible(); await expect(dialog).not.toContainText('private server detail');
 await (await revealTabbedControl(dialog.locator('#plant-watering-date'))).fill('2026-11-08T12:00'); await expect(dialog.locator('#plant-watering-date-error')).toHaveCount(0);
 await submit.click(); await expect(dialog).toHaveCount(0); await expect(warning).toHaveCount(0);
 expect(attempts).toHaveLength(3); expect(new Set(attempts.map(row => row.request_id)).size).toBe(1);
 expect(attempts[2]).toMatchObject({ with_products: false, comment: 'Retained watering', document_ids: ['report'] }); expect(errors).toEqual([]);
});

test('maintenance validates fields, retains attachments after failure and guards a dirty conflict reload', async ({ page }) => {
 const { errors } = await session(page); const attempts: Record<string, unknown>[] = [];
 await openModule(page, 'plants'); await page.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await detail.getByRole('button', { name: 'Enregistrer un entretien', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Entretien — Fictional Plant', exact: true });
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-type'))).selectOption('__new__'); await (await revealTabbedControl(dialog.locator('#plant-maintenance-custom-type'))).fill('Fictional care');
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-comment'))).fill('Retained care'); await (await revealTabbedControl(dialog.getByLabel('Choisir un document', { exact: true }).and(dialog.locator('select')))).selectOption('report');
 await page.route('**/api/v1/plants/plant-one/maintenance', route => {
  attempts.push(route.request().postDataJSON());
  if (attempts.length === 1) return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { type: ['hidden server detail'], comment: ['hidden server detail'], 'document_ids.0': ['hidden server detail'] } } });
  if (attempts.length === 2) return route.fulfill({ status: 409, json: { error: { code: 'plants.errors.conflict' } } });
  return route.fallback();
 });
 const submit = dialog.getByRole('button', { name: 'Enregistrer l’entretien', exact: true }); await submit.click();
 await expect(dialog.locator('#plant-maintenance-custom-type')).toBeFocused();
 await expect(dialog.locator('#plant-maintenance-custom-type')).toHaveAttribute('aria-invalid', 'true');
 await expect(dialog.locator('#plant-maintenance-custom-type')).toHaveAttribute('aria-describedby', /plant-maintenance-custom-type-error/);
 await expect(dialog.locator('#plant-maintenance-comment')).toHaveAttribute('aria-invalid', 'true');
 await dialog.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
 await expect(dialog.locator('#plant-care-attachments-error')).toBeVisible(); await expect(dialog).not.toContainText('hidden server detail');
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-custom-type'))).fill('Adjusted care'); await expect(dialog.locator('#plant-maintenance-custom-type-error')).toHaveCount(0);
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-comment'))).fill('Adjusted comment'); await expect(dialog.locator('#plant-maintenance-comment-error')).toHaveCount(0);
 await submit.click(); await dialog.getByRole('button', { name: 'Recharger les plantes', exact: true }).click(); await expect(warning).toBeVisible();
 await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(dialog.locator('#plant-maintenance-custom-type')).toHaveValue('Adjusted care');
 await dialog.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
 await expect(dialog.getByRole('button', { name: 'Retirer report.pdf de cette entrée', exact: true })).toBeVisible();
 await submit.click(); await expect(dialog).toHaveCount(0); await expect(detail).toBeVisible();
 expect(attempts).toHaveLength(3); expect(new Set(attempts.map(row => row.request_id)).size).toBe(1); expect(attempts[2]).toMatchObject({ type: 'Adjusted care', comment: 'Adjusted comment', document_ids: ['report'] });
 await page.keyboard.press('Escape'); await openModule(page, 'home');
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: 'Entretien — Fictional Plant', exact: true }).click();
 await (await revealTabbedControl(dialog.locator('#plant-maintenance-type'))).selectOption('pruning'); await submit.click();
 await expect(dialog).toHaveCount(0); await expect(warning).toHaveCount(0); expect(attempts).toHaveLength(4);
 expect(attempts[3]).toMatchObject({ type: 'pruning', document_ids: [] }); expect(errors).toEqual([]);
});

for (const location of ['home', 'plants'] as const) test(`lifecycle care validates harvest weight and dates with retained drafts on ${location}`, async ({ page }) => {
 const { errors, mutations } = await session(page, true, false, { phase: 'curing', nextAction: 'start_maturation' });
 await openModule(page, location);
 const card = page.getByTestId(location === 'home' ? 'plant-card-plant-one' : 'plant-row-plant-one');
 await card.getByRole('button', { name: 'Passer en maturation — Fictional Plant', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Enregistrer : Passer en maturation', exact: true });
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 const actionTab = dialog.getByRole('tab', { name: 'Action', exact: true });
 const commentTab = dialog.getByRole('tab', { name: 'Commentaire', exact: true });
 const attachmentsTab = dialog.getByRole('tab', { name: 'Pièces jointes', exact: true });
 await expect(actionTab).toHaveAttribute('aria-selected', 'true');
 await actionTab.focus(); await page.keyboard.press('ArrowRight');
 await expect(commentTab).toHaveAttribute('aria-selected', 'true');
 await dialog.locator('#plant-action-comment').fill('Retained maturation');
 await attachmentsTab.click(); await expect(dialog.locator('#plant-care-attachments')).toBeVisible();
 const submit = dialog.getByRole('button', { name: 'Enregistrer l’action', exact: true }); await submit.click();
 await expect(actionTab).toHaveAttribute('aria-selected', 'true');
 await expect(dialog.locator('#plant-harvest-weight')).toBeFocused(); await expect(dialog.locator('#plant-harvest-weight-error')).toBeVisible(); expect(mutations).toEqual([]);
 await dialog.locator('#plant-harvest-weight').fill('123.45'); await dialog.locator('#plant-action-date').fill('2099-01-01T12:00'); await submit.click();
 await expect(dialog.locator('#plant-action-date')).toBeFocused(); await expect(dialog.locator('#plant-action-date-error')).toBeVisible(); expect(mutations).toEqual([]);
 await dialog.locator('#plant-action-date').fill('2026-11-08T12:00'); await expect(dialog.locator('#plant-action-date-error')).toHaveCount(0);
 await commentTab.click(); await page.keyboard.press('Escape'); await expect(warning).toBeVisible(); await page.keyboard.press('Escape');
 await expect(dialog.locator('#plant-action-comment')).toHaveValue('Retained maturation'); await submit.click(); await expect(dialog).toHaveCount(0); await expect(warning).toHaveCount(0);
 expect(mutations).toHaveLength(1); expect(mutations[0].input).toMatchObject({ action: 'start_maturation', harvest_weight_grams: 123.45, comment: 'Retained maturation' }); expect(errors).toEqual([]);
});

test('watering keeps uploads in progress and confirms abandoning uploaded attachment associations', async ({ page }) => {
 const { errors, mutations } = await session(page); await openModule(page, 'plants'); await openModule(page, 'home');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let uploading = false;
 await page.route('**/api/v1/documents', async route => {
  if (route.request().method() !== 'POST') return route.fallback();
  uploading = true; await pending;
  return route.fulfill({ json: { data: { id: 'uploaded', title: 'Fictional.png', originalName: 'Fictional.png', mediaType: 'image/png', sizeBytes: 68 } } });
 });
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: /^Enregistrer un arrosage pour/ }).click();
 const dialog = page.getByRole('dialog', { name: 'Arrosage — Fictional Plant', exact: true });
 try {
  await dialog.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
  await dialog.locator('input[type=file]').first().setInputFiles({ name: 'Fictional.png', mimeType: 'image/png', buffer: Buffer.from('Fictional image') });
  await dialog.getByRole('button', { name: 'Ajouter des fichiers', exact: true }).click();
  await expect.poll(() => uploading).toBe(true); await expect(dialog.getByRole('button', { name: 'Annuler l’arrosage', exact: true })).toBeDisabled();
  await page.keyboard.press('Escape'); await expect(dialog).toBeVisible(); await page.goBack(); await expect(page).toHaveURL(/\/(?:\?.*)?$/);
 } finally { release(); }
 await expect(dialog.getByRole('button', { name: 'Retirer Fictional.png', exact: true })).toBeVisible();
 await dialog.getByRole('button', { name: 'Annuler l’arrosage', exact: true }).click();
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); await expect(warning).toBeVisible();
 await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(dialog).toHaveCount(0);
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

test('an action submitted without optional inputs guards browser reload and navigation until the write finishes', async ({ page }) => {
 const { errors, mutations } = await session(page); await openModule(page, 'plants'); await openModule(page, 'home');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/plants/plant-one/actions', async route => { writing = true; await pending; return route.fallback(); });
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: 'Rempoter avant la floraison — Fictional Plant', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Enregistrer : Rempoter avant la floraison', exact: true });
 await dialog.getByRole('button', { name: 'Enregistrer l’action', exact: true }).click();
 try {
  await expect.poll(() => writing).toBe(true); await expect(dialog.getByRole('button', { name: 'Annuler', exact: true })).toBeDisabled();
  await page.goBack(); await expect(page).toHaveURL(/\/(?:\?.*)?$/);
  let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); });
  await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true); await expect(dialog).toBeVisible();
 } finally { release(); }
 await expect(dialog).toHaveCount(0); expect(mutations).toHaveLength(1); expect(errors).toEqual([]);
});

for (const kind of ['plant', 'strain'] as const) test(`${kind} record field feedback focuses known errors, clears corrected fields and preserves retry data`, async ({ page }) => {
 const { errors, mutations } = await session(page);
 const plant = kind === 'plant'; const prefix = plant ? 'plant' : 'strain'; const name = plant ? 'Fictional Plant' : 'Fictional Strain';
 await openModule(page, plant ? 'plants' : 'catalog');
 await page.getByRole('button', { name: `Ouvrir ${name}`, exact: true }).click();
 await page.getByRole('dialog', { name, exact: true }).getByRole('button', { name: `Modifier ${name}`, exact: true }).click();
 const editor = page.getByRole('dialog', { name: plant ? 'Modifier la plante' : 'Modifier la variété', exact: true });
 const submit = editor.getByRole('button', { name: plant ? 'Enregistrer la plante' : 'Enregistrer la variété', exact: true });
 if (plant) await editor.locator('#plant-name').evaluate((input: HTMLInputElement) => { input.value = 'N'.repeat(121); input.dispatchEvent(new Event('input', { bubbles: true })); });
 else await editor.locator('#strain-name').fill('');
 await submit.click();
 await expect(editor.locator(`#${prefix}-name`)).toBeFocused(); await expect(editor.locator(`#${prefix}-name-error`)).toBeVisible(); expect(mutations).toEqual([]);
 await editor.locator(`#${prefix}-name`).fill('Retained name'); await expect(editor.locator(`#${prefix}-name-error`)).toHaveCount(0);
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click();
 await editor.locator(`#${prefix}-notes`).fill('Retained notes');
 const endpoint = plant ? '**/api/v1/plants/plant-one' : '**/api/v1/strains/strain-one'; let reject = true;
 await page.route(endpoint, route => route.request().method() === 'PATCH' && reject
  ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { name: ['Private server text'], notes: ['Private notes'], ...(plant ? { 'location.id': ['Private location'] } : { source_url: ['Private reference'], 'duration_overrides.flowering': ['Private duration'] }) } } }) : route.fallback());
 await submit.click(); await expect(editor.locator(`#${prefix}-name`)).toBeFocused();
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click();
 await expect(editor.locator(`#${prefix}-notes-error`)).toBeVisible(); await expect(editor).not.toContainText('Private');
 await expect(editor.locator(`#${prefix}-notes`)).toHaveText('Retained notes');
 await editor.getByRole('tab', { name: 'Général', exact: true }).click();
 await editor.locator(`#${prefix}-name`).fill('Corrected name'); await expect(editor.locator(`#${prefix}-name-error`)).toHaveCount(0);
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click();
 await expect(editor.locator(`#${prefix}-notes-error`)).toBeVisible();
 if (plant) { await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await expect(editor.locator('#plant-location-field-error')).toBeVisible(); }
 else {
  await editor.getByRole('tab', { name: 'Général', exact: true }).click();
  await expect(editor.locator('#strain-sourceUrl-error')).toBeVisible();
  await editor.getByRole('tab', { name: 'Cycle', exact: true }).click(); await expect(editor.locator('#override-flowering-error')).toBeVisible();
  await editor.getByRole('tab', { name: 'Général', exact: true }).click();
  await editor.locator('#strain-sourceUrl').fill('https://example.test/corrected'); await expect(editor.locator('#strain-sourceUrl-error')).toHaveCount(0);
  await editor.getByRole('tab', { name: 'Cycle', exact: true }).click();
  await editor.locator('#override-flowering').fill('11'); await expect(editor.locator('#override-flowering-error')).toHaveCount(0);
 }
 reject = false; await submit.click(); await expect(editor).toHaveCount(0);
 expect(mutations).toHaveLength(1); expect(mutations[0].input).toMatchObject({ name: 'Corrected name', notes: 'Retained notes' });
 if (!plant) expect(mutations[0].input.duration_overrides).toMatchObject({ flowering: 77 });
 expect(errors).toEqual([]);
});

test('strain metadata and duration validation reject invalid inputs before writing and focus in form order', async ({ page }) => {
 const { errors, mutations } = await session(page); await openModule(page, 'catalog');
 await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 await page.getByRole('dialog', { name: 'Fictional Strain', exact: true }).getByRole('button', { name: 'Modifier Fictional Strain', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier la variété', exact: true }); const submit = editor.getByRole('button', { name: /^(?:Enregistrer la variété|Créer)$/, exact: true });
 if (process.env.MODAL_REVIEW) {
  for (const width of [1280, 390]) { await page.setViewportSize({ width, height: 1000 }); await editor.screenshot({ path: test.info().outputPath(`canabicultarr-modal-strain-${width}.png`), animations: 'disabled' }); }
  await page.setViewportSize({ width: 1280, height: 900 });
 }
 await editor.locator('#strain-yield').fill('-1'); await editor.locator('#strain-sourceUrl').fill('javascript:alert(1)'); await editor.getByRole('tab', { name: 'Cycle', exact: true }).click(); await editor.locator('#override-flowering').fill('0.5');
 await submit.click(); await expect(editor.locator('#strain-yield')).toBeFocused(); await expect(editor.locator('#strain-sourceUrl-error')).toBeVisible(); await editor.getByRole('tab', { name: 'Cycle', exact: true }).click(); await expect(editor.locator('#override-flowering-error')).toBeVisible(); await editor.getByRole('tab', { name: 'Général', exact: true }).click();
 await editor.locator('#strain-yield').fill('500.25'); await submit.click(); await expect(editor.locator('#strain-sourceUrl')).toBeFocused();
 await editor.locator('#strain-sourceUrl').fill('https://example.test/valid'); await submit.click(); await expect(editor.locator('#override-flowering')).toBeFocused();
 expect(mutations).toEqual([]);
 await editor.locator('#override-flowering').fill('10'); await submit.click(); await expect(editor).toHaveCount(0);
 expect(mutations).toHaveLength(1); expect(mutations[0].input).toMatchObject({ theoretical_yield: '500.25', source_url: 'https://example.test/valid', duration_overrides: { flowering: 70 } }); expect(errors).toEqual([]);
});

test('plant creation identifies missing strain and location without issuing a write', async ({ page }) => {
 const { errors, mutations } = await session(page); await openModule(page, 'plants');
 await page.getByRole('button', { name: 'Ajouter une plante', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await expect(editor.locator('#plant-strain-select option[value="strain-one"]')).toBeAttached(); await expect(editor.locator('#plant-strain-select')).toBeEnabled(); await editor.locator('#plant-name').fill('New plant');
 await editor.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect.poll(() => page.evaluate(() => document.activeElement?.closest('[data-ui-select]')?.getAttribute('data-ui-select'))).toBe('plant-strain-select'); await expect(editor.locator('#plant-strain-select-error')).toBeVisible(); await expect(editor.locator('#plant-location-field-error')).toBeVisible();
 await editor.locator('#plant-strain-select').selectOption('strain-one'); await expect(editor.locator('#plant-strain-select-error')).toHaveCount(0);
 await editor.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click(); await expect(editor.locator('#plant-location-field')).toBeFocused();
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

async function batchSession(page: Page, phases = ['growth', 'flowering', 'maturation', 'growth']) {
 const existing = await session(page, true, false, {}, 4);
 const base = await page.evaluate(async () => (await (await fetch('/api/v1/plants/plant-one')).json()).data);
 const plants = phases.map((phase, index) => ({ ...structuredClone(base), id: `batch-${index}`, name: `Batch Plant ${index + 1}`, phase, nextAction: lifecycleStages.find(stage => stage.id === phase)?.actions[0]?.id ?? null, canManage: index !== 3, canDelete: index !== 3, version: 3, watering: { ...base.watering, status: phase === 'growth' ? 'alternate' : phase === 'flowering' ? 'flush' : 'unavailable', nextWithProducts: phase === 'growth', last: null } }));
 const writes: { id: string; path: string; input: Record<string, unknown> }[] = [];
 const state = { failId: '', failStatus: 503, commitBeforeFailure: false, failRead: '', pending: null as Promise<void> | null, commits: new Set<string>() };
 const meta = { current_page: 1, last_page: 1, total: plants.length, per_page: 20 };
 await page.route('**/api/v1/sites/*/plant-dashboard?**', route => route.fulfill({ json: { ...dashboardPayload(plants, new URL(route.request().url())), meta, summary: { plants: 3, planned: 0, due: 0, strains: 1 }, filterOptions: { strains: [] } } }));
 await page.route('**/api/v1/plants**', async route => {
  const url = new URL(route.request().url()); const path = url.pathname; const method = route.request().method();
  if (path.endsWith('/maintenance-types')) return route.fulfill({ json: { data: ['Leaf inspection'] } });
  if (path.endsWith('/plants')) { const data = plants.filter(plant => plant.name.toLowerCase().includes((url.searchParams.get('search') ?? '').toLowerCase())); return route.fulfill({ json: { data, meta: { ...meta, total: data.length } } }); }
  const plant = plants.find(plant => path.split('/').includes(plant.id));
  if (!plant) throw new Error(`Unexpected batch request ${path}`);
  if (method === 'GET') {
   if (path.endsWith('/consumables')) return route.fulfill({ json: { data: { products: [], waterLiters: null, canConsume: true, container: null, currentContainer: plant.container ?? null } } });
   if (plant.id === state.failRead) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
   return route.fulfill({ json: { data: plant } });
  }
  if (method !== 'POST') throw new Error(`Unexpected batch write ${method} ${path}`);
  const input = route.request().postDataJSON(); writes.push({ id: plant.id, path, input });
  if (state.pending) await state.pending;
  if (state.failId === plant.id) { state.failId = ''; if (state.commitBeforeFailure) { state.commits.add(`${plant.id}:${input.request_id}`); plant.version++; } return route.fulfill({ status: state.failStatus, json: { error: { code: state.failStatus === 409 ? 'plants.errors.conflict' : 'auth.errors.requestFailed' } } }); }
  const key = `${plant.id}:${input.request_id}`;
  if (!state.commits.has(key)) { state.commits.add(key); plant.version++; if (path.endsWith('/actions')) plant.phase = lifecycleStages.find(stage => stage.id === plant.phase)?.actions.find(action => action.id === input.action)?.target ?? plant.phase; }
  return route.fulfill({ json: { data: plant } });
 });
 await page.reload(); await expect(page.getByTestId('plant-home')).toBeVisible(); await expect(page.getByLabel('Sélectionner Batch Plant 1', { exact: true })).toBeVisible();
 return { ...existing, plants, writes, state };
}

test('table selection confirms deletion, excludes protected rows and reports partial failure', async ({ page }) => {
 const { plants } = await batchSession(page);
 const removed: string[] = [];
 await page.route('**/api/v1/plants/*', async route => {
  if (route.request().method() !== 'DELETE') return route.fallback();
  const id = new URL(route.request().url()).pathname.split('/').pop()!;
  expect(route.request().postDataJSON()).toEqual({ version: 3 });
  removed.push(id);
  if (id === 'batch-1') return route.fulfill({ status: 409, json: { error: { code: 'plants.errors.conflict' } } });
  plants.splice(plants.findIndex(plant => plant.id === id), 1);
  return route.fulfill({ status: 204 });
 });
 await openModule(page, 'plants');
 await page.getByRole('button', { name: 'Tableau', exact: true }).click();
 const collection = page.getByRole('table', { name: 'Liste', exact: true });
 const selectAll = page.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' });
 await selectAll.click();
 await expect(collection.getByRole('checkbox', { name: 'Sélectionner Batch Plant 1', exact: true })).toBeChecked();
 await expect(collection.getByRole('checkbox', { name: 'Sélectionner Batch Plant 4', exact: true })).toHaveCount(0);
 await page.getByRole('region', { name: 'Actions groupées' }).getByRole('button', { name: 'Supprimer', exact: true }).click();
 const confirmation = page.getByRole('dialog', { name: 'Supprimer · 3 sélectionnés' });
 await expect(confirmation).toBeVisible();
 expect(removed).toEqual([]);
 await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
 await expect(confirmation.getByRole('alert')).toContainText('Batch Plant 2');
 const close = confirmation.getByRole('button', { name: 'Fermer', exact: true });
 await expect(close).toBeEnabled();
 expect(removed).toEqual(['batch-0', 'batch-1', 'batch-2']);
 await close.click();
 await expect(collection.getByRole('checkbox', { name: 'Sélectionner Batch Plant 2', exact: true })).toBeChecked();
 await expect(collection.getByRole('checkbox', { name: 'Sélectionner Batch Plant 1', exact: true })).toHaveCount(0);
});

test('batch dashboard watering reviews eligibility, protects drafts and retries failures without repeating successes', async ({ page }) => {
 const { errors, writes, state } = await batchSession(page);
 const toolbar = page.getByRole('region', { name: 'Actions groupées' });
 await expect(page.getByLabel('Sélectionner Batch Plant 4', { exact: true })).toHaveCount(0);
 await page.getByLabel('Sélectionner Batch Plant 1', { exact: true }).check();
 await expect(page.getByRole('dialog')).toHaveCount(0); await expect(toolbar).toContainText('1 plante sélectionnée');
 const selectAll = page.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' });
 await expect(selectAll).toHaveAttribute('aria-pressed', 'mixed');
 await selectAll.click();
 await expect(page.locator('.page-actions').getByRole('button', { name: 'Tout désélectionner' })).toHaveAttribute('aria-pressed', 'true');
 await expect(toolbar).toContainText('3 plantes sélectionnées');
 await expect(toolbar.getByRole('button', { name: 'Vider la sélection' })).toHaveCount(0);
 await page.locator('.page-actions').getByRole('button', { name: 'Tout désélectionner' }).click();
 await expect(toolbar).toHaveCount(0);
 await selectAll.click();
 for (const width of [1440, 1024, 768, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const heights = await toolbar.getByRole('button').evaluateAll(buttons => buttons.map(button => button.getBoundingClientRect().height));
  expect(Math.max(...heights) - Math.min(...heights)).toBeLessThanOrEqual(1);
  expect(await toolbar.evaluate(node => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
  if (process.env.BATCH_TOOLBAR_REVIEW) await toolbar.screenshot({ path: test.info().outputPath(`canabicultarr-batch-dashboard-${width}.png`), animations: 'disabled' });
 }
 await toolbar.getByRole('button', { name: 'Arrosage', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Arrosage groupé', exact: true });
 await expect(dialog).toContainText('2 plantes sur 3'); await expect(dialog).toContainText('Action indisponible'); await expect(dialog).toContainText('Nettoyage');
 await dialog.getByRole('button', { name: 'Appliquer à 2 plantes' }).click();
 await expect(dialog.getByRole('combobox', { name: 'Fertilisants utilisés', exact: true })).toBeFocused(); expect(writes).toHaveLength(0);
 await dialog.getByLabel('Fertilisants utilisés').and(dialog.locator('select')).selectOption('without'); await (await revealTabbedControl(dialog.getByLabel('Commentaire', { exact: true }).and(dialog.locator('textarea')))).fill('Shared watering note');
 await page.keyboard.press('Escape'); const discard = page.getByRole('dialog', { name: 'Modifications non enregistrées' }); await expect(discard).toBeVisible(); await discard.getByRole('button', { name: 'Continuer à modifier' }).click();
 state.failId = 'batch-1'; state.commitBeforeFailure = true; let release!: () => void; state.pending = new Promise<void>(resolve => { release = resolve; });
 await dialog.getByRole('button', { name: 'Appliquer à 2 plantes' }).click(); await expect.poll(() => writes.length).toBe(1);
 await expect(dialog.getByLabel('Commentaire', { exact: true }).and(dialog.locator('textarea'))).toBeDisabled(); await page.keyboard.press('Escape'); await expect(dialog).toBeVisible(); release(); state.pending = null;
 await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(1); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(1); await expect(dialog.getByRole('button', { name: 'Réessayer les plantes en échec' })).toBeEnabled();
 const original = writes.find(row => row.id === 'batch-1')!;
 await dialog.getByRole('button', { name: 'Réessayer les plantes en échec' }).click(); await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(2); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(0);
 expect(writes.map(row => row.id)).toEqual(['batch-0', 'batch-1', 'batch-1']); expect(writes[2]!.input).toEqual({ ...original.input, version: 4 });
 expect(writes[0]!.input).toMatchObject({ with_products: false, comment: 'Shared watering note', version: 3 }); expect(writes[0]!.input.occurred_at).toBe(writes[1]!.input.occurred_at); expect(state.commits.size).toBe(2);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click(); await expect(dialog).toHaveCount(0); expect(errors).toEqual([]);
});

test('batch registry maintenance supports reusable custom types and limits selection to the current view', async ({ page }) => {
 const { errors, writes } = await batchSession(page);
 await openModule(page, 'plants'); const toolbar = page.getByRole('region', { name: 'Actions groupées' });
 await page.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' }).click(); for (const width of [1440, 1024, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const heights = await toolbar.getByRole('button').evaluateAll(buttons => buttons.map(button => button.getBoundingClientRect().height));
  expect(Math.max(...heights) - Math.min(...heights)).toBeLessThanOrEqual(1);
  expect(await toolbar.evaluate(node => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
  if (process.env.BATCH_TOOLBAR_REVIEW) await toolbar.screenshot({ path: test.info().outputPath(`canabicultarr-batch-registry-${width}.png`), animations: 'disabled' });
 }
 await toolbar.getByRole('button', { name: 'Entretien', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Entretien groupé', exact: true }); await expect(dialog).toContainText('2 plantes sur 3');
 await expect(dialog.locator('option[value=other]')).toHaveCount(0);
 await dialog.getByLabel('Type d’entretien', { exact: true }).and(dialog.locator('select')).selectOption('saved:Leaf inspection'); await dialog.getByLabel('Type d’entretien', { exact: true }).and(dialog.locator('select')).selectOption('__new__'); await dialog.getByLabel('Type d’entretien personnalisé', { exact: true }).fill('Root inspection');
 await dialog.getByRole('button', { name: 'Appliquer à 2 plantes' }).click(); await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(2); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(0);
 expect(writes.map(row => row.input.type)).toEqual(['Root inspection', 'Root inspection']); expect(writes.every(row => row.path.endsWith('/maintenance'))).toBe(true);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).click(); await page.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' }).click();
 await page.locator('#plant-search').fill('Batch Plant 1'); await expect(page.locator('.plant-grid > li')).toHaveCount(1); await expect(toolbar).toContainText('1 plante sélectionnée');
 await toolbar.getByRole('button', { name: 'Vider la sélection' }).click(); await expect(page.getByLabel('Sélectionner Batch Plant 1', { exact: true })).not.toBeChecked(); expect(errors).toEqual([]);
});

test('batch cycle actions require a separate harvest weight for each compatible plant and retain conflicts', async ({ page }) => {
 const { errors, writes, state, plants } = await batchSession(page, ['curing', 'curing', 'growth', 'growth']);
 await openModule(page, 'plants'); const toolbar = page.getByRole('region', { name: 'Actions groupées' });
 await page.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' }).click(); await toolbar.getByRole('button', { name: 'Action du cycle', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Action du cycle', exact: true }); await dialog.getByLabel('Action du cycle', { exact: true }).and(dialog.locator('select')).selectOption('start_maturation'); await expect(dialog).toContainText('2 plantes sur 3');
 await expect(dialog.locator('.batch-results > li')).toHaveCount(2);
 await expect(dialog).not.toContainText('Batch Plant 3'); await expect(dialog).not.toContainText('Action indisponible');
 await dialog.getByLabel('Action du cycle', { exact: true }).and(dialog.locator('select')).selectOption('repot_flowering');
 await expect(dialog.locator('.batch-results > li')).toHaveCount(1); await expect(dialog.locator('.batch-results')).toContainText('Batch Plant 3');
 await dialog.getByLabel('Action du cycle', { exact: true }).and(dialog.locator('select')).selectOption('start_maturation');
 const weights = dialog.getByLabel('Poids de récolte (g)', { exact: true }); await expect(weights).toHaveCount(2);
 await dialog.getByRole('button', { name: 'Appliquer à 2 plantes' }).click(); await expect(weights.first()).toBeFocused(); expect(writes).toHaveLength(0);
 await weights.nth(0).fill('90.25'); await weights.nth(1).fill('120'); state.failId = 'batch-1'; state.failStatus = 409;
 await dialog.getByRole('button', { name: 'Appliquer à 2 plantes' }).click(); await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(1); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(1);
 plants[1]!.version = 8; await dialog.getByRole('button', { name: 'Réessayer les plantes en échec' }).click(); await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(2); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(0);
 expect(writes.map(row => [row.id, row.input.harvest_weight_grams, row.input.version])).toEqual([['batch-0', 90.25, 3], ['batch-1', 120, 3], ['batch-1', 120, 8]]);
 expect(writes[1]!.input.request_id).toBe(writes[2]!.input.request_id); expect(writes.every(row => row.input.action === 'start_maturation')).toBe(true); expect(errors).toEqual([]);
});

test('batch review blocks writes after failed plant reads and retains the selected operation during dashboard refresh time', async ({ page }) => {
 await page.clock.install(); const { state, writes, errors } = await batchSession(page);
 state.failRead = 'batch-0'; await page.getByRole('button', { name: 'Tout sélectionner sur cette vue' }).click();
 const toolbar = page.getByRole('region', { name: 'Actions groupées' }); await toolbar.getByRole('button', { name: 'Arrosage', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Arrosage groupé', exact: true }); await expect(dialog.getByRole('button', { name: 'Appliquer à 1 plante' })).toBeDisabled(); await dialog.getByLabel('Fertilisants utilisés').and(dialog.locator('select')).selectOption('without'); await dialog.getByRole('combobox', { name: 'Fertilisants utilisés', exact: true }).press('Enter'); expect(writes).toHaveLength(0);
 state.failRead = ''; await dialog.getByRole('button', { name: 'Réessayer le chargement', exact: true }).click(); await expect(dialog).toContainText('2 plantes sur 3'); await dialog.getByLabel('Fertilisants utilisés').and(dialog.locator('select')).selectOption('without');
 await page.clock.fastForward(61_000); await expect(dialog).toBeVisible(); await expect(dialog.getByLabel('Fertilisants utilisés').and(dialog.locator('select'))).toHaveValue('without'); expect(writes).toHaveLength(0);
 await page.keyboard.press('Escape'); await page.getByRole('button', { name: 'Abandonner les modifications' }).click(); await expect(dialog).toHaveCount(0); expect(errors).toEqual([]);
});

test('batch care stops at a rate limit and resumes only unfinished plants', async ({ page }) => {
 const { state, writes, errors } = await batchSession(page, ['growth', 'growth', 'growth', 'growth']);
 await page.getByRole('button', { name: 'Tout sélectionner sur cette vue' }).click(); await page.getByRole('region', { name: 'Actions groupées' }).getByRole('button', { name: 'Arrosage', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Arrosage groupé', exact: true }); await dialog.getByLabel('Fertilisants utilisés').and(dialog.locator('select')).selectOption('without');
 state.failId = 'batch-1'; state.failStatus = 429;
 await dialog.getByRole('button', { name: 'Appliquer à 3 plantes' }).click(); await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(1); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(2); expect(writes.map(row => row.id)).toEqual(['batch-0', 'batch-1']);
 await dialog.getByRole('button', { name: 'Réessayer les plantes en échec' }).click(); await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(3); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(0); expect(writes.map(row => row.id)).toEqual(['batch-0', 'batch-1', 'batch-1', 'batch-2']); expect(errors).toEqual([]);
});

test('plant cards keep their actions on one line and linked readings below them', async ({ page }) => {
 const { errors, mutations } = await session(page, true, false, { measurements: [{ sensorId: 'moisture', sensorName: 'Pot moisture', device: { id: 'probe-one', name: 'Probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture', decimalPlaces: 1 }, value: '32.4', unit: '%', observedAt: '2026-11-09T12:00:00Z', isEnabled: true, isStale: false }] });
 for (const width of [1280, 1024, 768, 390, 375, 361, 320]) {
  await page.setViewportSize({ width, height: 1000 });
  for (const module of ['home', 'plants']) {
   await openModule(page, module);
   const card = module === 'home' ? page.getByTestId('plant-card-plant-one') : page.locator('.plant-grid .photo-plant').first();
   await expect(card.locator('.plant-reading')).toBeVisible();
   await expect.poll(() => card.evaluate(element => {
    const card = element.getBoundingClientRect(); const actions = element.querySelector('.plant-card-actions')!.getBoundingClientRect(); const readings = element.querySelector('.card-readings')!.getBoundingClientRect();
    const controls = [...element.querySelectorAll('.plant-card-utilities > .record-photo-actions, .plant-card-utilities > button, .plant-card-care > button')].map(control => control.getBoundingClientRect());
    const care = element.querySelector('.plant-card-care')!; const utilities = element.querySelector('.plant-card-utilities')!;
    return { businessFirst: !!(care.compareDocumentPosition(utilities) & Node.DOCUMENT_POSITION_FOLLOWING), count: controls.length, aligned: Math.max(...controls.map(rect => rect.top + rect.height / 2)) - Math.min(...controls.map(rect => rect.top + rect.height / 2)) < 2, contained: controls.every(rect => rect.left >= card.left && rect.right <= card.right), readingsBelow: readings.top >= actions.bottom - 1 };
   }), { message: `${module} cards at ${width}px` }).toEqual({ businessFirst: true, count: 5, aligned: true, contained: true, readingsBelow: true });
  }
 }
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('photo library only shows images, pages on demand and searches without linking previews', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 const { errors, mutations } = await session(page);
 const queries: URL[] = [];
 await page.route('**/api/v1/documents?**', route => {
  const url = new URL(route.request().url()); queries.push(url);
  expect(url.searchParams.get('format')).toBe('image');
  expect(url.searchParams.get('state')).toBe('active');
  const search = url.searchParams.get('search'); const current = Number(url.searchParams.get('page'));
  const photo = (id: string, title: string, format = 'image', mediaType = 'image/png') => ({ id, title, originalName: title + '.png', format, mediaType, isArchived: false });
  const data = search ? search === 'missing' ? [] : [photo('search-photo', 'Search result')] : current === 2 ? [photo('second-photo', 'Second page')] : [photo('first-photo', 'First photo'), photo('pdf', 'PDF report', 'pdf', 'application/pdf'), photo('text', 'Text note', 'text', 'text/plain'), photo('svg', 'Unsupported image', 'image', 'image/svg+xml')];
  return route.fulfill({ json: { data, meta: { current_page: current, last_page: search ? 1 : 2, total: search ? data.length : 2, per_page: 1 } } });
 });
 if (!await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).isVisible()) await page.getByTestId('plant-card-plant-one').locator('.open-plant').click(); await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).getByLabel('Gérer les photos', { exact: true }).click(); await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
 const picker = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
 await expect(picker.getByRole('button', { name: 'Choisir First photo', exact: true })).toBeVisible();
 await expect(picker.locator('.photo-library-choice')).toHaveCount(1);
 await expect.poll(() => picker.locator('.photo-library-image img').evaluateAll(images => images.every(image => (image as HTMLImageElement).naturalWidth > 0))).toBe(true);
 expect(queries).toHaveLength(1);
 await picker.getByRole('button', { name: 'Agrandir First photo', exact: true }).click();
 const enlarged = page.getByRole('dialog', { name: 'First photo', exact: true });
 await expect(enlarged).toBeVisible(); expect(mutations).toHaveLength(0);
 await page.keyboard.press('Escape'); await expect(enlarged).toHaveCount(0); await expect(picker).toBeVisible();
 await picker.getByRole('navigation').getByRole('button', { name: 'Page suivante', exact: true }).click();
 await expect(picker.getByRole('button', { name: 'Choisir Second page', exact: true })).toBeVisible();
 expect(queries.at(-1)?.searchParams.get('page')).toBe('2');
 await picker.getByRole('searchbox').fill('missing'); await expect(picker.getByText('Aucune photo trouvée.', { exact: true })).toBeVisible();
 await picker.getByRole('searchbox').fill('result');
 await expect(picker.getByRole('button', { name: 'Choisir Search result', exact: true })).toBeVisible();
 expect(queries.at(-1)?.searchParams.get('page')).toBe('1');
 await picker.getByRole('button', { name: 'Choisir Search result', exact: true }).focus(); await page.keyboard.press('Enter');
 await expect(picker).toHaveCount(0); await expect.poll(() => mutations.at(-1)?.input.photo_document_id).toBe('search-photo');
 expect(errors).toEqual([]);
});

test('photo library ignores stale searches and recovers from a failed result page', async ({ page }) => {
 const { mutations } = await session(page);
 let release!: () => void; const delayed = new Promise<void>(resolve => { release = resolve; });
 let slowStarted = false; let fail = true;
 await page.route('**/api/v1/documents?**', async route => {
  const url = new URL(route.request().url()); const search = url.searchParams.get('search') || '';
  if (search === 'slow') { slowStarted = true; await delayed; }
  if (search === 'retry' && fail) { fail = false; return route.fulfill({ status: 500, json: {} }); }
  return route.fulfill({ json: { data: [{ id: search || 'initial', title: search || 'Initial', originalName: 'fixture.png', format: 'image', mediaType: 'image/png', isArchived: false }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
 });
 if (!await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).isVisible()) await page.getByTestId('plant-card-plant-one').locator('.open-plant').click(); await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).getByLabel('Gérer les photos', { exact: true }).click(); await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
 const picker = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
 await expect(picker.getByRole('button', { name: 'Choisir Initial', exact: true })).toBeVisible();
 await picker.getByRole('searchbox').fill('slow'); await expect.poll(() => slowStarted).toBe(true);
 await picker.getByRole('searchbox').fill('fresh'); await expect(picker.getByRole('button', { name: 'Choisir fresh', exact: true })).toBeVisible();
 release(); await expect(picker.getByRole('button', { name: 'Choisir slow', exact: true })).toHaveCount(0);
 await picker.getByRole('searchbox').fill('retry'); await expect(picker.getByRole('alert')).toBeVisible();
 await picker.getByRole('button', { name: 'Réessayer', exact: true }).click();
 await expect(picker.getByRole('button', { name: 'Choisir retry', exact: true })).toBeVisible();
 await picker.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(picker).toHaveCount(0);
 if (!await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).isVisible()) await page.getByTestId('plant-card-plant-one').locator('.open-plant').click(); await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).getByLabel('Gérer les photos', { exact: true }).click(); await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
 await expect(picker.getByRole('searchbox')).toHaveValue('');
 await picker.getByRole('button', { name: 'Fermer le formulaire', exact: true }).click();
 await expect(picker).toHaveCount(0); expect(mutations).toHaveLength(0);
});



const consumptionLocation = { type: 'site', id: 'site-one', site: { id: 'site-one', name: 'Fictional Site' }, room: null, zone: null };
function consumptionProduct(id: string, unit: string, quantity: string) { return { id, name: `Synthetic ${id}`, unit, quantity, available: true, balances: [{ id: `${id}-balance`, quantity: '100.000', location: consumptionLocation }] }; }

test('watering prefills stage volume and doses, checks stock and sends actual edited consumption', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.route('**/api/v1/plants/*/consumables', route => route.fulfill({ json: { data: { waterLiters: '1.5', canConsume: true, products: [consumptionProduct('fertilizer', 'ml', '2.333')], container: null, currentContainer: null } } }));
 await page.getByTestId('plant-card-plant-one').locator('button[data-action-icon="water"]').click();
 const dialog = page.locator('.form-dialog'); await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('with');
 const stock = dialog.getByTestId('watering-fertilizers'); await expect(stock.getByLabel('Volume d’eau (L par plante)')).toHaveValue('1.5');
 if (process.env.MODAL_REVIEW) {
  for (const width of [1280, 390]) { await page.setViewportSize({ width, height: 1000 }); await dialog.screenshot({ path: test.info().outputPath(`canabicultarr-modal-watering-${width}.png`), animations: 'disabled' }); }
  await page.setViewportSize({ width: 1280, height: 900 });
 }
 await expect(stock).toContainText('3.500 ml');
 await stock.getByLabel('Dosage (ml/L)', { exact: true }).fill('200');
 await dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }).click(); await expect(stock.page().getByRole('alert').filter({ hasText: 'Stock insuffisant' }).first()).toContainText('Stock insuffisant'); expect(mutations).toHaveLength(0);
 await stock.getByLabel('Dosage (ml/L)', { exact: true }).fill('3'); await stock.getByLabel('Volume d’eau (L par plante)').fill('2');
 await expect(stock).toContainText('6.000 ml');
 let conflict = true;
 await page.route('**/api/v1/plants/plant-one/waterings', route => { if (conflict) { conflict = false; return route.fulfill({ status: 409, json: { error: { code: 'plants.errors.conflict' } } }); } return route.fallback(); });
 await dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }).click();
 await dialog.getByRole('button', { name: 'Recharger les plantes', exact: true }).click();
 await expect(stock.getByLabel('Dosage (ml/L)', { exact: true })).toHaveValue('3');
 await expect(stock.getByLabel('Volume d’eau (L par plante)')).toHaveValue('2');
 await dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }).click(); await expect(dialog).toHaveCount(0);
 expect(mutations[0]?.input).toMatchObject({ with_products: true, water_liters: '2', fertilizers: [{ inventory_item_id: 'fertilizer', balance_id: 'fertilizer-balance', dose_per_liter: '3', unit: 'ml' }] }); expect(errors).toEqual([]);
});

test('repotting proposes substrate and pot defaults and requires the previous pot disposition', async ({ page }, testInfo) => {
 const stages = structuredClone(lifecycleStages) as any[];
 const action = stages.find(stage => stage.id === 'growth').actions[0]; action.substrates = [{ inventory_item_id: 'substrate', quantity: '5' }]; action.container_inventory_item_id = 'pot';
 const { errors, mutations } = await session(page, true, false, {}, 1, stages);
 await page.route('**/api/v1/plants/*/consumables?**', route => route.fulfill({ json: { data: { waterLiters: null, canConsume: true, products: [consumptionProduct('substrate', 'l', '5')], container: { ...consumptionProduct('pot', 'piece', '1'), capacityLiters: '7' }, currentContainer: { inventoryItemId: 'old-pot', name: 'Old pot', capacityLiters: '3' } } } }));
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: 'Rempoter avant la floraison — Fictional Plant', exact: true }).click();
 const dialog = page.locator('.form-dialog'); const stock = dialog.getByTestId('action-substrates');
 const stockTab = dialog.getByRole('tab', { name: 'Stock', exact: true });
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(dialog.getByRole('tab', { name: 'Pièces jointes', exact: true })).toBeInViewport({ ratio: 1 });
 await expect(stock).toBeHidden(); await stockTab.click();
 await expect(stock.getByLabel('Quantité (l par plante)')).toHaveValue('5'); await expect(stock).toContainText('Synthetic pot · 7 L');
 if (process.env.MODAL_REVIEW) {
  await dialog.screenshot({ path: testInfo.outputPath('repot-stock-mobile.png'), animations: 'disabled' });
 }
 await dialog.getByRole('tab', { name: 'Commentaire', exact: true }).click();
 await dialog.getByRole('button', { name: 'Enregistrer l’action', exact: true }).click();
 await expect(stockTab).toHaveAttribute('aria-selected', 'true'); await expect(stock.page().getByRole('alert').filter({ hasText: 'récupérer ou jeter' }).first()).toContainText('récupérer ou jeter'); expect(mutations).toHaveLength(0);
 await stock.getByLabel('Ancien pot', { exact: true }).and(stock.locator('select')).selectOption('recover'); await stock.getByLabel('Quantité (l par plante)').fill('6');
 await dialog.getByRole('button', { name: 'Enregistrer l’action', exact: true }).click(); await expect(dialog).toHaveCount(0);
 expect(mutations[0]?.input).toMatchObject({ action: 'repot_flowering', substrates: [{ inventory_item_id: 'substrate', balance_id: 'substrate-balance', quantity: '6', unit: 'l' }], container: { inventory_item_id: 'pot', balance_id: 'pot-balance', previous: 'recover' } }); expect(errors).toEqual([]);
});

test('cycle recipes persist fertilizer doses, water volume, substrates and pot model', async ({ page }) => {
 const { errors } = await session(page); const writes: any[] = []; const stages = structuredClone(lifecycleStages);
 const catalog = [{ id: 'fertilizer', type: 'fertilizer', name: 'Synthetic fertilizer', unit: 'ml', defaultDosePerLiter: '2.500' }, { id: 'substrate', type: 'substrate', name: 'Synthetic substrate', unit: 'l' }, { id: 'pot', type: 'container', name: 'Synthetic pot', unit: 'piece', capacityLiters: '7' }];
 await page.route('**/api/v1/inventory/items?**', route => { const type = new URL(route.request().url()).searchParams.get('type'); return route.fulfill({ json: { data: catalog.filter(row => row.type === type), meta: { current_page: 1, last_page: 1, total: 1, per_page: 100 } } }); });
 await page.route('**/api/v1/lifecycle-settings', route => { if (route.request().method() === 'PATCH') { const input = route.request().postDataJSON(); writes.push(input); return route.fulfill({ json: { data: { ...input, version: 2, canManage: true, icons: lifecycleIcons, wateringDefaults: { alertDays: 3, moistureThreshold: 35 } } } }); } return route.fulfill({ json: { data: { stages, icons: lifecycleIcons, durations, version: 1, canManage: true } } }); });
 await openModule(page, 'cycleSettings'); await page.locator('[data-stage="growth"]').getByRole('button', { name: 'Modifier l’étape', exact: true }).click();
 const dialog = page.locator('.form-dialog'); await dialog.getByRole('tab', { name: 'Arrosage', exact: true }).click(); await dialog.getByLabel('Volume d’eau par défaut (L par plante)').fill('2.5');
 await dialog.getByRole('button', { name: 'Ajouter une période', exact: true }).click();
 await dialog.getByLabel('Nom de la période (facultatif)').fill('First feeding');
 await dialog.getByLabel('Unité', { exact: true }).and(dialog.locator('select')).selectOption('weeks');
 await dialog.getByLabel('À partir de', { exact: true }).fill('1');
 await dialog.getByLabel('Jusqu’à', { exact: true }).fill('2');
 await dialog.getByLabel('Ajouter un fertilisant', { exact: true }).and(dialog.locator('select')).selectOption('fertilizer'); await expect(dialog.getByLabel('Synthetic fertilizer · Dosage (ml/L)', { exact: true })).toHaveValue('2.500'); await dialog.getByLabel('Synthetic fertilizer · Dosage (ml/L)', { exact: true }).fill('4');
 if (process.env.FERTILIZER_VISUAL_REVIEW) {
  for (const width of [1280, 390]) { await page.setViewportSize({ width, height: 1000 }); await dialog.screenshot({ path: test.info().outputPath(`cana42-periods-${width}.png`), animations: 'disabled' }); }
  await page.setViewportSize({ width: 1280, height: 900 });
 }
 await dialog.getByRole('tab', { name: /^Actions/ }).click();
 await dialog.getByLabel('Ajouter un substrat', { exact: true }).and(dialog.locator('select')).selectOption('substrate'); await dialog.getByLabel('Synthetic substrate · Quantité (l par plante)', { exact: true }).fill('7');
 await dialog.getByLabel('Pot par défaut', { exact: true }).and(dialog.locator('select')).selectOption('pot');
 await dialog.getByRole('button', { name: 'Valider l’étape', exact: true }).click(); await expect(dialog).toHaveCount(0);
 await expect.poll(() => writes.length).toBe(1);
 const growth = writes[0].stages.find((stage: any) => stage.id === 'growth'); expect(growth.water_liters).toBe('2.5'); expect(growth.fertilizer_periods).toEqual([expect.objectContaining({ name: 'First feeding', unit: 'weeks', start: 1, end: 2, products: [{ inventory_item_id: 'fertilizer', dose_per_liter: '4' }] })]); expect(growth.actions[0]).toMatchObject({ substrates: [{ inventory_item_id: 'substrate', quantity: '7' }], container_inventory_item_id: 'pot' }); expect(errors).toEqual([]);
});

test('batch watering applies the stage recipe per plant and preserves doses on retry', async ({ page }) => {
 const { errors, writes, state } = await batchSession(page, ['growth', 'growth', 'maturation', 'growth']);
 await page.route('**/api/v1/plants/*/consumables', route => route.fulfill({ json: { data: { waterLiters: '2', canConsume: true, products: [consumptionProduct('fertilizer', 'ml', '3')], container: null, currentContainer: null } } }));
 const toolbar = page.getByRole('region', { name: 'Actions groupées' });
 await page.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' }).click();
 await toolbar.getByRole('button', { name: 'Arrosage', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Arrosage groupé', exact: true });
 await dialog.getByLabel('Fertilisants utilisés').and(dialog.locator('select')).selectOption('with');
 const stock = dialog.getByTestId('watering-fertilizers'); await expect(stock).toHaveCount(1);
 await expect(stock.getByLabel('Volume d’eau (L par plante)')).toHaveValue('2');
 await stock.getByLabel('Dosage (ml/L)', { exact: true }).fill('4');
 await expect(stock).toContainText('8.000 ml');
 if (process.env.STOCK_VISUAL_REVIEW) {
  await page.waitForTimeout(250);
  await page.screenshot({ path: test.info().outputPath('canabicultarr-stock-desktop.png') });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: test.info().outputPath('canabicultarr-stock-mobile.png') });
 }
 state.failId = 'batch-1';
 await dialog.getByRole('button', { name: 'Appliquer à 2 plantes' }).click();
 await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(1); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(1);
 const firstAttempt = writes[1]!.input;
 await dialog.getByRole('button', { name: 'Réessayer les plantes en échec' }).click();
 await expect(dialog.locator('.batch-results > li[data-state=done]')).toHaveCount(2); await expect(dialog.locator('.batch-results > li[data-state=failed]')).toHaveCount(0);
 expect(writes.map(row => row.id)).toEqual(['batch-0', 'batch-1', 'batch-1']);
 expect(writes.every(row => row.input.water_liters === '2')).toBe(true);
 expect(writes[0]!.input.fertilizers).toEqual([{ inventory_item_id: 'fertilizer', balance_id: 'fertilizer-balance', dose_per_liter: '4', unit: 'ml' }]);
 expect(writes[2]!.input).toEqual(firstAttempt); expect(errors).toEqual([]);
});

test('pump control starts once, resumes unfinished watering, stops manually and records fertilizer choice', async ({ page }) => {
 const { errors, mutations } = await session(page, true, false, { devices: [{ id: 'pump-one', name: 'Synthetic pump', status: 'active', category: 'pump', canActivate: true }] });
 const activation = { id: 'synthetic-activation', plantId: 'plant-one', occurredAt: '2026-11-10T02:00:00Z', mode: 'manual', stopDueAt: null, stoppedAt: null as string | null, stopStatus: null as string | null };
 let starts = 0, stops = 0;
 await page.route('**/api/v1/assets/pump-one/**', route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/pump-context')) return route.fulfill({ json: { data: { plantsMeta: { current_page: 1, last_page: 1, total: 1, per_page: 20 }, plants: [{ id: 'plant-one', name: 'Fictional Plant', activation: starts && !mutations.length ? activation : null }], stoppable: starts && !stops ? activation : null, running: starts && !stops ? activation : null } } });
  if (path.endsWith('/pump-status')) return route.fulfill({ json: { data: { stoppable: starts && !stops ? activation : null, running: starts && !stops ? activation : null } } });
  if (path.endsWith('/activate')) { starts++; return route.fulfill({ json: { data: activation } }); }
  if (path.endsWith('/stop')) { stops++; activation.stoppedAt = '2026-11-10T02:01:00Z'; activation.stopStatus = 'confirmed'; return route.fulfill({ json: { data: activation } }); }
  throw new Error(`Unexpected pump route ${path}`);
 });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const record = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await record.locator('.plant-hardware-details > summary').click();
 await record.getByRole('button', { name: 'Commander Synthetic pump', exact: true }).click();
 const control = page.getByRole('dialog', { name: 'Synthetic pump', exact: true });
 await control.getByRole('button', { name: 'Démarrer', exact: true }).click();
 await expect(control.getByRole('button', { name: 'Déclarer l’arrosage', exact: true })).toBeVisible();
 await expect(page.getByTestId('watering-form')).toHaveCount(0);
 await control.getByRole('button', { name: 'Déclarer l’arrosage', exact: true }).click();
 await expect(page.getByTestId('watering-form')).toBeVisible();
 const watering = page.locator('.form-dialog').filter({ has: page.getByTestId('watering-form') });
 await expect(page.getByRole('status').filter({ hasText: 'Commande de démarrage envoyée' }).first()).toBeVisible();
 if (process.env.PUMP_VISUAL_REVIEW) {
  await page.waitForTimeout(250); await page.screenshot({ path: test.info().outputPath('canabicultarr-pump-desktop.png') });
  await page.setViewportSize({ width: 390, height: 844 }); await page.waitForTimeout(300); await page.screenshot({ path: test.info().outputPath('canabicultarr-pump-mobile.png') });
 }
 expect(starts).toBe(1); expect(mutations).toHaveLength(0);
 await watering.getByRole('button', { name: 'Annuler l’arrosage', exact: true }).click();
 await expect(control.getByRole('button', { name: 'Déclarer l’arrosage', exact: true })).toBeVisible();
 await control.getByRole('button', { name: 'Déclarer l’arrosage', exact: true }).click();
 await expect(page.getByTestId('watering-form')).toBeVisible(); expect(starts).toBe(1);
 await watering.getByRole('button', { name: 'Arrêter', exact: true }).click(); await expect.poll(() => stops).toBe(1);
 await watering.getByLabel('Fertilisants utilisés').and(watering.locator('select')).selectOption('without');
 await watering.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }).click();
 await expect.poll(() => mutations.length).toBe(1);
 expect(mutations[0]!.input).toMatchObject({ request_id: activation.id, with_products: false, occurred_at: activation.occurredAt });
 expect(starts).toBe(1); expect(errors).toEqual([]);
});

test('pump configuration selects an integration control and timer duration', async ({ page }) => {
 const { errors } = await session(page);
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'reader', name: 'Fictional User', email: 'reader@example.test', permissions: ['administration.view', 'assets.manage', 'sensors.manage'], isActive: true, oidcLinked: false } } }));
 await page.reload(); await expect(page.getByTestId('plant-home')).toBeVisible();
 const asset = { id: 'pump-one', category: 'pump', name: 'Synthetic pump', status: 'active', assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null, location: null, plants: [], channelsCount: 0, enabledChannelsCount: 0, pumpControl: null };
 const writes: any[] = [];
 await page.route('**/api/v1/assets**', route => {
  if (route.request().method() === 'PATCH') { const input = route.request().postDataJSON(); writes.push(input); return route.fulfill({ json: { data: { ...asset, pumpControl: input.pump_control } } }); }
  return route.fulfill({ json: { data: [asset], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
 });
 await page.route('**/api/v1/pump-targets?**', route => route.fulfill({ json: { data: [{ id: 'switch.synthetic_pump', name: 'Synthetic pump switch', disabled: false }] } }));
 await openModule(page, 'assets'); await page.getByRole('button', { name: 'Modifier Synthetic pump', exact: true }).click();
 const dialog = page.locator('.form-dialog'); await dialog.getByRole('tab', { name: 'Commandes', exact: true }).click(); const settings = dialog.getByTestId('pump-settings');
 await settings.getByLabel('Connexion', { exact: true }).and(settings.locator('select')).selectOption('home_assistant');
 await settings.getByLabel('Commande', { exact: true }).and(settings.locator('select')).selectOption('switch.synthetic_pump');
 await settings.getByLabel('Mode de fonctionnement').and(settings.locator('select')).selectOption('timer');
 await settings.getByLabel('Durée (secondes)').fill('45');
 if (process.env.PUMP_VISUAL_REVIEW) {
  await page.waitForTimeout(250); await page.screenshot({ path: test.info().outputPath('canabicultarr-pump-settings.png') });
 }
 await dialog.getByRole('button', { name: /^(?:Enregistrer la fiche|Créer)$/, exact: true }).click();
 await expect.poll(() => writes.length).toBe(1);
 expect(writes[0].pump_control).toEqual({ provider: 'home_assistant', entity: 'switch.synthetic_pump', mode: 'timer', seconds: 45 }); expect(errors).toEqual([]);
});

test('plant history retains its disclosure state and displays typed historical actions with linked hardware', async ({ page }) => {
 const { errors } = await session(page, true, false, { devices: [
  { id: 'sensor-one', name: 'Soil moisture sensor', status: 'active', category: 'sensor' },
  { id: 'fan-one', name: 'Circulation fan', status: 'maintenance', category: 'fan' },
  { id: 'pump-one', name: 'Irrigation pump', status: 'active', category: 'pump', canActivate: true },
 ], measurements: [
  { sensorId: 'soil-one', sensorName: 'Soil moisture', device: { id: 'sensor-one', name: 'Soil moisture sensor' }, measurementType: { name: 'Soil moisture', nature: 'substrate_moisture', decimalPlaces: 0 }, value: '42', unit: '%', observedAt: '2026-11-05T10:00:00Z', isEnabled: true, isStale: false },
  { sensorId: 'battery-one', sensorName: 'Battery', device: { id: 'sensor-one', name: 'Soil moisture sensor' }, measurementType: { name: 'Battery', nature: 'battery', decimalPlaces: 0 }, value: null, unit: '%', observedAt: null, isEnabled: true, isStale: false },
 ] });
 const historyRows = [
  { id: 'repot', kind: 'repot_growth', occurredAt: '2026-11-05T10:00:00Z', fromPhase: 'germination', toPhase: 'growth', icon: 'pot' },
  { id: 'plant', kind: 'plant', occurredAt: '2026-11-02T10:00:00Z', fromPhase: 'sowing', toPhase: 'germination', icon: 'sprout' },
  { id: 'sow', kind: 'sow', occurredAt: '2026-11-01T10:00:00Z', fromPhase: 'planned', toPhase: 'sowing', icon: 'seed' },
 ];
 await page.route('**/api/v1/plants/plant-one/events?**', route => route.fulfill({ json: { data: historyRows.map(row => ({ ...row, recordedAt: row.occurredAt, comment: null, attachments: [], snapshot: { lifecycle_action: { id: row.kind, name: null, icon: row.icon, target: row.toPhase } } })), meta: { current_page: 1, last_page: 1, total: 3, per_page: 20 } } }));
 const trigger = page.getByTestId('plant-card-plant-one').locator('.open-plant'); await trigger.click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 const history = detail.locator('.plant-history-details'); await expect(history).toHaveAttribute('open', '');
 await expect(history.locator('.timeline-heading strong')).toHaveText(['Rempoter avant la croissance', 'Planter', 'Démarrer le semis']);
 await expect(history.locator('.timeline-stage')).toHaveText(['Croissance', 'Germination', 'Semis']);
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const layout = await history.locator('.n-timeline-item').evaluateAll(items => items.map(item => {
   const marker = item.querySelector('.timeline-marker')!.getBoundingClientRect();
   const heading = item.querySelector('.timeline-heading')!.getBoundingClientRect();
   const row = item.getBoundingClientRect();
   return { gap: heading.left - marker.right, markerInside: marker.left >= row.left && marker.bottom <= row.bottom, headingInside: heading.right <= row.right + 1 };
  }));
  expect(layout.every(row => row.markerInside && row.headingInside && row.gap >= 8)).toBe(true);
 }

 await detail.locator('.plant-hardware-details > summary').click();
 await expect(detail.locator('.plant-hardware-list')).toContainText('Soil moisture sensor');
 await expect(detail.locator('.plant-hardware-list')).toContainText('Circulation fan');
 await expect(detail.locator('.plant-hardware-measurements')).toHaveText('1 mesure disponible');
 await expect(detail.locator('.plant-hardware-control button')).toBeEnabled();
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const hardware = detail.locator('.plant-hardware-details');
  const sections = await detail.locator('.plant-detail').evaluate(node => Array.from(node.children).filter(child => child.matches('.record-overview,.plant-readings,.plant-hardware-details,.plant-strain-details,.plant-history-details')).map(child => child.classList.contains('plant-readings') ? 'measurements' : child.classList.contains('record-overview') ? 'upcoming' : child.classList.contains('plant-hardware-details') ? 'hardware' : child.classList.contains('plant-strain-details') ? 'strain' : 'history'));
  expect(sections).toEqual(['upcoming', 'measurements', 'hardware', 'strain', 'history']);
  const widths = await detail.locator('.plant-detail').evaluate(node => ({ overview: node.querySelector('.record-overview')!.getBoundingClientRect().width, readings: node.querySelector(':scope > .plant-readings')!.getBoundingClientRect().width }));
  expect(Math.abs(widths.overview - widths.readings)).toBeLessThanOrEqual(1);
  expect(await hardware.evaluate(node => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
  if (process.env.PLANT_HARDWARE_REVIEW) await hardware.screenshot({ path: test.info().outputPath(`plant-hardware-${width}.png`) });
 }

 await history.locator('summary').click(); await expect(history).not.toHaveAttribute('open', '');
 await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0); await trigger.click();
 await expect(detail.locator('.plant-history-details')).not.toHaveAttribute('open', '');
 await detail.locator('.plant-history-details > summary').click(); await expect(detail.locator('.timeline-event')).toHaveCount(3);
 expect(errors).toEqual([]);
});

async function largeLocationCatalog(page: Page, includeStored = false) {
 const { errors, mutations, requests: allRequests } = await session(page);
 const calls: URL[] = []; const state = { fail: false };
 const zones: FixtureLocationNode[] = Array.from({ length: 2000 }, (_, index) => ({ id: `zone-${index}`, kind: 'zone', name: `Zone ${String(index).padStart(4, '0')}`, canSelect: true, children: [] }));
 if (includeStored) zones.push({ id: 'zone-one', kind: 'zone', name: 'ZZ Stored Zone', canSelect: true, children: [] });
 const tree: FixtureLocationNode[] = [{ id: 'site-one', kind: 'site', name: 'Fictional Site', canSelect: false, children: [{ id: 'room-one', kind: 'room', name: 'Fictional Room', canSelect: false, children: zones }] }];
 await page.route('**/api/v1/locations/options?*', route => {
  const url = new URL(route.request().url()); calls.push(url);
  if (state.fail) return route.fulfill({ status: 503, json: { error: { code: 'untrusted-provider-detail' } } });
  return route.fulfill({ json: locationOptionPage(tree, url) });
 });
 return { calls, state, errors, mutations, allRequests };
}

test('a large single-site plant selector pages and remotely searches descendants with retry inside its tree', async ({ page }) => {
 const { calls, state, errors, mutations, allRequests } = await largeLocationCatalog(page);
 await page.getByRole('button', { name: 'Ajouter une plante', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await expect(editor.locator('#plant-location-select')).toBeEnabled(); expect(calls).toHaveLength(1);
 await editor.locator('.location-tree-select').click(); const panel = page.locator('.n-tree-select-menu');
 await panel.getByRole('button', { name: 'Afficher plus', exact: true }).click();
 await expect.poll(() => calls.length).toBe(2); expect(calls.at(-1)?.searchParams.get('page')).toBe('2');
 const filter = panel.locator('input'); await filter.fill('Zone 1999');
 await expect(panel.getByRole('treeitem', { name: 'Zone 1999', exact: true })).toBeVisible();
 state.fail = true; await filter.fill('Zone 1988');
 const alert = panel.getByRole('alert'); await expect(alert).toBeVisible(); await expect(alert).not.toContainText('untrusted-provider-detail');
 state.fail = false; await alert.getByRole('button', { name: 'Recharger', exact: true }).click();
 const choice = panel.getByRole('treeitem', { name: 'Zone 1988', exact: true }); await expect(choice).toBeVisible();
 await choice.getByText('Zone 1988', { exact: true }).click();
 await expect(editor.getByTestId('plant-location')).toHaveText('Fictional Site / Fictional Room / Zone 1988');
 expect(allRequests.some(path => path.includes('/locations/tree'))).toBe(false);
 expect(errors).toEqual([]); expect(mutations).toEqual([]);
});

test('mobile location catalogs restore off-page choices and retain disabled navigation ancestors', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 const { calls, errors } = await largeLocationCatalog(page, true);
 await page.getByTestId('plant-card-plant-one').locator('.plant-edit').click();
 const editor = page.getByRole('dialog', { name: 'Modifier la plante', exact: true });
 const select = editor.getByTestId('plant-location');
 await expect(select).toHaveText('Fictional Site / Fictional Room / ZZ Stored Zone');
 expect(calls).toHaveLength(2);
 expect(calls.at(-1)?.searchParams.get('search')).toBe('zone:zone-one');
 await select.click();
 const panel = editor.locator('.n-tree-select-menu');
 expect(await panel.getByRole('treeitem').count()).toBeLessThan(30);
 await expect(panel.getByRole('treeitem', { name: 'Fictional Site', exact: true })).toHaveAttribute('aria-disabled', 'true');
 await expect(panel.getByRole('treeitem', { name: 'Fictional Room', exact: true })).toHaveAttribute('aria-disabled', 'true');
 await panel.getByRole('textbox').fill('Zone 1999');
 await panel.getByRole('treeitem', { name: 'Zone 1999', exact: true }).getByText('Zone 1999', { exact: true }).click();
 await expect(select).toHaveText('Fictional Site / Fictional Room / Zone 1999');
 expect(errors).toEqual([]);
});


test('care uploads preview before sending and retry only failed files while protecting pending drafts', async ({ page }) => {
 const { errors, mutations } = await session(page);
 const sent: string[] = []; let fail = true;
 await page.route('**/api/v1/documents', async route => {
  if (route.request().method() !== 'POST') return route.fallback();
  const body = route.request().postDataBuffer()!.toString(); const name = body.includes('second.txt') ? 'second.txt' : 'first.txt'; sent.push(name);
  if (name === 'second.txt' && fail) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  return route.fulfill({ status: 201, json: { data: { id: name, title: name, originalName: name, mediaType: 'text/plain', sizeBytes: 8 } } });
 });
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: /^Enregistrer un arrosage pour / }).click();
 const dialog = page.getByRole('dialog', { name: /arrosage/i });
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without');
 await expect(dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true })).toBeEnabled();
 await dialog.getByRole('tab', { name: 'Pièces jointes', exact: true }).click();
 // Inspect locally created previews without a fetch blocked by the production CSP.
 const previewBlobs = await page.evaluateHandle(() => {
  const blobs = new Map<string, Blob>(); const create = URL.createObjectURL.bind(URL); const revoke = URL.revokeObjectURL.bind(URL);
  URL.createObjectURL = blob => { const url = create(blob); if (blob instanceof Blob) blobs.set(url, blob); return url; };
  URL.revokeObjectURL = url => { blobs.delete(url); revoke(url); }; return blobs;
 });
 const picker = dialog.getByTestId('upload-selection');
 await picker.locator('input[type=file]').first().setInputFiles({ name: 'preview.pdf', mimeType: 'application/pdf', buffer: previewPdf() });
 await expect(picker.locator('iframe')).toHaveCount(0);
 await picker.getByRole('button', { name: 'Aperçu de preview.pdf', exact: true }).click();
 const previewDialog = page.getByRole('dialog', { name: 'preview.pdf', exact: true });
 const pdf = previewDialog.getByTitle('Aperçu de preview.pdf');
 await expect(pdf).toBeVisible();
 const pdfUrl = (await pdf.getAttribute('src'))!;
 expect(await previewBlobs.evaluate(async (blobs, url) => (await blobs.get(url)?.text())?.startsWith('%PDF-'), pdfUrl)).toBe(true);
 if (process.env.UPLOAD_PDF_SCREENSHOT) { await pdf.scrollIntoViewIfNeeded(); await page.waitForTimeout(1000); await page.screenshot({ path: process.env.UPLOAD_PDF_SCREENSHOT, animations: 'disabled' }); }
 await previewDialog.getByRole('button', { name: 'Fermer l’aperçu', exact: true }).click();
 await expect(previewDialog).toHaveCount(0);
 await picker.getByRole('button', { name: 'Retirer preview.pdf', exact: true }).click();
 await expect(pdf).toHaveCount(0);
 await expect.poll(() => previewBlobs.evaluate((blobs, url) => blobs.has(url), pdfUrl)).toBe(false);
 await picker.locator('input[type=file]').first().setInputFiles([{ name: 'first.txt', mimeType: 'text/plain', buffer: Buffer.from('First preview') }, { name: 'second.txt', mimeType: 'text/plain', buffer: Buffer.from('Second preview') }]);
 await expect(picker.locator('.upload-text-thumbnail').first()).toContainText('First preview');
 await picker.getByRole('button', { name: 'Aperçu de first.txt', exact: true }).click();
 const textPreview = page.getByRole('dialog', { name: 'first.txt', exact: true });
 await expect(textPreview.locator('pre')).toHaveText('First preview');
 await textPreview.getByRole('button', { name: 'Fermer l’aperçu', exact: true }).click();
 if (process.env.UPLOAD_CARDS_SCREENSHOT) { await picker.scrollIntoViewIfNeeded(); await page.waitForTimeout(250); await page.screenshot({ path: process.env.UPLOAD_CARDS_SCREENSHOT, animations: 'disabled' }); }
 expect(sent).toEqual([]);
 await expect(dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true })).toBeDisabled();
 await dialog.getByRole('button', { name: 'Ajouter des fichiers', exact: true }).click();
 await expect(picker.locator('[data-upload-state=done]')).toHaveCount(1);
 await expect(picker.locator('[data-upload-state=error]')).toHaveCount(1);
 fail = false;
 await dialog.getByRole('button', { name: 'Ajouter des fichiers', exact: true }).click();
 await expect(picker.locator('[data-upload-state=done]')).toHaveCount(2);
 expect(sent).toEqual(['first.txt', 'second.txt', 'second.txt']);
 await picker.getByRole('button', { name: 'Retirer first.txt', exact: true }).click();
 await expect(picker.locator('li')).toHaveCount(1);
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without');
 await dialog.getByRole('button', { name: 'Enregistrer l’arrosage', exact: true }).click();
 await expect.poll(() => mutations.at(-1)?.input.document_ids).toEqual(['second.txt']);
 expect(errors).toEqual([]);
});

test('collection layouts remember independent row views and preserve record actions without changing Dashboard', async ({ page }) => {
 const { errors, requests, preferences } = await session(page, true, false, {}, 3);
 await expect(page.getByRole('group', { name: 'Affichage', exact: true })).toHaveCount(0);
 await openModule(page, 'plants');
 const grid = page.locator('.collection-grid');
 await expect(grid).toHaveAttribute('data-view', 'cards');
 const listRequests = () => requests.filter(request => request === 'GET /api/v1/plants').length;
 const before = listRequests();
 await page.getByRole('button', { name: 'Tableau', exact: true }).click();
 await expect(grid).toHaveAttribute('data-view', 'rows');
 await expect.poll(() => preferences.values()['layout:plants:mode']).toBe('rows');
 const table = page.getByRole('table', { name: 'Liste', exact: true });
 await expect(table.getByRole('columnheader', { name: 'Nom', exact: true })).toBeVisible();
 await expect(table.getByRole('columnheader', { name: 'Variété', exact: true })).toHaveCount(0);
 await expect(table.getByRole('columnheader', { name: 'Étape', exact: true })).toHaveCount(0);
 await expect(table.getByRole('row')).toHaveCount(4);
 expect(listRequests()).toBe(before);
 const plantRow = table.getByRole('row').filter({ has: page.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true }) });
 const identity = plantRow.getByRole('cell').nth(1);
 for (const badge of ['Croissance', 'S2 · J8', 'Fictional Strain', 'Hybride']) await expect(identity).toContainText(badge);
 await plantRow.getByRole('checkbox').check();
 await expect(plantRow.getByRole('checkbox')).toBeChecked();
 await plantRow.getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 const edit = page.getByRole('dialog', { name: 'Modifier la plante', exact: true });
 await expect(edit).toBeVisible(); await edit.getByRole('button', { name: 'Annuler', exact: true }).click();
 await plantRow.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail).toBeVisible(); await detail.getByRole('button', { name: 'Fermer la fiche', exact: true }).click();
 await page.getByRole('button', { name: 'Cartes', exact: true }).click();
 await expect(grid).toHaveAttribute('data-view', 'cards');
 await page.getByRole('button', { name: 'Tableau', exact: true }).click();
 await expect(plantRow.getByRole('checkbox')).toBeChecked();
 if (process.env.COLLECTION_ROWS_SCREENSHOT) await page.screenshot({ path: process.env.COLLECTION_ROWS_SCREENSHOT, fullPage: true, animations: 'disabled' });
 await openModule(page, 'catalog');
 await expect(grid).toHaveAttribute('data-view', 'cards');
 await page.getByRole('button', { name: 'Tableau', exact: true }).click();
 await expect.poll(() => preferences.values()['layout:strains:mode']).toBe('rows');
 await expect(page.getByRole('columnheader', { name: 'Génétique', exact: true })).toHaveCount(0);
 await expect(page.getByRole('columnheader', { name: 'Statut', exact: true })).toHaveCount(0);
 await expect(page.getByRole('table').getByRole('row').nth(1)).toContainText('Hybride');
 if (process.env.COLLECTION_STRAINS_SCREENSHOT) await page.screenshot({ path: process.env.COLLECTION_STRAINS_SCREENSHOT, fullPage: true, animations: 'disabled' });
 await page.reload();
 await openModule(page, 'plants'); await expect(grid).toHaveAttribute('data-view', 'rows');
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 if (process.env.COLLECTION_MOBILE_SCREENSHOT) await page.screenshot({ path: process.env.COLLECTION_MOBILE_SCREENSHOT, fullPage: true, animations: 'disabled' });
 await openModule(page, 'home');
 await expect(page.getByRole('group', { name: 'Affichage', exact: true })).toHaveCount(0);
 expect(errors).toEqual([]);
});

test('collection layouts restore resource rows independently and safely ignore invalid stored views', async ({ page }) => {
 const { errors, preferences } = await session(page);
 preferences.values()['layout:plants:mode'] = 'unexpected';
 preferences.values()['layout:locations:site:mode'] = 'rows';
 await page.reload();
 await openModule(page, 'plants');
 await expect(page.locator('.collection-grid')).toHaveAttribute('data-view', 'cards');
 await openModule(page, 'locations');
 const table = page.getByRole('table', { name: 'Liste', exact: true });
 await expect(page.getByRole('button', { name: 'Tableau', exact: true })).toHaveAttribute('aria-pressed', 'true');
 await expect(table.getByRole('button', { name: 'Ouvrir Fictional Site', exact: true })).toBeVisible();
 if (process.env.COLLECTION_RESOURCE_SCREENSHOT) await page.screenshot({ path: process.env.COLLECTION_RESOURCE_SCREENSHOT, fullPage: true, animations: 'disabled' });
 await page.getByRole('button', { name: 'Cartes', exact: true }).click();
 await expect.poll(() => preferences.values()['layout:locations:site:mode']).toBe('cards');
 expect(errors).toEqual([]);
});

test('table headers order actions and plant cells include watering alerts and selected readings', async ({ page }) => {
 const measurement: PlantMeasurement = { sensorId: 'sensor-0', sensorName: 'Moisture one', device: { id: 'device-0', name: 'Fictional probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture', decimalPlaces: 1 }, value: '25', unit: '%', observedAt: '2026-11-09T11:00:00Z', isEnabled: true, isStale: false };
 const { errors } = await session(page, true, true, { measurements: [measurement, { ...measurement, sensorId: 'hidden', sensorName: 'Hidden reading' }, { ...measurement, sensorId: 'empty', sensorName: 'Empty reading', value: null }], summarySensorIds: ['sensor-0', 'empty'] });
 await page.route('**/api/v1/**', async route => {
  const url = new URL(route.request().url());
  if (url.pathname.includes('/reports/sensors/')) return route.fulfill({ json: { data: insightReport(url) } });
  if (url.pathname.endsWith('/sensors')) return route.fulfill({ json: { data: insightSensors, meta: insightMeta } });
  if (url.pathname.endsWith('/sensors/sensor-0/measurements')) return route.fulfill({ json: { data: [], meta: { ...insightMeta, total: 0 }, summary: { sensor: insightSensors[0], latest: null, status: 'missing' } } });
  return route.fallback();
 });
 await openModule(page, 'plants');
 const header = page.locator('.plant-card .page-heading').first();
 await expect(header.getByRole('group', { name: 'Affichage', exact: true })).toHaveCount(1);
 const controls = header.locator('.page-actions').getByRole('button');
 await expect(controls).toHaveCount(5);
 expect(await controls.evaluateAll(buttons => buttons
  .map(button => ({ left: button.getBoundingClientRect().left, icon: button.getAttribute('data-action-icon') }))
  .sort((a, b) => a.left - b.left).map(button => button.icon)))
  .toEqual(['check', 'refresh', 'layoutGrid', 'table', 'plus']);
 await header.getByRole('button', { name: 'Tableau', exact: true }).click();
 const row = page.getByRole('table').getByRole('row').filter({ has: page.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true }) });
 await expect(row.getByTestId('watering-needed')).toContainText('Arrosage à effectuer');
 await expect(row.locator('summary')).toHaveCount(0);
 await expect(row.locator('details.plant-readings')).toHaveCount(0);
 const reading = row.getByRole('button', { name: 'Historique · Moisture one', exact: true });
 await expect(reading).toContainText('25'); await expect(reading.locator('time')).toHaveAttribute('datetime', measurement.observedAt!);
 await expect(row.getByText('Hidden reading', { exact: true })).toHaveCount(0); await expect(row.getByRole('button', { name: 'Historique · Empty reading', exact: true })).toContainText('Aucune mesure');
 await reading.click();
 const history = page.getByRole('dialog', { name: 'Historique · Moisture one', exact: true }); await expect(history).toBeVisible();
 await page.keyboard.press('Escape'); await expect(history).toHaveCount(0);
 if (process.env.COLLECTION_READINGS_SCREENSHOT) await page.screenshot({ path: process.env.COLLECTION_READINGS_SCREENSHOT, fullPage: true, animations: 'disabled' });
 expect(errors).toEqual([]);
});

test('nested catalogs keep their view controls only in the settings header', async ({ page }) => {
 const { errors } = await session(page);
 await openModule(page, 'catalogs');
 await page.getByRole('tab', { name: 'Types de mesures', exact: true }).click();
 const header = page.locator('.settings-heading');
 await expect(page.getByRole('group', { name: 'Affichage', exact: true })).toHaveCount(1);
 await expect(header.getByRole('button', { name: 'Tableau', exact: true })).toBeVisible();
 expect(await header.locator('.page-actions').getByRole('button').evaluateAll(buttons => buttons.map(button => button.getAttribute('data-action-icon')))).toEqual(['refresh', 'layoutGrid', 'table', 'plus']);
 const geometry = () => header.evaluate(element => {
  const title = element.querySelector('.page-title')!.getBoundingClientRect();
  const actions = element.querySelector('.page-actions')!.getBoundingClientRect();
  const bounds = element.getBoundingClientRect();
  return {
   sameRow: title.top < actions.bottom && actions.top < title.bottom,
   actionsBelow: actions.top >= title.bottom,
   insideHeader: actions.right <= bounds.right + 1 && actions.left >= bounds.left - 1,
   alignedRight: Math.abs(actions.right - bounds.right) <= 1,
  };
 });
 await expect.poll(geometry).toMatchObject({ sameRow: true, insideHeader: true, alignedRight: true });
 await page.setViewportSize({ width: 320, height: 900 });
 await expect.poll(geometry).toMatchObject({ actionsBelow: true, insideHeader: true, alignedRight: true });
 await page.getByRole('tab', { name: 'Types d’entretien', exact: true }).click();
 await expect(page.getByRole('group', { name: 'Affichage', exact: true })).toHaveCount(1);
 await expect(header.getByRole('button', { name: 'Cartes', exact: true })).toBeVisible();
 expect(errors).toEqual([]);
});

test.describe('single-line strain parentage', () => {
 test.use({ hasTouch: true });

test('strain parentage stays on one line with complete keyboard and mobile access', async ({ page }) => {
 const lineage = Array.from({ length: 18 }, (_, index) => `Fictional Parent ${index + 1}`).join(' × ');
 const { errors } = await session(page, true, false, {}, 1, lifecycleStages, lineage);
 await openModule(page, 'catalog');
 const card = page.locator('.strain-photo-card');
 const parents = card.locator('.strain-lineage');
 await expect(parents).toHaveText(lineage); await expect(parents).toHaveAttribute('title', lineage);
 expect(await parents.evaluate(element => element.scrollWidth > element.clientWidth)).toBe(true);
 expect(await parents.evaluate(element => { const range = document.createRange(); range.selectNodeContents(element); return new Set([...range.getClientRects()].map(rect => Math.round(rect.top))).size; })).toBe(1);
 const open = card.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true });
 await expect(open).toHaveAccessibleDescription(lineage);
 await open.focus(); await page.keyboard.press('Enter');
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true }); await expect(detail).toBeVisible();
 const read = detail.getByRole('button', { name: 'Lire les parents en entier', exact: true });
 await expect(read).toHaveAttribute('title', lineage); expect(await read.locator('span').evaluate(element => element.scrollWidth > element.clientWidth)).toBe(true);
 await read.focus(); await page.keyboard.press('Space');
 const full = page.getByRole('dialog', { name: 'Parents / lignée', exact: true });
 await expect(full).toBeVisible(); await expect(full).toContainText(lineage);
 await page.keyboard.press('Escape'); await expect(full).toHaveCount(0); await expect(detail).toBeVisible(); await expect(read).toBeFocused();
 await page.setViewportSize({ width: 390, height: 844 });
 await read.tap(); await expect(full).toBeVisible(); await expect(full).toContainText(lineage);
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 if (process.env.STRAIN_LINEAGE_SCREENSHOT) { await page.waitForTimeout(250); await page.screenshot({ path: process.env.STRAIN_LINEAGE_SCREENSHOT, animations: 'disabled' }); }
 await page.keyboard.press('Escape'); await expect(full).toHaveCount(0);
 expect(errors).toEqual([]);
});

});

test('strain cards omit photos while table photos and safe deletion remain available', async ({ page }) => {
 const { errors, mutations } = await session(page);
 let deletes = 0;
 await page.route('**/api/v1/strains/strain-one', route => {
  if (route.request().method() !== 'DELETE') return route.fallback();
  deletes++; expect(route.request().postDataJSON()).toHaveProperty('version', 2);
  return deletes === 1 ? route.fulfill({ status: 409, json: { error: { code: 'catalog.errors.inUse' } } }) : route.fulfill({ status: 204 });
 });
 await openModule(page, 'catalog');
 for (const mode of ['Cartes', 'Tableau']) {
  await page.getByRole('button', { name: mode, exact: true }).click();
  const row = mode === 'Cartes' ? page.getByTestId('strain-row-strain-one') : page.getByRole('row').filter({ has: page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }) });
  if (mode === 'Cartes') {
   await expect(row.getByRole('button', { name: 'Gérer les photos', exact: true })).toHaveCount(0);
   if (process.env.RECORD_CARD_REVIEW) await row.screenshot({ path: test.info().outputPath('canabicultarr-strain-card-final.png'), animations: 'disabled' });
  }
  else {
  const photoButton = row.getByRole('button', { name: 'Gérer les photos', exact: true });
  await expect(photoButton).toBeVisible();
  const editButton = row.getByRole('button', { name: 'Modifier Fictional Strain', exact: true });
  for (const width of [1280, 390]) {
   await page.setViewportSize({ width, height: 844 });
   await photoButton.scrollIntoViewIfNeeded();
   const photo = (await photoButton.boundingBox())!;
   const edit = (await editButton.boundingBox())!;
   const icon = (await photoButton.locator('svg').boundingBox())!;
   expect(photo.width).toBe(edit.width);
   expect(photo.height).toBe(edit.height);
   expect(Math.abs((photo.x + photo.width / 2) - (icon.x + icon.width / 2))).toBeLessThan(1);
   expect(Math.abs((photo.y + photo.height / 2) - (icon.y + icon.height / 2))).toBeLessThan(1);
   if (process.env.RECORD_CARD_REVIEW) await photoButton.locator('..').screenshot({ path: test.info().outputPath(`strain-table-actions-${width}.png`) });
  }
  await page.setViewportSize({ width: 1280, height: 844 });
  await photoButton.click();
  const photos = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
  await photos.getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
  await photos.getByRole('button', { name: 'Choisir Photo 2.png', exact: true }).click();
  await expect.poll(() => mutations.filter(item => item.input.photo_document_id === 'photo-two').length).toBe(1);
  await expect(page.getByRole('dialog')).toHaveCount(0);
  }
  await row.getByRole('button', { name: 'Supprimer Fictional Strain', exact: true }).click();
  const confirmation = page.getByRole('dialog', { name: 'Supprimer Fictional Strain ?', exact: true });
  await confirmation.getByRole('button', { name: 'Annuler', exact: true }).click();
  expect(deletes).toBe(0);
 }
 await page.getByRole('button', { name: 'Supprimer Fictional Strain', exact: true }).click();
 const confirmation = page.getByRole('dialog', { name: 'Supprimer Fictional Strain ?', exact: true });
 await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'Cette variété est utilisée par des plantes.' })).toBeVisible();
 await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
 await expect(confirmation).toHaveCount(0); expect(deletes).toBe(2);
 await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(detail.locator('.record-badges').getByRole('link', { name: 'Lien de référence' })).toHaveAttribute('href', 'https://example.test/strain');
 await expect(detail.getByRole('navigation').getByRole('link')).toHaveCount(0);
 if (process.env.RECORD_CARD_REVIEW) {
  for (const width of [1280, 390]) {
   await page.setViewportSize({ width, height: 900 });
   await detail.screenshot({ path: test.info().outputPath(`canabicultarr-strain-detail-${width}.png`), animations: 'disabled' });
  }
 }
 expect(errors).toEqual([]);
});


test('strain cards create plants with a preselected strain without requiring strain management', async ({ page }) => {
 const { errors, mutations } = await session(page, false);
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'reader', name: 'Fictional User', email: 'reader@example.test', role: 'member', permissions: ['plants.manage'], isActive: true, oidcLinked: false } } }));
 await page.route('**/api/v1/plants', route => {
  if (route.request().method() !== 'POST') return route.fallback();
  const input = route.request().postDataJSON(); mutations.push({ path: '/api/v1/plants', input });
  return route.fulfill({ status: 201, json: { data: { id: 'new-card-plant', name: input.name, phase: 'planned' } } });
 });
 await page.reload(); await openModule(page, 'catalog');
 const card = page.getByTestId('strain-row-strain-one');
 await expect(card.getByRole('button', { name: 'Modifier Fictional Strain', exact: true })).toHaveCount(0);
 const trigger = card.getByRole('button', { name: 'Ajouter une plante', exact: true });
 await trigger.click();
 const editor = page.getByRole('dialog', { name: 'Nouvelle plante', exact: true });
 await expect(editor.locator('#plant-strain-select')).toHaveValue('strain-one');
 await expect(page.getByRole('dialog', { name: 'Fictional Strain', exact: true })).toHaveCount(0);
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await expect(trigger).toBeFocused(); expect(mutations).toEqual([]);
 await trigger.click(); await editor.locator('#plant-name').fill('Created from strain card'); await editor.getByRole('switch').check();
 await editor.locator('.location-tree-select').click();
 await page.getByRole('treeitem', { name: 'Fictional Site', exact: true }).getByText('Fictional Site', { exact: true }).click();
 await editor.getByRole('button', { name: /^(?:Enregistrer la plante|Créer)$/, exact: true }).click();
 await expect(editor).toHaveCount(0); await expect(trigger).toBeFocused();
 await expect(page.getByRole('status').filter({ hasText: 'La plante a été créée.' })).toBeVisible();
 expect(mutations).toHaveLength(1); expect(mutations[0]!.input).toMatchObject({ strain_id: 'strain-one', name: 'Created from strain card', planned: true, location: { type: 'site', id: 'site-one' } });
 expect(errors).toEqual([]);
});


test('strain correction requires a reason, preserves the edit draft and displays its immutable history', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.route('**/api/v1/strains?*', route => route.fulfill({ json: { data: [{ id: 'strain-one', name: 'Fictional Strain' }, { id: 'strain-corrected', name: 'Corrected Strain' }], meta: { current_page: 1, last_page: 1, total: 2, per_page: 20 } } }));
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier la plante', exact: true });
 const reason = editor.locator('#plant-strain-change-reason');
 await expect(reason).toHaveCount(0);
 await editor.locator('#plant-name').fill('Corrected plant');
 await editor.locator('#plant-strain-select').selectOption('strain-corrected');
 await editor.getByRole('button', { name: 'Enregistrer la plante', exact: true }).click();
 await expect(reason).toBeFocused(); await expect(reason).toHaveAttribute('aria-invalid', 'true'); expect(mutations).toHaveLength(0);
 await reason.fill('Corrected identification from the seed label');
 await editor.locator('#plant-strain-select').selectOption('strain-one'); await expect(reason).toHaveCount(0);
 await editor.locator('#plant-strain-select').selectOption('strain-corrected');
 await expect(reason).toHaveValue('Corrected identification from the seed label');
 await expect(editor.locator('#plant-name')).toHaveValue('Corrected plant');
 if (process.env.MODAL_REVIEW) for (const width of [1280, 390]) {
  await page.setViewportSize({ width, height: 900 }); await editor.locator('.n-card-content').evaluate(el => { el.scrollTop = 0; }); await editor.screenshot({ path: test.info().outputPath(`canabicultarr-strain-correction-${width}.png`) });
 }
 await editor.getByRole('button', { name: 'Enregistrer la plante', exact: true }).click(); await expect(editor).toHaveCount(0);
 expect(mutations[0]?.input).toMatchObject({ version: 1, strain_id: 'strain-corrected', strain_change_reason: 'Corrected identification from the seed label', name: 'Corrected plant' });
 await page.route('**/api/v1/plants/plant-one/events?*', route => route.fulfill({ json: { data: [{ id: 'correction', kind: 'updated', fromPhase: 'growth', toPhase: 'growth', actorId: 'reader', occurredAt: '2026-11-14T12:00:00Z', recordedAt: '2026-11-14T12:00:00Z', fields: ['strain_id'], snapshot: { name: 'Corrected plant', strain_id: 'strain-corrected', location: null, previous_location: null, strain_change: { previous: { id: 'strain-one', name: 'Fictional Strain' }, current: { id: 'strain-corrected', name: 'Corrected Strain' }, reason: 'Corrected identification from the seed label' } }, comment: null, attachments: [], maintenance: null, watering: null }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Corrected plant', exact: true });
 const history = detail.locator('.plant-history-details');
 if (!await history.evaluate(el => (el as HTMLDetailsElement).open)) await history.locator(':scope > summary').click();
 await expect(detail.locator('.timeline-event')).toContainText('Variété modifiée');
 await expect(detail.locator('.timeline-event')).toContainText('Fictional Strain → Corrected Strain');
 await expect(detail.locator('.timeline-event')).toContainText('Corrected identification from the seed label');
 expect(errors).toEqual([]);
});


test('retired generic maintenance remains readable in existing plant history', async ({ page }) => {
 const { errors } = await session(page);
 await page.route('**/api/v1/plants/plant-one/events?*', route => route.fulfill({ json: { data: [{ id: 'historical-maintenance', kind: 'maintained', fromPhase: 'growth', toPhase: 'growth', actorId: 'reader', occurredAt: '2026-11-01T12:00:00Z', recordedAt: '2026-11-01T12:00:00Z', snapshot: { name: 'Fictional Plant', strain_id: 'strain-one', location: null, previous_location: null }, fields: [], comment: 'Historical maintenance record', maintenance: { type: 'other' }, attachments: [], watering: null }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const detail = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(detail.locator('.timeline-event')).toContainText('Autre entretien');
 await expect(detail.locator('.timeline-event')).toContainText('Historical maintenance record');
 expect(errors).toEqual([]);
});


test('watering dates refresh untouched suggestions and preserve manual fertilizer corrections', async ({ page }) => {
 const { errors } = await session(page);
 const dates: (string | null)[] = [];
 await page.route('**/api/v1/plants/*/consumables*', route => {
  const at = new URL(route.request().url()).searchParams.get('at'); dates.push(at);
  return route.fulfill({ json: { data: { waterLiters: '2', canConsume: true, products: [consumptionProduct('fertilizer', 'ml', at ? '3' : '2')], container: null, currentContainer: null } } });
 });
 await page.getByTestId('plant-card-plant-one').locator('button[data-action-icon="water"]').click();
 const dialog = page.locator('.form-dialog'); await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('with');
 const stock = dialog.getByTestId('watering-fertilizers');
 await expect(stock.getByLabel('Dosage (ml/L)', { exact: true })).toHaveValue('2');
 await dialog.locator('input[type="datetime-local"]').fill('2026-11-08T12:00');
 await expect(stock.getByLabel('Dosage (ml/L)', { exact: true })).toHaveValue('3');
 await stock.getByLabel('Dosage (ml/L)', { exact: true }).fill('4');
 await expect(stock).toContainText('8.000 ml');
 await dialog.locator('input[type="datetime-local"]').fill('2026-11-09T12:00');
 await expect(stock).toContainText('Vos quantités ajustées sont conservées');
 await expect(stock.getByLabel('Dosage (ml/L)', { exact: true })).toHaveValue('4');
 expect(dates).toHaveLength(2);
 expect(dates[1]).toContain('2026-11-08');
 expect(errors).toEqual([]);
});


for (const canManage of [false, true]) test(`dashboard separates rooms from filters with batch controls ${canManage ? 'visible' : 'hidden'}`, async ({ page }) => {
 const { errors } = await session(page, canManage);
 const dashboard = page.getByTestId('plant-home');
 const toolbar = dashboard.locator('.batch-toolbar');
 const selectAll = dashboard.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' });
 await expect(selectAll).toHaveCount(canManage ? 1 : 0);
 await expect(toolbar).toHaveCount(0);
 if (canManage) await selectAll.click();
 await expect(toolbar).toHaveCount(canManage ? 1 : 0);
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const filters = dashboard.locator('.dashboard-tools');
  await expect(filters).toBeVisible();
  const gap = await dashboard.locator('.home-room').first().evaluate(
   room => room.getBoundingClientRect().top - document.querySelector('.dashboard-tools')!.getBoundingClientRect().bottom,
  );
  expect(gap).toBeCloseTo(12, 0);
  const metricsGap = await filters.evaluate(node => node.getBoundingClientRect().top - document.querySelector('.home-metrics')!.getBoundingClientRect().bottom);
  expect(metricsGap).toBeCloseTo(12, 0);
  if (canManage) {
   await expect(toolbar).toBeVisible();
   const selectionGap = await toolbar.evaluate(bar => document.querySelector('.home-metrics')!.getBoundingClientRect().top - bar.getBoundingClientRect().bottom);
   expect(selectionGap).toBeCloseTo(12, 0);
  }
 }
 expect(errors).toEqual([]);
});


for (const kind of ['detail', 'form'] as const) test(`long ${kind} dialogs scroll their content without moving the header`, async ({ page }) => {
 const { errors } = await session(page);
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: kind === 'form' ? 420 : 600 });
  await page.getByTestId('plant-card-plant-one').locator(kind === 'detail' ? '.open-plant' : '.plant-edit').click();
  const dialog = page.getByRole('dialog', { name: kind === 'detail' ? 'Fictional Plant' : 'Modifier la plante', exact: true });
  const content = dialog.locator(':scope > .n-card-content');
  await expect(dialog).toBeVisible();
  if (kind === 'form') await dialog.getByRole('tab', { name: 'Notes', exact: true }).click();
  await expect.poll(() => content.evaluate(node => node.scrollHeight - node.clientHeight)).toBeGreaterThan(0);
  await content.hover(); await page.mouse.wheel(0, 500);
  await expect.poll(() => content.evaluate(node => node.scrollTop)).toBeGreaterThan(0);
  await expect(dialog.locator(':scope > .n-card-header')).toBeInViewport();
  if (kind === 'form') await expect(dialog.locator(':scope > .n-card__footer')).toBeInViewport();
  await content.evaluate(node => node.scrollTo(0, node.scrollHeight));
  await expect.poll(() => content.evaluate(node => node.scrollHeight - node.clientHeight - node.scrollTop)).toBeLessThanOrEqual(1);
  await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0);
 }
 expect(errors).toEqual([]);
});


test('select options remain clickable beyond a short scrolling modal', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await page.setViewportSize({ width: 1440, height: 1000 });
 await page.getByTestId('plant-card-plant-one').getByRole('button', { name: /entretien/i }).click();
 const dialog = page.getByRole('dialog', { name: 'Entretien — Fictional Plant', exact: true });
 await dialog.evaluate(node => { node.style.maxHeight = '15rem'; });
 await dialog.locator('[data-ui-select="plant-maintenance-type"]').click();
 const popup = page.locator('[data-select-popup]');
 const option = popup.getByRole('option').last(); await expect(option).toBeVisible();
 const name = await option.getAttribute('aria-label');
 const hit = await option.evaluate(node => {
  const box = node.getBoundingClientRect(); const card = node.closest('.app-dialog')!.getBoundingClientRect();
  const x = box.x + box.width / 2; const y = box.y + box.height / 2;
  return { outside: y > card.bottom, clickable: node.contains(document.elementFromPoint(x, y)) };
 });
 expect(hit.outside).toBe(true); expect(hit.clickable).toBe(true);
 await option.click();
 await expect(dialog.locator('#plant-maintenance-type option:checked')).toHaveText(name!);
 await expect(dialog).toBeVisible(); expect(errors).toEqual([]); expect(mutations).toEqual([]);
});


test('strain editor tabs preserve drafts, align controls and reveal invalid durations', async ({ page }) => {
 const { errors, mutations } = await session(page);
 await openModule(page, 'catalog');
 await page.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 await page.getByRole('dialog', { name: 'Fictional Strain', exact: true }).getByRole('button', { name: 'Modifier Fictional Strain', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier la variété', exact: true });
 await editor.locator('#strain-name').fill('Updated strain');
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const heights = await editor.evaluate(node => ['strain-name', 'strain-yield', 'strain-genetics', 'strain-sourceUrl'].map(id => {
   const control = node.querySelector<HTMLElement>(`#${id}`)!;
   return (control.classList.contains('app-select-source') ? control.nextElementSibling! : control).getBoundingClientRect().height;
  }));
  expect(Math.max(...heights) - Math.min(...heights)).toBeLessThanOrEqual(1);
  if (process.env.MODAL_REVIEW) await editor.screenshot({ path: test.info().outputPath(`strain-general-${width}.png`), animations: 'disabled' });
  await editor.getByRole('tab', { name: 'Cycle', exact: true }).click();
  await expect(editor.locator('.strain-editor-tabs > .n-tabs-nav .n-tabs-tab--active')).toContainText('Cycle');
  await expect(editor.locator('#override-growth-unit')).toHaveValue('weeks');
  if (width > 700) await expect(editor.locator('[data-ui-select="override-growth-unit"]')).toHaveText('semaines');
  const dimensions = await editor.evaluate(node => {
   const input = node.querySelector('#override-growth')!; const select = node.querySelector('#override-growth-unit')!;
   return [input.getBoundingClientRect().height, (select.classList.contains('app-select-source') ? select.nextElementSibling! : select).getBoundingClientRect().height];
  });
  expect(Math.abs(dimensions[0]! - dimensions[1]!)).toBeLessThanOrEqual(1);
  if (process.env.MODAL_REVIEW) await editor.screenshot({ path: test.info().outputPath(`strain-cycle-${width}.png`), animations: 'disabled' });
  await editor.getByRole('tab', { name: 'Général', exact: true }).click();
 }
 await editor.getByRole('tab', { name: 'Cycle', exact: true }).click();
 await editor.locator('#override-growth-unit').selectOption('weeks'); await editor.locator('#override-growth').fill('0');
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click();
 await editor.locator('#strain-notes').fill('Retained tab note');
 await editor.getByRole('tab', { name: 'Stock', exact: true }).click();
 await expect(editor.locator('#strain-stock')).toHaveValue('stock-one');
 await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await expect(editor.locator('#strain-name')).toHaveValue('Updated strain');
 await editor.getByRole('button', { name: 'Enregistrer la variété', exact: true }).click();
 await expect(editor.getByRole('tab', { name: 'Cycle', exact: true })).toHaveAttribute('aria-selected', 'true');
 await expect(editor.locator('#override-growth')).toBeFocused(); expect(mutations).toEqual([]);
 await editor.locator('#override-growth').fill('3');
 await editor.getByRole('button', { name: 'Enregistrer la variété', exact: true }).click(); await expect(editor).toHaveCount(0);
 expect(mutations.at(-1)?.input).toMatchObject({ name: 'Updated strain', notes: 'Retained tab note', duration_overrides: { growth: 21 } }); expect(errors).toEqual([]);
});


test('plant editor tabs preserve identity, hardware and notes and reveal API errors', async ({ page }) => {
 const { errors, mutations } = await session(page, true, false, { devices: [{ id: 'sensor-one', name: 'Associated probe', category: 'sensor', status: 'active' }] });
 const fan = { id: 'fan-one', name: 'Circulation fan with a long display name for mobile', category: 'fan', status: 'maintenance', locations: [] };
 await page.route('**/api/v1/assets?*', route => route.fulfill({ json: { data: [fan], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 let reject = true;
 await page.route('**/api/v1/plants/plant-one', route => {
  if (route.request().method() !== 'PATCH' || !reject) return route.fallback();
  reject = false; return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { notes: ['Private validation detail'] } } });
 });
 await openModule(page, 'plants'); await page.getByRole('button', { name: 'Ouvrir Fictional Plant', exact: true }).click();
 await page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).getByRole('button', { name: 'Modifier Fictional Plant', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier la plante', exact: true });
 await editor.locator('#plant-name').fill('Tabbed plant');
 if (process.env.MODAL_REVIEW) for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await editor.screenshot({ path: test.info().outputPath(`plant-general-${width}.png`), animations: 'disabled' });
 }

 await editor.getByRole('tab', { name: 'Matériels', exact: true }).click();
 const hardware = editor.locator('#plant-devices-field');
 await expect(hardware.getByRole('listitem')).toContainText('Associated probe');
 await hardware.getByRole('button', { name: 'Retirer Associated probe' }).click();
 await expect(hardware.getByText('Aucun matériel associé.')).toBeVisible();
 await selectOptionByText(page, '#plant-device-picker', fan.name);
 await expect(hardware.getByRole('listitem')).toContainText(fan.name);
 if (process.env.MODAL_REVIEW) for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await editor.screenshot({ path: test.info().outputPath(`plant-hardware-editor-${width}.png`), animations: 'disabled' });
 }
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await editor.locator('#plant-notes').fill('Retained tab note');
 await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await expect(editor.locator('#plant-name')).toHaveValue('Tabbed plant');
 await editor.getByRole('button', { name: 'Enregistrer la plante', exact: true }).click();
 await expect(editor.getByRole('tab', { name: 'Notes', exact: true })).toHaveAttribute('aria-selected', 'true');
 await expect(editor.locator('#plant-notes-error')).toBeVisible(); await expect(editor.locator('#plant-notes')).toBeFocused(); await expect(editor).not.toContainText('Private validation detail');
 await editor.locator('#plant-notes').fill('Corrected tab note');
 await editor.getByRole('tab', { name: 'Matériels', exact: true }).click();
 await expect(hardware.getByRole('listitem')).toHaveCount(1);
 await expect(hardware.getByRole('listitem')).toContainText(fan.name);
 await editor.getByRole('button', { name: 'Enregistrer la plante', exact: true }).click(); await expect(editor).toHaveCount(0);
 expect(mutations).toHaveLength(1); expect(mutations[0]?.input).toMatchObject({ name: 'Tabbed plant', notes: 'Corrected tab note', device_ids: [fan.id] }); expect(errors).toEqual([]);
});


test('plant strain tags open the complete record without opening or leaving the plant', async ({ page }) => {
 const { errors, requests, mutations } = await session(page, false);
 await page.goto('/');
 const card = page.getByTestId('plant-card-plant-one');
 const tag = card.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true });
 await tag.click();
 const strain = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await expect(strain).toBeVisible();
 await expect(strain.getByText('Fictional strain notes', { exact: true })).toBeVisible();
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape');
 await expect(strain).toHaveCount(0);
 await expect(tag).toBeFocused();
 await card.locator('.open-plant').click();
 const plant = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 await expect(plant).toBeVisible();
 const detailTag = plant.getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true });
 await detailTag.focus(); await page.keyboard.press('Enter');
 await expect(strain).toBeVisible();
 await page.keyboard.press('Escape');
 await expect(strain).toHaveCount(0); await expect(plant).toBeVisible();
 await expect(detailTag).toBeFocused();
 await page.keyboard.press('Escape');
 await openModule(page, 'plants');
 await page.locator('.plant-card .page-heading').getByRole('button', { name: 'Tableau', exact: true }).click();
 await page.getByRole('table').getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true }).click();
 await expect(strain).toBeVisible();
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(strain).toHaveCount(0);
 await openModule(page, 'home');
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(tag).toBeVisible();
 await card.screenshot({ path: test.info().outputPath('clickable-strain-mobile.png') });
 await tag.click(); await expect(strain).toBeVisible();
 await page.keyboard.press('Escape'); await expect(tag).toBeFocused();
 expect(requests.some(path => path.endsWith('/strains'))).toBe(false);
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});


test('a linked strain can be edited without leaving the plant workspace', async ({ page }) => {
 const { errors, requests, mutations } = await session(page);
 const tag = page.getByTestId('plant-card-plant-one').getByRole('button', { name: 'Ouvrir Fictional Strain', exact: true });
 await tag.click();
 const strain = page.getByRole('dialog', { name: 'Fictional Strain', exact: true });
 await strain.getByRole('button', { name: 'Modifier Fictional Strain', exact: true }).click();
 await page.locator('#strain-yield').fill('600');
 await page.getByRole('button', { name: 'Enregistrer la variété', exact: true }).click();
 await expect(strain).toBeVisible(); await expect(strain.getByText('600 g/m²', { exact: true })).toContainText('600');
 await page.keyboard.press('Escape'); await expect(tag).toBeFocused();
 expect(mutations).toHaveLength(1);
 expect(requests.some(path => path === 'GET /api/v1/strains')).toBe(false);
 expect(errors).toEqual([]);
});

test('an unavailable linked strain reports the error and keeps the plant accessible', async ({ page }) => {
 const { errors } = await session(page, false);
 await page.route('**/api/v1/strains/strain-one', route => route.fulfill({ status: 404, json: { error: { code: 'auth.errors.notFound' } } }));
 const card = page.getByTestId('plant-card-plant-one');
 await card.locator('.strain-reference-tag').click();
 const dialog = page.getByRole('dialog');
 await expect(page.getByRole('alert').first()).toBeVisible();
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0);
 await card.locator('.open-plant').click();
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toBeVisible();
 expect(errors).toEqual([]);
});


test('plant summary tiles show known dates and container above its overview', async ({ page }) => {
 const { errors } = await session(page, false, false, { container: { inventoryItemId: 'pot-one', name: 'Fictional pot', capacityLiters: '11' } });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const dialog = page.getByRole('dialog', { name: 'Fictional Plant', exact: true });
 const tiles = dialog.locator('.plant-summary-metrics');
 await expect(tiles.locator('dt')).toHaveText(['Date et heure du semis', 'Début de l’étape', 'Fin prévisionnelle de l’étape', 'Pot actuel']);
 await expect(tiles.locator('dd').nth(0)).toContainText('01/11/2026');
 await expect(tiles.locator('dd').nth(2)).toContainText('22/11/2026');
 await expect(tiles.locator('dd').nth(3)).toHaveText('Fictional pot · 11 L');
 await expect(dialog.locator('.record-overview .record-metrics')).toHaveCount(0);
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  await tiles.screenshot({ path: test.info().outputPath(`plant-summary-${width}.png`) });
 }
 expect(errors).toEqual([]);
});

test('plant summary omits unknown dates and maturation deadlines while preserving harvest weight', async ({ page }) => {
 const { errors } = await session(page, false, false, { phase: 'maturation', startedAt: null, phaseStartedAt: null, phaseDueAt: '2026-11-22T12:00:00Z', day: null, week: null, harvestWeightGrams: '125.5' });
 await page.getByTestId('plant-card-plant-one').locator('.open-plant').click();
 const tiles = page.getByRole('dialog', { name: 'Fictional Plant', exact: true }).locator('.plant-summary-metrics');
 await expect(tiles.locator('dt')).toHaveText(['Poids de récolte']);
 await expect(tiles.locator('dd')).toContainText('125,5 g');
 expect(errors).toEqual([]);
});


test('location plant tables keep complete rows readable inside their own horizontal scroll area', async ({ page }) => {
 const reading: PlantMeasurement = { sensorId: 'sensor-0', sensorName: 'Moisture', device: { id: 'device-0', name: 'Fictional probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture', decimalPlaces: 1 }, value: '25', unit: '%', observedAt: '2026-11-09T11:00:00Z', isEnabled: true, isStale: false };
 const { errors, plants } = await session(page, true, true, { measurements: [reading] });
 const site = { id: 'site-one', kind: 'site', name: 'Fictional Site', description: null, parentId: null, childrenCount: 0, canSelect: true };
 await page.route('**/api/v1/locations/site/site-one', route => route.fulfill({ json: { data: site } }));
 await page.route('**/api/v1/locations/site/site-one/plants?*', route => route.fulfill({ json: { data: plants, meta: { current_page: 1, last_page: 1, total: plants.length, per_page: 20 } } }));
 await openModule(page, 'locations');
 await page.getByRole('button', { name: 'Photos et informations de Fictional Site', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Fictional Site', exact: true });
 const list = dialog.getByRole('region', { name: 'Plantes présentes', exact: true });
 const table = list.getByRole('table');
 await expect(table.getByRole('columnheader')).toHaveCount(7);
 await expect(list.locator('.page-actions').getByRole('button', { name: 'Tout sélectionner sur cette vue' })).toBeEnabled();
 await expect(table).toContainText('Fictional Plant'); await expect(table).toContainText('Fictional Strain');
 await expect(table).toContainText('25'); await expect(table.getByTestId('watering-needed')).toBeVisible();
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  await expect(table).toBeVisible();
  const geometry = await table.evaluate(el => {
   const cells = [...el.querySelectorAll('tbody tr:first-child td')];
   const bounds = cells.map(cell => cell.getBoundingClientRect());
   const actions = cells.at(-1)!;
   return { widths: bounds.map(rect => rect.width), actionOverflow: actions.scrollWidth - actions.clientWidth,
    overlaps: bounds.slice(0, -1).some((rect, i) => rect.right > bounds[i + 1]!.left + 1) };
  });
  expect(geometry.overlaps).toBe(false);
  expect(geometry.widths.slice(1).every(value => value >= 100)).toBe(true);
  expect(geometry.actionOverflow).toBeLessThanOrEqual(2);
  expect(await list.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
  await list.screenshot({ path: test.info().outputPath(`location-plant-table-${width}.png`) });
 }
 const scroll = list.locator('.collection-scroll');
 await scroll.evaluate(el => { el.scrollLeft = el.scrollWidth; });
 await expect(table.getByRole('button', { name: 'Modifier Fictional Plant', exact: true })).toBeVisible();
 expect(errors).toEqual([]);
});


test('location detail tags show the immediate parent even when opened through a direct link', async ({ page }) => {
 const { errors, requests } = await session(page, false);
 const records = {
  site: { id: 'site-one', kind: 'site', name: 'Fictional Site', parentId: null, code: null },
  room: { id: 'room-one', kind: 'room', name: 'Fictional Room', parentId: 'site-one', code: 'ROOM_WITH_A_LONG_CODE_1234567890' },
  zone: { id: 'zone-one', kind: 'zone', name: 'Fictional Zone', parentId: 'room-one', code: 'Z-01' },
 };
 await page.route(/\/api\/v1\/locations\/(site|room|zone)\/[^/?]+$/, route => {
  const kind = new URL(route.request().url()).pathname.split('/').at(-2) as keyof typeof records;
  return route.fulfill({ json: { data: { ...records[kind], description: null, childrenCount: 0, canSelect: true } } });
 });
 for (const kind of ['room', 'zone', 'site'] as const) {
  const record = records[kind];
  await page.goto(`/sites?location=${kind}:${record.id}`);
  const dialog = page.getByRole('dialog', { name: record.name, exact: true });
  const tags = dialog.locator('.location-detail-tags');
  await expect(tags.getByText({ site: 'Site', room: 'Pièce', zone: 'Zone' }[kind], { exact: true })).toBeVisible();
  if (record.code) await expect(tags.getByText(record.code, { exact: true })).toBeVisible();
  if (kind !== 'site') await expect(tags.getByText(kind === 'zone' ? 'Fictional Room' : 'Fictional Site', { exact: true })).toBeVisible();
  else await expect(tags.locator('.n-tag')).toHaveCount(1);
  if (kind === 'room') for (const width of [1440, 390]) {
   await page.setViewportSize({ width, height: 1000 });
   expect(await tags.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
   await tags.screenshot({ path: test.info().outputPath(`location-tags-${width}.png`) });
  }
 }
 expect(requests.filter(path => path.endsWith('/context'))).toHaveLength(2);
 expect(errors).toEqual([]);
});


test('tabbed pages avoid duplicate panel insets while retaining usable content', async ({ page }) => {
 const { errors } = await session(page);
 for (const module of ['assets', 'catalogs', 'inventory']) {
  await openModule(page, module);
  const workspace = page.locator('#workspace-content');
  const panes = workspace.locator('.app-tabs > .n-tabs-pane-wrapper > .n-tab-pane:visible');
  await expect(panes.first()).toBeVisible();
  const insets = await panes.evaluateAll(elements => elements.map(el => {
   const pane = getComputedStyle(el); const wrapper = getComputedStyle(el.parentElement!);
   return { top: parseFloat(pane.paddingTop) + parseFloat(wrapper.paddingTop), horizontal: parseFloat(pane.paddingLeft) + parseFloat(wrapper.paddingLeft) };
  }));
  for (const inset of insets) { expect(inset.top).toBeLessThanOrEqual(16); expect(inset.horizontal).toBe(0); }
  const embedded = panes.locator('.n-card:not(.resource-card) > .n-card-content').first();
  if (await embedded.count()) expect(await embedded.evaluate(el => parseFloat(getComputedStyle(el).paddingLeft))).toBe(0);
  for (const width of [1440, 390]) {
   await page.setViewportSize({ width, height: 1000 });
   expect(await workspace.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
   await workspace.screenshot({ path: test.info().outputPath(`tabbed-${module}-${width}.png`) });
  }
 }
 expect(errors).toEqual([]);
});


test('button subtabs keep readable labels, separated targets and bounded rows on desktop and mobile', async ({ page }) => {
 const { errors } = await session(page);
 for (const section of ['catalogs', 'connections']) {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await openModule(page, section === 'catalogs' ? 'catalogs' : 'instanceSettings');
  if (section === 'connections') await page.locator('.settings-tabs').getByRole('tab', { name: 'Connexions', exact: true }).click();
  const tabs = page.locator('#workspace-content .button-tabs:visible').first();
  await expect(tabs.getByRole('tab').first()).toBeVisible();
  for (const width of [1440, 390]) {
   await page.setViewportSize({ width, height: 1000 });
   await expect(tabs.getByRole('tab').first()).toBeVisible();
   const layout = await tabs.getByRole('tab').evaluateAll(nodes => nodes.map(node => {
    const rect = node.getBoundingClientRect(); const style = getComputedStyle(node);
    return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, clipped: node.scrollWidth > node.clientWidth + 1, hiddenText: style.color === style.backgroundColor, label: node.textContent?.trim() };
   }));
   expect(layout.length).toBeGreaterThan(1);
   expect(layout.every(tab => !!tab.label && !tab.clipped && !tab.hiddenText)).toBe(true);
   for (let i = 1; i < layout.length; i++) {
    const current = layout[i]!; const previous = layout[i - 1]!;
    expect(Math.abs(current.top - previous.top) < 1 ? current.left - previous.right : current.top - previous.bottom).toBeGreaterThanOrEqual(4);
   }
   expect(await tabs.evaluate(node => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
   if (process.env.BUTTON_SUBTABS_REVIEW) {
    await tabs.screenshot({ path: test.info().outputPath(`button-subtabs-${section}-${width}.png`), animations: 'disabled' });
   }
  }
 }
 expect(errors).toEqual([]);
});


test('dismissing a modal error preserves its draft and retry controls without submitting', async ({ page }) => {
 const { errors } = await session(page); let attempts = 0;
 await page.route('**/api/v1/plants/plant-one/waterings', route => { attempts++; return route.fulfill({status:409,json:{error:{code:'plants.errors.conflict'}}}); });
 await page.getByTestId('plant-card-plant-one').getByRole('button', {name:/^Enregistrer un arrosage pour/}).click();
 const dialog=page.getByRole('dialog',{name:'Arrosage — Fictional Plant',exact:true});
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without');
 await (await revealTabbedControl(dialog.locator('#plant-watering-comment'))).fill('Preserved after dismissing feedback');
 await dialog.getByRole('button',{name:'Enregistrer l’arrosage',exact:true}).click();
 const feedback=page.locator('.feedback-stack .n-notification').filter({hasText:'Cette plante a changé.'});
 await expect(feedback).toBeVisible();
 await feedback.getByRole('button',{name:'close',exact:true}).click();
 await expect(feedback).toHaveCount(0); expect(attempts).toBe(1);
 await expect(dialog.locator('#plant-watering-comment')).toHaveValue('Preserved after dismissing feedback');
 await expect(dialog.getByRole('button',{name:'Recharger les plantes',exact:true})).toBeVisible();
 await dialog.getByRole('button',{name:'Enregistrer l’arrosage',exact:true}).click();
 await expect(feedback).toBeVisible(); expect(attempts).toBe(2);
 expect(errors).toEqual([]);
});

test('global feedback stacks watering success after the modal closes and retains it across navigation', async ({ page }) => {
 await page.clock.install();
 const { errors, mutations } = await session(page);
 await page.getByTestId('plant-card-plant-one').getByRole('button',{name:/^Enregistrer un arrosage pour/}).click();
 const dialog=page.getByRole('dialog',{name:'Arrosage — Fictional Plant',exact:true});
 await (await revealTabbedControl(dialog.locator('#plant-watering-choice'))).selectOption('without');
 await dialog.getByRole('button',{name:'Enregistrer l’arrosage',exact:true}).click();
 await expect(dialog).toHaveCount(0);
 const watering=page.locator('.feedback-stack .n-notification').filter({hasText:'Arrosage enregistré.'});
 await expect(watering).toBeVisible();expect(mutations).toHaveLength(1);
 await openModule(page,'plants'); await expect(watering).toBeVisible();
 await page.getByTestId('plant-row-plant-one').getByRole('button',{name:'Modifier Fictional Plant',exact:true}).click();
 const editor=page.getByRole('dialog',{name:'Modifier la plante',exact:true});
 await editor.locator('#plant-name').fill('Saved with global feedback');
 await editor.getByRole('button',{name:/^(Enregistrer la plante|Enregistrer)$/}).click();
 await expect(editor).toHaveCount(0);
 const saved=page.locator('.feedback-stack .n-notification').filter({hasText:'La plante a été modifiée.'});
 await expect(saved).toBeVisible();await expect(watering).toBeVisible();
 await page.setViewportSize({width:390,height:844});
 await watering.getByRole('button',{name:'close',exact:true}).click();
 await expect(watering).toHaveCount(0);await expect(saved).toBeVisible();
 await page.setViewportSize({width:1280,height:720});
 await page.route('**/api/v1/auth/logout', route => route.fulfill({status:204}));
 await page.locator('#app-navigation .sign-out').click();
 await expect(page.locator('#login-email')).toBeVisible();await expect(saved).toHaveCount(0);
 expect(errors).toEqual([]);
});

test('device assignment trails navigate to exact location and plant records', async ({ page }) => {
 const { errors, mutations, plants } = await session(page);
 const plant = plants[0]!; const location = plant.location!;
 const asset = { id: 'linked-device', name: 'Linked pump', category: 'pump', status: 'active', locations: [location], plants: [{ id: plant.id, name: plant.name, location }], plantsCount: 1, assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null, channelsCount: 0, enabledChannelsCount: 0 };
 await page.route('**/api/v1/assets?*', route => route.fulfill({ json: { data: [asset], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await page.route('**/api/v1/locations/zone/zone-one', route => route.fulfill({ json: { data: { id: 'zone-one', kind: 'zone', name: 'Fictional Zone', parentId: 'room-one', description: null, childrenCount: 0, canManage: true } } }));
 await page.route('**/api/v1/locations/zone/zone-one/context', route => route.fulfill({ json: { data: { id: 'zone:zone-one', name: 'Fictional Zone', context: location } } }));
 await openModule(page, 'assets');
 const assignments = page.locator('.asset-grid .device-assignment').first();
 await expect(assignments.locator('.location-trail').first().getByRole('link')).toHaveText(['Fictional Site', 'Fictional Room', 'Fictional Zone']);
 await page.setViewportSize({ width: 390, height: 844 });
 await assignments.scrollIntoViewIfNeeded();
 await assignments.getByRole('link', { name: 'Fictional Zone', exact: true }).first().click();
 await expect(page).toHaveURL(/\/sites\?location=zone(?::|%3A)zone-one/);
 await expect(page.getByRole('dialog', { name: 'Fictional Zone', exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await openModule(page, 'assets');
 await assignments.getByRole('link', { name: 'Plante : Fictional Plant', exact: true }).click();
 await expect(page).toHaveURL(/\/plants\?plant=plant-one/);
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toBeVisible();
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

test('plant card location links remain independently clickable above the card opener', async ({ page }) => {
 const { errors, requests, mutations } = await session(page);
 await page.route('**/api/v1/locations/room/room-one', route => route.fulfill({ json: { data: { id: 'room-one', kind: 'room', name: 'Fictional Room', parentId: 'site-one', description: null, childrenCount: 1 } } }));
 await openModule(page, 'plants');
 const card = page.locator('.photo-plant').first();
 await card.getByRole('link', { name: 'Fictional Room', exact: true }).click();
 await expect(page.getByRole('dialog', { name: 'Fictional Room', exact: true })).toBeVisible();
 await expect(page.getByRole('dialog', { name: 'Fictional Plant', exact: true })).toHaveCount(0);
 expect(requests.filter(path => path === 'GET /api/v1/plants/plant-one')).toHaveLength(0);
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});
