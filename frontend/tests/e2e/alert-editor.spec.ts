import { revealTabbedControl } from './navigation';
import { test, expect, type Page } from '../coverage/fixture';
import { insightRoutes, insightSensors, insightMeta } from './insights-fixture';
import { openModule } from './navigation';

async function alerts(page: Page, canManage = true) {
 const { errors } = await insightRoutes(page);
 const sensor = insightSensors[0]!;
 let rule = { id: 'rule-one', name: 'Fictional moisture rule', kind: 'threshold', severity: 'warning', sensor, minimum: '20.000000000', maximum: '70.000000000', maxAgeSeconds: 600, delaySeconds: null, isEnabled: true, version: 1, activatedAt: '2026-11-01T12:00:00Z', lastEvaluatedAt: null, lastResult: 'waiting', canManage };
 const writes: Record<string, unknown>[] = [];
 const state = { response: 'ok', reloadFails: false, listFails: false, reads: 0, paused: false };
 let release: (() => void) | undefined;
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  const data = (value: unknown) => route.fulfill({ json: { data: value } });
  if (path.endsWith('/auth/user')) return data({ id: 'alert-editor', name: 'Alert editor', email: 'editor@example.test', permissions: canManage ? ['alerts.manage'] : [], isActive: true, oidcLinked: false });
  if (path.endsWith('/alerts')) return route.fulfill({ json: { data: [], meta: { ...insightMeta, total: 0 } } });
  if (path.endsWith('/sensors')) return route.fulfill({ json: { data: [sensor], meta: insightMeta } });
  if (path.endsWith('/alert-rules/rule-one') && route.request().method() === 'GET') {
   state.reads++;
   if (state.reloadFails) return route.fulfill({ status: 500, json: { error: { code: 'private-provider-trace' } } });
   return data({ ...rule, name: 'Updated remote rule', version: 3 });
  }
  if (path.endsWith('/alert-rules') && route.request().method() === 'GET') {
   if (state.listFails) return route.fulfill({ status: 500, json: { error: { code: 'auth.errors.requestFailed' } } });
   return route.fulfill({ json: { data: [rule], meta: insightMeta } });
  }
  if (path.includes('/alert-rules') && ['POST', 'PATCH'].includes(route.request().method())) {
   const input = route.request().postDataJSON(); writes.push(input);
   if (state.paused) await new Promise<void>(done => { release = done; });
   if (state.response === 'conflict') return route.fulfill({ status: 409, json: { error: { code: 'alerts.errors.conflict' } } });
   if (state.response === 'bounds') return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { maximum: ['alerts.validation.boundsOrder'], severity: ['private-provider-trace'], unknown: ['private-provider-trace'] } } });
   if (state.response === 'delay') return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { delay_seconds: ['private-provider-trace'] } } });
   if (state.response === 'failure') return route.fulfill({ status: 500, json: { error: { code: 'private-provider-trace' } } });
   rule = { ...rule, ...input, version: Number(input.version ?? 0) + 1 };
   return data(rule);
  }
  return route.fallback();
 });
 await page.reload(); await openModule(page, 'alerts');
 await page.getByRole('tab', { name: 'Règles de surveillance', exact: true }).click();
 return { writes, errors, state, release: () => release?.() };
}
const editor = (page: Page) => page.getByRole('dialog', { name: /^(Créer une|Modifier la) règle de surveillance$/ });
const discard = (page: Page) => page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
const save = (page: Page) => editor(page).getByRole('button', { name: /^(?:Enregistrer la règle|Créer)$/, exact: true });
async function edit(page: Page) {
 await page.getByRole('button', { name: 'Modifier Fictional moisture rule', exact: true }).click();
 await expect(page.locator('#alert-rule-name')).toHaveValue('Fictional moisture rule');
}

