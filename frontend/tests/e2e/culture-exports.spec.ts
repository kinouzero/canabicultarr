import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

test('culture export keeps selected context and visible sections for PDF and CSV, then downloads privately', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const writes: Record<string, unknown>[] = []; const rows: Record<string, unknown>[] = [];
 await page.route('**/api/v1/culture/overview/exports', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: { data: rows } });
  const input = route.request().postDataJSON(); writes.push(input);
  const row = { id: `export-${writes.length}`, sensorId: null, format: input.format, status: 'ready', canDownload: true, progress: 100, processedRows: 3, totalRows: 3, bytes: 50, errorCode: null, createdAt: new Date().toISOString(), expiresAt: new Date(Date.now() + 86400000).toISOString() };
  rows.unshift(row); return route.fulfill({ status: 202, json: { data: row } });
 });
 await page.route('**/api/v1/report-exports/*/download', route => route.fulfill({ status: 200, headers: { 'Content-Type': 'application/pdf', 'Cache-Control': 'private, no-store' }, body: '%PDF-1.7\nFictional mocked export' }));
 await openModule(page, 'overview'); const overview = page.getByTestId('culture-overview');
 await (await revealTabbedControl(overview.locator('select').and(overview.getByLabel('Site', { exact: true })))).selectOption('site-one');
 await (await revealTabbedControl(overview.locator('select').and(overview.getByLabel('Activité enregistrée', { exact: true })))).selectOption('30');
 await overview.getByRole('button', { name: 'Personnaliser', exact: true }).click();
 const custom = page.getByRole('dialog', { name: 'Personnaliser', exact: true });
 const strains = custom.getByRole('switch', { name: 'Plantes actives par variété', exact: true }); await expect(strains).toBeChecked(); await strains.click(); await expect(strains).not.toBeChecked();
 await custom.getByRole('button', { name: 'Fermer', exact: true }).last().click();
 await expect(overview.getByTestId('culture-active')).toHaveText('2');
 await overview.getByRole('button', { name: 'Exporter', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Exporter la vue d’ensemble', exact: true });
 await expect(dialog).toContainText('Fictional site');
 for (const width of [1280, 390, 320]) {
  await page.setViewportSize({ width, height: 900 });
  const selector = dialog.getByRole('combobox', { name: 'Format', exact: true });
  await expect(async () => {
   const control = await selector.boundingBox(); const button = await dialog.getByRole('button', { name: 'Générer', exact: true }).boundingBox();
   expect(control).not.toBeNull(); expect(button).not.toBeNull();
   expect(Math.abs(control!.height - button!.height)).toBeLessThanOrEqual(1);
   if (Math.abs(control!.y - button!.y) > 1) {
    expect(button!.y).toBeGreaterThanOrEqual(control!.y + control!.height);
    expect(Math.abs((button!.x + button!.width) - (control!.x + control!.width))).toBeLessThanOrEqual(1);
   } else expect(Math.abs((control!.y + control!.height) - (button!.y + button!.height))).toBeLessThanOrEqual(1);
  }).toPass({ timeout: 5000 });
 }
 await page.setViewportSize({ width: 1280, height: 900 });

 await dialog.getByRole('button', { name: 'Générer', exact: true }).click();
 await expect.poll(() => writes.length).toBe(1);
 expect(writes[0]).toMatchObject({ site_id: 'site-one', days: 30, format: 'pdf', locale: 'fr' });
 expect(writes[0]!.sections).not.toContain('strains');
 const downloaded = page.waitForEvent('download');
 await dialog.getByRole('button', { name: 'Télécharger le PDF', exact: true }).click();
 expect((await downloaded).suggestedFilename()).toBe('culture_export-1.pdf');
 await (await revealTabbedControl(dialog.locator('select').and(dialog.getByLabel('Format', { exact: true })))).selectOption('csv');
 await dialog.getByRole('button', { name: 'Générer', exact: true }).click();
 await expect.poll(() => writes.length).toBe(2); expect(writes[1]!.format).toBe('csv');
 expect(writes[1]!.request_key).not.toBe(writes[0]!.request_key);
 await dialog.getByRole('button', { name: 'Fermer', exact: true }).last().click();
 await expect(dialog).toHaveCount(0); expect(errors).toEqual([]);
});
