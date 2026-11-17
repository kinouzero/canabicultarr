import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { activationLink } from './activation-fixture';
import { openModule } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('first administrator, session persistence, logout, and local login', async ({ page }) => {
  const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
  const required = (await (await page.request.get('/api/v1/setup')).json()).data.requiresSetup;
  await page.goto('/');
  await (await revealTabbedControl(page.locator('#language'))).selectOption('fr');
  const csrfRejected = await page.request.post('/api/v1/auth/login', { data: { email: 'admin@example.test', password: 'A long test password 123!' } });
  expect(csrfRejected.status()).toBe(419);
  if (required) {
  const token = execFileSync('docker', [
    ...isolatedCompose(),
    'exec', '-T', 'app', 'php', 'artisan', 'app:setup-token', '--raw', '--no-ansi',
  ], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, encoding: 'utf8' }).trim();
  expect(/^[A-Za-z0-9]{64}$/.test(token)).toBeTruthy();

  await (await revealTabbedControl(page.locator('#setup-token'))).fill('x'.repeat(64));
  await (await revealTabbedControl(page.locator('#setup-name'))).fill('Test Administrator');
  await (await revealTabbedControl(page.locator('#setup-email'))).fill('admin@example.test');
  await (await revealTabbedControl(page.locator('#setup-password'))).fill('A long test password 123!');
  await (await revealTabbedControl(page.locator('#setup-confirmation'))).fill('A long test password 123!');
  await page.getByRole('button', { name: 'Créer le compte administrateur' }).click();
  await expect(page.getByText('Le jeton est invalide ou expiré.', { exact: false })).toBeVisible();

  await (await revealTabbedControl(page.locator('#setup-token'))).fill(token);
  await page.getByRole('button', { name: 'Créer le compte administrateur' }).click();
    await expect(page.getByRole('heading', { name: 'Activer votre compte', exact: true })).toBeVisible();
    expect((await page.request.get('/api/v1/auth/user')).status()).toBe(401);
    await page.goto(activationLink());
    await (await revealTabbedControl(page.locator('#activation-email'))).fill('admin@example.test');
    await page.getByRole('button', { name: 'Activer le compte', exact: true }).click();
  }
  await (await revealTabbedControl(page.locator('#login-email'))).fill('admin@example.test');
  await (await revealTabbedControl(page.locator('#login-password'))).fill('A long test password 123!');
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
  await openModule(page, 'account');
  await expect(page.getByTestId('nav-administration')).toBeVisible();
  expect((await (await page.request.get('/api/v1/auth/user')).json()).data.permissions).toContain('administration.view');
  await page.reload();
  await openModule(page, 'account');
  await expect(page.getByTestId('nav-administration')).toBeVisible();
  expect((await (await page.request.get('/api/v1/auth/user')).json()).data.permissions).toContain('administration.view');
  const user = await page.request.get('/api/v1/auth/user');
  expect(user.status()).toBe(200);
  expect((await user.json()).data.permissionRoles[0].name).toBe('Administrator');

  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible();
  expect((await page.request.get('/api/v1/auth/user')).status()).toBe(401);

  await (await revealTabbedControl(page.locator('#login-email'))).fill('admin@example.test');
  await (await revealTabbedControl(page.locator('#login-password'))).fill('An incorrect password');
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
  await expect(page.getByText('L’adresse e-mail ou le mot de passe est incorrect.')).toBeVisible();
  await (await revealTabbedControl(page.locator('#login-password'))).fill('A long test password 123!');
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
  await openModule(page, 'account');
  await expect(page.getByTestId('nav-administration')).toBeVisible();
  expect((await (await page.request.get('/api/v1/auth/user')).json()).data.permissions).toContain('administration.view');

  // Domain CRUD is covered by focused browser specifications and backend feature tests.
  const storage = await page.evaluate(() => Object.entries(localStorage));
  expect(storage.every(([key]) => ['locale', 'appearance', 'instance-theme'].includes(key))).toBe(true);
  expect(JSON.stringify(storage)).not.toContain('A long test password 123!');
  await page.getByRole('button', { name: 'Se déconnecter', exact: true }).click();
  await expect(page.locator('#login-email')).toBeVisible();
  expect((await page.request.get('/api/v1/auth/user')).status()).toBe(401);
});
