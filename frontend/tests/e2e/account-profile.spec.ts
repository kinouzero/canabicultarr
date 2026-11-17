import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';
import { uiPreferencesFixture } from './ui-preferences-fixture';
import { openModule } from './navigation';

async function session(page: Page, admin = false) {
 const preferences = uiPreferencesFixture('fictional-user');
 let user = { pendingEmail: null as string | null, id: 'fictional-user', name: 'Fictional User', email: 'fictional@example.test', role: admin ? 'admin' : 'member', isActive: true, oidcLinked: true, permissions: admin ? ['administration.view'] : [], locationAccess: null };
 let settings = { oidcProviderName: 'Garden SSO', siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: admin };
 const writes: { path: string; body: Record<string, unknown> }[] = []; const errors: string[] = [];
 let localPasswordAvailable = true; let signedOut = false;
 page.on('pageerror', error => errors.push(error.message));
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname; const method = route.request().method();
  if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (method !== 'GET') {
   const body = route.request().postData() ? route.request().postDataJSON() : {}; writes.push({ path, body });
   if (path.endsWith('/account/profile') && method === 'PATCH') {
    if (body.email === 'taken@example.test') return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { email: ['users.validation.emailTaken'] } } });
    expect(Object.keys(body).sort()).toEqual(['email', 'locale', 'name']); user = { ...user, name: body.name.trim(), pendingEmail: body.email.trim().toLowerCase() === user.email ? user.pendingEmail : body.email.trim().toLowerCase() }; return route.fulfill({ json: { data: user } });
   }
   if (path.endsWith('/account/email-change/resend')) return route.fulfill({ json: { data: user } });
   if (path.endsWith('/account/email-change') && method === 'DELETE') { user = { ...user, pendingEmail: null }; return route.fulfill({ json: { data: user } }); }
   if (path.endsWith('/instance-settings') && method === 'PATCH') { expect(admin).toBe(true); settings = { ...settings, oidcProviderName: body.oidc_provider_name, version: settings.version + 1 }; return route.fulfill({ json: { data: settings } }); }
   if (path.endsWith('/account/password')) { signedOut = true; return route.fulfill({ status: 204 }); }
   throw new Error(`Unexpected mocked write ${method} ${path}`);
  }
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: true, result: null } } });
  if (path.endsWith('/auth/user')) return signedOut ? route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } }) : route.fulfill({ json: { data: user } });
  if (path.endsWith('/branding')) return route.fulfill({ json: { data: { oidcProviderName: settings.oidcProviderName, siteTitle: settings.siteTitle, siteSubtitle: null, version: settings.version } } });
  if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: settings } });
  if (path.endsWith('/account/security')) return route.fulfill({ json: { data: { localPasswordAvailable, databaseSessions: true, otherSessions: 2 } } });
  return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } });
 });
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.goto('/'); await expect(page.getByTestId('plant-home')).toBeVisible();
 return { writes, errors, disablePassword: () => { localPasswordAvailable = false; } };
}

test('members edit only their own profile with validation, cancellation and live identity updates', async ({ page }) => {
 const state = await session(page); await openModule(page, 'account');
 await expect(page.getByTestId('account-oidc-tag')).toHaveText('Garden SSO associé');
 await page.getByRole('button', { name: 'Modifier mon profil', exact: true }).click();
 await (await revealTabbedControl(page.locator('#profile-name'))).fill('Discarded'); await page.getByRole('button', { name: 'Annuler', exact: true }).click(); await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); expect(state.writes).toEqual([]);
 await page.getByRole('button', { name: 'Modifier mon profil', exact: true }).click();
 await expect(page.locator('#profile-name')).toHaveValue('Fictional User');
 await (await revealTabbedControl(page.locator('#profile-name'))).fill('Updated User'); await (await revealTabbedControl(page.locator('#profile-email'))).fill('taken@example.test'); await page.getByRole('button', { name: 'Enregistrer les modifications', exact: true }).click();
 await expect(page.locator('#profile-email-error')).toBeVisible(); await expect(page.locator('#profile-name')).toHaveValue('Updated User');
 await (await revealTabbedControl(page.locator('#profile-email'))).fill('updated@example.test'); await page.getByRole('button', { name: 'Enregistrer les modifications', exact: true }).click();
 await expect(page.getByRole('dialog')).toHaveCount(0); await expect(page.getByTestId('account-profile')).toContainText('updated@example.test'); await expect(page.getByTestId('nav-account')).toContainText('Updated User');
 await expect(page.locator('.profile-name')).toContainText('fictional@example.test');
 await page.getByRole('button', { name: 'Renvoyer le lien', exact: true }).click();
 await page.getByRole('button', { name: 'Annuler le changement', exact: true }).click();
 await expect(page.locator('.email-change-notice')).toHaveCount(0);
 await page.reload(); await openModule(page, 'account'); await expect(page.getByTestId('account-profile')).toContainText('Updated User'); expect(state.errors).toEqual([]);
});

