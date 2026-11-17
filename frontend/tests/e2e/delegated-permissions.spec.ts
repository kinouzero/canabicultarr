import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('administrators delegate one operational scope, members use it, and revocation closes existing sessions', async ({ page, browser }) => {
  const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
  const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
  const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
  const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' }).toString();
  const memberContext = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' }); const member = await memberContext.newPage();
  const login = async (target: typeof page, email: string, french = false) => { await (await revealTabbedControl(target.locator('#login-email'))).fill(email); await (await revealTabbedControl(target.locator('#login-password'))).fill('A fictional permissions password 123!'); await target.getByRole('button', { name: french ? 'Se connecter' : 'Sign in', exact: true }).click(); };
  const editMember = async () => { await page.getByRole('button', { name: 'Modifier Permissions Member', exact: true }).click(); await page.getByRole('dialog').getByRole('tab', { name: 'Profils et droits', exact: true }).click(); await expect(page.getByRole('group', { name: /Droits de gestion/ })).toBeVisible(); };
  try {
    const memberId = php(String.raw`App\Models\User::factory()->create(['name'=>'Permissions Administrator','email'=>'permissions-admin@example.test','password'=>'A fictional permissions password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);$member=App\Models\User::factory()->create(['name'=>'Permissions Member','email'=>'permissions-member@example.test','password'=>'A fictional permissions password 123!']);echo $member->uuid;`);
    await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr'); await login(page, 'permissions-admin@example.test', true);
    await member.goto('/'); await login(member, 'permissions-member@example.test');
    await openModule(member, 'assets'); await expect(member.getByRole('button', { name: 'Add hardware', exact: true })).toHaveCount(0);
    await openModule(member, 'home');
    await openModule(page, 'users'); await editMember();
    await page.getByLabel(/^Gérer le matériel, les appareils et les affectations/).and(page.locator('select')).selectOption('allow');
    expect((await page.request.patch('/api/v1/users/' + memberId, { data: { permission_grants: ['assets.manage'] } })).status()).toBe(419);
    await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.PLAYWRIGHT_PERMISSIONS_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_PERMISSIONS_SCREENSHOT, fullPage: true, animations: 'disabled' }); await page.setViewportSize({ width: 1280, height: 720 });
    await page.getByRole('button', { name: /^(?:Enregistrer le compte|Créer)$/, exact: true }).click(); await expect(page.getByText('Le compte a été modifié.', { exact: true }).last()).toBeVisible();
    await member.reload(); await expect(member.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
    await login(member, 'permissions-member@example.test'); await openModule(member, 'accountAccess'); await expect(member.locator('.profile-access')).toContainText('Manage equipment, devices and assignments');
    await expect(member.getByRole('button', { name: 'Manage users', exact: true })).toHaveCount(0);
    expect((await member.request.get('/api/v1/users')).status()).toBe(403);
    await openModule(member, 'assets'); await member.getByRole('button', { name: 'Add hardware', exact: true }).click();
    await (await revealTabbedControl(member.locator('#asset-name'))).fill('E2E Delegated Manual Device'); await member.getByRole('button', { name: /^(Save record|Create)$/ }).click(); await expect(member.getByText('E2E Delegated Manual Device', { exact: true })).toBeVisible();
    await openModule(member, 'home'); await openModule(member, 'locations'); await expect(member.getByRole('button', { name: 'Add site', exact: true })).toHaveCount(0);
    await editMember(); await page.getByLabel(/^Gérer le matériel, les appareils et les affectations/).and(page.locator('select')).selectOption('inherit'); await page.getByRole('button', { name: /^(?:Enregistrer le compte|Créer)$/, exact: true }).click(); await expect(page.getByText('Le compte a été modifié.', { exact: true }).last()).toBeVisible();
    await member.reload(); await expect(member.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible(); await login(member, 'permissions-member@example.test');
    await openModule(member, 'accountAccess'); await expect(member.locator('.profile-access')).not.toContainText('Manage equipment, devices and assignments');
    await openModule(member, 'assets'); await expect(member.getByRole('button', { name: 'Add hardware', exact: true })).toHaveCount(0);
    const status = JSON.parse(php(String.raw`$member=App\Models\User::where('email','permissions-member@example.test')->sole();echo json_encode(['version'=>$member->session_version,'profiles'=>$member->permissionRoles()->count(),'grants'=>$member->effectiveOperationalPermissions(),'links'=>App\Models\HomeAssistantLink::count()+App\Models\HomebridgeLink::count()+App\Models\BluetoothLink::count()]);`));
    expect(status).toEqual({ version: 2, profiles: 1, grants: [], links: 0 });
  } finally {
    await memberContext.close();
    php(String.raw`DB::transaction(function(){$assets=App\Models\Asset::where('name','E2E Delegated Manual Device')->pluck('id');App\Models\AssetAssignment::whereIn('asset_id',$assets)->delete();DB::table('asset_contexts')->whereIn('asset_id',$assets)->delete();App\Models\Asset::whereIn('id',$assets)->delete();$users=App\Models\User::whereIn('email',['permissions-admin@example.test','permissions-member@example.test'])->get();DB::table('sessions')->whereIn('user_id',$users->pluck('id'))->delete();DB::table('password_reset_tokens')->whereIn('email',$users->pluck('email'))->delete();App\Models\User::whereIn('id',$users->pluck('id'))->delete();});`);
  }
});
