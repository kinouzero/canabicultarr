import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule, selectOptionByText } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('scoped HTTP sources issue one-time credentials, accept exact stateless samples and revoke safely', async ({ page, request, browser }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' }).toString();
 const context = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' }); const reader = await context.newPage();
 const login = async (target: typeof page, email: string, french = false) => { await (await revealTabbedControl(target.locator('#login-email'))).fill(email); await (await revealTabbedControl(target.locator('#login-password'))).fill('A fictional HTTP source password 123!'); await target.getByRole('button', { name: french ? 'Se connecter' : 'Sign in', exact: true }).click(); };
 try {
  php(String.raw`App\Models\User::factory()->create(['name'=>'HTTP Administrator','email'=>'http-admin@example.test','password'=>'A fictional HTTP source password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);App\Models\User::factory()->create(['name'=>'HTTP Reader','email'=>'http-reader@example.test','password'=>'A fictional HTTP source password 123!']);$asset=App\Models\Asset::create(['name'=>'E2E HTTP Manual Device','status'=>'active']);$type=App\Models\MeasurementType::create(['name'=>'E2E HTTP Type','code'=>'e2e_http_type','unit'=>'test','decimal_places'=>3]);$sensor=new App\Models\Sensor(['name'=>'E2E HTTP Probe','channel_key'=>'http_probe','is_enabled'=>true,'expected_interval_seconds'=>60]);$sensor->asset_id=$asset->id;$sensor->measurement_type_id=$type->id;$sensor->save();`);
  await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr'); await login(page, 'http-admin@example.test', true); await openModule(page, 'http'); await page.getByRole('button', { name: 'Ajouter une source HTTP', exact: true }).click();
  await selectOptionByText(page, '#httpIngestion-sensor', 'E2E HTTP Probe');
  await (await revealTabbedControl(page.locator('#http-expiry-days'))).fill('30');
  expect((await page.request.post('/api/v1/integrations/http/sources', { data: { expires_days: 30 } })).status()).toBe(419);
  await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  if (process.env.PLAYWRIGHT_HTTP_INGESTION_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_HTTP_INGESTION_SCREENSHOT, fullPage: true, animations: 'disabled' }); await page.setViewportSize({ width: 1280, height: 720 });
  await page.getByRole('button', { name: 'Créer', exact: true }).click(); const secret = page.locator('#http-one-time-token'); await expect(secret).toBeVisible(); const token = await secret.inputValue(); expect(token).toMatch(/^[a-f0-9]{64}$/);
  const endpoint = (await page.locator('.source-list > li code').textContent())!.trim(); const observed_at = new Date(Date.now() - 60_000).toISOString(); const payload = { value: '41.123456789', unit: 'test', observed_at };
  const send = (credential: string, body = payload) => request.post(endpoint, { headers: { Authorization: 'Bearer ' + credential }, data: body });
  const accepted = await send(token); expect(accepted.status()).toBe(201); expect(accepted.headers()['set-cookie']).toBeUndefined(); expect(await accepted.json()).toEqual({data:{outcome:'stored'}});
  expect((await send(token)).status()).toBe(200); expect((await send(token, { ...payload, value: '50' })).status()).toBe(409);
  await page.reload(); await openModule(page, 'http'); await expect(secret).toHaveCount(0); expect(await page.content()).not.toContain(token);
  await page.getByRole('button', { name: 'Mettre E2E HTTP Probe en pause', exact: true }).click(); await expect(page.getByText('La source HTTP a été modifiée.', { exact: true })).toBeVisible(); expect((await send(token)).status()).toBe(401);
  await page.getByRole('button', { name: 'Reprendre E2E HTTP Probe', exact: true }).click(); await expect(page.getByRole('button', { name: 'Mettre E2E HTTP Probe en pause', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Renouveler le jeton de E2E HTTP Probe', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Renouveler le jeton', exact: true }).click(); await expect(secret).toBeVisible(); const replacement = await secret.inputValue(); expect(replacement).not.toBe(token); expect((await send(token)).status()).toBe(401); expect((await send(replacement)).status()).toBe(200);
  await page.getByRole('button', { name: 'Révoquer le jeton de E2E HTTP Probe', exact: true }).click(); await expect(page.getByText('Le jeton a été révoqué et la source mise en pause.', { exact: true })).toBeVisible(); await expect(secret).toHaveCount(0); expect((await send(replacement)).status()).toBe(401); await expect(page.getByRole('button', { name: 'Reprendre E2E HTTP Probe', exact: true })).toBeDisabled();
  await reader.goto('/'); await login(reader, 'http-reader@example.test'); await expect(reader.getByRole('button', { name: 'HTTP measurement sources', exact: true })).toHaveCount(0);
  await openModule(reader, 'measurements'); await reader.locator('.channel-open').filter({ hasText: 'E2E HTTP Probe' }).click(); await expect(reader.getByRole('cell', { name: '41.123 test', exact: true })).toBeVisible();
  expect(JSON.parse(php(String.raw`$sensor=App\Models\Sensor::where('name','E2E HTTP Probe')->sole();echo json_encode(['readings'=>App\Models\Measurement::where('sensor_id',$sensor->id)->count(),'ha_links'=>App\Models\HomeAssistantLink::where('sensor_id',$sensor->id)->count(),'http_sources'=>App\Models\HttpMeasurementSource::where('sensor_id',$sensor->id)->count()]);`))).toEqual({readings:1,ha_links:0,http_sources:1});
  const stored = await page.evaluate(() => JSON.stringify(localStorage)); expect(stored).not.toContain(token); expect(stored).not.toContain(replacement);
 } finally {
  await context.close();
  php(String.raw`DB::transaction(function(){$sensors=App\Models\Sensor::where('name','E2E HTTP Probe')->pluck('id');App\Models\Measurement::whereIn('sensor_id',$sensors)->delete();App\Models\HttpMeasurementSource::whereIn('sensor_id',$sensors)->delete();App\Models\Sensor::whereIn('id',$sensors)->delete();$assets=App\Models\Asset::where('name','E2E HTTP Manual Device')->pluck('id');App\Models\AssetAssignment::whereIn('asset_id',$assets)->delete();DB::table('asset_contexts')->whereIn('asset_id',$assets)->delete();App\Models\Asset::whereIn('id',$assets)->delete();App\Models\MeasurementType::where('code','e2e_http_type')->delete();$users=App\Models\User::whereIn('email',['http-admin@example.test','http-reader@example.test'])->get();DB::table('sessions')->whereIn('user_id',$users->pluck('id'))->delete();App\Models\User::whereIn('id',$users->pluck('id'))->delete();});`);
 }
});
