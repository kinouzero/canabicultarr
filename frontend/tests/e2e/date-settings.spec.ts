import { revealTabbedControl } from './navigation';
import { uiPreferencesFixture } from './ui-preferences-fixture';
import { test, expect, type Page } from '../coverage/fixture';
import { openModule } from './navigation';
test.use({ timezoneId: 'Europe/Paris' });

async function session(page: Page, role: 'admin' | 'member' = 'admin', chooseLanguage = true) {
 let settings = { oidcProviderName: 'Authentik', siteTitle: 'Canabicultarr', siteSubtitle: null as string | null, dateFormat: 'd/m/Y', timeFormat: '24', theme: undefined as unknown, geneticsColors: { sativa: '#c75b50', indica: '#527eae', hybrid: '#b9922e' }, version: 1, canManage: role === 'admin' };
 const preferences = uiPreferencesFixture('fictional-user');
 let conflict = false; const failures: string[] = [];
 page.on('pageerror', cause => failures.push(cause.message));
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname; const method = route.request().method();
  const meta = { current_page: 1, last_page: 1, total: 0, per_page: 20 };
  if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (path.endsWith('/branding')) return route.fulfill({ json: { data: { oidcProviderName: settings.oidcProviderName, siteTitle: settings.siteTitle, siteSubtitle: settings.siteSubtitle, version: settings.version } } });
  if (path.endsWith('/instance-settings')) {
   if (method === 'PATCH') {
    expect(role).toBe('admin');
    if (conflict) { conflict = false; return route.fulfill({ status: 409, json: { error: { code: 'instanceSettings.conflict' } } }); }
    const input = route.request().postDataJSON(); expect(input.version).toBe(settings.version);
    settings = { ...settings, oidcProviderName: input.oidc_provider_name, siteTitle: input.site_title, siteSubtitle: input.site_subtitle || null, dateFormat: input.date_format, timeFormat: input.time_format, geneticsColors: input.genetics_colors, theme: input.theme, version: settings.version + 1 };
   }
   return route.fulfill({ json: { data: settings } });
  }
  if (path.includes('/connection-settings/')) return route.fulfill({ json: { data: { version: 1, fields: [] } } });
  if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
  if (method !== 'GET') throw new Error(`Unexpected synthetic write ${path}`);
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'fictional-user', name: 'Fictional User', email: 'fictional@example.test', role, permissions: role === 'admin' ? ['administration.view'] : [], isActive: true, oidcLinked: false } } });
  return route.fulfill({ json: { data: [], meta } });
 });
 if (chooseLanguage) await page.addInitScript(() => { if (!localStorage.getItem('locale')) localStorage.setItem('locale', 'fr'); }); await page.goto('/');
 await expect(page.getByTestId('plant-home')).toBeVisible();
 return { failures, conflictNextSave: () => { conflict = true; } };
}

