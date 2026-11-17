import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';

test('OIDC-only setup uses the server token without requiring local credentials', async ({ page }) => {
 const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
 const attempts: object[] = [];
 const token = 'a'.repeat(64);
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: true, localEnabled: false, registrationEnabled: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: true, result: null } } });
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (path.endsWith('/setup/oidc')) {
   const body = route.request().postDataJSON(); attempts.push(body);
   if (body.token !== token) return route.fulfill({ status: 403, json: { error: { code: 'auth.errors.invalidSetupToken' } } });
   return route.fulfill({ json: { data: { url: 'http://127.0.0.1:18080/fixture-oidc-authorize' } } });
  }
  return route.fulfill({ json: { data: {} } });
 });
 await page.route('**/fixture-oidc-authorize', route => route.fulfill({ contentType: 'text/html', body: '<h1>Provider authorization</h1>' }));
 await page.goto('/');
 await expect(page.locator('#setup-token')).toBeVisible();
 await expect(page.locator('#setup-password')).toHaveCount(0);
 await expect(page.locator('#setup-name')).toHaveCount(0);
 await page.getByRole('button', { name: /Create administrator with/ }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'token' }).first()).toContainText('token');
 await (await revealTabbedControl(page.locator('#setup-token'))).fill(token);
 await page.getByRole('button', { name: /Create administrator with/ }).click();
 await expect(page.getByRole('heading', { name: 'Provider authorization' })).toBeVisible();
 expect(attempts).toEqual([{ token: '' }, { token }]);
 expect(page.url()).not.toContain(token);
 expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toContain(token);
 expect(errors).toEqual([]);
});

test('mobile setup offers both methods with one shared token field', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.route('**/api/v1/**', route => {
  const path = new URL(route.request().url()).pathname;
  const data = path.endsWith('/setup') ? { requiresSetup: true, localEnabled: true, registrationEnabled: false }
   : path.endsWith('/auth/oidc') ? { enabled: true, result: null } : {};
  return route.fulfill({ json: { data } });
 });
 await page.goto('/');
 await expect(page.locator('#setup-token')).toHaveCount(1);
 await expect(page.locator('#setup-password')).toBeVisible();
 await expect(page.getByRole('button', { name: 'Créer le compte administrateur', exact: true })).toBeVisible();
 await expect(page.getByRole('button', { name: /Créer l’administrateur avec/ })).toBeVisible();
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
