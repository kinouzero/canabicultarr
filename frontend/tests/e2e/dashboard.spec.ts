import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('members read culture insights from the real API and lose access after revocation', async ({ page }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' });
 php(String.raw`$admin=App\Models\User::factory()->create(['name'=>'Culture Administrator','email'=>'dashboard-admin@example.test','password'=>'A dashboard test password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);App\Models\User::factory()->create(['name'=>'Culture Member','email'=>'dashboard-member@example.test','password'=>'A dashboard test password 123!']);$site=App\Models\Site::create(['name'=>'E2E Culture Site']);$strain=App\Models\Strain::create(['name'=>'E2E Culture Strain']);foreach(['growth','planned'] as $phase){$plant=app(App\Services\PlantTracking::class)->create($admin,['name'=>'E2E Culture '.$phase,'request_id'=>(string)Illuminate\Support\Str::uuid(),'strain_id'=>$strain->id,'location'=>['type'=>'site','id'=>$site->id]]);$plant->forceFill(['phase'=>$phase])->save();}`);
 try {
  await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr');
  await (await revealTabbedControl(page.locator('#login-email'))).fill('dashboard-member@example.test'); await (await revealTabbedControl(page.locator('#login-password'))).fill('A dashboard test password 123!');
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click(); await openModule(page, 'overview');
  const view = page.getByTestId('culture-overview'); const site = view.getByLabel('Site', { exact: true }).and(view.locator('select'));
  const option = site.locator('option').filter({ hasText: 'E2E Culture Site' }); await expect(option).toBeAttached(); await (await revealTabbedControl(site)).selectOption((await option.getAttribute('value'))!);
  await expect(view.getByTestId('culture-active')).toHaveText('1');
  await expect(view).toContainText('À semer · 1'); await expect(view.locator('canvas').first()).toBeVisible();
  php(String.raw`App\Models\User::where('email','dashboard-member@example.test')->update(['session_version'=>1]);`);
  await view.getByRole('button', { name: 'Recharger', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible(); await expect(view).toHaveCount(0);
 } finally {
  php(String.raw`DB::transaction(function(){$plants=App\Models\Plant::where('name','like','E2E Culture %')->pluck('id');App\Models\PlantEvent::whereIn('plant_id',$plants)->delete();App\Models\Plant::whereIn('id',$plants)->delete();App\Models\Strain::where('name','E2E Culture Strain')->delete();App\Models\Site::where('name','E2E Culture Site')->delete();App\Models\User::whereIn('email',['dashboard-admin@example.test','dashboard-member@example.test'])->delete();});`);
 }
});
