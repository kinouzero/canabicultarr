import { test, expect } from '../coverage/fixture';
import { insightRoutes, insightSensors } from './insights-fixture';
import { openModule } from './navigation';

test('measurement history distinguishes suspect samples from the usable latest reading', async ({ page }) => {
 const { errors } = await insightRoutes(page);
 const sensor = insightSensors[0]!;
 const base = { id: 'quality-good', value: '35.000000000', unit: '%', observedAt: '2026-11-09T10:00:00Z', receivedAt: '2026-11-09T10:00:01Z', location: null, quality: 'good' };
 await page.route('**/api/v1/sensors/sensor-0/measurements?**', route => route.fulfill({ json: { data: [{ ...base, id: 'quality-suspect', value: '99.000000000', quality: 'suspect' }, { ...base, id: 'quality-legacy', quality: 'unknown' }], meta: { current_page: 1, last_page: 1, total: 2, per_page: 50 }, summary: { sensor, latest: base, status: 'fresh' } } }));
 await openModule(page, 'measurements');
 await page.getByRole('button', { name: 'Historique · Moisture one', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Historique · Moisture one', exact: true });
 await expect(dialog.getByText('Bonne qualité', { exact: true })).toBeVisible();
 await expect(dialog.getByText('Suspecte', { exact: true })).toBeVisible();
 await expect(dialog.getByText('Qualité inconnue', { exact: true })).toBeVisible();
 await expect(dialog.getByRole('row').filter({ hasText: '99' })).toContainText('Suspecte');
 expect(errors).toEqual([]);
});
