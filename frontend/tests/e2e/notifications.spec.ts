import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule, openNotificationSection } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('notification opt-in, global consent, safe history and session-bound browser removal', async ({ page }) => {
  const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
  const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
  const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
  const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' });
  const login = async (email: string) => { await (await revealTabbedControl(page.locator('#login-email'))).fill(email); await (await revealTabbedControl(page.locator('#login-password'))).fill('A notification test password 123!'); await page.getByRole('button', { name: 'Se connecter', exact: true }).click(); };
  const open = async () => { await openModule(page, 'notifications'); await expect(page.getByRole('heading', { name: 'Canaux de notification', exact: true })).toBeVisible(); };
  try {
    const subscription = JSON.parse(php(String.raw`
      App\Models\User::factory()->create(['name'=>'Notification Administrator','email'=>'notify-admin@example.test','password'=>'A notification test password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);
      App\Models\User::factory()->create(['name'=>'Notification Member','email'=>'notify-member@example.test','password'=>'A notification test password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile()->id]]);
      $keys=Minishlink\WebPush\VAPID::createVapidKeys();echo json_encode(['endpoint'=>'https://fcm.googleapis.com/fcm/send/e2e-notification-browser','keys'=>['p256dh'=>$keys['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]]);
    `).toString());
    await page.goto('/'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr'); await login('notify-admin@example.test'); await open();
    await (await revealTabbedControl(page.locator('#notification-global-email'))).check(); await (await revealTabbedControl(page.locator('#notification-global-push'))).check(); await (await revealTabbedControl(page.locator('#notification-global-webhook'))).check();

    await expect(page.getByText('Réglages des notifications mis à jour.', { exact: true })).toBeVisible();
    await openModule(page, 'home'); await page.getByRole('button', { name: 'Se déconnecter', exact: true }).click();
    await login('notify-member@example.test'); await open();
    await openNotificationSection(page, 'preferences');
    await expect(page.getByRole('heading', { name: 'Réglages des envois de l’installation', exact: true })).toHaveCount(0);
    await (await revealTabbedControl(page.locator('#notification-email'))).check(); await (await revealTabbedControl(page.locator('#notification-locale'))).selectOption('fr');
    await expect(page.getByText('Réglages des notifications mis à jour.', { exact: true })).toBeVisible();
    // Production-only browser subscription is exercised separately. Register a fictional
    // capability through the real session/CSRF API to verify ownership and logout binding.
    await page.request.get('/api/v1/auth/csrf');
    const csrf = (await page.context().cookies()).find(cookie => cookie.name === 'XSRF-TOKEN')!;
    const response = await page.request.post('/api/v1/notifications/subscriptions', { headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) }, data: subscription }); expect(response.status()).toBe(201);
    await page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true }).click(); await expect(page.getByRole('button', { name: 'Retirer l’abonnement 1', exact: true })).toBeVisible();
    expect(await page.content()).not.toContain(subscription.endpoint);
    const dispatch = JSON.parse(php(String.raw`
      config(['alert_notifications.webhook.url'=>'https://notify.example.test/hook']);
      app()->bind(App\Services\WebhookDnsResolver::class,fn()=>new class extends App\Services\WebhookDnsResolver{public function addresses(string $host):array{return ['93.184.216.34'];}});
      Illuminate\Support\Facades\Http::preventStrayRequests();Illuminate\Support\Facades\Http::fake(['*'=>Illuminate\Support\Facades\Http::response([],202)]);Illuminate\Support\Facades\Mail::fake();
      app()->bind(App\Services\WebPushDelivery::class,fn()=>new class extends App\Services\WebPushDelivery{public function send(App\Models\PushSubscription $s,App\Models\NotificationDelivery $d):App\Services\NotificationResult{return new App\Services\NotificationResult(true);}});
      $admin=App\Models\User::where('email','notify-admin@example.test')->sole();$device=app(App\Services\AssetAdministration::class)->save($admin,['name'=>'E2E Notification Device']);
      $type=App\Models\MeasurementType::create(['name'=>'E2E Notification Type','code'=>'e2e_notification_type','unit'=>'test']);$sensor=app(App\Services\SensorAdministration::class)->save($admin,['name'=>'E2E Notification Probe','device_id'=>$device->id,'measurement_type_id'=>$type->id,'channel_key'=>'probe']);
      $rule=App\Models\AlertRule::create(['sensor_id'=>$sensor->id,'name'=>'E2E Notification Rule','kind'=>'missing','severity'=>'warning','delay_seconds'=>120,'activated_at'=>now()->subHour()]);app(App\Services\AlertEvaluator::class)->evaluate($rule);
      Illuminate\Support\Facades\Artisan::call('notifications:dispatch');echo json_encode(['sent'=>App\Models\NotificationDelivery::where('status','sent')->count(),'mail'=>count(Illuminate\Support\Facades\Mail::sent(App\Mail\MonitoringAlertMail::class))]);
    `).toString());
    expect(dispatch.sent).toBe(3); expect(dispatch.mail).toBe(1);
    await openNotificationSection(page, 'history');
    await page.getByRole('button', { name: 'Actualiser l’historique des envois', exact: true }).click(); await expect(page.locator('.notification-history tbody > tr')).toHaveCount(2); await expect(page.locator('.notification-history')).toContainText('Accepté par le service');
    await page.setViewportSize({ width: 390, height: 844 }); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (process.env.PLAYWRIGHT_NOTIFICATIONS_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_NOTIFICATIONS_SCREENSHOT, fullPage: true, animations: 'disabled' }); await page.setViewportSize({ width: 1280, height: 720 });
    const pagination = page.getByRole('navigation', { name: 'Pages de l’historique des envois', exact: true });
    await (await revealTabbedControl(pagination.locator('select'))).selectOption('10');
    await expect.poll(async () => (await (await page.request.get('/api/v1/account/ui-preferences')).json()).data['pagination:notification-deliveries:size']).toBe(10);
    await (await revealTabbedControl(pagination.locator('select'))).selectOption('50');
    await openNotificationSection(page, 'preferences');
    // Optimistic conflict recovery must not silently overwrite another saved preference.
    php(String.raw`App\Models\NotificationPreference::where('user_id',App\Models\User::where('email','notify-member@example.test')->value('id'))->increment('version');`);
    await (await revealTabbedControl(page.locator('#notification-email'))).uncheck();
    await expect(page.locator('#notification-email')).not.toBeChecked();
 await expect(page.getByRole('alert').filter({ hasText: 'Ces réglages ont changé' }).first()).toContainText('Ces réglages ont changé');
    await page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true }).click(); await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.locator('#notification-email')).toBeChecked();
    await openModule(page, 'home'); await page.getByRole('button', { name: 'Se déconnecter', exact: true }).click();
    expect(php(String.raw`echo App\Models\PushSubscription::where('user_id',App\Models\User::where('email','notify-member@example.test')->value('id'))->count();`).toString()).toBe('0');
  } finally {
    php(String.raw`DB::transaction(function(){
      $admin=App\Models\User::where('email','notify-admin@example.test')->first();if($admin)app(App\Services\NotificationAdministration::class)->saveSettings($admin,[...app(App\Services\NotificationConfiguration::class)->settings(),'email_enabled'=>false,'push_enabled'=>false,'webhook_enabled'=>false]);
      $rules=App\Models\AlertRule::where('name','E2E Notification Rule')->pluck('id');$episodes=App\Models\AlertEpisode::whereIn('alert_rule_id',$rules)->pluck('id');App\Models\NotificationDelivery::whereIn('alert_episode_id',$episodes)->delete();App\Models\AlertEpisode::whereIn('id',$episodes)->delete();App\Models\AlertRule::whereIn('id',$rules)->delete();
      App\Models\Sensor::where('name','E2E Notification Probe')->delete();$assets=App\Models\Asset::where('name','E2E Notification Device')->pluck('id');App\Models\AssetAssignment::whereIn('asset_id',$assets)->delete();DB::table('asset_contexts')->whereIn('asset_id',$assets)->delete();App\Models\Asset::whereIn('id',$assets)->delete();App\Models\MeasurementType::where('code','e2e_notification_type')->delete();App\Models\User::whereIn('email',['notify-admin@example.test','notify-member@example.test'])->delete();
    });`);
  }
});
