import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';
import { openModule } from './navigation';
import type { ConnectionField } from '../../src/api/connectionSettings';
async function settings(page: Page) {
 const { errors } = await insightRoutes(page); const writes: Record<string, unknown>[] = []; let failed = false;
 const field = (name: string, type: ConnectionField['type'], value: ConnectionField['value'], locked = false): ConnectionField => ({ name, type, value, locked, hasValue: value != null || type === 'secret', overridden: false, options: [] });
 const groups: Record<string, { version: number; fields: ConnectionField[] }> = {
  storage: { version: 1, fields: [field('AWS_BUCKET', 'text', 'fictional-bucket', true)] },
  mail: { version: 1, fields: [field('MAIL_HOST', 'text', 'mail.example.test', true), field('MAIL_PORT', 'port', 587), field('MAIL_PASSWORD', 'secret', null), field('MAIL_FROM_ADDRESS', 'email', 'sender@example.test')] },
  homebridge: { version: 1, fields: [field('HOMEBRIDGE_URL', 'url', 'https://bridge.example.test'), field('HOMEBRIDGE_PASSWORD', 'secret', null)] },
 };
 await page.route('**/api/v1/**', async route => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  const data = (value: unknown) => route.fulfill({ json: { data: value } });
  if (path.endsWith('/auth/user')) return data({ id: 'settings-admin', name: 'Settings admin', email: 'settings@example.test', role: 'admin', permissions: ['administration.view'], isActive: true, oidcLinked: false });
  if (path.endsWith('/instance-settings')) return data({ siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: true });
  if (path.includes('/connection-settings/')) {
   const record = groups[path.split('/').at(-1)!] ?? { version: 1, fields: [] };
   if (request.method() === 'PATCH') {
    const input = request.postDataJSON(); writes.push(input); const invalid: Record<string, string[]> = {};
    if (input.values.MAIL_PORT === 70000) invalid['values.MAIL_PORT'] = ['connectionSettings.invalid'];
    if (input.values.MAIL_FROM_ADDRESS === 'invalid') invalid['values.MAIL_FROM_ADDRESS'] = ['connectionSettings.invalid'];
    if (Object.keys(invalid).length) return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: invalid } });
    if (input.values.MAIL_PORT === 2525 && !failed) { failed = true; return route.fulfill({ status: 500, json: { error: { code: 'auth.errors.requestFailed' } } }); }
    for (const item of record.fields) if (item.name in input.values) { item.hasValue = input.values[item.name] !== null; if (item.type !== 'secret') item.value = input.values[item.name]; }
    record.version++;
   }
   return data(record);
  }
  return route.fallback();
 });
 await page.reload(); await openModule(page, 'instanceSettings'); await page.getByRole('tab', { name: 'Connexions', exact: true }).click(); await page.getByRole('tab', { name: 'E-mail', exact: true }).click();
 await expect(page.locator('#connection-MAIL_PORT')).toHaveValue('587'); return { errors, writes };
}
const save = (page: Page) => page.locator('.connection-settings:visible').getByRole('button', { name: 'Enregistrer les paramètres', exact: true });
const discard = (page: Page) => page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
test('connection links restore the selected group after reload and browser navigation', async ({ page }) => {
 const { errors } = await settings(page);
 await page.goto('/settings?tab=connections&connection=homebridge');
 await expect(page).toHaveURL(/\/settings\/connections\/homebridge$/);
 await expect(page.locator('#connection-HOMEBRIDGE_URL')).toHaveValue('https://bridge.example.test');
 await page.reload();
 await expect(page.getByRole('tab', { name: 'Homebridge', exact: true })).toHaveAttribute('aria-selected', 'true');
 await page.getByRole('tab', { name: 'E-mail', exact: true }).click();
 await expect(page).toHaveURL(/\/settings\/connections\/email$/);
 await page.goBack();
 await expect(page.locator('#connection-HOMEBRIDGE_URL')).toBeVisible();
 await page.goForward();
 await expect(page.locator('#connection-MAIL_PORT')).toHaveValue('587');
 expect(errors).toEqual([]);
});

