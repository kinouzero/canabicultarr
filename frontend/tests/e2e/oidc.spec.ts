import { revealTabbedControl } from './navigation';
import { uiPreferencesFixture } from './ui-preferences-fixture';
import { openModule } from './navigation';
import { test, expect } from '../coverage/fixture';

// Protocol verification is covered by PHP tests with real RSA-signed ID tokens.
// This test exercises localized UI states without depending on a real IdP.
test('Authentik sign-in, explicit linking, and sanitized callback messages', async ({ page }) => {
  const preferences = uiPreferencesFixture('fictional-user');
  let signedIn = false;
  let linked = false;
  let result: string | null = null;
  let started = false;
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
    let data: object;
    switch (path) {
      case '/api/v1/application-info': await route.fulfill({ status: 503, json: {} }); return;
      case '/api/v1/branding': data = { siteTitle: 'Canabicultarr', siteSubtitle: null, oidcProviderName: 'Authentik', version: 1 }; break;
      case '/api/v1/instance-settings': data = { dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: false }; break;
      case '/api/v1/notifications/inbox': await route.fulfill({ json: { data: [], unreadCount: 0, meta: { current_page: 1, last_page: 1, total: 0 } } }); return;
      case '/api/v1/lifecycle-settings': data = { stages: [], icons: [], durations: {}, version: 1, canManage: false }; break;
      case '/api/v1/sites': await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }); return;
      case '/api/v1/setup': data = { requiresSetup: false }; break;
      case '/api/v1/auth/oidc':
        data = { enabled: true, result };
        result = null;
        break;
      case '/api/v1/auth/csrf': await route.fulfill({ status: 204 }); return;
      case '/api/v1/auth/login': signedIn = true; // Continue with the account response.
      case '/api/v1/auth/user':
        if (!signedIn) {
          await route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
          return;
        }
        data = { id: 'fictional-user', name: 'Test Member', email: 'member@example.test', role: 'member', permissions: [], isActive: true, oidcLinked: linked };
        break;
      case '/api/v1/auth/oidc/link':
        expect(route.request().method()).toBe('POST');
        linked = true;
        result = 'linked';
        data = { url: new URL('/', route.request().url()).href };
        break;
      case '/api/v1/auth/logout':
        signedIn = false;
        await route.fulfill({ status: 204 }); return;
      case '/api/v1/auth/oidc/redirect':
        started = true;
        result = 'auth.errors.oidcFailed';
        await route.fulfill({ status: 302, headers: { location: '/' } }); return;
      default: throw new Error(`Unexpected test endpoint: ${path}`);
    }
    await route.fulfill({ json: { data } });
  });
  await page.goto('/');
  const sso = page.getByRole('link', { name: 'Sign in with Authentik' });
  await expect(sso).toHaveAttribute('href', '/api/v1/auth/oidc/redirect');
  await (await revealTabbedControl(page.locator('#login-email'))).fill('member@example.test');
  await (await revealTabbedControl(page.locator('#login-password'))).fill('A fictional local password 123!');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await openModule(page, 'account');
  await page.getByRole('button', { name: 'Connect Authentik', exact: true }).click();
  await expect(page.getByText('Authentik is now connected. You can use it the next time you sign in.')).toBeVisible();
  await openModule(page, 'account');
  await expect(page.getByTestId('account-oidc-tag')).toBeVisible();
  await expect(page.getByText('Authentik is now connected. You can use it the next time you sign in.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Connect Authentik', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Sign out' }).click();
  await page.getByRole('link', { name: 'Sign in with Authentik' }).click();
  await expect(page.getByText('Authentik sign-in could not be completed. Try again or sign in with your password.')).toBeVisible();
  expect(started).toBeTruthy();
  await (await revealTabbedControl(page.locator('#language'))).selectOption('fr');
  await expect(page.getByRole('link', { name: 'Se connecter avec Authentik' })).toBeVisible();
  await expect(page.getByText('La connexion Authentik a échoué. Réessayez ou connectez-vous avec votre mot de passe.')).toBeVisible();
});