test('instance date settings preview, persistence, conflict recovery and responsive form', async ({ page }) => {
 const { failures, conflictNextSave } = await session(page);
 await openModule(page, 'instanceSettings');
 const form = page.getByTestId('instance-settings');
 await expect(form.getByRole('button', { name: 'Enregistrer les paramètres', exact: true })).toHaveCount(0);
 await expect(page.locator('.app-header #language')).toHaveCount(0);
 await expect(form.locator('#language, #appearance-preference')).toHaveCount(0);
 await openModule(page, 'accountPreferences'); await (await revealTabbedControl(page.locator('#language'))).selectOption('en');
 await openModule(page, 'instanceSettings'); await expect(form.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
 await page.reload(); await openModule(page, 'accountPreferences'); await expect(page.locator('#language')).toHaveValue('en'); await (await revealTabbedControl(page.locator('#language'))).selectOption('fr'); await openModule(page, 'instanceSettings');
 await expect(form.locator('output')).toHaveText('08/11/2026 23:05');
 await (await revealTabbedControl(form.locator('#instance-date-format'))).selectOption('Y-m-d');
 await (await revealTabbedControl(form.locator('#instance-time-format'))).selectOption('12');
 await expect(form.locator('output')).toHaveText(/2026-11-08 11:05 PM/i);

 await expect(page.getByText('Réglages enregistrés.', { exact: true }).first()).toBeVisible();
 await page.reload(); await openModule(page, 'instanceSettings');
 await expect(form.locator('#instance-date-format')).toHaveValue('Y-m-d'); await expect(form.locator('#instance-time-format')).toHaveValue('12');
 conflictNextSave(); await (await revealTabbedControl(form.locator('#instance-date-format'))).selectOption('m/d/Y');

 await expect(page.getByText('Ces paramètres ont été modifiés. Rechargez-les avant d’enregistrer.', { exact: true }).first()).toBeVisible();
 await form.getByRole('button', { name: 'Recharger les paramètres', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(form.locator('#instance-date-format')).toHaveValue('Y-m-d');
 await (await revealTabbedControl(form.locator('#instance-date-format'))).selectOption('localized-full'); await (await revealTabbedControl(form.locator('#instance-time-format'))).selectOption('24');
 await expect(form.locator('output')).toHaveText('dimanche 8 novembre 2026 23:05');

 await expect(page.getByText('Réglages enregistrés.', { exact: true }).first()).toBeVisible();
 await page.reload(); await openModule(page, 'instanceSettings');
 await expect(form.locator('#instance-date-format')).toHaveValue('localized-full');
 await expect(form.locator('output')).toHaveText('dimanche 8 novembre 2026 23:05');
 if (process.env.PLAYWRIGHT_DATE_SETTINGS_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_DATE_SETTINGS_SCREENSHOT, animations: 'disabled' });
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 if (process.env.PLAYWRIGHT_DATE_SETTINGS_MOBILE_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_DATE_SETTINGS_MOBILE_SCREENSHOT, animations: 'disabled' });
 expect(failures).toEqual([]);
});

test('autosave keeps newer text and uses the returned version for the next write', async ({ page }) => {
 const { failures } = await session(page);
 await openModule(page, 'instanceSettings');
 const title = page.locator('#instance-site-title');
 const writes: { site_title: string; version: number }[] = [];
 let release!: () => void;
 const pending = new Promise<void>(resolve => { release = resolve; });
 await page.route('**/api/v1/instance-settings', async route => {
  if (route.request().method() === 'PATCH') {
   const { site_title, version } = route.request().postDataJSON();
   writes.push({ site_title, version });
   if (writes.length === 1) await pending;
  }
  return route.fallback();
 });
 try {
  await title.fill('Temporary title');
  await expect.poll(() => writes).toEqual([{ site_title: 'Temporary title', version: 1 }]);
  await title.fill('Final title');
 } finally { release(); }
 await expect(page).toHaveTitle('Final title');
 expect(writes).toEqual([{ site_title: 'Temporary title', version: 1 }, { site_title: 'Final title', version: 2 }]);
 await title.fill('  ');
 await expect(page.getByRole('alert').filter({ hasText: /Vérifiez les informations du site/ })).toBeVisible();
 expect(writes).toHaveLength(2);
 await title.fill('Valid title');
 await page.getByTestId('nav-home').click();
 await expect(page).toHaveURL(/\/(?:\?.*)?$/);
 await expect(page).toHaveTitle('Valid title');
 expect(writes.at(-1)).toEqual({ site_title: 'Valid title', version: 3 });
 expect(failures).toEqual([]);
});

test.describe('browser language detection', () => {
 test.use({ locale: 'fr-CA' });
 test('first visit follows the browser and an explicit language survives reload', async ({ page }) => {
  const { failures } = await session(page, 'member', false);
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  await expect(page.getByTestId('nav-home')).toHaveText('Tableau de bord');
  expect(await page.evaluate(() => localStorage.getItem('locale'))).toBeNull();
  await openModule(page, 'accountPreferences'); await (await revealTabbedControl(page.locator('#language'))).selectOption('en');
  await page.reload(); await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.getByTestId('nav-home')).toHaveText('Dashboard');
 expect(failures).toEqual([]);
 });
});

