import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('members change their local password and revoke every application session with explicit current-password confirmation', async ({ page, browser }) => {
  const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
  const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
  const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
  const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' });
  const oldPassword = 'A fictional security password 123!'; const newPassword = 'A fictional replacement password 456!';
  const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' }); const other = await otherContext.newPage();
  const login = async (target: typeof page, secret: string, french: boolean) => { await (await revealTabbedControl(target.locator('#login-email'))).fill('security-member@example.test'); await (await revealTabbedControl(target.locator('#login-password'))).fill(secret); await target.getByRole('button', { name: french ? 'Se connecter' : 'Sign in', exact: true }).click(); };
  const fill = async (current: string) => { await (await revealTabbedControl(page.locator('#security-current-password'))).fill(current); await (await revealTabbedControl(page.locator('#security-new-password'))).fill(newPassword); await (await revealTabbedControl(page.locator('#security-confirm-password'))).fill(newPassword); };
  try {
    php(String.raw`
      App\Models\User::factory()->create(['name'=>'Security Administrator','email'=>'security-admin@example.test','password'=>'A fictional security password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);
      App\Models\User::factory()->create(['name'=>'Security Member','email'=>'security-member@example.test','password'=>'A fictional security password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile()->id]]);
    `);
    await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr'); await login(page, oldPassword, true);
    await other.goto('/'); await login(other, oldPassword, false); await expect(other.getByTestId('nav-account')).toBeVisible();
    await openModule(page, 'security');
    await expect(page.getByTestId('other-session-count')).toHaveText('Autres sessions récentes : 1');
    // Real middleware rejects a write without the CSRF header before credential work.
    expect((await page.request.post('/api/v1/account/password', { data: { current_password: oldPassword, password: newPassword, password_confirmation: newPassword } })).status()).toBe(419);
    await page.getByRole('button', { name: 'Modifier le mot de passe', exact: true }).click();
    await fill('Wrong current password'); await page.getByRole('button', { name: 'Changer le mot de passe et me déconnecter', exact: true }).click();
    await expect(page.getByText('Le mot de passe local actuel est incorrect.', { exact: true })).toBeVisible();
    for (const id of ['security-current-password','security-new-password','security-confirm-password']) await expect(page.locator('#' + id)).toHaveValue('');
    await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.PLAYWRIGHT_ACCOUNT_SECURITY_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_ACCOUNT_SECURITY_SCREENSHOT, fullPage: true, animations: 'disabled' }); await page.setViewportSize({ width: 1280, height: 720 });
    await fill(oldPassword); await page.getByRole('button', { name: 'Changer le mot de passe et me déconnecter', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible(); await expect(page.getByText('Votre mot de passe a été changé', { exact: false })).toBeVisible();
    await expect(page.locator('#login-password')).toHaveValue('');
    await openModule(other, 'security'); await expect(other.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
    const failedLogin = page.waitForResponse(response => response.url().endsWith('/api/v1/auth/login') && response.request().method() === 'POST'); await login(page, oldPassword, true); expect((await failedLogin).status()).toBe(422);
    await login(page, newPassword, true); await expect(page.getByTestId('nav-account')).toBeVisible();
    await login(other, newPassword, false); await expect(other.getByTestId('nav-account')).toBeVisible();
    php(String.raw`
      $owner=App\Models\User::where('email','security-member@example.test')->sole();$url='https://fcm.googleapis.com/fcm/send/fictional-security-device';
      App\Models\PushSubscription::create(['user_id'=>$owner->id,'endpoint_hash'=>hash('sha256',$url),'session_hash'=>hash('sha256','fictional-session'),'endpoint'=>$url,'keys'=>['p256dh'=>'fictional-public-key','auth'=>'fictional-auth-key'],'vapid_key_hash'=>hash('sha256','fictional-vapid-key'),'user_version'=>$owner->session_version]);
    `);
    await openModule(page, 'security'); await expect(page.getByTestId('other-session-count')).toHaveText('Autres sessions récentes : 1');
    await page.getByRole('button', { name: 'Déconnecter mes appareils', exact: true }).click();
    await (await revealTabbedControl(page.locator('#security-revoke-password'))).fill(newPassword); await page.getByRole('button', { name: 'Fermer toutes mes connexions', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible(); await expect(page.getByText('Toutes vos connexions à l’application ont été fermées.', { exact: false })).toBeVisible();
    expect((await other.request.get('/api/v1/auth/user')).status()).toBe(401);
    expect(php(String.raw`$owner=App\Models\User::where('email','security-member@example.test')->sole();echo json_encode(['version'=>$owner->session_version,'subscriptions'=>App\Models\PushSubscription::where('user_id',$owner->id)->count(),'profiles'=>$owner->permissionRoles()->count()]);`).toString()).toBe('{"version":2,"subscriptions":0,"profiles":1}');
    await login(page, newPassword, true); await expect(page.getByTestId('nav-account')).toBeVisible();
  } finally {
    await otherContext.close();
    php(String.raw`DB::transaction(function(){$users=App\Models\User::whereIn('email',['security-admin@example.test','security-member@example.test'])->get();DB::table('sessions')->whereIn('user_id',$users->pluck('id'))->delete();DB::table('password_reset_tokens')->whereIn('email',$users->pluck('email'))->delete();App\Models\User::whereIn('id',$users->pluck('id'))->delete();});`);
  }
});
