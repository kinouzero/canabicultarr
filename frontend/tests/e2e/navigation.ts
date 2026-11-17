import { expect, type Page } from '@playwright/test';
const targets: Record<string, { primary: string; section?: string; child?: string }> = {
 home: { primary: 'home' }, accountPreferences: { primary: 'account', section: 'accountPreferences' }, accountAccess: { primary: 'account', section: 'accountAccess' }, accountNotifications: { primary: 'account', section: 'accountNotifications' }, account: { primary: 'account' }, security: { primary: 'account', section: 'security' }, notifications: { primary: 'administration', section: 'instanceSettings' },
 plants: { primary: 'culture' }, catalog: { primary: 'culture', section: 'catalog' }, locations: { primary: 'culture', section: 'locations' },
 assets: { primary: 'monitoring', section: 'assets' }, sensors: { primary: 'monitoring', section: 'assets' }, inventory: { primary: 'administration', section: 'inventory' }, overview: { primary: 'culture', section: 'overview' },
 measurements: { primary: 'monitoring' }, alerts: { primary: 'monitoring', section: 'alerts' }, events: { primary: 'monitoring', section: 'events' }, documents: { primary: 'administration', section: 'documents' },
 ha: { primary: 'integration' }, homebridge: { primary: 'integration', section: 'homebridge' }, mqtt: { primary: 'integration', section: 'mqtt' }, http: { primary: 'integration', section: 'mqtt', child: 'http' }, sources: { primary: 'integration', section: 'sources' },
 instanceSettings: { primary: 'administration', section: 'instanceSettings' },
 catalogs: { primary: 'administration', section: 'instanceSettings' }, users: { primary: 'administration' }, profiles: { primary: 'administration', child: 'profiles' }, cycleSettings: { primary: 'administration', section: 'instanceSettings' },
};
export async function openModule(page: Page, id: string) {
 const target = targets[id]; if (!target) throw new Error(`Unknown module ${id}`);
 const primary = page.getByTestId(`nav-${target.primary}`); await expect(primary).toBeAttached();
 if (!(await primary.isVisible())) await page.getByRole('button', { name: /^(Open menu|Ouvrir le menu)$/ }).click();
 if (['home', 'account'].includes(target.primary)) {
  await primary.click();
  if (target.section) await page.locator(`#workspace-tab-${target.section}`).click();
 } else {
  if (await primary.getAttribute('aria-expanded') !== 'true') await primary.click();
  const defaults: Record<string, string> = { culture: 'plants', monitoring: 'measurements', integration: 'ha', administration: 'users' };
  const section = page.getByTestId(`nav-item-${target.section ?? defaults[target.primary]}`);
  if (!target.child || await section.getAttribute('aria-current') !== 'page') await section.click();
 }
 if (target.child) await page.getByTestId(`workspace-section-${target.child}`).click();
 if (id === 'cycleSettings') await page.locator('.settings-tabs').getByRole('tab', { name: /^(Lifecycle|Cycle)$/ }).click();
 if (id === 'catalogs') await page.locator('.settings-tabs').getByRole('tab', { name: /^(Templates and types|Modèles et types)$/ }).click();
 if (id === 'notifications') await page.getByRole('tab', { name: /^(Notifications|Notifications d’alertes)$/ }).click();
 if (id === 'sensors') await page.getByTestId('workspace-section-sensors').click();
}

export async function selectOptionByText(page: Page, selector: string, text: string) {
 const select = page.locator(selector);
 await revealTabbedControl(select);
 const option = select.locator('option').filter({ hasText: text }).first();
 await expect(option).toBeAttached();
 await select.selectOption((await option.getAttribute('value'))!);
}

export async function openNotificationSection(page: Page, section: 'channels' | 'preferences' | 'history') {
 if (section === 'preferences') { if (!(await page.getByTestId('notification-preferences').isVisible())) await openModule(page, 'accountNotifications'); return; }
 if (section === 'history') {
  if (!(await page.locator('.journal-tabs').isVisible())) await openModule(page, 'events');
  await page.locator('.journal-tabs').getByRole('tab', { name: /^(Delivery history|Historique des envois)$/ }).click(); return;
 }
 if (!(await page.locator('.notifications-card').isVisible())) await openModule(page, 'notifications');
}

/** Native mobile selects and their desktop presentation share one focus contract. */
export async function expectControlFocused(control: import('@playwright/test').Locator) {
 await expect.poll(() => control.evaluate(element => document.activeElement === element || element instanceof HTMLSelectElement && element.dataset.selectMode === 'custom' && document.activeElement?.closest('[data-ui-select]')?.getAttribute('data-ui-select') === element.id)).toBe(true);
}

/** Open a field's existing tab before interacting, without forcing hidden controls. */
export async function revealTabbedControl(control: import('@playwright/test').Locator) {
 const description = control.toString(); const page = control.page();
 if (await page.locator('.user-editor-tabs').count() && /user-(password|confirmation)/.test(description)) await page.locator('.user-editor-tabs').getByRole('tab', { name: /^(Mot de passe|Password)$/ }).click();
 else if (await page.locator('.user-editor-tabs').count() && /user-(permission|location|inventory)|location-all|Gérer les plantes et les actions de culture/.test(description)) await page.locator('.user-editor-tabs').getByRole('tab', { name: /user-(location|inventory)|location-all/.test(description) ? /^(Emplacements|Locations)$/ : /^(Profils et droits|Profiles and permissions)$/ }).click();
 const tabs = await control.evaluate(element => {
  const ids: string[] = []; let panel = element.closest('[role="tabpanel"]');
  while (panel) { const id = panel.getAttribute('aria-labelledby'); if (id) ids.unshift(id); panel = panel.parentElement?.closest('[role="tabpanel"]') ?? null; }
  return ids;
 });
 for (const id of tabs) {
  const tab = page.locator(`[id="${id}"]`);
  if (await tab.getAttribute('aria-selected') !== 'true') await tab.click();
 }
 return control;
}

export async function openSelect(select: import('@playwright/test').Locator) {
 await revealTabbedControl(select);
 if (await select.getAttribute('data-select-mode') === 'custom') {
  const id = await select.getAttribute('id');
  await select.page().locator(`[data-ui-select="${id}"]`).getByRole('combobox').click();
 } else await select.click();
}
