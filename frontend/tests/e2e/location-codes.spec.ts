import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

test('room code conflicts retain edits and a corrected code appears beside its name', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const site = { id: 'code-site', kind: 'site', name: 'Code site', code: null, childrenCount: 1, canManage: true, canSelect: true };
 const room = { id: 'code-room', kind: 'room', name: 'Code room', code: 'ROOM-01', childrenCount: 0, canManage: true, canSelect: true };
 const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/**', route => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  const rows = (data: unknown[]) => route.fulfill({ json: { data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20 } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'insight-reader', name: 'Location manager', email: 'location@example.test', permissions: ['administration.view', 'locations.manage'] } } });
  if (path.endsWith('/sites/code-site/rooms/code-room') && request.method() === 'PATCH') {
   const input = request.postDataJSON(); writes.push(input);
   if (input.code === 'TAKEN') return route.fulfill({ status: 409, json: { error: { code: 'locations.errors.codeTaken' } } });
   Object.assign(room, input, { code: String(input.code).toUpperCase() });
   return route.fulfill({ json: { data: room } });
  }
  if (path.endsWith('/sites/code-site/rooms')) return rows([room]);
  if (path.endsWith('/sites')) return rows([site]);
  return route.fallback();
 });
 await page.reload(); await openModule(page, 'locations');
 await page.getByRole('button', { name: 'Ouvrir Code site', exact: true }).click();
 await page.getByRole('button', { name: 'Modifier Code room', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Modifier la pièce', exact: true });
 await expect(dialog.getByLabel('Code', { exact: true })).toHaveValue('ROOM-01');
 await (await revealTabbedControl(dialog.getByLabel('Code', { exact: true }))).fill('TAKEN');
 await dialog.getByRole('button', { name: 'Enregistrer le lieu', exact: true }).click();
 await expect(dialog.locator('#location-code-error')).toContainText('Ce code est déjà utilisé');
 await expect(dialog.getByLabel('Code', { exact: true })).toHaveValue('TAKEN');
 await (await revealTabbedControl(dialog.getByLabel('Code', { exact: true }))).fill('veg-02');
 await dialog.getByRole('button', { name: 'Enregistrer le lieu', exact: true }).click();
 await expect(dialog).toHaveCount(0);
 await expect(page.locator('.location-results')).toContainText('VEG-02');
 expect(writes.map(row => row.code)).toEqual(['TAKEN', 'veg-02']);
 expect(errors).toEqual([]);
});