test('rule editor actions wait for the active rule list request', async ({ page }) => {
 await alerts(page);
 const add = page.getByRole('button', { name: 'Ajouter une règle', exact: true });
 const editRule = page.getByRole('button', { name: 'Modifier Fictional moisture rule', exact: true });
 await expect(editRule).toBeEnabled();
 let release: () => void = () => {};
 const pending = new Promise<void>(resolve => { release = resolve; });
 await page.route('**/api/v1/alert-rules?*', async route => { await pending; await route.fallback(); }, { times: 1 });
 await page.getByRole('button', { name: 'Recharger la liste', exact: true }).click();
 try {
  await expect(add).toBeDisabled();
  await expect(editRule).toBeDisabled();
 } finally { release(); }
 await add.click();
 await expect(editor(page)).toBeVisible();
});

test('alert drafts protect close and Back while unchanged and reverted forms close freely', async ({ page }) => {
 const { writes, errors } = await alerts(page); const alertsUrl = page.url();
 await edit(page); await page.keyboard.press('Escape'); await expect(editor(page)).toHaveCount(0); await expect(discard(page)).toHaveCount(0);
 await edit(page); await (await revealTabbedControl(page.locator('#alert-rule-name'))).fill('Local draft');
 await editor(page).getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(discard(page)).toBeVisible();
 await page.keyboard.press('Escape'); await expect(discard(page)).toHaveCount(0); await expect(page.locator('#alert-rule-name')).toHaveValue('Local draft');
 await page.goBack(); await expect(discard(page)).toBeVisible();
 await discard(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(page).toHaveURL(alertsUrl);
 await (await revealTabbedControl(page.locator('#alert-rule-name'))).fill('Fictional moisture rule'); await page.keyboard.press('Escape'); await expect(editor(page)).toHaveCount(0);
 await edit(page); await (await revealTabbedControl(page.locator('#alert-rule-name'))).fill('Discarded draft'); await page.goBack();
 await discard(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page).not.toHaveURL(alertsUrl);
 expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});

test('alert validation focuses local and known server fields without leaking server text', async ({ page }) => {
 const { writes, errors, state } = await alerts(page);
 await page.getByRole('button', { name: 'Ajouter une règle', exact: true }).click(); await save(page).click();
 await expect(page.locator('#alert-rule-name')).toBeFocused(); await expect(page.locator('#alert-rule-name')).toHaveAttribute('aria-describedby', 'alert-rule-name-error'); expect(writes).toHaveLength(0);
 await (await revealTabbedControl(page.locator('#alert-rule-name'))).fill('Fictional new rule'); await (await revealTabbedControl(page.locator('#alert-rule-sensor'))).selectOption('sensor-0');
 await expect(page.locator('#alert-rule-sensor')).toHaveAttribute('aria-invalid', 'false');
 await editor(page).getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(page.locator('#alert-rule-maximum'))).fill('30'); await (await revealTabbedControl(page.locator('#alert-rule-age'))).fill('119'); await save(page).click();
 await expect(page.locator('#alert-rule-age')).toBeFocused(); expect(writes).toHaveLength(0);
 await (await revealTabbedControl(page.locator('#alert-rule-age'))).fill('600'); state.response = 'bounds'; await save(page).click();
 await expect(editor(page).locator('[data-ui-select="alert-rule-severity"] .n-base-selection-label')).toBeFocused(); await expect(page.locator('#alert-rule-maximum-error')).toHaveText('Le maximum doit être supérieur ou égal au minimum.');
 await expect(editor(page)).not.toContainText('private-provider-trace'); await expect(page.locator('#alert-rule-name')).toHaveValue('Fictional new rule');
 await (await revealTabbedControl(page.locator('#alert-rule-severity'))).selectOption('critical'); await expect(page.locator('#alert-rule-severity-error')).toHaveCount(0);
 await editor(page).getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(page.locator('#alert-rule-minimum'))).fill('10'); await expect(page.locator('#alert-rule-maximum-error')).toHaveCount(0);
 await editor(page).getByRole('tab', { name: 'Général', exact: true }).click(); await (await revealTabbedControl(page.locator('#alert-rule-kind'))).selectOption('missing'); await editor(page).getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(page.locator('#alert-rule-delay'))).fill('60'); await save(page).click(); await expect(page.locator('#alert-rule-delay')).toBeFocused(); expect(writes).toHaveLength(1);
 await editor(page).getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(page.locator('#alert-rule-delay'))).fill('300'); state.response = 'delay'; await save(page).click(); await expect(page.locator('#alert-rule-delay')).toBeFocused();
 await expect(page.locator('#alert-rule-delay')).toHaveAttribute('aria-describedby', 'alert-rule-delay-error'); await expect(editor(page)).not.toContainText('private-provider-trace');
 state.response = 'ok'; await editor(page).getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(page.locator('#alert-rule-delay'))).fill('301'); await save(page).click(); await expect(editor(page)).toHaveCount(0);
 expect(writes.at(-1)).toMatchObject({ kind: 'missing', delay_seconds: 301, name: 'Fictional new rule' }); expect(writes.at(-1)).not.toHaveProperty('maximum'); expect(errors).toEqual([]);
});

