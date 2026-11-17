import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';

async function signIn(page: Page, local: boolean, registration: boolean) {
 const registrations: Record<string, string>[] = [];
 const errors: string[] = [];
 page.on('pageerror', error => errors.push(error.message));
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false, localEnabled: local, registrationEnabled: local && registration } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: true, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (path.endsWith('/auth/register')) {
   registrations.push(route.request().postDataJSON());
   return route.fulfill({ status: 422, json: { errors: { email: ['auth.validation.emailTaken'] } } });
  }
  return route.fulfill({ json: { data: {} } });
 });
 await page.goto('/');
 await expect(page.locator('.oidc-login')).toBeVisible();
 return { registrations, errors };
}

test('registration submits its fields and shows validation while retaining the draft', async ({ page }) => {
 const { registrations, errors } = await signIn(page, true, true);
 await page.getByRole('button', { name: 'S’inscrire', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Créer un compte', exact: true })).toBeVisible();
 await expect(page.locator('#setup-token')).toHaveCount(0);
 await (await revealTabbedControl(page.locator('#setup-name'))).fill('New member');
 await (await revealTabbedControl(page.locator('#setup-email'))).fill('member@example.test');
 await (await revealTabbedControl(page.locator('#setup-password'))).fill('Fictional strong password 123!');
 await (await revealTabbedControl(page.locator('#setup-confirmation'))).fill('Fictional strong password 123!');
 await page.getByRole('button', { name: 'S’inscrire', exact: true }).click();
 await expect(page.locator('#setup-email-error')).toHaveText('Cette adresse e-mail est déjà utilisée.');
 expect(registrations).toEqual([{ name: 'New member', email: 'member@example.test', password: 'Fictional strong password 123!', password_confirmation: 'Fictional strong password 123!', locale: 'fr' }]);
 await expect(page.locator('#setup-name')).toHaveValue('New member');
 await page.getByRole('button', { name: 'Retour à la connexion', exact: true }).click();
 await expect(page.locator('#login-email')).toBeVisible();
 expect(errors).toEqual([]);
});

test('OIDC-only sign-in hides password, recovery and registration controls on mobile', async ({ page }) => {
 const { errors } = await signIn(page, false, true);
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(page.locator('#login-email')).toHaveCount(0);
 await expect(page.locator('#login-password')).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'Mot de passe oublié ?', exact: true })).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'S’inscrire', exact: true })).toHaveCount(0);
 await expect(page.locator('.oidc-login')).toHaveAttribute('href', '/api/v1/auth/oidc/redirect');
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 expect(errors).toEqual([]);
});

test('closed registration keeps password sign-in without exposing sign-up', async ({ page }) => {
 await signIn(page, true, false);
 await expect(page.locator('#login-password')).toBeVisible();
 await expect(page.getByRole('button', { name: 'Mot de passe oublié ?', exact: true })).toBeVisible();
 await expect(page.getByRole('button', { name: 'S’inscrire', exact: true })).toHaveCount(0);
});

test('authentication settings save editable flags and keep environment fields locked', async ({ page }) => {
 const { insightRoutes } = await import('./insights-fixture');
 const { openModule, selectOptionByText } = await import('./navigation');
 const { errors } = await insightRoutes(page);
 const saved: unknown[] = [];
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'auth-admin', name: 'Authentication admin', email: 'admin@example.test', isActive: true, oidcLinked: false, permissions: ['administration.view', 'instance-settings.manage'] } } }));
 await page.route('**/api/v1/instance-settings', route => route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: true } } }));
 await page.route('**/api/v1/connection-settings/**', route => {
  const authentication = route.request().url().endsWith('/authentication');
  if (route.request().method() === 'PATCH') saved.push(route.request().postDataJSON());
  return route.fulfill({ json: { data: { version: saved.length + 1, fields: authentication ? [
   { name: 'AUTH_LOCAL_ENABLED', type: 'boolean', options: [], locked: false, hasValue: true, value: saved.length ? false : true, overridden: saved.length > 0 },
   { name: 'AUTH_REGISTRATION_ENABLED', type: 'boolean', options: [], locked: true, hasValue: true, value: false, overridden: false },
  ] : [] } } });
 });
 await page.reload();
 await openModule(page, 'instanceSettings');
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await page.getByRole('tab', { name: 'Authentification', exact: true }).click();
 await expect(page.locator('#connection-AUTH_REGISTRATION_ENABLED')).toBeDisabled();
 await selectOptionByText(page, '#connection-AUTH_LOCAL_ENABLED', 'Non');

 await expect(page.getByText('Connexion enregistrée.', { exact: true })).toBeVisible();
 expect(saved).toEqual([{ version: 1, values: { AUTH_LOCAL_ENABLED: false } }]);
 expect(errors).toEqual([]);
});

test('public provider branding loads its logo and keeps sign-in usable if the CDN fails', async ({ page }) => {
 await signIn(page, true, false);
 await page.route('**/api/v1/branding', route => route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, oidcProviderName: 'Garden SSO', oidcProviderIcon: 'authentik', version: 1 } } }));
 await page.route('https://cdn.jsdelivr.net/gh/selfhst/icons@main/webp/**', route => route.abort());
 await page.reload();
 const provider = page.getByRole('link', { name: 'Se connecter avec Garden SSO' });
 await expect(provider).toBeVisible();
 await expect(provider.locator('img')).toHaveCount(0);
 await expect(provider).toHaveAttribute('href', '/api/v1/auth/oidc/redirect');
 await expect(page.locator('#login-password')).toBeVisible();
});

test('OIDC logo selection saves independently from an environment-locked provider name', async ({ page }) => {
 const { insightRoutes } = await import('./insights-fixture');
 const { openModule, selectOptionByText } = await import('./navigation');
 await insightRoutes(page);
 let icon: string | null = null;
 let locked = false;
 const saved: Record<string, unknown>[] = [];
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'auth-admin', name: 'Admin', email: 'admin@example.test', isActive: true, oidcLinked: false, permissions: ['administration.view', 'instance-settings.manage'] } } }));
 await page.route('https://cdn.jsdelivr.net/**', route => route.abort());
 await page.route('**/api/v1/instance-settings', route => {
  if (route.request().method() === 'PATCH') {
   const data = route.request().postDataJSON(); saved.push(data); icon = data.oidc_provider_icon;
  }
  return route.fulfill({ json: { data: { oidcProviderName: 'Garden SSO', oidcProviderNameLocked: true, oidcProviderIcon: icon, oidcProviderIconLocked: locked, siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: saved.length + 1, canManage: true } } });
 });
 await page.route('**/api/v1/connection-settings/**', route => route.fulfill({ json: { data: { version: 1, fields: [] } } }));
 await page.reload();
 await openModule(page, 'instanceSettings');
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await page.getByRole('tab', { name: 'OIDC', exact: true }).click();
 await expect(page.locator('#instance-oidc-provider-name')).toBeDisabled();
 await selectOptionByText(page, '#instance-oidc-provider-icon', 'Authentik');

 await expect.poll(() => saved.length).toBe(1);
 expect(saved[0]?.oidc_provider_icon).toBe('authentik');
 expect(saved[0]).not.toHaveProperty('oidc_provider_name');
 locked = true;
 await page.getByRole('button', { name: 'Recharger les paramètres', exact: true }).click();
 await expect(page.locator('#instance-oidc-provider-icon')).toBeDisabled();
 await expect(page.locator('.oidc-provider-settings button[type="submit"]')).toHaveCount(0);
});
