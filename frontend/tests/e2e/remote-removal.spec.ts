import { test, expect } from '../coverage/fixture';
import { insightRoutes, insightSensors } from './insights-fixture';
import { openModule } from './navigation';

for (const provider of ['home-assistant', 'homebridge'] as const) {
 test(`remove ${provider} entity with confirmation and preserve measurement access`, async ({ page }) => {
  const { errors } = await insightRoutes(page);
  let removed = false; let deletes = 0;
  const link = { id: 'fictional-link', entityId: 'sensor.fictional', sensor: insightSensors[0], version: 4, isEnabled: true, lastAttemptAt: null, lastSuccessAt: null, lastError: null };
  await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'insight-reader', name: 'Integration manager', email: 'manager@example.test', role: 'member', permissions: ['integrations.manage'], isActive: true } } }));
  await page.route(`**/api/v1/integrations/${provider}**`, async route => {
   const request = route.request(); const path = new URL(request.url()).pathname;
   if (request.method() === 'DELETE') {
    expect(path).toBe(`/api/v1/integrations/${provider}/links/fictional-link`);
    expect(request.postDataJSON()).toEqual({ version: 4 });
    deletes++; removed = true; return route.fulfill({ status: 204 });
   }
   if (path.endsWith('/links')) return route.fulfill({ json: { data: removed ? [] : [link], meta: { current_page: 1, last_page: 1, total: removed ? 0 : 1, per_page: 20 } } });
   return route.fulfill({ json: { data: { configured: true } } });
  });
  await page.reload(); await openModule(page, provider === 'homebridge' ? 'homebridge' : 'ha');
  await page.getByRole('button', { name: 'Retirer Moisture one', exact: true }).click();
  let dialog = page.getByRole('dialog', { name: 'Retirer Moisture one', exact: true });
  await expect(dialog).toContainText('mesures enregistrées seront conservés');
  await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
  expect(deletes).toBe(0);
  await page.getByRole('button', { name: 'Retirer Moisture one', exact: true }).click();
  dialog = page.getByRole('dialog', { name: 'Retirer Moisture one', exact: true });
  await dialog.getByRole('button', { name: 'Retirer l’entité', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByRole('status').filter({ hasText: 'Entité retirée' })).toBeVisible();
  await expect(page.locator('.link-list li')).toHaveCount(0);
  expect(deletes).toBe(1);
  await openModule(page, 'measurements');
  await expect(page.locator('.reading-list')).toContainText('Moisture one');
  expect(errors).toEqual([]);
 });
}
