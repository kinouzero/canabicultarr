import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';
import { openSelect } from './navigation';
import { insightRoutes } from './insights-fixture';

async function catalog(page: Page, initial = 'site-0') {
 const { errors, requests: apiRequests } = await insightRoutes(page);
 const requests: { search: string; page: number }[] = [];
 const state = { fail: false, removed: '' };
 await page.route('**/api/v1/sites?*', async route => {
  const url = new URL(route.request().url()); const search = url.searchParams.get('search') ?? ''; const current = Number(url.searchParams.get('page') ?? 1);
  requests.push({ search, page: current });
  if (search === 'slow') await new Promise(resolve => setTimeout(resolve, 900));
  if (state.fail) return route.fulfill({ status: 503, json: { error: { code: 'untrusted-provider-detail' } } });
  const all = Array.from({ length: 2000 }, (_, i) => ({ id: `site-${i}`, name: `Fictional site ${i}`, canSelect: i !== 0 }));
  const rows = all.filter(row => row.id !== state.removed && (!search || (search.startsWith('site-') ? row.id === search : row.name.includes(search))));
  return route.fulfill({ json: { data: rows.slice((current - 1) * 20, current * 20), meta: { current_page: current, last_page: Math.max(1, Math.ceil(rows.length / 20)), total: rows.length, per_page: 20 } } });
 });
 await page.goto(`/?site=${initial}`);
 await expect(page.locator('#dashboard-site')).toBeEnabled();
 return { requests, apiRequests, errors, state };
}

test('dashboard site catalog restores off-page sites and pages/searches without eager loading', async ({ page }) => {
 const { requests, errors, state } = await catalog(page, 'site-1999');
 const select = page.locator('#dashboard-site');
 await expect(select).toHaveValue('site-1999');
 expect(requests).toEqual([{ search: '', page: 1 }, { search: 'site-1999', page: 1 }]);
 await expect(select.locator('option')).toHaveCount(21);
 await openSelect(select);
 await page.getByRole('button', { name: 'Afficher plus', exact: true }).click();
 await expect(select.locator('option')).toHaveCount(41);
 await (await revealTabbedControl(page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }))).fill('1788');
 await expect(select.locator('option[value="site-1788"]')).toBeEnabled();
 await (await revealTabbedControl(select)).selectOption('site-1788');
 await expect(page).toHaveURL(/site=site-1788/);
 state.removed = 'site-1788';
 await page.keyboard.press('Escape');
 await page.getByRole('button', { name: 'Actualiser les plantes', exact: true }).click();
 await expect(select).toHaveValue('site-0');
 await expect(select.locator('option[value="site-0"]')).toBeEnabled();
 expect(requests.at(-1)).toEqual({ search: 'site-1788', page: 1 });
 expect(errors).toEqual([]);
});

test('dashboard site search ignores stale replies and retries without losing the current site', async ({ page }) => {
 const { requests, state, errors } = await catalog(page);
 const select = page.locator('#dashboard-site'); await expect(select).toHaveValue('site-0');
 expect(requests).toHaveLength(1);
 await openSelect(select); const filter = page.getByRole('textbox', { name: 'Filtrer les options…', exact: true });
 const slow = page.waitForResponse(response => new URL(response.url()).searchParams.get('search') === 'slow'); await (await revealTabbedControl(filter)).fill('slow'); await expect.poll(() => requests.at(-1)?.search).toBe('slow');
 await (await revealTabbedControl(filter)).fill('1999'); await expect(select.locator('option[value="site-1999"]')).toBeEnabled();
 await slow;
 await expect(select.locator('option[value="site-1999"]')).toBeEnabled(); await expect(select).toHaveValue('site-0');
 state.fail = true; await (await revealTabbedControl(filter)).fill('1988');
 const alert = page.getByTestId('plant-home').getByRole('alert'); await expect(alert).toBeVisible();
 await expect(alert).not.toContainText('untrusted-provider-detail'); await expect(select).toHaveValue('site-0');
 state.fail = false; await alert.getByRole('button', { name: 'Recharger la liste', exact: true }).click();
 await expect(select.locator('option[value="site-1988"]')).toBeEnabled(); await expect(alert).toHaveCount(0);
 await expect(select).toHaveValue('site-0'); expect(errors).toEqual([]);
});

test('mobile dashboard searches and loads sites inside the styled selector', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 const { requests, errors } = await catalog(page);
 const select = page.locator('#dashboard-site'); await expect(select).toHaveValue('site-0');
 await expect(select).toHaveAttribute('data-select-mode', 'custom'); expect(requests).toHaveLength(1);
 await openSelect(select);
 const popup = page.locator('[data-select-popup]');
 await popup.getByRole('button', { name: 'Afficher plus', exact: true }).click();
 await expect(select.locator('option')).toHaveCount(40);
 await popup.getByRole('option', { name: 'Fictional site 1', exact: true }).click();
 await expect(select).toHaveValue('site-1');
 await openSelect(select);
 await popup.getByRole('textbox').fill('1999');
 await popup.getByRole('option', { name: 'Fictional site 1999', exact: true }).click();
 await expect(select).toHaveValue('site-1999');
 await expect(popup).toHaveCount(0); expect(errors).toEqual([]);
});
