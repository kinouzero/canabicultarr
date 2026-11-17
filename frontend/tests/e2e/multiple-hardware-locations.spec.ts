import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { openModule } from './navigation';

function seedLocations(): { zone: string; site: string } {
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const fixture = String.raw`App\Models\User::factory()->create(['email'=>'multi-admin@example.test','password'=>Illuminate\Support\Facades\Hash::make('A fictional password 123!'),'permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);$site=App\Models\Site::create(['name'=>'First site']);$room=$site->rooms()->create(['name'=>'First room']);$zone=$room->zones()->create(['name'=>'First zone']);$second=App\Models\Site::create(['name'=>'Second site']);echo json_encode(['zone'=>$zone->id,'site'=>$second->id]);`;
 return JSON.parse(execFileSync('docker', [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r', guard + fixture], { cwd: fileURLToPath(new URL('../../..', import.meta.url)), env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, encoding: 'utf8' }).trim());
}

test('hardware editor adds several locations, preserves them on edit and removes one on mobile', async ({ page }) => {
 const locations = seedLocations();
 const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
 await page.addInitScript(() => localStorage.setItem('locale', 'fr'));
 await page.goto('/');
 await (await revealTabbedControl(page.locator('#login-email'))).fill('multi-admin@example.test');
 await (await revealTabbedControl(page.locator('#login-password'))).fill('A fictional password 123!');
 await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
 await openModule(page, 'assets');
 await page.getByRole('button', { name: 'Ajouter du matériel', exact: true }).click();
 await (await revealTabbedControl(page.locator('#asset-name'))).fill('Shared equipment');
 await (await revealTabbedControl(page.locator('#asset-location'))).selectOption('zone:' + locations.zone);
 await expect(page.locator('.assignment-chips li')).toHaveCount(1);
 await (await revealTabbedControl(page.locator('#asset-location'))).selectOption('site:' + locations.site);
 await expect(page.locator('.assignment-chips li')).toHaveCount(2);
 await page.getByRole('button', { name: 'Créer', exact: true }).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 const card = page.locator('.asset-node').filter({ hasText: 'Shared equipment' });
 await expect(card).toContainText('First zone'); await expect(card).toContainText('Second site');
 await page.getByRole('button', { name: 'Modifier Shared equipment', exact: true }).click();
 await expect(page.locator('.assignment-chips li')).toHaveCount(2);
 await (await revealTabbedControl(page.locator('#asset-location'))).selectOption('zone:' + locations.zone);
 await expect(page.locator('.assignment-chips li')).toHaveCount(2);
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('button', { name: 'Retirer Second site', exact: true }).click();
 await expect(page.locator('.assignment-chips li')).toHaveCount(1);
 await page.getByRole('button', { name: 'Enregistrer la fiche', exact: true }).click();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(card).toContainText('First zone'); await expect(card).not.toContainText('Second site');
 await page.reload(); await openModule(page, 'assets');
 await expect(page.locator('.asset-node').filter({ hasText: 'Shared equipment' })).toContainText('First zone');
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 expect(errors).toEqual([]);
});
