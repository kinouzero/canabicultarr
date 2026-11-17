import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import type { Page } from '@playwright/test';
async function setup(page: Page, enabled = true) {
 await insightRoutes(page);
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'ble-reader', name: 'BLE manager', email: 'ble@example.test', permissions: ['integrations.manage'], locationAccess: null, isActive: true, oidcLinked: false } } }));
 await page.addInitScript(() => Object.defineProperty(navigator, 'bluetooth', { get() { throw new Error('Browser radio must never be used'); }, configurable: true }));
 const sampleBodies: Record<string, unknown>[] = []; let paired = false; let failedPoll = false;
 const device = { id: 'device', assetId: 'asset', name: 'Server probe', isEnabled: true, version: 1, channels: [{ id: 'link', sensorId: 'sensor', kind: 'battery', name: 'Bluetooth battery', isEnabled: true, version: 1, lastSeenAt: null }] };
 await page.route('**/api/v1/integrations/bluetooth/**', route => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  if (path.endsWith('/status')) return route.fulfill({ json: { data: { enabled, adapter: 'hci0', transport: 'relay', operationId: null } } });
  if (path.endsWith('/discovery')) {
   expect(Object.keys(request.postDataJSON())).toEqual(['request_id']);
   return route.fulfill({ status: 202, json: { data: { id: 'scan', kind: 'discover', status: 'pending', data: null, error: null } } });
  }
  if (path.endsWith('/operations/scan')) return route.fulfill({ json: { data: { id: 'scan', kind: 'discover', status: 'completed', data: [{ id: 'candidate', name: 'Server probe', rssi: -60 }], error: null } } });
  if (path.endsWith('/operations/read')) {
   if (!failedPoll) { failedPoll = true; return route.fulfill({ status: 503, json: { error: { code: 'bluetooth.errors.failed' } } }); }
   return route.fulfill({ json: { data: { id: 'read', kind: 'read', status: 'completed', data: { stored: 1, duplicates: 0 }, error: null } } });
  }
  if (path.endsWith('/readings')) { sampleBodies.push(request.postDataJSON()); return route.fulfill({ status: 202, json: { data: { id: 'read', kind: 'read', status: 'pending', data: null, error: null } } }); }
  if (request.method() === 'DELETE') { paired = false; return route.fulfill({ status: 204 }); }
  if (request.method() === 'PATCH') { device.channels[0]!.isEnabled = false; device.channels[0]!.version++; return route.fulfill({ status: 204 }); }
  if (request.method() === 'POST') {
   expect(request.postDataJSON()).toEqual({ discovery_id: 'scan', candidate_id: 'candidate', request_id: expect.any(String) });
   paired = true; return route.fulfill({ status: 202, json: { data: { id: 'pair', kind: 'pair', status: 'completed', data: device, error: null } } });
  }
  return route.fulfill({ json: { data: paired ? [device] : [], meta: { current_page: 1, last_page: 1, per_page: 20, total: paired ? 1 : 0 } } });
 });
 await page.goto('/integrations/bluetooth');
 return { sampleBodies };
}
test('server Bluetooth discovers and reads without browser radio access and resumes a failed operation poll', async ({ page }) => {
 const { sampleBodies } = await setup(page);
 await page.getByRole('button', { name: 'Rechercher des capteurs', exact: true }).click();
 const discovered = page.getByRole('region', { name: 'Capteurs à proximité', exact: true });
 await expect(discovered.getByText('Server probe', { exact: true })).toBeVisible();
 await discovered.getByRole('button', { name: 'Ajouter', exact: true }).click();
 const read = page.getByRole('button', { name: 'Lire Server probe', exact: true }); await read.click();
 await expect(page.getByRole('button', { name: 'Reprendre', exact: true })).toBeVisible();
 await expect(read).toBeDisabled();
 await page.getByRole('button', { name: 'Reprendre', exact: true }).click();
 await expect(read).toBeEnabled();
 expect(sampleBodies).toEqual([{ version: 1, request_id: expect.any(String) }]);
 await page.getByRole('switch', { name: 'Activer Bluetooth battery', exact: true }).click(); await expect(read).toBeDisabled();
 await page.getByRole('button', { name: 'Dissocier', exact: true }).click(); await expect(page.getByText('Aucun capteur Bluetooth associé.')).toBeVisible();
});
test('disabled server Bluetooth explains host configuration without depending on browser support', async ({ page }) => {
 await setup(page, false);
 await expect(page.getByText(/Activez Bluetooth et configurez/)).toBeVisible();
 await expect(page.getByRole('button', { name: 'Rechercher des capteurs', exact: true })).toBeDisabled();
});

test('an expired server operation releases its controls without sending another radio command', async ({ page }) => {
 const { sampleBodies } = await setup(page);
 await page.route('**/integrations/bluetooth/operations/read', route => route.fulfill({ status: 404, json: {} }));
 await page.getByRole('button', { name: 'Rechercher des capteurs', exact: true }).click();
 await page.getByRole('region', { name: 'Capteurs à proximité', exact: true }).getByRole('button', { name: 'Ajouter', exact: true }).click();
 const read = page.getByRole('button', { name: 'Lire Server probe', exact: true }); await read.click();
 await expect(read).toBeEnabled();
 await expect(page.getByRole('button', { name: 'Reprendre', exact: true })).toHaveCount(0);
 expect(sampleBodies).toHaveLength(1);
});
