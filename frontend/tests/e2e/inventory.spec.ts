import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { openModule, selectOptionByText } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('inventory balances, exact transfers, counts, retry protection, and member access', async ({ page, browser }) => {
  // Fixtures exist only inside the isolated browser-test project, never development.
  const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
  const fixture = String.raw`require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!app()->environment('testing') || config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== '/var/www/html/storage/e2e.sqlite') { throw new RuntimeException('Isolated E2E database required.'); }
    App\Models\User::factory()->create(['name'=>'Inventory Administrator','email'=>'inventory-admin@example.test','password'=>'An inventory test password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);
    App\Models\User::factory()->create(['name'=>'Inventory Member','email'=>'inventory-member@example.test','password'=>'An inventory test password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile()->id]]);`;
  execFileSync('docker', [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r', fixture], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' });
  await page.goto('/');
  await (await revealTabbedControl(page.locator('#language'))).selectOption('fr');
  await (await revealTabbedControl(page.locator('#login-email'))).fill('inventory-admin@example.test');
  await (await revealTabbedControl(page.locator('#login-password'))).fill('An inventory test password 123!');
  await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
  await openModule(page, 'inventory');
  await page.getByRole('tab', { name: 'Lieux de stockage', exact: true }).click();
  const discarded = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
  await expect(discarded).toHaveCount(0);
  await page.getByRole('button', { name: 'Ajouter un lieu de stockage', exact: true }).click();
  await (await revealTabbedControl(page.getByRole('textbox', { name: 'Nom', exact: true }))).fill('Unsaved cabinet');
  await page.getByRole('button', { name: 'Annuler', exact: true }).click();
  await expect(discarded).toBeVisible();
  await discarded.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
  await expect(page.getByRole('textbox', { name: 'Nom', exact: true })).toHaveValue('Unsaved cabinet');
  await page.getByRole('button', { name: 'Annuler', exact: true }).click();
  await discarded.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
  await expect(page.getByRole('tab', { name: 'Lieux de stockage', exact: true })).toHaveAttribute('aria-selected', 'true');

  for (const name of ['Inventory Warehouse', 'Inventory Workshop']) {
    await page.getByRole('button', { name: 'Ajouter un lieu de stockage', exact: true }).click();
    await (await revealTabbedControl(page.getByRole('textbox', { name: 'Nom', exact: true }))).fill(name);
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByRole('button', { name: `Modifier ${name}`, exact: true })).toBeVisible();
  }
  await page.getByRole('tab', { name: 'Inventaire', exact: true }).click();
  await page.getByRole('button', { name: 'Ajouter un article', exact: true }).click();
  await page.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click();
  await expect(page.getByText('Saisissez un nom de 120 caractères maximum.', { exact: true })).toBeVisible();
  await (await revealTabbedControl(page.locator('#inventory-name'))).fill('Test Cable');
  await (await revealTabbedControl(page.locator('#inventory-sku'))).fill('CABLE-TEST');
  await (await revealTabbedControl(page.locator('#inventory-unit'))).selectOption('m');
  await (await revealTabbedControl(page.locator('#inventory-threshold'))).fill('2.5');
  await page.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click();
  await expect(page.getByText('Article enregistré.', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Stocks et historique de Test Cable', exact: true }).click();
  const details = page.getByRole('dialog', { name: 'Test Cable', exact: true });
  await expect(details.locator('.stock-total').filter({ hasText: 'Stock total: 0,000 m' })).toBeVisible();
  const choose = async (side: 'source' | 'destination', location: string) => {
    await selectOptionByText(page, `#inventory-${side}`, location);
  };
  await page.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
  await (await revealTabbedControl(page.locator('#movement-quantity'))).fill('10.125');
  await (await revealTabbedControl(page.locator('#movement-reason'))).fill('Initial <b>literal</b> receipt');
  await choose('destination', 'Inventory Warehouse');
  await expect(page.locator('#movement-quantity')).toHaveValue('10.125');
  await page.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
  await expect(details.locator('.stock-total').filter({ hasText: 'Stock total: 10,125 m' })).toBeVisible();
  const storedItem = (await (await page.request.get('/api/v1/inventory/items?search=CABLE-TEST')).json()).data[0];
  const csrf = (await page.context().cookies()).find(cookie => cookie.name === 'XSRF-TOKEN')!;
  const firstMovement = (await (await page.request.get(`/api/v1/inventory/items/${storedItem.id}/movements`)).json()).data[0];
  await page.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
  await (await revealTabbedControl(page.locator('#movement-kind'))).selectOption('transfer');
  await (await revealTabbedControl(page.locator('#movement-quantity'))).fill('3.001');
  await (await revealTabbedControl(page.locator('#movement-reason'))).fill('Transfer to workshop');
  await choose('source', 'Inventory Warehouse'); await choose('destination', 'Inventory Workshop');
  await page.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
  await expect(details.locator('.balances')).toContainText('7,124 m');
  await expect(details.locator('.balances')).toContainText('3,001 m');
  // A receipt replay remains idempotent even after a later transfer.
  const replay = await page.request.post(`/api/v1/inventory/items/${storedItem.id}/movements`, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) }, data: { kind: 'receipt', quantity: '10.125', reason: 'Initial <b>literal</b> receipt', version: 1, request_id: firstMovement.requestId, destination: { id: firstMovement.destination.id } } });
  expect(replay.status()).toBe(201); expect((await replay.json()).data.id).toBe(firstMovement.id);
  await page.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
  await (await revealTabbedControl(page.locator('#movement-kind'))).selectOption('issue');
  await (await revealTabbedControl(page.locator('#movement-quantity'))).fill('50');
  await (await revealTabbedControl(page.locator('#movement-reason'))).fill('Rejected excessive issue');
  await choose('source', 'Inventory Workshop');
  await page.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
  await expect(page.locator('#movement-quantity-error')).toHaveText('Le stock du lieu d’origine est insuffisant.');
  await (await revealTabbedControl(page.locator('#movement-quantity'))).fill('0.001');
  await page.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
  await expect(details.locator('.stock-total').filter({ hasText: 'Stock total: 10,124 m' })).toBeVisible();
  // Lose the first count response after it commits; the UI must retry its UUID.
  let dropResponse = true;
  await page.route(`**/api/v1/inventory/items/${storedItem.id}/movements`, async route => {
    if (route.request().method() === 'POST' && dropResponse) { dropResponse = false; await route.fetch(); await route.abort('failed'); }
    else await route.continue();
  });
  await page.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
  await (await revealTabbedControl(page.locator('#movement-kind'))).selectOption('count');
  await (await revealTabbedControl(page.locator('#movement-quantity'))).fill('1');
  await (await revealTabbedControl(page.locator('#movement-reason'))).fill('Physical stock verification');
  await choose('destination', 'Inventory Warehouse');
  await page.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
  await expect(page.getByRole('alert').last()).toContainText('Le résultat n’a pas pu être confirmé.');
  await expect(page.locator('#movement-quantity')).toBeDisabled();
  await page.getByRole('button', { name: 'Réessayer la même opération', exact: true }).click();
  await expect(details.locator('.stock-total').filter({ hasText: 'Stock total: 4,000 m' })).toBeVisible();
  await expect(details.locator('.history > li')).toHaveCount(4);
  await details.locator('.history summary').first().click();
  await expect(details.getByText('Physical stock verification', { exact: true })).toBeVisible();
  // A metadata change invalidates the opened movement version.
  await page.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
  await (await revealTabbedControl(page.locator('#movement-quantity'))).fill('1'); await (await revealTabbedControl(page.locator('#movement-reason'))).fill('Stale operation'); await choose('destination', 'Inventory Warehouse');
  const current = (await (await page.request.get(`/api/v1/inventory/items/${storedItem.id}`)).json()).data;
  expect((await page.request.patch(`/api/v1/inventory/items/${storedItem.id}`, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) }, data: { version: current.version, description: 'Concurrent information change' } })).status()).toBe(200);
  await page.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
  await expect(page.getByRole('alert').last()).toContainText('Cet article ou son stock a changé dans une autre session.');
  await page.getByRole('button', { name: 'Recharger l’article actuel', exact: true }).click();
  await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
  await expect(page.locator('#movement-quantity')).toHaveCount(0);
  await details.getByRole('button', { name: 'Modifier Test Cable', exact: true }).click();
  await expect(page.locator('#inventory-unit')).toBeDisabled();
  await (await revealTabbedControl(page.locator('#inventory-threshold'))).fill('5');
  await page.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click();
  await expect(page.getByText('Article enregistré.', { exact: true })).toBeVisible();
  await expect(page.locator('#inventory-name')).toHaveCount(0);
  await page.getByRole('button', { name: 'Fermer les détails', exact: true }).click();
  await (await revealTabbedControl(page.locator('#inventory-low'))).check();
  await expect(page.getByRole('navigation', { name: 'Pages de l’inventaire', exact: true }).getByRole('status')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Stocks et historique de Test Cable', exact: true })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  if (process.env.PLAYWRIGHT_INVENTORY_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_INVENTORY_SCREENSHOT, fullPage: true, animations: 'disabled' });
  const memberContext = await browser.newContext({ baseURL: 'http://127.0.0.1:18080' });
  try {
    const member = await memberContext.newPage(); await member.goto('/');
    await (await revealTabbedControl(member.locator('#login-email'))).fill('inventory-member@example.test'); await (await revealTabbedControl(member.locator('#login-password'))).fill('An inventory test password 123!');
    await member.getByRole('button', { name: 'Sign in', exact: true }).click(); await openModule(member, 'inventory');
    await expect(member.getByRole('button', { name: 'Add item', exact: true })).toHaveCount(0);
    await member.getByRole('button', { name: 'Stock and history for Test Cable', exact: true }).click();
    await expect(member.getByRole('button', { name: 'Record a movement', exact: true })).toHaveCount(0);
    await expect(member.locator('.history > li')).toHaveCount(4);
    await member.request.get('/api/v1/auth/csrf'); const memberCsrf = (await memberContext.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN')!;
    expect((await member.request.patch(`/api/v1/inventory/items/${storedItem.id}`, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(memberCsrf.value) }, data: { version: 7, name: 'Denied' } })).status()).toBe(403);
  } finally { await memberContext.close(); }
});
