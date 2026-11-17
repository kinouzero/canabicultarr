import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

test('provider cadence distinguishes paused collection from configured credentials', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'sync-reader', name: 'Sync reader', email: 'sync@example.test', permissions: ['integrations.manage'], isActive: true, oidcLinked: false } } }));
 let enabled = true;
 await page.route('**/api/v1/integrations/home-assistant', route => route.fulfill({ json: { data: { configured: true, sync: { enabled, configurationValid: true, intervalSeconds: 300, eventsEnabled: enabled, linksCount: 0, lastAttemptAt: null, lastSuccessAt: null, nextEligibleAt: null } } } }));
 await page.route('**/api/v1/integrations/home-assistant/links?**', route => route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } }));
 await page.reload(); await openModule(page, 'ha');
 const summary = page.getByRole('region', { name: 'Synchronisation automatique' });
 await expect(summary.getByText('Toutes les 5 min', { exact: true })).toBeVisible();
 await expect(summary.getByText('Collecte automatique activée', { exact: true })).toBeVisible();
 await expect(summary.getByText('Événements activés ; réconciliation périodique conservée.', { exact: true })).toBeVisible();
 enabled = false;
 await page.getByRole('button', { name: 'Actualiser', exact: true }).click();
 await expect(summary.getByText('Collecte automatique en pause', { exact: true })).toBeVisible();
 await expect(summary.getByText('Toutes les 5 min', { exact: true })).toHaveCount(0);
 expect(errors).toEqual([]);
});