test('genetics palette previews, persists, updates tags and resets to instance defaults', async ({ page }) => {
 const { failures } = await session(page); await openModule(page, 'instanceSettings'); await page.getByRole('tab', { name: 'Thèmes', exact: true }).click();
 const form = page.getByTestId('instance-settings');
 const color = form.locator('#genetics-color-sativa'); await expect(color).toHaveValue('#c75b50');
 const tag = form.locator('.genetics-tag.sativa'); const original = await tag.evaluate(el => getComputedStyle(el).backgroundColor);
 await (await revealTabbedControl(color)).fill('#008844'); expect(await tag.evaluate(el => getComputedStyle(el).backgroundColor)).not.toBe(original);

 await expect(page.getByText('Réglages enregistrés.', { exact: true }).first()).toBeVisible();

 await page.reload(); await openModule(page, 'instanceSettings'); await page.getByRole('tab', { name: 'Thèmes', exact: true }).click(); await expect(color).toHaveValue('#008844');
 await page.setViewportSize({ width: 390, height: 844 }); expect(await form.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 await form.locator('.genetics-settings').scrollIntoViewIfNeeded(); await page.screenshot({ path: test.info().outputPath('canabicultarr-genetics-settings-mobile.png'), animations: 'disabled' });
 await form.getByRole('button', { name: 'Rétablir les couleurs par défaut', exact: true }).click(); await expect(color).toHaveValue('#c75b50');
 await expect(page.getByText('Réglages enregistrés.', { exact: true }).first()).toBeVisible();
 await page.reload(); await openModule(page, 'instanceSettings'); await page.getByRole('tab', { name: 'Thèmes', exact: true }).click(); await expect(color).toHaveValue('#c75b50'); expect(failures).toEqual([]);
});

test('site branding saves globally, survives reload, clears subtitle and fits mobile dark mode', async ({ page }) => {
 const { failures, conflictNextSave } = await session(page);
 await openModule(page, 'instanceSettings');
 const form = page.getByTestId('instance-settings');
 await expect(form.locator('#instance-site-title')).toHaveValue('Canabicultarr');
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await page.getByRole('tab', { name: 'OIDC', exact: true }).click();
 await (await revealTabbedControl(form.locator('#instance-oidc-provider-name'))).fill('Garden SSO');
 await page.getByRole('tab', { name: 'Général', exact: true }).click();
 await (await revealTabbedControl(form.locator('#instance-site-title'))).fill('Mon jardin <b>privé</b>');
 await (await revealTabbedControl(form.locator('#instance-site-subtitle'))).fill('Plantes, photos et souvenirs');
 await expect(form.locator('.branding-preview')).toContainText('Mon jardin <b>privé</b>');
 await expect(page.getByTestId('header-home')).toHaveText('Canabicultarr');

 await expect(page.getByTestId('header-home')).toHaveAccessibleName('Mon jardin <b>privé</b>');
 await expect(page.locator('.site-subtitle')).toHaveText('Plantes, photos et souvenirs');
 await expect(page.locator('.navigation-brand strong')).toHaveText('Mon jardin <b>privé</b>');
 await expect(page).toHaveTitle('Mon jardin <b>privé</b>');

 await expect(page).toHaveTitle('Mon jardin <b>privé</b>');
 await expect(page.locator('.header-brand b')).toHaveCount(0);
 await page.reload(); await openModule(page, 'instanceSettings');
 await expect(form.locator('#instance-site-subtitle')).toHaveValue('Plantes, photos et souvenirs');
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await page.getByRole('tab', { name: 'OIDC', exact: true }).click();
 await expect(page.getByRole('region', { name: 'OIDC', exact: true })).toBeVisible();
 await expect(form.locator('#instance-oidc-provider-name')).toHaveValue('Garden SSO');
 await (await revealTabbedControl(form.locator('#instance-oidc-provider-name'))).fill('Garden identity');

 await expect(page.getByText('Réglages enregistrés.', { exact: true }).first()).toBeVisible();
 await expect(page.getByRole('tab', { name: 'OIDC / Authentik', exact: true })).toHaveCount(0);
 await page.getByRole('tab', { name: 'Général', exact: true }).click();
 conflictNextSave(); await (await revealTabbedControl(form.locator('#instance-site-title'))).fill('Conflict draft');

 await expect(page.getByText('Ces paramètres ont été modifiés. Rechargez-les avant d’enregistrer.', { exact: true }).first()).toBeVisible();
 await expect(page.getByTestId('header-home')).toHaveAccessibleName('Mon jardin <b>privé</b>');
 await form.getByRole('button', { name: 'Recharger les paramètres', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(form.locator('#instance-site-title')).toHaveValue('Mon jardin <b>privé</b>');
 await (await revealTabbedControl(form.locator('#instance-site-title'))).fill('Jardin'.repeat(13));
 await (await revealTabbedControl(form.locator('#instance-site-subtitle'))).fill('Souvenirs '.repeat(16));

 await expect(page).toHaveTitle('Jardin'.repeat(13));
 await page.emulateMedia({ colorScheme: 'dark' }); await expect(page.locator('html')).toHaveClass(/app-dark/);
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await page.screenshot({ path: test.info().outputPath('canabicultarr-branding-mobile.png'), animations: 'disabled' });
 await page.getByRole('button', { name: 'Ouvrir le menu', exact: true }).click();
 await expect(page.locator('.navigation-brand strong')).toHaveText('Jardin'.repeat(13));
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await page.screenshot({ path: test.info().outputPath('canabicultarr-branding-menu-mobile.png'), animations: 'disabled' });
 await page.getByRole('button', { name: 'Fermer le menu', exact: true }).click();
 await (await revealTabbedControl(form.locator('#instance-site-subtitle'))).fill('');

 await expect(page.locator('.site-subtitle')).toHaveCount(0);
 await expect(page.locator('.navigation-brand small')).toHaveCount(0);
 expect(failures).toEqual([]);
});

test('public sign-in uses saved site branding without reading protected settings', async ({ page }) => {
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname;
  if (route.request().method() !== 'GET') throw new Error('Unexpected public write');
  if (path.endsWith('/branding')) return route.fulfill({ json: { data: { siteTitle: 'Public Garden', siteSubtitle: 'Welcome home', version: 8 } } });
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false } } });
  expect(path).not.toContain('instance-settings');
  return route.fulfill({ status: 401, json: { error: { code: 'auth.errors.sessionExpired' } } });
 });
 await page.goto('/');
 await expect(page.getByRole('heading', { name: 'Public Garden', exact: true })).toBeVisible();
 await expect(page.locator('.site-subtitle')).toHaveText('Welcome home');
 await expect(page).toHaveTitle('Public Garden');
});