test('alert conflict reload requires discard and preserves edits on failed reads before adopting the current version', async ({ page }) => {
 const { writes, errors, state } = await alerts(page); await edit(page);
 await (await revealTabbedControl(page.locator('#alert-rule-name'))).fill('Local conflict draft'); state.response = 'conflict'; await save(page).click(); await expect(save(page)).toBeDisabled();
 const reload = editor(page).getByRole('button', { name: 'Recharger la règle actuelle', exact: true });
 await reload.click(); await discard(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); expect(state.reads).toBe(0);
 state.reloadFails = true; await reload.click(); await discard(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(page.locator('#alert-rule-name')).toHaveValue('Local conflict draft'); await expect(save(page)).toBeDisabled(); await expect(editor(page)).not.toContainText('private-provider-trace');
 state.reloadFails = false; await reload.click(); await discard(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(page.locator('#alert-rule-name')).toHaveValue('Updated remote rule'); await expect(save(page)).toBeEnabled();
 state.response = 'ok'; state.listFails = true; await save(page).click(); await expect(editor(page)).toHaveCount(0);
 await expect(page.getByRole('alert').filter({ hasText: 'La demande n’a pas pu aboutir.' }).first()).toBeVisible();
 expect(writes).toHaveLength(2); expect(writes[1]).toMatchObject({ name: 'Updated remote rule', version: 3, minimum: '20.000000000', maximum: '70.000000000' });
 await page.getByTestId('nav-home').click(); await expect(discard(page)).toHaveCount(0); expect(writes).toHaveLength(2); expect(errors).toEqual([]);
});

test('pending alert saves block departure and failed saves retain values for retry', async ({ page }) => {
 const { writes, errors, state, release } = await alerts(page); const alertsUrl = page.url(); await edit(page);
 await (await revealTabbedControl(page.locator('#alert-rule-name'))).fill('Retained after failure'); state.paused = true; state.response = 'failure'; await save(page).click();
 await expect.poll(() => writes.length).toBe(1); await expect(page.locator('#alert-rule-name')).toBeDisabled();
 await page.keyboard.press('Escape'); await expect(editor(page)).toBeVisible(); await page.goBack(); await expect(discard(page)).toHaveCount(0); await expect(page).toHaveURL(alertsUrl);
 release(); await expect(page.locator('#alert-rule-name')).toBeEnabled(); await expect(page.locator('#alert-rule-name')).toHaveValue('Retained after failure'); await expect(editor(page)).not.toContainText('private-provider-trace');
 state.paused = false; state.response = 'ok'; await save(page).click(); await expect(editor(page)).toHaveCount(0); expect(writes).toHaveLength(2); expect(errors).toEqual([]);
});

test('read-only alert rules expose no editing or evaluation action', async ({ page }) => {
 const { writes, errors } = await alerts(page, false);
 await expect(page.getByRole('button', { name: 'Ajouter une règle', exact: true })).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'Modifier Fictional moisture rule', exact: true })).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'Évaluer Fictional moisture rule', exact: true })).toHaveCount(0);
 expect(writes).toHaveLength(0); expect(errors).toEqual([]);
});
