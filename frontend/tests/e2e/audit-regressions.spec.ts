import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

test('notification history restores the requested page and labels connection test deliveries', async ({ page }) => {
 const { errors } = await insightRoutes(page); const pages: string[] = [];
 await page.route('**/api/v1/notifications/deliveries?*', route => {
  const current = new URL(route.request().url()).searchParams.get('page')!; pages.push(current);
  return route.fulfill({ json: { data: [{ id: 'delivery-one', episodeId: null, event: 'test', channel: 'email', status: 'sent', attempts: 1, errorCode: null, createdAt: '2026-11-16T00:00:00Z', sentAt: '2026-11-16T00:00:00Z', nextAttemptAt: null }], meta: { current_page: Number(current), last_page: 4, total: 70, per_page: 20 } } });
 });
 await page.goto('/journal?view=notifications&deliveries.page=3');
 const history = page.getByTestId('notification-history'); await expect(history).toBeVisible();
 await expect(history.getByText('Test de connexion', { exact: true })).toBeVisible();
 await expect.poll(() => pages.at(-1)).toBe('3');
 await page.reload(); await expect.poll(() => pages.length).toBe(2); expect(pages).toEqual(['3', '3']);
 expect(errors).toEqual([]);
});

test('a rejected preference batch does not block later valid display changes', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 await openModule(page, 'measurements');
 const batches: { key: string; value: unknown }[][] = [];
 await page.route('**/api/v1/account/ui-preferences', route => {
  if (route.request().method() !== 'PATCH') return route.fallback();
  batches.push(route.request().postDataJSON().entries);
  return batches.length === 1 ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { entries: ['collection.preferenceLimit'] } } }) : route.fulfill({ status: 204 });
 });
 await (await revealTabbedControl(page.locator('#measurement-status'))).selectOption('stale'); await expect.poll(() => batches.length).toBe(1);
 await (await revealTabbedControl(page.getByTestId('measurement-workspace').locator('.filter-primary select'))).selectOption('name_desc');
 await expect.poll(() => batches.length).toBe(2);
 expect(batches[1]!.map(entry => entry.key), JSON.stringify(batches)).toContain('sorting:measurements:sort');
 expect(batches[1]!.map(entry => entry.key)).not.toContain('measurements:status');
 expect(errors).toEqual([]);
});

test('an older export history request cannot hide a newly queued report', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 let release: (() => void) | undefined; let reads = 0; let creates = 0;
 const job = { id: 'export-one', sensorId: 'sensor-0', format: 'pdf', status: 'queued', canDownload: false, progress: 0, processedRows: 0, totalRows: 2, bytes: null, errorCode: null, createdAt: '2026-11-16T00:00:00Z', expiresAt: '2026-11-17T00:00:00Z' };
 await page.route('**/api/v1/reports/sensors/sensor-0/exports', async route => {
  if (route.request().method() === 'POST') { creates++; return route.fulfill({ status: 202, json: { data: job } }); }
  reads++;
  if (reads === 1) { await new Promise<void>(resolve => { release = resolve; }); await route.fulfill({ json: { data: [] } }).catch(() => {}); return; }
  return route.fulfill({ json: { data: [job] } });
 });
 await page.goto('/measurements?sensor=sensor-0');
 const history = page.getByRole('dialog', { name: 'Historique · Moisture one', exact: true });
 await history.getByRole('button', { name: /Exporter/ }).click();
 const dialog = page.getByRole('dialog', { name: 'Exporter les mesures', exact: true });
 await expect.poll(() => !!release).toBe(true); await (await revealTabbedControl(dialog.locator('select'))).selectOption('pdf');
 await dialog.getByRole('button', { name: 'Générer', exact: true }).click();
 await expect.poll(() => creates).toBe(1); release!();
 await expect(dialog.getByText('En attente', { exact: true })).toBeVisible();
 await expect.poll(() => reads).toBe(2); expect(errors).toEqual([]);
});

test('served pages and static error responses enforce the browser policy', async ({ page, request }) => {
 const { errors } = await insightRoutes(page);
 const response = await page.goto('/plants');
 expect(response!.headers()['content-security-policy']).toContain("script-src 'self'");
 expect(response!.headers()['referrer-policy']).toBe('no-referrer');
 expect(response!.headers()['x-content-type-options']).toBe('nosniff');
 const moduleUrl = await page.locator('script[type=module]').first().getAttribute('src');
 const asset = await request.get(moduleUrl!); expect(asset.ok()).toBe(true);
 expect(asset.headers()['content-security-policy']).toContain("script-src 'self'");
 expect(asset.headers()['cache-control']).toContain('immutable');
 const missing = await request.get('/assets/audit-missing.js'); expect(missing.status()).toBe(404);
 expect(missing.headers()['content-security-policy']).toContain("frame-ancestors 'self'");
 await page.evaluate(() => { const script = document.createElement('script'); script.textContent = 'window.auditInjected=true'; document.body.append(script); });
 expect(await page.evaluate(() => (window as unknown as { auditInjected?: boolean }).auditInjected)).toBeUndefined();
 expect(errors).toEqual([]);
});
