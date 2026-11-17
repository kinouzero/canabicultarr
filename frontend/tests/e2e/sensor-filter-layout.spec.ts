import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';

for (const width of [320, 390, 700]) test(`channel statistics filters fit ${width}px dialogs with custom dates`, async ({ page }) => {
 const { errors } = await insightRoutes(page);
 await page.setViewportSize({ width, height: 844 });
 await openModule(page, 'measurements');
 await page.getByRole('button', { name: 'Historique · Moisture one', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Historique · Moisture one', exact: true });
 await expect(dialog.getByTestId('report-mean')).toBeVisible();
 await (await revealTabbedControl(dialog.getByLabel('Période', { exact: true }).and(dialog.locator('select')))).selectOption('custom');
 const period = await dialog.getByRole('combobox', { name: 'Période', exact: true }).boundingBox();
 const grouping = await dialog.getByRole('combobox', { name: 'Regroupement en UTC', exact: true }).boundingBox();
 expect(Math.abs(period!.y + period!.height - grouping!.y - grouping!.height)).toBeLessThan(2);
 const filters = dialog.locator('.search-filters');
 const bounds = await filters.boundingBox();
 for (const control of await filters.getByRole('combobox').or(filters.locator('.n-input')).all()) {
  const box = await control.boundingBox();
  expect(box!.x).toBeGreaterThanOrEqual(bounds!.x - 1);
  expect(box!.x + box!.width).toBeLessThanOrEqual(bounds!.x + bounds!.width + 1);
 }
 expect(errors).toEqual([]);
});
