import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule, selectOptionByText } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('administrators create reusable profiles, assign members and propagate changed rights with session revocation', async ({ page, browser }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' }).toString();
 const context = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' }); const member = await context.newPage();
 const login = async (target: typeof page, email: string, french = false) => { await (await revealTabbedControl(target.locator('#login-email'))).fill(email); await (await revealTabbedControl(target.locator('#login-password'))).fill('A fictional access profile password 123!'); await target.getByRole('button', { name: french ? 'Se connecter' : 'Sign in', exact: true }).click(); };
 const assign = async (name: string, direct: boolean) => {
  await page.getByRole('button', { name: 'Modifier ' + name, exact: true }).click();
  await page.getByRole('dialog').getByRole('tab', { name: 'Profils et droits', exact: true }).click();
  await selectOptionByText(page, '#user-permission-profile', 'E2E Access Operators');
  await expect(page.getByRole('dialog').locator('.user-profile-chips')).toContainText('E2E Access Operators');
  if (direct) await (await revealTabbedControl(page.getByLabel(/Gérer le stock et ses mouvements/).and(page.locator('select')))).selectOption('allow');
  await page.getByRole('button', { name: /^(?:Enregistrer le compte|Créer)$/, exact: true }).click();
  await expect(page.getByText('Le compte a été modifié.', { exact: true }).last()).toBeVisible();
 };
 try {
  php(String.raw`App\Models\User::factory()->create(['name'=>'Profile Administrator','email'=>'profile-admin@example.test','password'=>'A fictional access profile password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);App\Models\User::factory()->create(['name'=>'Profile Member One','email'=>'profile-one@example.test','password'=>'A fictional access profile password 123!']);App\Models\User::factory()->create(['name'=>'Profile Member Two','email'=>'profile-two@example.test','password'=>'A fictional access profile password 123!','is_active'=>false]);`);
  await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr'); await login(page, 'profile-admin@example.test', true);
  await openModule(page, 'profiles'); await page.getByRole('button', { name: 'Créer un profil', exact: true }).click();
  await (await revealTabbedControl(page.locator('#profile-name'))).fill('E2E Access Operators'); await page.getByRole('dialog').getByRole('tab', { name: 'Droits', exact: true }).click(); await (await revealTabbedControl(page.getByLabel('Gérer le matériel, les appareils et les affectations', { exact: true }))).check();
  expect((await page.request.post('/api/v1/permission-roles', { data: { name: 'CSRF rejected fixture', permissions: [] } })).status()).toBe(419);
  await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  if (process.env.PLAYWRIGHT_ACCESS_PROFILES_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_ACCESS_PROFILES_SCREENSHOT, fullPage: true, animations: 'disabled' }); await page.setViewportSize({ width: 1280, height: 720 });
  await page.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true }).click(); await expect(page.getByText('Le profil d’accès a été créé.', { exact: true })).toBeVisible();
  await openModule(page, 'home'); await openModule(page, 'users');
  await assign('Profile Member One', true); await assign('Profile Member Two', false);
  await member.goto('/'); await login(member, 'profile-one@example.test'); await openModule(member, 'accountAccess'); expect((await (await member.request.get('/api/v1/auth/user')).json()).data.permissionRoles).toEqual(expect.arrayContaining([expect.objectContaining({ name: 'E2E Access Operators' })])); await expect(member.locator('.profile-access')).toContainText('Manage equipment, devices and assignments');
  await expect(member.getByRole('button', { name: 'Access profiles', exact: true })).toHaveCount(0); expect((await member.request.get('/api/v1/permission-roles')).status()).toBe(403);
  await openModule(member, 'assets'); await expect(member.getByRole('button', { name: 'Add hardware', exact: true })).toBeVisible();
  await openModule(page, 'home'); await openModule(page, 'profiles'); await expect(page.locator('.resource-card').filter({ has: page.getByRole('heading', { name: 'E2E Access Operators', exact: true }) }).getByText('Comptes associés : 2', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Modifier E2E Access Operators', exact: true }).click();
  php(String.raw`App\Models\PermissionRole::where('name','E2E Access Operators')->increment('version');`);
  await page.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true }).click(); await expect(page.getByText('Ce profil a changé.', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: 'Recharger les profils', exact: true }).click(); await page.getByRole('button', { name: 'Modifier E2E Access Operators', exact: true }).click();
  await page.getByRole('dialog').getByRole('tab', { name: 'Droits', exact: true }).click(); await (await revealTabbedControl(page.getByLabel('Gérer le matériel, les appareils et les affectations', { exact: true }))).uncheck(); await (await revealTabbedControl(page.getByLabel('Gérer les capteurs et les types de mesure', { exact: true }))).check();
  await page.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true }).click(); await expect(page.getByText('Le profil d’accès a été modifié.', { exact: true })).toBeVisible();
  await member.reload(); await expect(member.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible(); await login(member, 'profile-one@example.test');
  await openModule(member, 'assets'); await expect(member.getByRole('button', { name: 'Add hardware', exact: true })).toHaveCount(0);
  const result = JSON.parse(php(String.raw`echo json_encode(App\Models\User::whereIn('email',['profile-one@example.test','profile-two@example.test'])->orderBy('email')->get()->map(fn($u)=>['version'=>$u->session_version,'permissions'=>$u->effectiveOperationalPermissions()])->all());`));
  expect(result).toEqual([{version:2,permissions:['inventory.manage','sensors.manage']},{version:2,permissions:['sensors.manage']}]);
  await page.getByRole('button', { name: 'Modifier E2E Access Operators', exact: true }).click(); await (await revealTabbedControl(page.getByLabel('Archiver ce profil', { exact: true }))).check(); await page.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true }).click(); await expect(page.locator('#profile-archived-error')).toHaveText('Retirez toutes les attributions à des comptes avant d’archiver ce profil.');
 } finally {
  await context.close();
  php(String.raw`DB::transaction(function(){$users=App\Models\User::whereIn('email',['profile-admin@example.test','profile-one@example.test','profile-two@example.test'])->get();DB::table('sessions')->whereIn('user_id',$users->pluck('id'))->delete();DB::table('password_reset_tokens')->whereIn('email',$users->pluck('email'))->delete();App\Models\User::whereIn('id',$users->pluck('id'))->delete();App\Models\PermissionRole::where('name','E2E Access Operators')->delete();});`);
 }
});
