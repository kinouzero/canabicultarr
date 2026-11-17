import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { openModule } from './navigation';

function isolatedPhp(code: string): string {
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 return execFileSync('docker', [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r', guard + code], { cwd: fileURLToPath(new URL('../../..', import.meta.url)), env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, encoding: 'utf8' }).trim();
}

test('email replacement requires mailbox proof and closes previous sessions without losing access', async ({ page }) => {
 isolatedPhp(String.raw`App\Models\User::factory()->create(['email'=>'email-change-current@example.test','password'=>Illuminate\Support\Facades\Hash::make('A fictional password 123!')]);`);
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.goto('/');
 await (await revealTabbedControl(page.locator('#login-email'))).fill('email-change-current@example.test');
 await (await revealTabbedControl(page.locator('#login-password'))).fill('A fictional password 123!');
 await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
 await openModule(page, 'account');
 await page.getByRole('button', { name: 'Modifier mon profil', exact: true }).click();
 await (await revealTabbedControl(page.locator('#profile-email'))).fill('email-change-new@example.test');
 await page.getByRole('button', { name: 'Enregistrer les modifications', exact: true }).click();
 await expect(page.locator('.email-change-notice')).toContainText('email-change-new@example.test');
 await expect(page.locator('.profile-name')).toContainText('email-change-current@example.test');
 const link = isolatedPhp(String.raw`Illuminate\Support\Facades\Mail::fake();$job=Illuminate\Support\Facades\Queue::connection('database')->pop('account-activation');$job->fire();$job->delete();echo Illuminate\Support\Facades\Mail::sent(App\Mail\EmailChangeMail::class)->sole()->confirmationUrl;`);
 expect(link).toMatch(/^http:\/\/127\.0\.0\.1:18080\/#confirm-email=[A-Za-z0-9]{64}$/);
 await page.goto(link);
 await expect(page.getByRole('heading', { name: 'Confirmer la nouvelle adresse', exact: true })).toBeVisible();
 await expect.poll(() => new URL(page.url()).hash).toBe('');
 await (await revealTabbedControl(page.locator('#activation-email'))).fill('email-change-new@example.test');
 await page.getByRole('button', { name: 'Confirmer l’adresse', exact: true }).click();
 await expect(page.getByRole('status').filter({ hasText: 'Adresse confirmée.' })).toBeVisible();
 expect((await page.request.get('/api/v1/auth/user')).status()).toBe(401);
 await (await revealTabbedControl(page.locator('#login-password'))).fill('A fictional password 123!');
 await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
 await openModule(page, 'account');
 await expect(page.locator('.profile-name')).toContainText('email-change-new@example.test');
 await expect(page.locator('.email-change-notice')).toHaveCount(0);
 expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toContain(link.split('=')[1]!);
});
