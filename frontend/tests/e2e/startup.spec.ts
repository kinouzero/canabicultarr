import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';

test('startup times out, cancels pending requests and recovers through retry', async ({ page }) => {
 await page.clock.install();
 let attempts = 0;
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/setup')) {
   attempts++;
   if (attempts === 1) return;
   return route.fulfill({ json: { data: { requiresSetup: false } } });
  }
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
  return route.fulfill({ json: { data: {} } });
 });
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.goto('/');
 const startup = page.getByTestId('startup-screen');
 await expect(startup).toBeVisible(); await expect(startup).toHaveAttribute('aria-busy', 'true');
 await expect(page.locator('.auth-language')).toHaveCount(0);
 await page.clock.fastForward(15001);
 await expect(startup.page().getByRole('alert').filter({ hasText: 'Le serveur met trop de temps' }).first()).toContainText('Le serveur met trop de temps');
 await expect(startup).toHaveAttribute('aria-busy', 'false');
 await startup.getByRole('button', { name: 'Réessayer', exact: true }).click();
 await expect(page.locator('#login-email')).toBeVisible(); await expect(startup).toHaveCount(0);
 expect(attempts).toBe(2);
});

test('startup errors stay actionable and fit mobile dark appearance', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 }); await page.emulateMedia({ colorScheme: 'dark' });
 await page.route('**/api/v1/**', route => route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } }));
 await page.goto('/');
 const startup = page.getByTestId('startup-screen');
 await expect(startup.getByRole('alert')).toBeVisible(); await expect(startup.getByRole('button')).toBeVisible();
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('login keeps credential errors, OIDC and password recovery accessible', async ({ page }) => {
 const releaseChecks: string[] = [];
 page.on('request', request => { if (new URL(request.url()).pathname.endsWith('/application-info')) releaseChecks.push(request.url()); });
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.route('**/api/v1/**', route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: true, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
  if (path.endsWith('/auth/login')) return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.requestFailed' }, errors: { email: ['auth.validation.email'] } } });
  return route.fulfill({ json: { data: { enabled: false } } });
 });
 await page.goto('/');
 await expect(page.getByRole('link', { name: 'Se connecter avec Authentik' })).toHaveAttribute('href', '/api/v1/auth/oidc/redirect');
 await (await revealTabbedControl(page.locator('#login-email'))).fill('fictional@example.test');
 await (await revealTabbedControl(page.locator('#login-password'))).fill('Fictional password');
 await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
 await expect(page.locator('#login-email-error')).toBeVisible();
 await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeEnabled();
 await page.getByRole('button', { name: 'Mot de passe oublié ?', exact: true }).click();
 await expect(page.locator('#login-email')).toHaveCount(0);
 await expect(page.getByText('La récupération de mot de passe n’est pas activée', { exact: false })).toBeVisible();
 await page.getByRole('button', { name: 'Retour à la connexion', exact: true }).click();
 await expect(page.locator('#login-email')).toBeVisible();
 expect(releaseChecks).toEqual([]);
});