test('provider display name persists in settings, account tag and public sign-in', async ({ page }) => {
 const state = await session(page, true); await openModule(page, 'instanceSettings');
 await page.route('**/api/v1/connection-settings/*', route => route.fulfill({ json: { data: { version: 1, fields: [] } } }));
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await page.getByRole('tab', { name: 'OIDC', exact: true }).click();
 await (await revealTabbedControl(page.locator('#instance-oidc-provider-name'))).fill('My identity provider');
 await expect(page.getByText('Réglages enregistrés.', { exact: true })).toBeVisible();
 await openModule(page, 'account'); await expect(page.getByTestId('account-oidc-tag')).toHaveText('My identity provider associé');
 await page.reload(); await openModule(page, 'account'); await expect(page.getByTestId('account-oidc-tag')).toHaveText('My identity provider associé');
 // Simulate an expired application session without writing to any real account.
 await page.route('**/api/v1/auth/user', route => route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } }));
 await page.reload(); await expect(page.locator('.oidc-login')).toContainText('My identity provider'); expect(state.errors).toEqual([]);
});

test('account security opens sensitive actions in dialogs, clears canceled secrets and handles passwordless accounts', async ({ page }) => {
 const state = await session(page); await openModule(page, 'account');
 const tabs = page.getByRole('tablist', { name: 'Mon compte', exact: true });
 await tabs.getByRole('tab', { name: 'Profil', exact: true }).focus();
 await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight'); await expect(tabs.getByRole('tab', { name: 'Sécurité', exact: true })).toBeFocused();
 await page.keyboard.press('Enter');
 await expect(page.getByTestId('other-session-count')).toContainText('2'); await expect(page.locator('#security-current-password')).toHaveCount(0);
 await page.getByRole('button', { name: 'Modifier le mot de passe', exact: true }).click(); await (await revealTabbedControl(page.locator('#security-current-password'))).fill('fictional secret'); await page.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('button', { name: 'Modifier le mot de passe', exact: true }).click(); await expect(page.locator('#security-current-password')).toHaveValue(''); await page.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('button', { name: 'Déconnecter mes appareils', exact: true }).click(); await expect(page.locator('#security-revoke-password')).toBeVisible(); await page.getByRole('button', { name: 'Annuler', exact: true }).click();
 state.disablePassword(); await page.getByRole('button', { name: 'Actualiser la sécurité du compte', exact: true }).click(); await expect(page.getByRole('button', { name: 'Modifier le mot de passe', exact: true })).toBeDisabled(); expect(state.writes).toEqual([]); expect(state.errors).toEqual([]);
});

test('account tabs separate identity, preferences and access and support browser history', async ({ page }) => {
 const { errors } = await session(page); await openModule(page, 'account');
 await expect(page.getByRole('tab')).toHaveCount(5);
 await expect(page.locator('.profile-identity')).toBeVisible();
 await expect(page.getByTestId('profile-preferences')).toHaveCount(0);
 await page.getByRole('tab', { name: 'Préférences', exact: true }).click();
 await expect(page).toHaveURL(/\/account\/preferences$/);
 await expect(page.getByTestId('profile-preferences')).toBeVisible();
 await expect(page.locator('.profile-identity')).toHaveCount(0);
 await page.getByRole('tab', { name: 'Accès', exact: true }).click();
 await expect(page).toHaveURL(/\/account\/access$/);
 await expect(page.locator('.profile-access')).toBeVisible();
 await expect(page.getByTestId('profile-preferences')).toHaveCount(0);
 await page.goBack(); await expect(page.getByTestId('profile-preferences')).toBeVisible();
 await page.reload(); await expect(page.getByTestId('profile-preferences')).toBeVisible();
 expect(errors).toEqual([]);
});
