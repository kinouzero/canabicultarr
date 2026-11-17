import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { openModule, selectOptionByText } from './navigation';

test('administrators configure unlocked connections while environment fields and secrets stay protected', async ({ page }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' });
 const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
 try {
  php(String.raw`App\Models\User::factory()->create(['name'=>'Connection Administrator','email'=>'connections@example.test','password'=>'Fictional connection password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);`);
  await page.addInitScript(() => localStorage.setItem('locale', 'fr')); await page.goto('/');
  await (await revealTabbedControl(page.locator('#login-email'))).fill('connections@example.test'); await (await revealTabbedControl(page.locator('#login-password'))).fill('Fictional connection password 123!');
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click(); await openModule(page, 'instanceSettings');
  await page.getByRole('tab', { name: 'Connexions', exact: true }).click(); await page.getByRole('tab', { name: 'E-mail', exact: true }).click();
  await expect(page.locator('#connection-MAIL_HOST')).toBeDisabled();
  await expect(page.locator('#connection-MAIL_PASSWORD')).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Revenir aux valeurs d’environnement', exact: true })).toHaveCount(0);
  await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
  await expect(page.locator('#connection-HOMEBRIDGE_SYNC_INTERVAL_SECONDS')).toHaveValue('60');
  await (await revealTabbedControl(page.locator('#connection-HOMEBRIDGE_SYNC_INTERVAL_SECONDS'))).fill('300');
  await selectOptionByText(page, '#connection-HOMEBRIDGE_EVENTS_ENABLED', 'Oui');
  await (await revealTabbedControl(page.locator('#connection-HOMEBRIDGE_URL'))).fill('https://fictional-homebridge.example.test');
  await (await revealTabbedControl(page.locator('#connection-HOMEBRIDGE_USERNAME'))).fill('fictional-integration');
  await (await revealTabbedControl(page.locator('#connection-HOMEBRIDGE_PASSWORD'))).fill('Fictional provider secret');
  await page.locator('#connection-HOMEBRIDGE_PASSWORD').blur();

  await expect(page.getByText('Connexion enregistrée.', { exact: true })).toBeVisible();
  await expect(page.locator('#connection-HOMEBRIDGE_PASSWORD')).toHaveValue('');
  await expect(page.locator('#connection-HOMEBRIDGE_PASSWORD')).toHaveAttribute('placeholder', 'Secret configuré');
  const settings = await page.request.get('/api/v1/connection-settings/homebridge');
  expect(await settings.text()).not.toContain('Fictional provider secret');
  await page.reload(); await openModule(page, 'instanceSettings'); await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
  await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
  await expect(page.locator('#connection-HOMEBRIDGE_USERNAME')).toHaveValue('fictional-integration');
  await expect(page.locator('#connection-HOMEBRIDGE_SYNC_INTERVAL_SECONDS')).toHaveValue('300');
  const status = await page.request.get('/api/v1/integrations/homebridge');
  expect((await status.json()).data.sync).toMatchObject({ enabled: true, configurationValid: true, intervalSeconds: 300, eventsEnabled: true, linksCount: 0, nextEligibleAt: null });
  await expect(page.getByRole('button', { name: 'Revenir aux valeurs d’environnement', exact: true })).toHaveCount(0);
  await page.getByRole('tab', { name: 'Webhook de notification', exact: true }).click();
  const internalHosts = page.locator('#connection-ALERT_WEBHOOK_ALLOWED_INTERNAL');
  await (await revealTabbedControl(internalHosts)).fill('0.0.0.0/0');

  await expect(internalHosts).toHaveAttribute('aria-invalid', 'true');
  await (await revealTabbedControl(internalHosts)).fill('ntfy.lan,192.168.10.0/24');

  await expect(page.getByText('Connexion enregistrée.', { exact: true })).toBeVisible();
  await page.route('**/api/v1/connection-settings/webhook/check', route => route.fulfill({ status: 503, json: { error: { code: 'connectionSettings.webhookBlocked' } } }));
  await page.getByRole('button', { name: 'Tester la connexion', exact: true }).click();
  await expect(page.getByText(/Destination bloquée/)).toBeVisible();
  await page.getByRole('tab', { name: 'Stockage', exact: true }).click();
  await expect(page.locator('#connection-DOCUMENTS_DISK')).toBeDisabled();
  await expect(page.locator('#connection-AWS_BUCKET')).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Tester la connexion', exact: true })).toBeVisible();
  // Simulate a completely environment-managed group without changing deployment credentials.
  await page.route('**/api/v1/connection-settings/homebridge', async route => {
   const response = await route.fetch(); const body = await response.json();
   body.data.fields = body.data.fields.map((field: Record<string, unknown>) => ({ ...field, locked: true }));
   await route.fulfill({ response, json: body });
  });
  await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
  await page.getByRole('tab', { name: 'E-mail', exact: true }).click();
  await expect(page.locator('#connection-MAIL_HOST')).toBeDisabled();
  await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
  await expect(page.locator('#connection-HOMEBRIDGE_USERNAME')).toBeDisabled();
  const connection = page.locator('.connection-settings:visible');
  await expect(connection.getByRole('button', { name: 'Enregistrer les paramètres', exact: true })).toHaveCount(0);
  await expect(connection.getByRole('button', { name: 'Revenir aux valeurs d’environnement', exact: true })).toHaveCount(0);
  await page.setViewportSize({ width: 390, height: 844 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
 } finally {
  php(String.raw`DB::table('connection_settings')->whereIn('id',['homebridge','webhook'])->update(['values'=>null]);$user=App\Models\User::where('email','connections@example.test')->first();if($user){DB::table('sessions')->where('user_id',$user->id)->delete();$user->delete();}`);
 }
});