for (const role of ['admin', 'member'] as const) {
 test(`profile owns personal language and appearance preferences for ${role}`, async ({ page }) => {
  const { failures } = await session(page, role);
  if (role === 'admin') {
   await openModule(page, 'instanceSettings');
   await expect(page.locator('#language, #appearance-preference')).toHaveCount(0);
   await expect(page.locator('#instance-site-title')).toBeVisible();
  }
  await openModule(page, 'accountPreferences');
  const profile = page.getByTestId('profile-preferences');
  await expect(profile.locator('#language')).toHaveValue('fr');
  await (await revealTabbedControl(profile.locator('#language'))).selectOption('en');
  await (await revealTabbedControl(profile.getByLabel('Appearance', { exact: true }).and(profile.locator('select')))).selectOption('dark');
  await expect(page.locator('html')).toHaveClass(/app-dark/);
  await page.reload(); await openModule(page, 'accountPreferences');
  await expect(profile.locator('#language')).toHaveValue('en');
  await expect(profile.locator('#appearance-preference')).toHaveValue('dark');
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(profile).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await (await revealTabbedControl(profile.getByLabel('Appearance', { exact: true }).and(profile.locator('select')))).selectOption('system');
  await page.emulateMedia({ colorScheme: 'light' }); await expect(page.locator('html')).not.toHaveClass(/app-dark/);
  await (await revealTabbedControl(profile.locator('#language'))).selectOption('fr');
  expect(failures).toEqual([]);
 });
}


