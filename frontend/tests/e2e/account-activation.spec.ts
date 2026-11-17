import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { activationLink } from './activation-fixture';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('initial administrator must activate the emailed link before signing in', async ({ page }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const prefix = [...isolatedCompose(), 'exec', '-T', 'app'];
 const options = { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, encoding: 'utf8' as const };
 const token = execFileSync('docker', [...prefix, 'php', 'artisan', 'app:setup-token', '--raw', '--no-ansi'], options).trim();
 await page.goto('/');
 await (await revealTabbedControl(page.locator('#language'))).selectOption('fr');
 await (await revealTabbedControl(page.locator('#setup-token'))).fill(token);
 await (await revealTabbedControl(page.locator('#setup-name'))).fill('Test Administrator');
 await (await revealTabbedControl(page.locator('#setup-email'))).fill('admin@example.test');
 await (await revealTabbedControl(page.locator('#setup-password'))).fill('A long test password 123!');
 await (await revealTabbedControl(page.locator('#setup-confirmation'))).fill('A long test password 123!');
 await page.getByRole('button', { name: 'Créer le compte administrateur', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Activer votre compte', exact: true })).toBeVisible();
 expect((await page.request.get('/api/v1/auth/user')).status()).toBe(401);
 const link = activationLink();
 expect(link).toMatch(/^http:\/\/127\.0\.0\.1:18080\/#activate-account=[A-Za-z0-9]{64}$/);
 await page.goto(link);
 await expect(page.getByRole('button', { name: 'Activer le compte', exact: true })).toBeVisible();
 await expect.poll(() => new URL(page.url()).hash).toBe('');
 await (await revealTabbedControl(page.locator('#activation-email'))).fill('admin@example.test');
 await page.getByRole('button', { name: 'Activer le compte', exact: true }).click();
 await expect(page.getByRole('status').filter({ hasText: 'Votre compte est activé.' })).toBeVisible();
 await (await revealTabbedControl(page.locator('#login-password'))).fill('A long test password 123!');
 await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
 await expect(page.getByTestId('nav-account')).toBeVisible();
 const persisted = await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]));
 expect(persisted).not.toContain(link.split('=')[1]!);
});

test('activation link errors allow a safe resend on mobile', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 const sent: object[] = [];
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false, localEnabled: true, registrationEnabled: true } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (path.endsWith('/activation/confirm')) return route.fulfill({ status: 422, json: { error: { code: 'accountActivation.invalidLink' } } });
  if (path.endsWith('/activation/resend')) { sent.push(route.request().postDataJSON()); return route.fulfill({ status: 204 }); }
  return route.fulfill({ json: { data: {} } });
 });
 await page.goto('/#activate-account=' + 'a'.repeat(64));
 await (await revealTabbedControl(page.locator('#activation-email'))).fill('pending@example.test');
 await page.getByRole('button', { name: 'Activer le compte', exact: true }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'Ce lien est invalide' }).first()).toContainText('Ce lien est invalide');
 await page.getByRole('button', { name: 'Envoyer le lien', exact: true }).click();
 await page.getByRole('button', { name: 'Envoyer le lien', exact: true }).click();
 await expect(page.getByRole('status')).toContainText('Si un compte attend son activation');
 expect(sent).toEqual([{ email: 'pending@example.test', locale: 'fr' }]);
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
