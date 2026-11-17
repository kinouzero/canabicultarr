import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

test('location galleries preserve hierarchy and metadata through library selection, uploads and edits', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const imageBytes = await page.evaluate(() => {
  const canvas = document.createElement('canvas'); canvas.width = 480; canvas.height = 280;
  const context = canvas.getContext('2d')!; context.fillStyle = '#315b3c'; context.fillRect(0, 0, 480, 280);
  context.fillStyle = '#ffffff'; context.font = '30px sans-serif'; context.fillText('Location photo', 130, 145);
  return canvas.toDataURL('image/png').split(',')[1]!;
 });
 const image = Buffer.from(imageBytes, 'base64');
 const preview = 'data:image/png;base64,' + image.toString('base64');
 const records = ['site', 'room', 'zone'].map(kind => ({ id: `${kind}-photo`, kind, code: kind === 'site' ? null : `${kind.toUpperCase()}-01`, name: `Fictional ${kind}`, description: `Notes for ${kind}`, childrenCount: kind === 'zone' ? 0 : 1, canManage: true, canSelect: true, wateringAlertDays: 4, photoUrl: null as string | null, hasPhoto: false }));
 const galleries = new Map<string, string[]>(); let uploads = 0; let deletions = 0; const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/**', async route => {
  const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
  const data = (value: unknown) => route.fulfill({ json: { data: value } });
  if (/\/locations\/(site|room|zone)\/[^/]+\/plants$/.test(path)) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
  const list = (value: unknown[]) => route.fulfill({ json: { data: value, meta: { current_page: 1, last_page: 1, per_page: 20, total: value.length } } });
  if (path.endsWith('/auth/user')) return data({ id: 'insight-reader', name: 'Location manager', email: 'location@example.test', role: 'admin', permissions: ['administration.view', 'locations.manage'], isActive: true, oidcLinked: false });
  if (path.endsWith('/documents') && request.method() === 'POST') {
   expect(request.postData()).toContain('subject[type]'); expect(request.postData()).not.toContain('name="disk"'); uploads++;
   return data({ id: `uploaded-${uploads}` });
  }
  if (/\/documents\/library-photo\/(preview|thumbnail)$/.test(path)) return route.fulfill({ contentType: 'image/png', body: image });
  if (/^\/api\/v1\/locations\/(site|room|zone)\/[^/]+$/.test(path)) return data(records.find(row => row.id === path.split('/').at(-1)));
  if (path.endsWith('/documents') && request.method() === 'GET') return list([{ id: 'library-photo', title: 'Library photo', originalName: 'library.png', format: 'image', mediaType: 'image/png', isArchived: false }]);
  if (path.includes('/locations/') && path.endsWith('/photos')) {
   const id = path.split('/').at(-2)!; const record = records.find(row => row.id === id)!;
   return list((galleries.get(id) ?? []).map(photo => ({ id: photo, name: `${photo}.png`, url: preview, createdAt: '2026-11-09T12:00:00Z', isCover: record.hasPhoto && photo === galleries.get(id)?.at(-1) })));
  }
  if (path.startsWith('/api/v1/sites')) {
   if (request.method() === 'DELETE') {
    deletions++;
    if (deletions === 1) return route.fulfill({ status: 409, json: { error: { code: 'locations.errors.inUse' } } });
    return route.fulfill({ status: 204 });
   }
   if (request.method() === 'PATCH') {
    const record = records.find(row => path.endsWith('/' + row.id))!; const input = request.postDataJSON(); writes.push(input);
    if ('photo_document_id' in input) { const id = input.photo_document_id; if (id) galleries.set(record.id, [...new Set([...(galleries.get(record.id) ?? []), id])]); record.photoUrl = id ? preview : null; record.hasPhoto = !!id; }
    else Object.assign(record, { name: input.name, code: input.code ?? record.code, description: input.description, wateringAlertDays: input.watering_alert_days });
    return data(record);
   }
   if (path.endsWith('/rooms')) return list([records[1]]);
   if (path.endsWith('/zones')) return list([records[2]]);
   if (path.endsWith('/sites')) return list([records[0]]);
  }
  return route.fallback();
 });
 await page.reload(); await openModule(page, 'locations');
 for (const [index, record] of records.entries()) {
  if (process.env.RECORD_CARD_REVIEW && index === 0) {
   await expect(page.locator('.resource-card').first()).toBeVisible();
   await page.locator('.resource-card').first().screenshot({ path: test.info().outputPath('canabicultarr-location-card-empty.png'), animations: 'disabled' });
  }
  await page.getByRole('button', { name: `Photos et informations de ${record.name}`, exact: true }).first().click();
  const dialog = page.getByRole('dialog', { name: record.name, exact: true });
  await expect(dialog).toBeVisible();
  if (index === 0) {
   await dialog.getByRole('button', { name: 'Gérer les photos', exact: true }).click();
   await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('tab', { name: 'Bibliothèque', exact: true }).click();
   const library = page.getByRole('dialog', { name: 'Gérer les photos', exact: true });
   await library.getByRole('button', { name: 'Choisir Library photo', exact: true }).click();
  } else {
   await dialog.getByRole('button', { name: 'Gérer les photos', exact: true }).click();
   await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).locator('input[type=file]').setInputFiles({ name: 'location.png', mimeType: 'image/png', buffer: image });
   await page.getByRole('dialog', { name: 'Gérer les photos', exact: true }).getByRole('button', { name: 'Importer', exact: true }).click();
  }
  await expect(dialog.locator('.gallery-enlarge > img')).toBeVisible();
  expect(writes.at(-1)).toHaveProperty('photo_document_id'); expect(Object.keys(writes.at(-1)!)).toEqual(['photo_document_id']);
  await dialog.getByRole('button', { name: `Modifier ${record.name}`, exact: true }).click();
  const editor = page.getByRole('dialog', { name: index === 0 ? 'Modifier le site' : index === 1 ? 'Modifier la pièce' : 'Modifier la zone', exact: true });
  await expect(editor.locator('#location-name')).toHaveValue(record.name);
  await (await revealTabbedControl(editor.locator('#location-watering-days'))).fill('6');
  await editor.getByRole('button', { name: /^(?:Enregistrer le lieu|Créer)$/, exact: true }).click();
  await expect(editor).toHaveCount(0); await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0);
  if (index < 2) await page.getByRole('button', { name: `Ouvrir ${record.name}`, exact: true }).click();
 }
 if (process.env.RECORD_CARD_REVIEW) await page.screenshot({ path: test.info().outputPath('canabicultarr-location-card-photo.png'), fullPage: true, animations: 'disabled' });
 const zoneName = 'Fictional zone';
 await page.getByRole('button', { name: `Supprimer ${zoneName}`, exact: true }).click();
 const confirmation = page.getByRole('dialog', { name: `Supprimer ${zoneName} ?`, exact: true });
 await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
 await expect(confirmation.page().getByRole('alert').filter({ hasText: 'éléments liés' }).first()).toContainText('éléments liés');
 await confirmation.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('button', { name: `Supprimer ${zoneName}`, exact: true }).click();
 await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
 await expect(confirmation).toHaveCount(0);
 expect(deletions).toBe(2);
 expect(uploads).toBe(2); expect(errors).toEqual([]);
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