test('shared themes preserve drafts, customize both palettes, persist and follow personal mode', async ({ page }) => {
 const { failures } = await session(page); await openModule(page, 'instanceSettings');
 const settings = page.getByTestId('instance-settings');
 await (await revealTabbedControl(settings.locator('#instance-site-title'))).fill('Fictional themed garden');
 await settings.getByRole('tab', { name: 'Thèmes', exact: true }).click();
 await (await revealTabbedControl(settings.locator('#theme-preset'))).selectOption('ocean');
 await expect(settings.locator('#palette-accent')).toHaveValue('#256c94');
 await (await revealTabbedControl(settings.locator('#palette-accent'))).fill('#7545a0');
 await (await revealTabbedControl(settings.locator('#palette-actionWater'))).fill('#2567ab');
 await expect(settings.locator('#theme-preset')).toHaveValue('custom');
 await (await revealTabbedControl(settings.locator('#theme-palette-mode'))).selectOption('dark');
 await (await revealTabbedControl(settings.locator('#palette-accent'))).fill('#a074d0');
 await (await revealTabbedControl(settings.locator('#palette-actionWater'))).fill('#8abcef');
 await (await revealTabbedControl(settings.locator('#palette-shadow'))).fill('#102030');
 await expect.poll(() => page.evaluate(() => document.documentElement.style.getPropertyValue('--theme-accent-fill'))).toBe('#607b45');
 await settings.getByRole('tab', { name: 'Général', exact: true }).click();
 await expect(settings.locator('#instance-site-title')).toHaveValue('Fictional themed garden');
 await settings.getByRole('tab', { name: 'Thèmes', exact: true }).click();

 await expect(page.getByText('Réglages enregistrés.', { exact: true })).toBeVisible();
 await expect.poll(() => page.evaluate(() => document.documentElement.style.getPropertyValue('--theme-accent-fill'))).toBe('#7545a0');
 await page.reload(); await openModule(page, 'instanceSettings'); await settings.getByRole('tab', { name: 'Thèmes', exact: true }).click();
 await expect(settings.locator('#palette-accent')).toHaveValue('#7545a0');
 await expect(settings.locator('#palette-actionWater')).toHaveValue('#2567ab');
 await openModule(page, 'accountPreferences'); await page.locator('#appearance-preference').selectOption('dark'); await openModule(page, 'instanceSettings'); await settings.getByRole('tab', { name: 'Thèmes', exact: true }).click();
 await expect.poll(() => page.evaluate(() => document.documentElement.style.getPropertyValue('--theme-accent-fill'))).toBe('#a074d0');
 await page.setViewportSize({ width: 390, height: 844 });
 await (await revealTabbedControl(settings.locator('#theme-palette-mode'))).selectOption('dark');
 await expect(settings.locator('#palette-accent')).toHaveValue('#a074d0');
 await expect(settings.locator('#palette-actionWater')).toHaveValue('#8abcef');
 await expect(settings.locator('#palette-shadow')).toHaveValue('#102030');
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await settings.getByRole('button', { name: 'Rétablir Botanique', exact: true }).click();

 await expect(settings.locator('#theme-preset')).toHaveValue('botanical');
 await expect(page.getByText('Réglages enregistrés.', { exact: true })).toBeVisible();
 expect(failures).toEqual([]);
});