test('connection failures protect drafts when leaving a group or the settings page', async ({ page }) => {
 const { errors, writes } = await settings(page);
 await expect(save(page)).toHaveCount(0);
 await expect(page.locator('#connection-MAIL_HOST')).toBeDisabled();
 await page.locator('#connection-MAIL_PORT').fill('70000');
 await expect(page.locator('#connection-error-MAIL_PORT')).toBeVisible();
 await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
 await expect(discard(page)).toBeVisible();
 await expect(page.getByRole('tab', { name: 'E-mail', exact: true })).toHaveAttribute('aria-selected', 'true');
 await page.keyboard.press('Escape');
 await expect(discard(page)).toHaveCount(0);
 await page.getByRole('tab', { name: 'Général', exact: true }).click();
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await expect(page.locator('#connection-MAIL_PORT')).toHaveValue('70000');
 await page.getByTestId('nav-home').click();
 await expect(discard(page)).toBeVisible();
 await discard(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(page).toHaveURL(/\/settings\/connections\/email$/);
 await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
 await discard(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(page.locator('#connection-HOMEBRIDGE_URL')).toBeVisible();
 await page.getByRole('tab', { name: 'E-mail', exact: true }).click();
 await expect(page.locator('#connection-MAIL_PORT')).toHaveValue('587');
 await expect(discard(page)).toHaveCount(0);
 await page.getByRole('tab', { name: 'Stockage', exact: true }).click();
 await expect(page.locator('#connection-AWS_BUCKET')).toBeDisabled();
 await expect(save(page)).toHaveCount(0);
 await page.getByTestId('nav-home').click();
 await expect(page).toHaveURL(/\/(?:\?.*)?$/);
 expect(writes).toEqual([{ version: 1, values: { MAIL_PORT: 70000 } }]);
 expect(errors).toEqual([]);
});
test('connection autosave groups changed fields, protects secret typing and retries a write failure', async ({ page }) => {
 const { errors, writes } = await settings(page);
 await page.clock.install();
 const port = page.locator('#connection-MAIL_PORT');
 const sender = page.locator('#connection-MAIL_FROM_ADDRESS');
 const secret = page.locator('#connection-MAIL_PASSWORD');
 await port.fill('70000'); await sender.fill('invalid');
 await page.clock.runFor(700);
 await expect(port).toHaveAttribute('aria-invalid', 'true');
 await expect(page.locator('#connection-error-MAIL_FROM_ADDRESS')).toBeVisible();
 await port.fill('2525'); await sender.fill('sender@example.test');
 await secret.fill('Fictional new password');
 await page.clock.runFor(1500);
 expect(writes).toHaveLength(1);
 await secret.blur(); await page.clock.runFor(700);
 const retry = page.locator('.connection-settings').getByRole('button', { name: 'Réessayer', exact: true });
 await expect(retry).toBeVisible();
 await expect(secret).toHaveValue('Fictional new password');
 await page.clock.runFor(1500);
 expect(writes).toHaveLength(2);
 await retry.click();
 await expect(page.getByText('Connexion enregistrée.', { exact: true })).toBeVisible();
 await expect(secret).toHaveValue('');
 expect(writes.at(-1)).toEqual({ version: 1, values: { MAIL_PORT: 2525, MAIL_PASSWORD: 'Fictional new password' } });
 await page.getByRole('button', { name: 'Effacer', exact: true }).click();
 await page.clock.runFor(700);
 await expect.poll(() => writes.at(-1)).toEqual({ version: 2, values: { MAIL_PASSWORD: null } });
 await expect(page.getByRole('button', { name: 'Effacer', exact: true })).toHaveCount(0);
 expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain('Fictional new password');
 expect(errors).toEqual([]);
});

test('pending connection reads do not trap navigation or restore a closed settings form', async ({ page }) => {
 const { errors } = await settings(page);
 let release!: () => void; const pending = new Promise<void>(done => { release = done; });
 await page.route('**/api/v1/connection-settings/homebridge', async route => { await pending; await route.fulfill({ json: { data: { version: 1, fields: [] } } }); });
 await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
 await expect(page.locator('.connection-settings:visible [role=status]')).toBeVisible();
 await page.getByTestId('nav-home').click(); await expect(page).toHaveURL(/\/(?:\?.*)?$/);
 const response = page.waitForResponse('**/api/v1/connection-settings/homebridge'); release(); await response;
 await expect(page.getByTestId('instance-settings')).toHaveCount(0); expect(errors).toEqual([]);
});

test('all connection tabs test saved settings and surface service results without discarding drafts', async ({ page }) => {
 const { errors } = await settings(page); const checked: string[] = [];
 await page.route('**/api/v1/connection-settings/*/check', async route => {
  const group = new URL(route.request().url()).pathname.split('/').at(-2)!;
  expect(route.request().postDataJSON()).toEqual({ locale: 'fr' }); checked.push(group);
  if (group === 'push') return route.fulfill({ status: 422, json: { error: { code: 'connectionSettings.pushSubscriptionRequired' } } });
  return route.fulfill({ json: { data: { message: group === 'oidc' ? 'connectionSettings.oidcChecked' : 'connectionSettings.connected' } } });
 });
 const check = () => page.locator('.connection-settings:visible').getByRole('button', { name: 'Tester la connexion', exact: true });
 await (await revealTabbedControl(page.locator('#connection-MAIL_PORT'))).fill('465'); await expect(check()).toBeDisabled();
 await (await revealTabbedControl(page.locator('#connection-MAIL_PORT'))).fill('587'); await expect(check()).toBeEnabled();
 for (const name of ['Stockage', 'OIDC', 'Home Assistant', 'Homebridge', 'MQTT', 'E-mail', 'Webhook de notification', 'Notifications du navigateur']) {
  await page.getByRole('tab', { name, exact: true }).click(); await check().click();
  if (name === 'OIDC') await expect(page.getByText(/Découverte OIDC vérifiée/)).toBeVisible();
  else if (name === 'Notifications du navigateur') await expect(page.getByText(/Activez les notifications du navigateur pour cette session/)).toBeVisible();
  else await expect(page.getByText('Connexion vérifiée.', { exact: true }).last()).toBeVisible();
 }
 expect(checked).toEqual(['storage', 'oidc', 'home_assistant', 'homebridge', 'mqtt', 'mail', 'webhook', 'push']);
 expect(errors).toEqual([]);
});

test('environment provider name is displayed locked in OIDC and omitted from other settings saves', async ({ page }) => {
 await settings(page); let payload: Record<string, unknown> | undefined;
 await page.route('**/api/v1/instance-settings', async route => {
  if (route.request().method() === 'PATCH') payload = route.request().postDataJSON();
  await route.fulfill({ json: { data: { siteTitle: payload?.site_title ?? 'Canabicultarr', siteSubtitle: null, oidcProviderName: 'Environment identity', oidcProviderNameLocked: true, dateFormat: 'd/m/Y', timeFormat: '24', version: payload ? 2 : 1, canManage: true } } });
 });
 await page.reload(); await openModule(page, 'instanceSettings');
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click(); await page.getByRole('tab', { name: 'OIDC', exact: true }).click();
 await expect(page.locator('#instance-oidc-provider-name')).toHaveValue('Environment identity');
 await expect(page.locator('#instance-oidc-provider-name')).toBeDisabled();
 await expect(page.locator('#instance-oidc-provider-icon')).toBeEnabled();
 await page.getByRole('tab', { name: 'Général', exact: true }).click();
 await (await revealTabbedControl(page.locator('#instance-site-title'))).fill('Fictional garden');
 await expect.poll(() => payload?.site_title).toBe('Fictional garden');
 expect(payload).not.toHaveProperty('oidc_provider_name');
});
