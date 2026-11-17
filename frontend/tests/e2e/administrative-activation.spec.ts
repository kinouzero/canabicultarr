import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { activationLink } from './activation-fixture';
import { openModule } from './navigation';

test('administrative local account creation requires activation and displays pending email status', async ({ page, browser }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const observerId = execFileSync('docker', [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r', guard + String.raw`App\Models\User::factory()->create(['name'=>'Activation administrator','email'=>'activation-admin@example.test','password'=>'Fictional activation admin 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);echo Database\Seeders\AccessProfileSeeder::defaultProfile()->id;`], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, encoding: 'utf8' }).trim();
 const guestContext = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' });
 const guest = await guestContext.newPage();
 try {
  await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
  await page.goto('/'); await (await revealTabbedControl(page.locator('#login-email'))).fill('activation-admin@example.test'); await (await revealTabbedControl(page.locator('#login-password'))).fill('Fictional activation admin 123!'); await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
  await openModule(page, 'users'); await page.getByRole('button', { name: 'Ajouter un compte', exact: true }).click();
  const editor = page.getByRole('dialog', { name: 'Nouveau compte', exact: true });
  await expect(editor).toContainText('doivent confirmer leur email');
  await (await revealTabbedControl(editor.locator('#user-name'))).fill('Invited observer'); await (await revealTabbedControl(editor.locator('#user-email'))).fill('invited-observer@example.test');
  await editor.getByRole('tab', { name: 'Profils et droits', exact: true }).click();
  await (await revealTabbedControl(editor.locator('#user-permission-profile'))).selectOption(observerId);
  await editor.getByRole('tab', { name: 'Mot de passe', exact: true }).click();
  await (await revealTabbedControl(editor.locator('#user-password'))).fill('Fictional invited observer 123!'); await (await revealTabbedControl(editor.locator('#user-confirmation'))).fill('Fictional invited observer 123!');
  if (process.env.MODAL_REVIEW) for (const width of [1280, 390]) {
   await page.setViewportSize({ width, height: 900 }); await editor.locator('.n-card__content').evaluate(el => { el.scrollTop = 0; }); await editor.screenshot({ path: test.info().outputPath(`canabicultarr-admin-activation-${width}.png`), animations: 'disabled' });
  }
  await editor.getByRole('button', { name: 'Créer', exact: true }).click(); await expect(editor).toHaveCount(0);
  await expect(page.getByRole('status').filter({ hasText: 'L’email d’activation est en attente d’envoi.' }).first()).toContainText('L’email d’activation est en attente d’envoi.');
  const row = page.getByRole('row').filter({ hasText: 'invited-observer@example.test' }); await expect(row).toContainText('Email à confirmer');
  await guest.goto('/'); await (await revealTabbedControl(guest.locator('#login-email'))).fill('invited-observer@example.test'); await (await revealTabbedControl(guest.locator('#login-password'))).fill('Fictional invited observer 123!'); await guest.getByRole('button', { name: /^(Sign in|Se connecter)$/ }).click();
  await expect(guest.locator('#activation-email')).toBeVisible();
  await guest.goto(activationLink()); await (await revealTabbedControl(guest.locator('#activation-email'))).fill('invited-observer@example.test'); await guest.getByRole('button', { name: /^(Activate account|Activer le compte)$/ }).click();
  await (await revealTabbedControl(guest.locator('#login-email'))).fill('invited-observer@example.test'); await (await revealTabbedControl(guest.locator('#login-password'))).fill('Fictional invited observer 123!'); await guest.getByRole('button', { name: /^(Sign in|Se connecter)$/ }).click();
  await expect(guest.getByRole('navigation', { name: /^(Main navigation|Navigation principale)$/ })).toBeVisible();
  expect((await guest.request.get('/api/v1/users')).status()).toBe(403);
  await page.getByRole('button', { name: 'Recharger les utilisateurs', exact: true }).click(); await expect(row).not.toContainText('Email à confirmer');
 } finally { await guestContext.close(); }
});
