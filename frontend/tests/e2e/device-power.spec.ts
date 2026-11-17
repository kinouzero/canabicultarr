import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';
import { insightRoutes } from './insights-fixture';

async function setup(page: Page, failTargetDiscovery = false, category: 'light' | 'fan' = 'light') {
 const { errors } = await insightRoutes(page);
 const settings = { provider: 'home_assistant', entity: 'light.fixture', mode: 'manual', on_at: null as string | null, off_at: null as string | null, timezone: 'Europe/Paris' };
 const asset = { id: 'light-one', name: `Fictional ${category}`, category, integrations: ['home_assistant'], importedControlProvider: category === 'fan' ? 'home_assistant' : null, status: 'active', locations: [], plants: [], plantsCount: 0, channelsCount: 0, enabledChannelsCount: 0, powerControl: category === 'fan' ? null : settings, canControlPower: true, canConfigurePower: true, pumpControl: null, canActivate: false, assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null };
 const state = { on: false, fail: false, writes: [] as { path: string; input: Record<string, unknown> }[] };
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'power-user', name: 'Fictional', email: 'power@example.test', isActive: true, oidcLinked: false, permissions: ['assets.manage'], permissionRoles: [] } } }));
 await page.route('**/api/v1/power-targets?*', route => {
  expect(new URL(route.request().url()).searchParams.get('asset_id')).toBe(asset.id);
  if (failTargetDiscovery) {
   failTargetDiscovery = false;
   return route.fulfill({ status: 503, json: { error: { code: 'measurements.errors.providerUnavailable' } } });
  }
  return route.fulfill({ json: { data: [{ id: 'light.fixture', name: 'Fixture light', disabled: false }] } });
 });
 await page.route('**/api/v1/assets**', async route => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  if (request.method() !== 'GET') {
   const input = request.postDataJSON(); state.writes.push({ path, input });
   if (path.endsWith('/power')) {
    if (state.fail) return route.fulfill({ status: 502, json: { error: { code: 'assets.power.uncertain', detail: 'private-provider-message' } } });
    state.on = input.is_on;
    return route.fulfill({ json: { data: { id: input.request_id, isOn: state.on, status: 'confirmed', createdAt: new Date().toISOString(), confirmedAt: new Date().toISOString() } } });
   }
   Object.assign(settings, input.power_control); asset.powerControl = settings; return route.fulfill({ json: { data: asset } });
  }
  if (path.endsWith('/power')) return route.fulfill({ json: { data: { isOn: state.on, lastCommand: null } } });
  if (path.endsWith('/light-one')) return route.fulfill({ json: { data: asset } });
  return route.fulfill({ json: { data: [asset], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
 });
 await page.goto('/hardware'); return { state, errors };
}

test('light controls send explicit on/off commands and show sanitized failures without opening the record', async ({ page }) => {
 const { state, errors } = await setup(page);
 await page.getByRole('button', { name: 'Commander Fictional light', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Commander Fictional light', exact: true });
 await expect(dialog).toContainText('Appareil éteint');
 await dialog.getByRole('button', { name: 'Allumer', exact: true }).click();
 await expect(dialog).toContainText('Appareil allumé');
 await expect(page.getByText('État confirmé par l’intégration.', { exact: true })).toBeVisible();
 expect(state.writes).toHaveLength(1); expect(state.writes[0]!.input.is_on).toBe(true);
 state.fail = true; await dialog.getByRole('button', { name: 'Éteindre', exact: true }).click();
 await expect(dialog.page().getByRole('alert').filter({ hasText: 'Le résultat est incertain' }).first()).toContainText('Le résultat est incertain'); await expect(dialog).not.toContainText('private-provider-message');
 expect(state.writes).toHaveLength(2); expect(state.writes[1]!.input.is_on).toBe(false);
 await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0); expect(errors).toEqual([]);
});

test('fan editor saves fixed on/off times and an explicit timezone with guarded drafts', async ({ page }) => {
 const { state, errors } = await setup(page, true, 'fan');
 await expect(page.locator('.asset-node').getByText('Home Assistant', { exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'Modifier Fictional fan', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Modifier la fiche', exact: true });
 await dialog.getByRole('tab', { name: 'Commandes', exact: true }).click();
 const control = dialog.getByRole('group', { name: 'Commande électrique', exact: true });
 const connection = control.getByLabel('Connexion', { exact: true });
 await expect(connection).toHaveValue('Home Assistant');
 await expect(connection).not.toBeEditable();
 const failure = control.getByRole('alert');
 const target = control.getByLabel('Appareil', { exact: true }).and(control.locator('select'));
 await expect(failure).toContainText('Impossible de charger les commandes depuis Home Assistant');
 await expect(target).toHaveValue('');
 await failure.getByRole('button', { name: 'Réessayer', exact: true }).click();
 await expect(failure).toHaveCount(0);
 await (await revealTabbedControl(target)).selectOption('light.fixture');
 await (await revealTabbedControl(control.getByLabel('Mode de fonctionnement', { exact: true }).and(control.locator('select')))).selectOption('auto');
 await (await revealTabbedControl(control.getByLabel('Allumer à', { exact: true }))).fill('10:00');
 await (await revealTabbedControl(control.getByLabel('Éteindre à', { exact: true }))).fill('10:05');
 await (await revealTabbedControl(control.getByLabel('Fuseau horaire', { exact: true }).and(control.locator('select')))).selectOption('Europe/Paris');
 await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(control.getByLabel('Allumer à', { exact: true })).toHaveValue('10:00');
 await dialog.getByRole('button', { name: /Enregistrer/, exact: false }).click();
 await expect(dialog).toHaveCount(0);
 expect(state.writes.at(-1)!.input.power_control).toEqual({ provider: 'home_assistant', entity: 'light.fixture', mode: 'auto', on_at: '10:00', off_at: '10:05', timezone: 'Europe/Paris' });
 expect(errors).toEqual([]);
});
