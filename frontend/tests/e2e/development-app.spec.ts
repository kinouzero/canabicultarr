import { test, expect } from '../coverage/fixture';

test.skip(process.env.E2E_DEV_SERVER !== 'true', 'Run through scripts/test-development.sh');

test('development proxy loads optimized Vite dependencies while protecting private paths', async ({ page }) => {
 const errors: string[] = [];
 const dependencies: number[] = [];
 page.on('pageerror', error => errors.push(error.message));
 page.on('response', response => {
  if (new URL(response.url()).pathname.startsWith('/node_modules/.vite/deps/')) dependencies.push(response.status());
 });
 await page.goto('/');
 await expect(page.locator('#app')).not.toBeEmpty();
 await expect(page.locator('input[type="email"]')).toBeVisible();
 expect(dependencies.length).toBeGreaterThan(0);
 expect(dependencies.every(status => status === 200 || status === 304)).toBe(true);
 expect(errors).toEqual([]);
 for (const path of ['/.env', '/.git/config', '/src/.env', '/node_modules/.env']) {
  expect((await page.request.get(path)).status(), path).toBe(403);
 }
 expect((await page.request.get('/index.php')).status()).toBe(404);
 await page.reload();
 await expect(page.locator('input[type="email"]')).toBeVisible();
 expect(errors).toEqual([]);
});
