import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('forgotten password recovery queues mail, strips the link token and revokes previous sessions', async ({ page, browser }) => {
  const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
  const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
  const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
  const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' }).toString();
  const oldPassword = 'A fictional recovery password 123!'; const newPassword = 'A fictional recovered password 456!';
  const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' }); const other = await otherContext.newPage();
  const login = async (target: typeof page, password: string, french = false) => { await (await revealTabbedControl(target.locator('#login-email'))).fill('recovery-member@example.test'); await (await revealTabbedControl(target.locator('#login-password'))).fill(password); await target.getByRole('button', { name: french ? 'Se connecter' : 'Sign in', exact: true }).click(); };
  try {
    php(String.raw`App\Models\User::factory()->create(['name'=>'Recovery Member','email'=>'recovery-member@example.test','password'=>'A fictional recovery password 123!']);`);
    await other.goto('/'); await login(other, oldPassword); await expect(other.getByTestId('nav-account')).toBeVisible();
    await other.goto('/plants'); await expect(other.locator('#plant-search')).toBeVisible();
    await other.reload(); await expect(other.locator('#plant-search')).toBeVisible(); expect(new URL(other.url()).pathname).toBe('/plants');
    await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr');
    await page.getByRole('button', { name: 'Mot de passe oublié ?', exact: true }).click();
    await (await revealTabbedControl(page.locator('#recovery-email'))).fill('recovery-member@example.test');
    expect((await page.request.post('/api/v1/auth/forgot-password', { data: { email: 'recovery-member@example.test', locale: 'fr' } })).status()).toBe(419);
    await page.getByRole('button', { name: 'Envoyer un lien de récupération', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'vous recevrez un e-mail de réinitialisation' })).toBeVisible();
    const link = php(String.raw`Illuminate\Support\Facades\Http::preventStrayRequests();Illuminate\Support\Facades\Mail::fake();while($job=Illuminate\Support\Facades\Queue::connection('database')->pop('password-recovery'))$job->fire();$mail=Illuminate\Support\Facades\Mail::sent(App\Mail\PasswordRecoveryMail::class)->sole();echo $mail->resetUrl;`);
    expect(link).toMatch(/^http:\/\/127\.0\.0\.1:18080\/#reset-password=[a-f0-9]{64}$/);
    await page.goto(link); await expect(page.getByRole('heading', { name: 'Choisir un nouveau mot de passe', exact: true })).toBeVisible(); expect(new URL(page.url()).hash).toBe('');
    await (await revealTabbedControl(page.locator('#recovery-email'))).fill('recovery-member@example.test'); await (await revealTabbedControl(page.locator('#recovery-password'))).fill('short'); await (await revealTabbedControl(page.locator('#recovery-confirmation'))).fill('short');
    await page.getByRole('button', { name: 'Réinitialiser et fermer toutes les connexions', exact: true }).click();
    await expect(page.locator('#recovery-password-error')).toBeVisible(); await expect(page.locator('#recovery-password')).toHaveValue(''); await expect(page.locator('#recovery-confirmation')).toHaveValue('');
    await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.PLAYWRIGHT_RECOVERY_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_RECOVERY_SCREENSHOT, fullPage: true, animations: 'disabled' });
    await (await revealTabbedControl(page.locator('#recovery-password'))).fill(newPassword); await (await revealTabbedControl(page.locator('#recovery-confirmation'))).fill(newPassword); await page.getByRole('button', { name: 'Réinitialiser et fermer toutes les connexions', exact: true }).click();
    await expect(page.getByText('Votre mot de passe a été réinitialisé.', { exact: false })).toBeVisible(); await expect(page.locator('#login-password')).toHaveValue('');
    expect((await other.request.get('/api/v1/auth/user')).status()).toBe(401);
    const oldLogin = page.waitForResponse(response => response.url().endsWith('/api/v1/auth/login') && response.request().method() === 'POST'); await login(page, oldPassword, true); expect((await oldLogin).status()).toBe(422);
    await login(page, newPassword, true); await page.getByRole('button', { name: 'Ouvrir le menu', exact: true }).click(); await expect(page.getByTestId('nav-account')).toBeVisible();
    // A consumed link remains invalid even if opened from the newly signed-in browser.
    await page.goto(link); await (await revealTabbedControl(page.locator('#recovery-email'))).fill('recovery-member@example.test'); await (await revealTabbedControl(page.locator('#recovery-password'))).fill('A fictional third password 789!'); await (await revealTabbedControl(page.locator('#recovery-confirmation'))).fill('A fictional third password 789!'); await page.getByRole('button', { name: 'Réinitialiser et fermer toutes les connexions', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Ce lien de récupération est invalide' })).toBeVisible();
    const persisted = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage }, history: window.history.state }));
    for (const secret of [oldPassword, newPassword, 'A fictional third password 789!', link.split('#reset-password=')[1]!]) expect(persisted).not.toContain(secret);
    await page.getByRole('button', { name: 'Demander un nouveau lien', exact: true }).click(); await expect(page.getByRole('button', { name: 'Envoyer un lien de récupération', exact: true })).toBeVisible();
  } finally {
    await otherContext.close();
    php(String.raw`DB::transaction(function(){$users=App\Models\User::where('email','recovery-member@example.test')->get();DB::table('sessions')->whereIn('user_id',$users->pluck('id'))->delete();DB::table('password_reset_tokens')->whereIn('email',$users->pluck('email'))->delete();DB::table('jobs')->where('queue','password-recovery')->delete();App\Models\User::whereIn('id',$users->pluck('id'))->delete();});`);
  }
});
