import { applicationInfoFixture } from './application-info-fixture';
import { locationOptionPage } from './location-options-fixture';
import type { NotificationPreferences, NotificationStatus } from '../../src/api/notifications';
import { uiPreferencesFixture } from './ui-preferences-fixture';
import { insightReport } from './insights-fixture';
import { lifecycleStages, lifecycleIcons } from './lifecycle-fixture';
import { test, expect, type Page } from '../coverage/fixture';
import { openSelect, revealTabbedControl, expectControlFocused, openModule, openNotificationSection } from './navigation';
import { delegatedPermissions } from '../../src/api/permissions';

async function syntheticSession(page: Page, role: 'admin' | 'member', permissions: string[], avatarUrl?: string) {
 const preferences = uiPreferencesFixture('fictional-user');
 const requests: string[] = []; const failures: string[] = [];
 page.on('pageerror', error => failures.push(error.message));
 await page.route('**/api/v1/**', async route => {
  const path = new URL(route.request().url()).pathname; requests.push(path);
  if (path.endsWith('/account/ui-preferences')) return preferences.handle(route);
  if (route.request().method() !== 'GET') throw new Error('Synthetic navigation must not write');
  const meta = { current_page: 1, last_page: 1, total: 0, per_page: 20 };
  if (path.includes('/reports/sensors/')) { const report = insightReport(new URL(route.request().url())); return route.fulfill({ json: { data: { ...report, sensor: { ...report.sensor, id: path.split('/').at(-1), name: 'Fictional humidity', measurementType: { ...report.sensor.measurementType, decimalPlaces: 3 } } } } }); }
  if (path.endsWith('/measurements/overview')) return route.fulfill({ json: { data: [], meta, summary: { total: 0, fresh: 0, stale: 0, missing: 0, unknown: 0, disabled: 0 }, generatedAt: new Date().toISOString() } });
  if (path.endsWith('/application-info')) return route.fulfill({ json: { data: applicationInfoFixture() } });
  if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
  if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
  if (path.endsWith('/auth/user')) return route.fulfill({ json: { data: { id: 'fictional-user', name: 'Fictional User', email: 'fictional@example.test', avatarUrl, permissionRoles: [{ id: role, name: role === 'admin' ? 'Administrator' : 'Observer' }], permissions: role === 'admin' ? [...delegatedPermissions] : permissions, oidcLinked: false, isActive: true, locationAccess: null } } });
  if (path.endsWith('/notifications/settings')) return route.fulfill({ json: { data: { canManage: role === 'admin', settings: { email_enabled: false, push_enabled: false, webhook_enabled: false, include_recovery: true, minimum_severity: 'info', locale: 'en', version: 1 }, channels: { email: { configured: false }, push: { configured: false, publicKey: null }, webhook: { configured: false, format: 'generic' } } } } });
  if (path.endsWith('/notifications/preferences')) return route.fulfill({ json: { data: { email_enabled: false, push_enabled: false, locale: 'en', version: 1 } } });
  if (path.endsWith('/inventory/items')) return route.fulfill({ json: { data: [], meta, canEditCatalog: role === 'admin' } });
  if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: role === 'admin' } } });
  if (path.endsWith('/lifecycle-settings')) return route.fulfill({ json: { data: { stages: lifecycleStages, icons: lifecycleIcons, wateringDefaults: { alertDays: 3, moistureThreshold: 35 }, version: 1, canManage: role === 'admin', durations: { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 } } } });
  if (path.endsWith('/integrations/home-assistant') || path.endsWith('/integrations/mqtt')) return route.fulfill({ json: { data: { configured: false } } });
  return route.fulfill({ json: { data: [], meta } });
 });
 await page.addInitScript(() => { if (!localStorage.getItem('locale')) localStorage.setItem('locale', 'fr'); }); await page.goto('/');
 await expect(page.getByTestId('plant-home')).toBeVisible();
 return { requests, failures };
}

test('grouped menu exposes child destinations directly with contextual tools and accessible mobile navigation', async ({ page }) => {
 const { requests, failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const primary = page.locator('#app-navigation .primary-link');
 await expect(primary).toHaveText(['Tableau de bord', 'Culture', 'Surveillance', 'Intégrations', 'Administration']);
 await expect(page.locator('#submenu-culture button')).toHaveText(['Plantes', 'Variétés', 'Sites', 'Vue d’ensemble']);
 await expect(page.getByRole('tablist', { name: 'Culture', exact: true })).toHaveCount(0);
 const integration = page.getByTestId('nav-integration'); await integration.focus(); await page.keyboard.press('Space');
 await expect(integration).toHaveAttribute('aria-expanded', 'true');
 await expect(page.locator('#submenu-integration button')).toHaveText(['Home Assistant', 'Homebridge', 'MQTT / HTTP', 'Bluetooth', 'Diagnostic']);
 await expect(page.getByTestId('plant-home')).toBeVisible();
 expect(requests.some(path => path.includes('/integrations/home-assistant'))).toBe(false);
 await page.keyboard.press('Space'); await expect(integration).toHaveAttribute('aria-expanded', 'false');
 await openModule(page, 'locations');
 await expect(page.getByTestId('workspace-section-locations')).toHaveCount(0);
 await expect(page.getByTestId('workspace-section-assets')).toHaveCount(0);
 await openModule(page, 'assets');
 await expect(page.getByTestId('nav-item-assets')).toHaveAttribute('aria-current', 'page');
 await expect(page.getByRole('heading', { name: 'Matériels', exact: true })).toBeVisible();
 await expect(page.getByTestId('nav-monitoring')).toHaveAttribute('aria-current', 'true');
 await expect(page.getByRole('button', { name: 'Retour à l’accueil', exact: true })).toHaveCount(0);
 await openModule(page, 'inventory');
 await expect(page.getByRole('heading', { name: 'Inventaire', exact: true })).toBeVisible();
 await expect(page.getByTestId('nav-item-inventory')).toHaveAttribute('aria-current', 'page');
 await expect(page.getByTestId('nav-administration')).toHaveAttribute('aria-current', 'true');
 await openModule(page, 'catalog'); await expect(page.getByRole('button', { name: 'Réglages du cycle', exact: true })).toHaveCount(0);
 await openModule(page, 'catalogs'); await expect(page.locator('.comment-templates')).toBeVisible();
 await openModule(page, 'cycleSettings'); await expect(page.locator('#cycle-flush')).toHaveValue('2'); await expect(page.locator('#cycle-flush-unit')).toHaveValue('weeks'); await expect(page.locator('#strain-search')).toHaveCount(0);
 await openModule(page, 'profiles'); await expect(page.getByTestId('nav-administration')).toHaveAttribute('aria-current', 'true');
 await expect(page.locator('#submenu-administration button').first()).toHaveAttribute('data-testid', 'nav-item-instanceSettings');
 await openModule(page, 'ha');
 await expect(page.getByText('Configurez l’adresse Home Assistant et le jeton privé sur le serveur.', { exact: true })).toBeVisible();
 const haRequests = requests.filter(path => path.includes('/integrations/home-assistant')).length;
 await openModule(page, 'measurements'); await expect(page.locator('#measurement-search')).toBeVisible();
 await expect(page.getByTestId('workspace-section-reports')).toHaveCount(0);
 await expect(page.getByTestId('nav-item-reports')).toHaveCount(0);
 const breadcrumb = page.getByRole('navigation', { name: 'Fil d’Ariane', exact: true });
 await expect(breadcrumb.locator('.breadcrumb-link')).toHaveText(['Surveillance', 'Mesures']);
 await expect(breadcrumb.locator('[aria-current=page]')).toHaveText('Mesures');
 await breadcrumb.getByRole('button', { name: 'Surveillance', exact: true }).click();
 await expect(page.getByTestId('nav-item-assets')).toHaveAttribute('aria-current', 'page');
 expect(requests.filter(path => path.includes('/integrations/home-assistant'))).toHaveLength(haRequests);
 await openModule(page, 'events'); await expect(page.getByTestId('workspace-section-documents')).toHaveCount(0);
 await expect(page.locator('#submenu-monitoring button')).toHaveText(['Matériels', 'Mesures', 'Alertes', 'Journal']);
 await openModule(page, 'documents');
 await expect(page.getByRole('heading', { name: 'Fichiers', exact: true })).toBeVisible();
 await expect(page.getByTestId('nav-item-documents')).toHaveAttribute('aria-current', 'page');
 await expect(page.getByTestId('nav-administration')).toHaveAttribute('aria-current', 'true');
 await expect(page.getByTestId('nav-item-events')).not.toHaveAttribute('aria-current', 'page');
 await openModule(page, 'homebridge'); await expect(page.getByRole('heading', { name: 'Intégration Homebridge', exact: true, level: 2 })).toBeVisible();
 await openModule(page, 'http'); await expect(page.getByTestId('nav-item-mqtt')).toHaveAttribute('aria-current', 'page');
 await expect(page.locator('.http-card').getByTestId('workspace-section-http')).toHaveAttribute('aria-selected', 'true');
 await page.getByTestId('workspace-section-mqtt').click();
 await expect(page.locator('.mqtt-card').getByTestId('workspace-section-mqtt')).toHaveAttribute('aria-selected', 'true');
 await page.getByTestId('workspace-section-http').click();

 await expect(breadcrumb.locator('.breadcrumb-link')).toHaveText(['Intégrations', 'MQTT / HTTP', 'HTTP']);
 await expect(page.getByRole('tablist', { name: 'Intégrations', exact: true })).toHaveCount(0);
 await openModule(page, 'notifications'); await expect(page.getByTestId('nav-administration')).toHaveAttribute('aria-current', 'true');
 await expect(page.locator('#notification-global-email')).toBeVisible();
 await openModule(page, 'account'); await expect(page.getByRole('region', { name: 'Mon compte', exact: true }).getByRole('tablist').getByRole('tab')).toHaveText(['Profil', 'Préférences', 'Accès', 'Sécurité', 'Notifications']);
 await page.setViewportSize({ width: 390, height: 844 }); await openModule(page, 'assets');
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 await page.getByRole('button', { name: 'Ouvrir le menu', exact: true }).click();
 const drawer = page.getByRole('dialog', { name: 'Navigation principale', exact: true }); await expect(drawer.locator('.primary-link')).toHaveCount(5);
 await expect(drawer.locator('#submenu-culture button')).toHaveText(['Plantes', 'Variétés', 'Sites', 'Vue d’ensemble']);
 if (await drawer.getByTestId('nav-administration').getAttribute('aria-expanded') !== 'true') await drawer.getByTestId('nav-administration').click();
 await drawer.getByTestId('nav-item-inventory').click();
 await expect(page.getByRole('heading', { name: 'Inventaire', exact: true })).toBeVisible();
 await expect(breadcrumb).toBeVisible();
 await expect(breadcrumb.locator('.breadcrumb-link')).toHaveText(['Administration', 'Inventaire']);
 await page.getByTestId('header-home').click();
 await expect(page.getByTestId('plant-home')).toBeVisible();
 await expect(breadcrumb.locator('.breadcrumb-link')).toHaveText(['Tableau de bord']);
 await openModule(page, 'inventory');
 await openModule(page, 'catalog');
 await expect(page.getByRole('dialog', { name: 'Navigation principale', exact: true })).toHaveCount(0);
 await expect(page.getByRole('heading', { name: 'Catalogue des variétés', exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'Ouvrir le menu', exact: true }).click();
 await page.keyboard.press('Shift+Tab'); await expectControlFocused(drawer.getByRole('button', { name: 'Se déconnecter', exact: true }));
 await page.keyboard.press('Tab'); await expectControlFocused(drawer.getByRole('button', { name: 'Fermer le menu', exact: true }));
 await page.keyboard.press('Escape'); await expectControlFocused(page.getByRole('button', { name: 'Ouvrir le menu', exact: true }));
 expect(failures).toEqual([]);
});

for (const delegated of [false, true]) test(`member workspace visibility respects integration delegation (${delegated})`, async ({ page }) => {
 const { failures } = await syntheticSession(page, 'member', delegated ? ['integrations.manage'] : []);
 await expect(page.getByTestId('nav-administration')).toHaveCount(1);
 await openModule(page, 'documents');
 await expect(page.locator('#submenu-administration button')).toHaveText(['Réglages', 'Inventaire', 'Fichiers']);
 await expect(page.getByTestId('nav-integration')).toHaveCount(delegated ? 1 : 0);
 await openModule(page, 'inventory'); await expect(page.getByRole('heading', { name: 'Inventaire', exact: true })).toBeVisible();
 await openModule(page, 'accountNotifications');
 await expect(page.getByTestId('nav-account')).toHaveAttribute('aria-current', 'page');
 await expect(page.locator('#notification-locale')).toBeAttached();
 await expect(page.locator('label[for="notification-locale"]')).toBeVisible();
 await expect(page.locator('#notification-global-email')).toHaveCount(0);
 if (delegated) await page.getByTestId('nav-integration').click();
 await expect(page.getByTestId('nav-item-ha')).toHaveCount(delegated ? 1 : 0);
 await expect(page.getByTestId('nav-item-sources')).toHaveCount(delegated ? 1 : 0);
 await openModule(page, 'catalog'); await expect(page.getByRole('button', { name: 'Ajouter une variété', exact: true })).toHaveCount(0);
 await expect(page.getByTestId('nav-item-cycleSettings')).toHaveCount(0);
 await openModule(page, 'documents'); await expect(page.getByRole('heading', { name: 'Fichiers', exact: true })).toBeVisible();
 if (delegated) { await openModule(page, 'homebridge'); await expect(page.getByRole('heading', { name: 'Intégration Homebridge', exact: true, level: 2 })).toBeVisible(); }
 expect(failures).toEqual([]);
});

test('installation help opens from the menu, retains native prompt state and restores desktop/mobile focus', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'member', []);
 await expect(page.locator('.app-availability summary')).toHaveCount(0);
 await page.evaluate(() => {
  const event = new Event('beforeinstallprompt', { cancelable: true });
  Object.assign(event, { prompt: async () => { document.documentElement.dataset.promptCount = '1'; }, userChoice: Promise.resolve({ outcome: 'dismissed' }) });
  window.dispatchEvent(event);
 });
 await expect(page.getByRole('button', { name: 'Installer l’application', exact: true })).toHaveCount(0);
 await page.locator('#navigation-install-help').click();
 const help = page.getByRole('dialog', { name: 'Aide à l’installation', exact: true }); await expect(help).toBeVisible();
 await expect(help.getByText('Dans le menu du navigateur, choisissez Installer l’application', { exact: false })).toBeVisible();
 expect(await page.locator('html').getAttribute('data-prompt-count')).toBeNull();
 await help.getByRole('button', { name: 'Installer l’application', exact: true }).click();
 await expect(page.locator('html')).toHaveAttribute('data-prompt-count', '1');
 await expect(help.getByRole('button', { name: 'Installer l’application', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(help).toHaveCount(0); await expectControlFocused(page.locator('#navigation-install-help'));
 await openModule(page, 'accountPreferences'); await (await revealTabbedControl(page.locator('#language'))).selectOption('en'); await openModule(page, 'home'); await expect(page.getByTestId('nav-home')).toHaveText('Dashboard');
 await page.setViewportSize({ width: 390, height: 844 }); await page.getByRole('button', { name: 'Open menu', exact: true }).click();
 await page.locator('#navigation-install-help').click();
 await expect(page.getByRole('dialog', { name: 'Main navigation', exact: true })).toHaveCount(0);
 await expect(page.getByRole('dialog', { name: 'Installation help', exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0);
 await expectControlFocused(page.getByRole('button', { name: 'Open menu', exact: true }));
 expect(failures).toEqual([]);
});

test('live user search returns to page one and ignores older responses', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let releaseSlow: (() => Promise<void>) | undefined;
 await page.route('**/api/v1/users?*', async route => {
  const query = new URL(route.request().url()).searchParams;
  const name = query.get('search') === 'slow' ? 'Stale user' : query.get('search') === 'latest' ? 'Latest user' : 'Initial user';
  const reply = () => route.fulfill({ json: {
   data: [{ id: 'fictional-user-row', name, email: 'fictional-row@example.test', avatarUrl: null, role: 'member', isActive: true, permissionGrants: [], locationAccess: null, permissionRoles: []}],
   meta: { current_page: Number(query.get('page')), last_page: 2, per_page: 20, total: 21 },
  } });
  if (query.get('search') === 'slow') { releaseSlow = reply; return; }
  await reply();
 });
 await openModule(page, 'users');
 await page.getByRole('navigation', { name: 'Pagination des utilisateurs', exact: true }).getByRole('button', { name: 'Suivante', exact: true }).click();
 await expect(page.getByRole('navigation', { name: 'Pagination des utilisateurs', exact: true })).toContainText('2 / 2');
 const search = page.locator('#users-search');
 const slow = page.waitForRequest(req => new URL(req.url()).searchParams.get('search') === 'slow');
 await (await revealTabbedControl(search)).fill('slow');
 expect(new URL((await slow).url()).searchParams.get('page')).toBe('1');
 await (await revealTabbedControl(search)).fill('latest');
 await expect(page.locator('.user-identity')).toContainText('Latest user');
 const response = page.waitForResponse(res => new URL(res.url()).searchParams.get('search') === 'slow');
 await releaseSlow!(); await response;
 await page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
 await expect(page.locator('.user-identity')).toContainText('Latest user');
 await expect(page.getByRole('navigation', { name: 'Pagination des utilisateurs', exact: true })).toContainText('1 / 2');
 expect(failures).toEqual([]);
});

test('Gravatar appears on profiles and user rows with local initials when images fail', async ({ page }) => {
 const avatarUrl = 'https://gravatar.com/avatar/8b076e534cba98f2e850d05a365e4744105eae05d628967595880eb5a422e60c?s=128&d=404&r=g';
 let missing = false;
 const avatarRequests: string[] = [];
 await page.route('https://gravatar.com/avatar/**', async route => {
  avatarRequests.push(route.request().url());
  expect(route.request().headers().referer).toBeUndefined();
  if (missing) return route.fulfill({ status: 404, body: '' });
  return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGXsAAAAASUVORK5CYII=', 'base64') });
 });
 const { failures } = await syntheticSession(page, 'admin', ['administration.view'], avatarUrl);
 const menuAvatar = page.getByTestId('nav-account').locator('.user-avatar');
 await expect(menuAvatar.locator('img')).toBeVisible();
 await expect(menuAvatar.locator('img')).toHaveAttribute('src', avatarUrl);
 await openModule(page, 'account');
 await expect(page.locator('.account .user-avatar img')).toBeVisible();
 await page.route('**/api/v1/users?*', route => route.fulfill({ json: { data: [{ id: 'fictional-user', name: 'Fictional User', email: 'fictional@example.test', avatarUrl, role: 'admin', isActive: true, permissionGrants: [], locationAccess: null, permissionRoles: []}], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } }));
 await openModule(page, 'users');
 await expect(page.locator('.user-identity .user-avatar img')).toBeVisible();
 missing = true;
 await page.reload();
 await expect(menuAvatar).toHaveText('FU');
 await expect(menuAvatar.locator('img')).toHaveCount(0);
 await openModule(page, 'account');
 await expect(page.locator('.account .user-avatar')).toHaveText('FU');
 expect(avatarRequests.length).toBeGreaterThan(0);
 expect(failures).toEqual([]);
});

test('workspace creation forms open, expose submit controls and return focus on cancel', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view', 'locations.manage', 'assets.manage', 'sensors.manage', 'inventory.manage', 'strains.manage', 'integrations.manage']);
 await page.route('**/api/v1/integrations/mqtt', route => route.fulfill({ json: { data: { configured: true } } }));
 await page.route('**/api/v1/comment-templates?*', route => route.fulfill({ json: { data: [], canManage: true, meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } }));
 const modules = [
  ['locations', 'Ajouter un site'], ['assets', 'Ajouter du matériel'], ['sensors', 'Ajouter un canal'], ['inventory', 'Ajouter un article'],
  ['events', 'Ajouter une entrée'], ['documents', 'Ajouter un document'], ['catalog', 'Ajouter une variété'],
  ['users', 'Ajouter un compte'], ['profiles', 'Créer un profil'], ['catalogs', 'Nouveau modèle'],
  ['http', 'Ajouter une source HTTP'], ['mqtt', 'Associer un topic'],
 ];
 for (const [index, [module, label]] of modules.entries()) {
  await page.setViewportSize({ width: index % 2 ? 390 : 1280, height: 844 });
  await openModule(page, module!); const trigger = page.getByRole('button', { name: label!, exact: true }); await trigger.click();
  const dialog = page.locator('.form-dialog'); const form = dialog.locator('form.dialog-form'); await expect(form).toBeVisible();
  await expect(dialog.locator('.n-card__content button[type=submit]')).toHaveCount(0);
  await expect(dialog.locator('.n-card__footer button[type=submit]')).toHaveText(module === 'documents' ? 'Importer' : 'Créer');
  await expect(dialog.locator('.n-card__footer button[type=submit]')).toHaveAttribute('form', (await form.getAttribute('id'))!);
  await dialog.locator('.dialog-body').evaluate(el => { el.scrollTop = el.scrollHeight; });
  await expect(dialog.locator('.n-card__footer')).toBeInViewport();
  expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
  await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expectControlFocused(trigger);
 }
 expect(failures).toEqual([]);
});

test('location list icons preserve distinct contextual navigation and edit names', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const meta = { current_page: 1, last_page: 1, per_page: 20, total: 1 };
 await page.route('**/api/v1/sites?*', route => route.fulfill({ json: { data: [{ id: 'fictional-site', name: 'Fictional Site', childrenCount: 1, canManage: true }], meta } }));
 await page.route('**/api/v1/sites/fictional-site/rooms?*', route => route.fulfill({ json: { data: [{ id: 'fictional-room', name: 'Fictional Room', childrenCount: 1, canManage: true }], meta } }));
 await openModule(page, 'locations');
 const content = page.locator('#workspace-content');
 await expect(content.getByRole('button', { name: 'Ouvrir Fictional Site', exact: true })).toHaveCount(1);
 const open = content.getByRole('button', { name: 'Ouvrir Fictional Site', exact: true });
 await expect(open).toHaveText('Fictional Site'); await open.click();
 await expect(content.getByRole('button', { name: 'Ouvrir Fictional Room', exact: true })).toHaveCount(1);
 await expect(content.getByRole('button', { name: 'Ouvrir Fictional Room', exact: true })).toHaveText('Fictional Room');
 await content.getByRole('button', { name: 'Modifier Fictional Room', exact: true }).click();
 await expect(page.locator('.form-dialog #location-name')).toHaveValue('Fictional Room'); await page.keyboard.press('Escape');
 expect(failures).toEqual([]);
});

test('inline selectors retain editor drafts and the parent workspace', async ({ page }) => {
 test.setTimeout(120_000);
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 await page.route('**/api/v1/integrations/mqtt', route => route.fulfill({ json: { data: { configured: true } } }));
 const scenarios = [
  ['users', 'Ajouter un compte', '#user-name', '#user-permission-profile'],
  ['alerts', 'Ajouter une règle', '#alert-rule-name', '#alert-rule-sensor'],
  ['http', 'Ajouter une source HTTP', '#http-source-name', '#httpIngestion-sensor'],
  ['mqtt', 'Associer un topic', '#mqtt-topic', '#mqtt-sensor'],
  ['events', 'Ajouter une entrée', '#event-title', '#events-subject'],
  ['documents', 'Ajouter un document', '#document-title', '#documents-subject'],
 ];
 for (const [index, [module, add, field, choose]] of scenarios.entries()) {
  await page.setViewportSize({ width: index % 2 ? 390 : 1440, height: 900 });
  await openModule(page, module!);
  if (module === 'alerts') await page.getByRole('tab', { name: 'Règles de surveillance', exact: true }).click();
  await page.getByRole('button', { name: add!, exact: true }).click();
  const editor = page.locator('.form-dialog');
  // Some source editors have no name field; preserve the topic/path draft instead.
  const input = editor.locator(field!);
  if (await input.count()) await (await revealTabbedControl(input)).fill('Fictional unsaved draft');
  if (module === 'events') await (await revealTabbedControl(editor.locator('#event-context-kind'))).selectOption('location');
  if (module === 'documents') await (await revealTabbedControl(editor.locator('#document-context-kind'))).selectOption('asset');
  if (module === 'users') await editor.getByRole('tab', { name: 'Profils et droits', exact: true }).click();
  const select = editor.locator(choose!); await expect(select).toBeEnabled();
  await expect(select).toHaveJSProperty('tagName', 'SELECT');
  await expect(page.locator('.module-dialog')).toHaveCount(0);
  await openSelect(select);
  await expect(page.getByRole('listbox')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('listbox')).toHaveCount(0);
  await expect(editor).toBeVisible();
  if (await input.count()) await expect(input).toHaveValue('Fictional unsaved draft');
  await page.keyboard.press('Escape'); if (module !== 'http') await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0);
 }
 expect(failures).toEqual([]);
});

test('strain stock select filters seeds, loads all pages, preserves drafts and supports mobile clearing', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view', 'strains.manage']);
 let failStock = true;
 await page.route('**/api/v1/inventory/items?*', route => {
  if (failStock) return route.fulfill({ status: 500, json: { error: { code: 'auth.errors.requestFailed' } } });
  const params = new URL(route.request().url()).searchParams; expect(params.get('type')).toBe('seeds'); expect(params.get('state')).toBe('active');
  const target = Number(params.get('page'));
  const data = target === 1 ? [{ id: 'liquid', type: 'fertilizer', name: 'Fictional liquid', totalQuantity: '5', unit: 'l' }, { id: 'pot', type: 'container', name: 'Fictional pot', totalQuantity: '5', unit: 'piece' }] : [{ id: 'fictional-stock', type: 'seeds', name: 'Fictional seed stock', totalQuantity: '12', unit: 'piece', isArchived: false, canEditCatalog: true }];
  return route.fulfill({ json: { data, meta: { current_page: target, last_page: 2, total: 2, per_page: 1 } } });
 });
 const writes: Record<string, unknown>[] = [];
 let record = { id: 'fictional-strain', name: 'Fictional draft strain', durationOverrides: {}, effectiveDurations: {}, seedStock: null as null | { id: string; name: string; quantity: string; isArchived: boolean; scoped: boolean }, version: 1, canManage: true, isArchived: false };
 await page.route('**/api/v1/strains**', route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: new URL(route.request().url()).pathname.endsWith('/strains') ? { data: writes.length ? [record] : [], meta: { current_page: 1, last_page: 1, total: writes.length ? 1 : 0, per_page: 20 } } : { data: record } });
  const input = route.request().postDataJSON(); writes.push(input);
  record = { ...record, seedStock: input.seed_inventory_item_id ? { id: input.seed_inventory_item_id, name: 'Fictional seed stock', quantity: '12', isArchived: false, scoped: false } : null };
  return route.fulfill({ json: { data: record } });
 });
 await openModule(page, 'catalog'); await page.getByRole('button', { name: 'Ajouter une variété', exact: true }).click();
 const editor = page.locator('.form-dialog'); const stock = editor.locator('#strain-stock');
 await (await revealTabbedControl(editor.locator('#strain-name'))).fill('Fictional draft strain'); await editor.getByRole('tab', { name: 'Stock', exact: true }).click(); await expect(editor.getByRole('alert')).toBeVisible();
 failStock = false; await editor.getByRole('button', { name: 'Recharger la liste', exact: true }).click(); await expect(stock).toBeEnabled();
 await expect(stock.locator('option[value="liquid"]')).toHaveCount(0); await expect(stock.locator('option[value="pot"]')).toHaveCount(0);
 await editor.locator(`[data-ui-select="${await stock.getAttribute('id')}"]`).click(); await (await revealTabbedControl(page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }))).fill('seed');
 await page.getByRole('listbox').getByRole('option', { name: 'Fictional seed stock · 12 graines', exact: true }).click();
 await expect(editor.locator('#strain-name')).toHaveValue('Fictional draft strain'); await expect(page.locator('.module-dialog')).toHaveCount(0);
 await editor.getByRole('button', { name: /^(?:Enregistrer la variété|Créer)$/, exact: true }).click(); await expect.poll(() => writes[0]?.seed_inventory_item_id).toBe('fictional-stock'); await expect(editor).toHaveCount(0);
 await page.setViewportSize({ width: 390, height: 844 }); await page.getByRole('button', { name: 'Modifier Fictional draft strain', exact: true }).click();
 await expect(stock).toBeEnabled(); await expect(stock).toHaveValue('fictional-stock'); await expect(stock).toHaveAttribute('data-select-mode', 'custom');
 await (await revealTabbedControl(stock)).selectOption(''); await editor.getByRole('button', { name: /^(?:Enregistrer la variété|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[1]?.seed_inventory_item_id).toBeNull(); expect(failures).toEqual([]);
});

test('stock movements use inline locations and preserve their parent record', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const stock = { id: 'fictional-stock', name: 'Fictional stock', sku: null, description: null, totalQuantity: '12', unit: 'piece', isArchived: false, canEditCatalog: true, canManage: true, version: 1, isLowStock: false, threshold: null };
 await page.route('**/api/v1/inventory/items**', route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/fictional-stock')) return route.fulfill({ json: { data: stock } });
  return route.fulfill({ json: { data: path.endsWith('/items') ? [stock] : [], meta, canEditCatalog: true } });
 });
 await page.route('**/api/v1/inventory/locations?*', route => route.fulfill({ json: { data: [{ id: 'fictional-storage', name: 'Fictional storage', description: null, isArchived: false }], meta, canManage: true } }));
 await page.setViewportSize({ width: 390, height: 900 }); await openModule(page, 'inventory');
 await page.locator('.item-list').getByRole('button', { name: /Fictional stock/ }).first().click();
 const detail = page.getByRole('dialog', { name: 'Fictional stock', exact: true }); await expect(detail).toBeVisible();
 await detail.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
 const form = page.locator('.form-dialog'); await (await revealTabbedControl(form.locator('#movement-quantity'))).fill('7');
 await (await revealTabbedControl(form.locator('#inventory-destination'))).selectOption('fictional-storage');
 await expect(page.getByRole('dialog')).toHaveCount(2);
 await expect(form.locator('#movement-quantity')).toHaveValue('7'); await expect(form.locator('#inventory-destination')).toHaveValue('fictional-storage');
 await page.keyboard.press('Escape');
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(form).toHaveCount(0); await expect(detail).toBeVisible();
 await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0);
 await expect(page.locator('.item-list')).toBeVisible(); expect(failures).toEqual([]);
});

test('hardware channels, assignment history and journal details open above their lists', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const asset = { id: 'fictional-device', name: 'Fictional device', category: 'sensor', status: 'active', locations: [], plants: [] };
 const event = { id: 'fictional-event', title: 'Fictional observation', body: 'Fictional detailed observation.', category: 'observation', severity: 'info', occurredAt: '2026-11-09T10:00:00Z', author: { id: 'fictional-user', name: 'Fictional User' }, subject: null, canEdit: true, version: 1 };
 await page.route('**/api/v1/assets?*', route => route.fulfill({ json: { data: [asset], meta } }));
 await page.route(`**/api/v1/assets/${asset.id}`, route => route.fulfill({ json: { data: asset } }));
 await page.route('**/api/v1/assets/fictional-device', route => route.fulfill({ json: { data: asset } }));
 await page.route('**/api/v1/events?*', route => route.fulfill({ json: { data: [event], meta } }));
 await page.route('**/api/v1/events/fictional-event', route => route.fulfill({ json: { data: event } }));
 await page.route('**/api/v1/assets/fictional-device/assignments?*', route => route.fulfill({ json: { data: [{ id: 'assignment-one', assignedAt: '2026-11-09T10:00:00Z', changedBy: null, locations: [{ type: 'zone', id: 'zone-one', site: { id: 'site-one', name: 'Fictional site' }, room: { id: 'room-one', name: 'Fictional room' }, zone: { id: 'zone-one', name: 'Fictional zone' } }] }], meta } }));
 await openModule(page, 'assets');
 const deviceSelection = page.getByRole('checkbox', { name: 'Sélectionner Fictional device', exact: true });
 await deviceSelection.check();
 await expect(page.getByRole('button', { name: 'Attribuer à une plante', exact: true })).toBeEnabled();
 await expect(page.getByRole('button', { name: 'Ajouter un emplacement', exact: true })).toBeEnabled();
 await deviceSelection.uncheck();
 for (const label of ['Ouvrir Fictional device', 'Historique des affectations de Fictional device']) {
  const opener = page.getByRole('button', { name: label, exact: true }); await opener.click();
  const dialog = page.getByRole('dialog', { name: label.startsWith('Ouvrir') ? 'Fictional device' : 'Historique des affectations : Fictional device', exact: true }); await expect(dialog).toBeVisible();
  const content = dialog.locator(':scope > .n-card-content');
  await expect.poll(() => content.evaluate(node => parseFloat(getComputedStyle(node).paddingInlineStart))).toBeGreaterThan(0);
  await expect.poll(() => content.evaluate(node => parseFloat(getComputedStyle(node).paddingInlineEnd))).toBeGreaterThan(0);
  await expect(dialog.locator('.dialog-heading')).toBeVisible();
  if (!label.startsWith('Ouvrir')) {
   const item = dialog.locator('.assignment-history-list > li').first();
   for (const width of [1280, 390]) {
    await page.setViewportSize({ width, height: 844 });
    await expect(item.getByRole('link')).toHaveText(['Fictional site', 'Fictional room', 'Fictional zone']);
    const inset = await item.evaluate(node => { const style = getComputedStyle(node); return [style.paddingInlineStart, style.paddingInlineEnd, style.paddingBlockStart, style.paddingBlockEnd].map(parseFloat); });
    expect(inset.every(value => value > 0)).toBe(true);
   }
   await page.setViewportSize({ width: 1280, height: 844 });
  }
  await expect(page.locator('#workspace-content .asset-grid')).toBeAttached();
  await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expectControlFocused(opener);
 }
 await openModule(page, 'events'); await page.locator('.event-list').getByRole('button').first().click();
 const detail = page.getByRole('dialog', { name: 'Fictional observation', exact: true }); await expect(detail).toBeVisible();
 await expect(detail.getByText('Fictional detailed observation.', { exact: true })).toBeVisible(); await expect(page.locator('#workspace-content .event-list')).toBeAttached();
 await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0); expect(failures).toEqual([]);
});

test('Home Assistant discovery is modal and retains entity choices when reopened', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 await page.route('**/api/v1/integrations/home-assistant', route => route.fulfill({ json: { data: { configured: true } } }));
 await page.route('**/api/v1/integrations/home-assistant/entities?*', route => route.fulfill({ json: { data: [{ id: 'device:fictional', name: 'Fictional humidity', category: 'sensor', entityCount: 2, importedCount: 0, entities: [{ id: 'sensor.fictional', name: 'Humidity', unit: '%' }, { id: 'sensor.battery', name: 'Battery', unit: '%' }], imported: false, available: true }], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await openModule(page, 'ha');
 const browse = page.getByRole('button', { name: 'Parcourir les appareils Home Assistant', exact: true }); await browse.click();
 const dialog = page.getByRole('dialog', { name: 'Sélecteur d’appareils Home Assistant', exact: true }); await expect(dialog).toBeVisible();
 const choice = dialog.getByRole('checkbox', { name: 'Sélectionner Fictional humidity', exact: true }); await (await revealTabbedControl(choice)).check();
 await page.setViewportSize({ width: 390, height: 900 }); expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expectControlFocused(browse);
 await browse.click(); await expect(choice).toBeChecked(); await page.keyboard.press('Escape'); expect(failures).toEqual([]);
});

test('integration measurement history opens in a modal without leaving the source workspace', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view', 'integrations.manage']);
 const meta = { current_page: 1, last_page: 1, total: 0, per_page: 20 };
 const sensor = { id: 'fictional-sensor', name: 'Fictional humidity', device: { id: 'fictional-device', name: 'Fictional device', status: 'active' }, measurementType: { id: 'fictional-type', name: 'Humidity', unit: '%', decimalPlaces: 1 }, isEnabled: true, channel: 'humidity', location: null };
 await page.route('**/api/v1/integrations/home-assistant/links?*', route => route.fulfill({ json: { data: [{ id: 'fictional-link', entityId: 'sensor.fictional', sensor, isEnabled: true, version: 1, lastSuccessAt: null, lastError: null }, { id: 'fictional-link-two', entityId: 'sensor.fictional_two', sensor: { ...sensor, name: 'Fictional temperature' }, isEnabled: true, version: 1, lastSuccessAt: null, lastError: null }], meta } }));
 await page.route('**/api/v1/sensors/fictional-sensor', route => route.fulfill({ json: { data: sensor } }));
 await page.route('**/api/v1/sensors/fictional-sensor/measurements?*', route => route.fulfill({ json: { data: [], meta, summary: { sensor, latest: null, status: 'missing' } } }));
 await openModule(page, 'ha');

 const opener = page.getByRole('button', { name: 'Voir les mesures de Fictional humidity', exact: true }); await opener.click();
 const dialog = page.getByRole('dialog', { name: 'Historique · Fictional humidity', exact: true }); await expect(dialog).toBeVisible();
 await expect(dialog.getByTestId('sensor-analysis')).toBeVisible();
 await expect(page).toHaveURL(/\/integrations\/home-assistant\?sensor=fictional-sensor$/);
 await page.goBack(); await expect(dialog).toHaveCount(0);
 await page.goForward(); await expect(dialog).toBeVisible();
 await page.reload(); await expect(dialog).toBeVisible();
 await expect(page.getByTestId('nav-item-ha')).toHaveAttribute('aria-current', 'page');
 await page.setViewportSize({ width: 390, height: 900 }); expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expect(page).toHaveURL(/\/integrations\/home-assistant$/);
 await opener.click(); await expect(dialog).toBeVisible(); await page.keyboard.press('Escape'); await expectControlFocused(opener);
 await page.setViewportSize({ width: 1280, height: 900 });
 await expect(page.locator('.link-list > li')).toHaveCount(2);
 await page.setViewportSize({ width: 390, height: 844 });
 expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 expect(failures).toEqual([]);
});

test('source diagnostics opens read-only measurement history without leaving its page', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['integrations.manage']);
 const meta = { current_page: 1, last_page: 1, total: 0, per_page: 20 };
 const sensor = { id: 'fictional-sensor', name: 'Fictional humidity', device: { id: 'fictional-device', name: 'Fictional device', status: 'active' }, measurementType: { id: 'fictional-type', name: 'Humidity', unit: '%', decimalPlaces: 1 }, isEnabled: true, channel: 'humidity', location: null };
 await page.route('**/api/v1/sensors/fictional-sensor', route => route.fulfill({ json: { data: sensor } }));
 await page.route('**/api/v1/sensors/fictional-sensor/measurements?*', route => route.fulfill({ json: { data: [], meta, summary: { sensor, latest: null, status: 'missing' } } }));
 await page.route('**/api/v1/integrations/sources?*', route => route.fulfill({ json: {
  data: [{ id: 'fictional-source', kind: 'mqtt', sensorId: sensor.id, sensorName: sensor.name, deviceName: 'Fictional device', state: 'enabled', freshness: 'missing', credentialCurrent: null, lastObservedAt: null, latestReceivedAt: null, lastAcceptedAt: null, lastError: null, hasUnknownError: false }], meta,
  summary: { total: 1, homeAssistant: 0, homebridge: 0, http: 0, mqtt: 1, states: { enabled: 1 }, readings: { fresh: 0, stale: 0, missing: 1, unknown: 0 } }, asOf: '2026-11-09T12:00:00Z', generatedAt: '2026-11-09T12:00:00Z',
 } }));
 await openModule(page, 'sources');
 if (process.env.DIAGNOSTICS_REVIEW) {
  await expect(page.getByTestId('source-total')).toHaveText('1');
  for (const width of [1280, 390]) {
   await page.setViewportSize({ width, height: 1000 });
   await page.screenshot({ path: test.info().outputPath(`canabicultarr-diagnostics-${width}.png`), fullPage: true, animations: 'disabled' });
  }
  await page.setViewportSize({ width: 1280, height: 900 });
 }
 await expect(page.getByTestId('nav-item-sources')).toHaveAttribute('aria-current', 'page'); await expect(page.getByTestId('workspace-section-sources')).toHaveCount(0); const opener = page.getByRole('button', { name: 'Consulter les relevés de Fictional humidity', exact: true }); await opener.click();
 const dialog = page.getByRole('dialog', { name: `Historique · ${sensor.name}`, exact: true }); await expect(dialog).toBeVisible();
 await expect(dialog.getByTestId('sensor-analysis')).toBeVisible();
 await expect(page.locator('#workspace-content .source-list')).toBeAttached();
 await expect(dialog.getByRole('button', { name: 'Parcourir les appareils Home Assistant', exact: true })).toHaveCount(0);
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expectControlFocused(opener); expect(failures).toEqual([]);
});

test('appearance follows the system by default and the menu switch persists an explicit choice', async ({ page }) => {
 await page.emulateMedia({ colorScheme: 'dark' });
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const html = page.locator('html'); const toggle = page.getByRole('switch', { name: 'Mode sombre', exact: true });
 await expect(html).toHaveClass(/app-dark/); await expect(toggle).toBeChecked();
 await openModule(page, 'accountPreferences'); const choice = page.getByLabel('Apparence', { exact: true }).and(page.locator('select')); await expect(choice).toHaveValue('system');
 await page.emulateMedia({ colorScheme: 'light' }); await expect(html).not.toHaveClass(/app-dark/); await expect(toggle).not.toBeChecked();
 await (await revealTabbedControl(choice)).selectOption('dark'); await expect(html).toHaveClass(/app-dark/); await expect(toggle).toBeChecked();
 await page.emulateMedia({ colorScheme: 'light' }); await expect(html).toHaveClass(/app-dark/);
 await toggle.focus(); await page.keyboard.press('Space'); await expect(toggle).not.toBeChecked(); await expect(choice).toHaveValue('light');
 expect(await page.evaluate(() => localStorage.getItem('appearance'))).toBe('light');
 await page.reload(); await expect(html).not.toHaveClass(/app-dark/); await openModule(page, 'accountPreferences'); await expect(choice).toHaveValue('light');
 await (await revealTabbedControl(choice)).selectOption('system'); await page.emulateMedia({ colorScheme: 'dark' }); await expect(html).toHaveClass(/app-dark/);
 await page.setViewportSize({ width: 390, height: 900 }); await page.getByRole('button', { name: 'Ouvrir le menu', exact: true }).click();
 await expect(page.getByRole('dialog', { name: 'Navigation principale', exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0);
 expect(failures).toEqual([]);
});

test('themed selectors filter options, support keyboard choices, dismiss cleanly and stay styled on mobile', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 await openModule(page, 'cycleSettings');
 const unit = page.locator('select[id$="-unit"]').first();
 const control = page.locator(`[data-ui-select="${await unit.getAttribute('id')}"]`).getByRole('combobox');
 await expect(unit).toHaveAttribute('data-select-mode', 'custom');
 await control.click();
 const popup = page.locator('[data-select-popup]');
 const filter = popup.getByRole('textbox', { name: 'Filtrer les options…', exact: true });
 await expectControlFocused(filter);
 await (await revealTabbedControl(filter)).fill('zzzz');
 await expect(popup.getByText('Aucune option correspondante', { exact: true })).toHaveText('Aucune option correspondante');
 await (await revealTabbedControl(filter)).fill('SEMA');
 await page.evaluate(() => window.scrollBy(0, -30));
 await expect(popup).toBeVisible();
 await expect(popup.getByRole('option')).toHaveCount(1);
 await filter.press('Enter');
 await expect(unit).toHaveValue('weeks');
 await expectControlFocused(control);
 await expect(popup).toHaveCount(0);
 await control.press('ArrowDown');
 await (await revealTabbedControl(filter)).fill('jours');
 await filter.press('ArrowDown');
 await filter.press('Enter');
 await expect(unit).toHaveValue('days');
 await control.click(); await filter.press('Escape');
 await expect(popup).toHaveCount(0); await expectControlFocused(control);
 await control.click(); await page.getByRole('heading').last().click(); await expect(popup).toHaveCount(0);
 await page.emulateMedia({ colorScheme: 'dark' });
 await control.click();
 await expect(popup).toBeVisible();
 await page.setViewportSize({ width: 390, height: 844 });
 await expect(popup).toBeVisible();
 await popup.getByRole('option', { name: 'semaines', exact: true }).click();
 await expect(unit).toHaveValue('weeks');
 await expect(popup).toHaveCount(0);
 await page.setViewportSize({ width: 1440, height: 1000 });
 await expect(unit).toHaveAttribute('data-select-mode', 'custom');
 await control.click(); await filter.press('Tab'); await expect(popup).toHaveCount(0);
 await expectControlFocused(page.locator('#cycle-watering-days'));
 expect(failures).toEqual([]);
});

test('touch devices use styled selectors even at tablet desktop widths', async ({ browser, baseURL }) => {
 const context = await browser.newContext({ baseURL, viewport: { width: 1024, height: 900 }, hasTouch: true });
 const page = await context.newPage();
 try {
  const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
  await openModule(page, 'cycleSettings');
  const unit = page.locator('select[id$="-unit"]').first();
  await expect(unit).toHaveAttribute('data-select-mode', 'custom');
  const control = page.locator(`[data-ui-select="${await unit.getAttribute('id')}"]`).getByRole('combobox');
  await control.tap();
  const popup = page.locator('[data-select-popup]');
  await expect(popup).toBeVisible();
  await expect(popup.getByRole('textbox')).not.toBeFocused();
  await popup.getByRole('option', { name: 'semaines', exact: true }).tap();
  await expect(unit).toHaveValue('weeks');
  await expect(popup).toHaveCount(0);
  expect(failures).toEqual([]);
 } finally { await context.close(); }
});

test('culture overview clears stale counts on errors and access loss', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'member', []);
 const summary = { generatedAt: '2026-11-09T12:00:00Z', days: 7,
  plants: { total: 12, active: 8, planned: 2, archived: 2, due: 3, lifecycleDue: 2, wateringDue: 2, harvested: 2, harvestWeightGrams: '145.50', byPhase: { growth: 8 } },
  strains: [], locations: [], activity: [], upcoming: [] };
 let status = 200;
 await page.route('**/api/v1/culture/overview?*', route => route.fulfill({ status, json: status === 200 ? { data: summary } : { error: { code: 'auth.errors.requestFailed' } } }));
 await openModule(page, 'overview');
 const view = page.getByTestId('culture-overview');
 await expect(view.getByTestId('culture-active')).toHaveText('8');
 await view.getByRole('button', { name: /Plantes actives 8/ }).click();
 await expect(page.getByTestId('nav-item-plants')).toHaveAttribute('aria-current', 'page');
 await openModule(page, 'overview');
 summary.plants.active = 9;
 await view.getByRole('button', { name: 'Recharger', exact: true }).click();
 await expect(view.getByTestId('culture-active')).toHaveText('9');
 status = 503;
 await view.getByRole('button', { name: 'Recharger', exact: true }).click();
 await expect(page.getByRole('alert')).toBeVisible(); await expect(view.getByTestId('culture-active')).toHaveCount(0);
 status = 200;
 await view.getByRole('button', { name: 'Recharger', exact: true }).click();
 await expect(view.getByTestId('culture-active')).toHaveText('9');
 status = 403;
 await view.getByRole('button', { name: 'Recharger', exact: true }).click();
 await expect(view).toHaveCount(0); expect(failures).toEqual([]);
});

test('lifecycle relocation uses an optional inline destination, persists the switch and retains unavailable references', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let missing = false;
 let config = { stages: structuredClone(lifecycleStages), icons: lifecycleIcons, durations: { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 }, wateringDefaults: { alertDays: 3, moistureThreshold: 35 }, automaticRelocation: false, canManage: true, version: 1 };
 const writes: Record<string, unknown>[] = [];
 const site = { id: 'target-site', name: 'Target site' }; const room = { id: 'target-room', name: 'Target room' }; const zone = { id: 'target-zone', name: 'Target zone' };
 await page.route('**/api/v1/lifecycle-settings', async route => {
  if (route.request().method() === 'PATCH') { const input = route.request().postDataJSON(); writes.push(input); config = { ...config, stages: input.stages, automaticRelocation: input.automatic_relocation, version: config.version + 1 }; }
  await route.fulfill({ json: { data: config } });
 });
 await page.route('**/api/v1/locations/options?*', route => route.fulfill({ json: locationOptionPage(missing ? [] : [{ ...site, kind: 'site', canSelect: true, children: [{ ...room, kind: 'room', canSelect: true, children: [{ ...zone, kind: 'zone', canSelect: true, children: [] }] }] }], new URL(route.request().url())) }));
 await page.route('**/api/v1/locations/zone/target-zone/context', route => route.fulfill(missing ? { status: 404, json: { error: { code: 'auth.errors.requestFailed' } } } : { json: { data: { id: 'zone:target-zone', name: zone.name, context: { type: 'zone', id: zone.id, site, room, zone }, ancestorSelection: { site: true, room: true }, disabled: false } } }));
 await openModule(page, 'cycleSettings'); const toggle = page.getByLabel('Déplacer les plantes automatiquement', { exact: true }); await expect(toggle).not.toBeChecked(); await (await revealTabbedControl(toggle)).check();
 await expect.poll(() => writes.length).toBe(1);
 const stage = page.locator('[data-stage="growth"]');
 await expect(stage.locator('.cycle-destination')).toHaveCount(0);
 await expect(stage.locator('.cycle-meta')).toContainText('Arrosage');
 await expect(stage.locator('.cycle-meta')).toContainText('Entretien');
 await expect(stage.locator('.cycle-meta')).not.toContainText('Nettoyage');
 await expect(page.locator('[data-stage="flowering"] .cycle-meta')).toContainText('Nettoyage');
 await stage.getByRole('button', { name: 'Modifier l’étape', exact: true }).click();
 const dialog = page.getByRole('dialog'); const destination = dialog.getByLabel('Destination de l’étape', { exact: true }).and(dialog.locator('select'));
 await expect(destination.locator('option[value="zone:target-zone"]')).toHaveCount(1); await (await revealTabbedControl(destination)).selectOption('zone:target-zone');
 for (const section of ['Étape', 'Arrosage', /^Actions/]) {
  await dialog.getByRole('tab', { name: section, exact: typeof section === 'string' }).click();
  if (process.env.LIFECYCLE_RELOCATION_REVIEW) for (const width of [1280, 390]) {
   await page.setViewportSize({ width, height: 1000 });
   await dialog.screenshot({ path: test.info().outputPath(`canabicultarr-stage-editor-${section instanceof RegExp ? 'actions' : section === 'Étape' ? 'stage' : 'watering'}-${width}.png`), animations: 'disabled' });
  }
 }
 await dialog.getByRole('tab', { name: 'Étape', exact: true }).click(); await expect(destination).toHaveValue('zone:target-zone');
 await dialog.getByRole('button', { name: 'Valider l’étape', exact: true }).click();
 await expect.poll(() => writes.length).toBe(2); expect(writes[1]!.automatic_relocation).toBe(true);
 expect((writes[1]!.stages as { id: string; target_location?: unknown }[]).find(value => value.id === 'growth')!.target_location).toEqual({ type: 'zone', id: zone.id });
 await expect(stage.locator('.cycle-destination')).toContainText('Target site / Target room / Target zone');
 await stage.getByRole('button', { name: 'Modifier l’étape', exact: true }).click(); await expect(destination).toHaveValue('zone:target-zone'); await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
 missing = true;
 await page.reload(); await openModule(page, 'cycleSettings');
 await expect(stage.locator('.cycle-destination')).toContainText('Destination indisponible');
 await stage.getByRole('button', { name: 'Modifier l’étape', exact: true }).click();
 await expect(dialog).toContainText('Destination indisponible.'); await expect(destination).toHaveValue('zone:target-zone');
 await (await revealTabbedControl(destination)).selectOption(''); await dialog.getByRole('button', { name: 'Valider l’étape', exact: true }).click();
 await expect.poll(() => writes.length).toBe(3);
 await expect(stage.locator('.cycle-destination')).toHaveCount(0);
 await (await revealTabbedControl(toggle)).uncheck(); await expect.poll(() => writes.length).toBe(4);
 expect(writes[3]!.automatic_relocation).toBe(false); expect((writes[3]!.stages as { id: string; target_location?: unknown }[]).find(value => value.id === 'growth')!.target_location).toBeNull();
 expect(failures).toEqual([]);
});

test('lifecycle editor adds stages and care actions, keeps day storage and persists the ordered draft', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let configuration = { stages: structuredClone(lifecycleStages), icons: lifecycleIcons, durations: { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 } as Record<string, number>, wateringDefaults: { alertDays: 3, moistureThreshold: 35 }, canManage: true, version: 1 };
 const writes: typeof configuration[] = [];
 await page.route('**/api/v1/lifecycle-settings', async route => {
  if (route.request().method() === 'PATCH') {
   const input = route.request().postDataJSON();
   configuration = { ...configuration, stages: input.stages, durations: input.durations, version: configuration.version + 1 };
   writes.push(structuredClone(configuration));
  }
  return route.fulfill({ json: { data: configuration } });
 });
 await openModule(page, 'cycleSettings');
 const stageRows = page.locator('.cycle-stage'); await expect(stageRows).toHaveCount(10);
 // Canceling creation discards the newly inserted stage without changing existing targets.
 await page.getByRole('button', { name: 'Ajouter une étape', exact: true }).click();
 const dialog = page.getByRole('dialog'); await expect(dialog).toBeVisible();
 await dialog.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(stageRows).toHaveCount(10);
 await page.getByRole('button', { name: 'Ajouter une étape', exact: true }).click();
 await (await revealTabbedControl(dialog.locator('#stage-name'))).fill('Fictional preparation');
 await (await revealTabbedControl(dialog.locator('#stage-duration-unit'))).selectOption('weeks'); await (await revealTabbedControl(dialog.locator('#stage-duration'))).fill('2');
 await (await revealTabbedControl(dialog.locator('#stage-icon'))).selectOption('box');

 await dialog.getByRole('tab', { name: /^Actions/ }).click();
 await expect(dialog.locator('#action-preset option')).toHaveCount(12);
 await (await revealTabbedControl(dialog.locator('#action-preset'))).selectOption('start_maturation');
 await dialog.getByRole('button', { name: 'Ajouter une action', exact: true }).click();
 await expect(dialog.locator('#action-target-1')).toHaveValue('archived');
 const presetAction = dialog.locator('.cycle-action-editor').nth(1);
 await expect(presetAction.getByRole('switch')).toBeChecked();
 await presetAction.getByRole('button', { name: 'Supprimer l’action', exact: true }).click();
 await (await revealTabbedControl(dialog.locator('#action-preset'))).selectOption('topping');
 await dialog.getByRole('button', { name: 'Ajouter une action', exact: true }).click();
 await expect(dialog.locator('#action-name-1')).toHaveValue('Étêtage');
 await expect(dialog.locator('#action-icon-1')).toHaveValue('scissors');
 await expect(dialog.locator('#action-target-1 option')).toHaveCount(11);
 await expect(dialog.locator('#action-target-1 option:checked')).toHaveText('Rester à cette étape (entretien)');
 await (await revealTabbedControl(dialog.locator('#action-name-1'))).fill('Fictional inspection');
 await (await revealTabbedControl(dialog.locator('#action-icon-1'))).selectOption('camera');
 await (await revealTabbedControl(dialog.locator('#action-preset'))).selectOption('custom');
 await dialog.getByRole('button', { name: 'Ajouter une action', exact: true }).click();
 await (await revealTabbedControl(dialog.locator('#action-name-2'))).fill('Fictional return');
 await (await revealTabbedControl(dialog.locator('#action-target-2'))).selectOption('growth');
 await dialog.getByRole('button', { name: 'Valider l’étape', exact: true }).click(); await expect(dialog).toHaveCount(0);
 await expect(stageRows).toHaveCount(11); await expect(page.locator('.cycle-stages')).toContainText('Fictional inspection');
 await expect.poll(() => writes.length).toBe(1);
 const added = page.locator('.cycle-stage').filter({ has: page.getByRole('heading', { name: 'Fictional preparation', exact: true }) });
 await added.getByRole('button', { name: 'Monter l’étape', exact: true }).click();
 await expect.poll(() => writes.length).toBe(2);
 await expect(page.getByRole('status').filter({ hasText: 'Cycle enregistré.' }).first()).toBeVisible();
 expect(writes).toHaveLength(2); const saved = writes[1]; const custom = saved.stages.find(stage => stage.name === 'Fictional preparation')!;
 expect(saved.durations[custom.id]).toBe(14); expect(custom.icon).toBe('box'); expect(custom.actions[1]).toMatchObject({ name: 'Fictional inspection', target: custom.id });
 expect(saved.stages[7].actions[0].target).toBe('maturation'); expect(custom.actions[0].target).toBe('archived');
 expect(custom.actions[2]).toMatchObject({ name: 'Fictional return', target: 'growth' });
 await page.getByRole('button', { name: 'Recharger les paramètres du cycle', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Fictional preparation', exact: true })).toBeVisible();
 await page.setViewportSize({ width: 1440, height: 1000 });
 await page.locator('.cycle-defaults').scrollIntoViewIfNeeded();
 const controlBoxes = await page.locator('#cycle-flush, #cycle-watering-days, #cycle-moisture, #cycle-flush-unit').evaluateAll(elements => elements.map(el => ({ top: el.getBoundingClientRect().top, height: el.getBoundingClientRect().height })));
 expect(Math.max(...controlBoxes.map(box => box.top)) - Math.min(...controlBoxes.map(box => box.top))).toBeLessThan(1);
 expect(Math.max(...controlBoxes.map(box => box.height)) - Math.min(...controlBoxes.map(box => box.height))).toBeLessThan(1);
 await added.getByRole('button', { name: 'Supprimer l’étape', exact: true }).click(); await expect(stageRows).toHaveCount(10);
 expect(failures).toEqual([]);
});

test('menu bell shows personal unread notifications, marks a snapshot read and returns mobile focus', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'member', []);
 let read = false; let fail = false; const marks: unknown[] = [];
 const through = '2026-11-09T12:00:00.000000Z';
 await page.route('**/api/v1/notifications/inbox?*', route => {
  if (fail) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  const query = new URL(route.request().url()).searchParams;
  const target = Number(query.get('page') ?? 1);
  return route.fulfill({ json: { data: { unreadCount: read ? 0 : 2, readThrough: through, page: target, lastPage: 2,
   items: query.get('summary') === '1' ? [] : [{ id: `fictional-alert-${target}`, name: `Fictional notification ${target}`, sensorName: 'Fictional probe', severity: 'warning', event: target === 1 ? 'opened' : 'recovered', occurredAt: through, unread: !read }],
  } } });
 });
 await page.route('**/api/v1/notifications/inbox/read', async route => { marks.push(route.request().postDataJSON()); read = true; await route.fulfill({ status: 204 }); });
 await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
 const bell = page.getByTestId('notification-bell');
 await expect(bell).toHaveAccessibleName('Ouvrir les notifications (2 non lues)');
 await bell.click(); const inbox = page.getByRole('dialog', { name: 'Notifications', exact: true });
 await expect(inbox.getByText('Fictional notification 1', { exact: true })).toBeVisible();
 await inbox.getByRole('button', { name: 'Suivant', exact: true }).click();
 await expect(inbox.getByText('Fictional notification 2', { exact: true })).toBeVisible();
 await inbox.getByRole('button', { name: 'Tout marquer comme lu', exact: true }).click();
 await expect.poll(() => marks).toEqual([{ read_through: through }]);
 await expect(bell).toHaveAccessibleName('Ouvrir les notifications');
 await expect(inbox.getByRole('button', { name: 'Tout marquer comme lu', exact: true })).toBeDisabled();
 await page.keyboard.press('Escape'); await expect(inbox).toHaveCount(0); await expectControlFocused(bell);
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('button', { name: 'Ouvrir le menu', exact: true }).click(); await bell.click();
 await expect(page.getByRole('dialog', { name: 'Navigation principale', exact: true })).toHaveCount(0);
 await expect(inbox).toBeVisible();
 await expect(inbox.getByText('Fictional notification 1', { exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expectControlFocused(page.getByRole('button', { name: 'Ouvrir le menu', exact: true }));
 await page.setViewportSize({ width: 1280, height: 900 }); fail = true; await bell.click();
 await expect(page.getByRole('alert').first()).toBeVisible(); fail = false;
 await inbox.getByRole('button', { name: 'Actualiser les notifications', exact: true }).click();
 await expect(inbox.getByText('Fictional notification 1', { exact: true })).toBeVisible();
 await inbox.getByRole('button', { name: 'Réglages des notifications', exact: true }).click();
 await expect(inbox).toHaveCount(0); await openNotificationSection(page, 'preferences');
 await expect(page.locator('#notification-locale')).toBeAttached();
 await expect(page.locator('label[for="notification-locale"]')).toBeVisible();
 expect(failures).toEqual([]);
});

test('inline channel and hardware selects preserve drafts, page through choices and keep channel identity locked', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view', 'assets.manage', 'sensors.manage']);
 const site = { id: 'fictional-site', name: 'Fictional Site' }; const zone = { id: 'fictional-zone', name: 'Fictional Zone' };
 const location = { type: 'zone', id: zone.id, site, room: null, zone };
 const makeAsset = (id: string, name: string, status = 'active') => ({ id, name, category: 'sensor', status, location: null, assetTag: null, manufacturer: null, model: null, serialNumber: null, description: null });
 const first = makeAsset('fictional-first', 'Fictional first'); const second = makeAsset('fictional-second', 'Fictional second'); const retired = makeAsset('fictional-retired', 'Fictional retired', 'retired');
 const type = { id: 'fictional-type', name: 'Fictional temperature', code: 'temperature', unit: '°C', decimalPlaces: 2, description: null, sensorsCount: 0 };
 const channel = { id: 'fictional-channel', name: 'Fictional channel', channelKey: 'temp', isEnabled: true, expectedIntervalSeconds: null, description: null, device: second, measurementType: type };
 const writes: Record<string, unknown>[] = []; let created = false; let failChoices = false;
 const list = (data: unknown[], current = 1, last = 1) => ({ data, meta: { current_page: current, last_page: last, total: data.length, per_page: 20 } });
 await page.route('**/api/v1/locations/options?*', route => route.fulfill({ json: locationOptionPage([{ ...site, kind: 'site', canSelect: false, children: [{ ...zone, kind: 'zone', canSelect: true, children: [] }] }], new URL(route.request().url())) }));
 await page.route('**/api/v1/assets**', route => {
  if (route.request().method() !== 'GET') { writes.push(route.request().postDataJSON()); return route.fulfill({ json: { data: first } }); }
  if (failChoices) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
  const current = Number(new URL(route.request().url()).searchParams.get('page') ?? 1);
  return route.fulfill({ json: list(current === 1 ? [first, retired] : [second], current, 2) });
 });
 await page.route('**/api/v1/measurement-types?*', route => {
  const current = Number(new URL(route.request().url()).searchParams.get('page') ?? 1);
  return route.fulfill({ json: list(current === 2 ? [type] : [], current, 2) });
 });
 await page.route('**/api/v1/sensors**', route => {
  if (route.request().method() !== 'GET') { writes.push(route.request().postDataJSON()); created = true; return route.fulfill({ json: { data: channel } }); }
  return route.fulfill({ json: list(created ? [channel] : []) });
 });
 await openModule(page, 'assets'); await page.getByRole('button', { name: 'Ajouter du matériel', exact: true }).click();
 await (await revealTabbedControl(page.locator('#asset-name'))).fill('Fictional hardware draft');
 await expect(page.locator('#asset-location')).toBeEnabled();
 await expect(page.locator('#asset-location option[value="site:fictional-site"]')).toBeDisabled();
 await openSelect(page.locator('#asset-location')); await (await revealTabbedControl(page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }))).fill('Zone');
 await page.getByRole('listbox').getByRole('option', { name: 'Fictional Site / Fictional Zone', exact: true }).click();
 await expect(page.locator('#asset-name')).toHaveValue('Fictional hardware draft');
 await expect(page.locator('.module-dialog')).toHaveCount(0);
 await page.getByRole('button', { name: /^(?:Enregistrer la fiche|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[0]?.locations).toEqual([{ type: location.type, id: location.id }]);
 await page.getByRole('button', { name: 'Modifier Fictional first', exact: true }).click();
 await expect(page.locator('#asset-location')).toBeEnabled(); await (await revealTabbedControl(page.locator('#asset-location'))).selectOption('zone:fictional-zone'); await page.locator('.assignment-chips').getByRole('button').click();
 await page.getByRole('button', { name: /^(?:Enregistrer la fiche|Créer)$/, exact: true }).click(); await expect.poll(() => writes[1]?.locations).toEqual([]);
 await openModule(page, 'sensors'); failChoices = true; await page.getByRole('button', { name: 'Ajouter un canal', exact: true }).click();
 const editor = page.locator('.form-dialog'); await (await revealTabbedControl(editor.locator('#sensor-name'))).fill('Fictional channel'); await (await revealTabbedControl(editor.locator('#sensor-channel'))).fill('temp');
 await expect(page.getByRole('alert').first()).toBeVisible(); failChoices = false; await editor.getByRole('button', { name: 'Recharger la liste', exact: true }).click();
 await expect(editor.locator('#sensor-device')).toBeEnabled();
 await expect(editor.locator('#sensor-device option[value="fictional-retired"]')).toBeDisabled();
 await (await revealTabbedControl(editor.locator('#sensor-device'))).selectOption(second.id); await (await revealTabbedControl(editor.locator('#sensor-type'))).selectOption(type.id);
 await expect(editor.locator('#sensor-name')).toHaveValue('Fictional channel');
 await editor.getByRole('button', { name: /^(?:Enregistrer le canal|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[2]).toMatchObject({ device_id: second.id, measurement_type_id: type.id, channel_key: 'temp' });
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('button', { name: 'Modifier Fictional channel', exact: true }).click();
 await expect(editor.locator('#sensor-device')).toBeDisabled(); await expect(editor.locator('#sensor-device')).toHaveValue(second.id);
 await expect(editor.locator('#sensor-type')).toBeDisabled(); await expect(editor.locator('#sensor-type')).toHaveValue(type.id);
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await page.getByRole('button', { name: 'Ajouter un canal', exact: true }).click();
 await expect(editor.locator('#sensor-device')).toHaveAttribute('data-select-mode', 'custom'); await (await revealTabbedControl(editor.locator('#sensor-device'))).selectOption(second.id); await (await revealTabbedControl(editor.locator('#sensor-type'))).selectOption(type.id);
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 expect(failures).toEqual([]);
});

test('Homebridge imports selected accessory readings, collects, pauses and opens shared history', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const ids = ['a'.repeat(64) + ':10', 'b'.repeat(64) + ':11'];
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const sensor = { id: 'fictional-hb-sensor', name: 'Fictional Homebridge temperature', device: { id: 'fictional-hb-device', name: 'Fictional Homebridge probe', status: 'active', location: null }, measurementType: { id: 'fictional-hb-type', code: 'hb_temperature', name: 'Temperature', unit: '°C', decimalPlaces: 3 }, isEnabled: true, channelKey: 'hb_temperature', expectedIntervalSeconds: 60, description: null };
 const link = { id: 'fictional-hb-link', entityId: ids[0], sensor, isEnabled: true, version: 1, lastAttemptAt: null as string | null, lastSuccessAt: null as string | null, lastError: null };
 let imported = false; let deletedVersion: number | null = null; let denyCheck = false; const imports: string[][] = [];
 await page.route('**/api/v1/integrations/homebridge**', async route => {
  const url = new URL(route.request().url()); const path = url.pathname;
  if (path.endsWith('/check')) return denyCheck ? route.fulfill({ status: 503, json: { error: { code: 'measurements.errors.accessDenied' } } }) : route.fulfill({ json: { data: { connected: true } } });
  if (path.endsWith('/entities')) { const current = Number(url.searchParams.get('page') ?? 1); return route.fulfill({ json: { data: [{ id: ids[current - 1], name: current === 1 ? sensor.name : 'Fictional Homebridge humidity', category: 'sensor', entityCount: 1, importedCount: imported ? 1 : 0, entities: [{ id: 'channel-'+current, name: current === 1 ? 'Temperature' : 'Humidity', unit: current === 1 ? '°C' : '%' }], available: true, imported }], meta: { ...meta, current_page: current, last_page: 2, total: 21 } } }); }
  if (path.endsWith('/import')) { imports.push(route.request().postDataJSON().device_ids); imported = true; return route.fulfill({ status: 201, json: { data: { devicesCreated: 1, sensorsCreated: 2, typesCreated: 2, alreadyImported: 0 } } }); }
  if (path.endsWith('/collect')) { link.lastAttemptAt = link.lastSuccessAt = '2026-11-09T12:00:00Z'; return route.fulfill({ json: { data: { outcome: 'queued', code: null } } }); }
  if (route.request().method() === 'PATCH') { const body = route.request().postDataJSON(); expect(body.version).toBe(link.version); link.isEnabled = body.is_enabled; link.version++; return route.fulfill({ json: { data: link } }); }
  if (route.request().method() === 'DELETE') {
   expect(path).toBe('/api/v1/integrations/homebridge/links/' + link.id);
   deletedVersion = route.request().postDataJSON().version;
   return route.fulfill({ status: 204 });
  }
  if (path.endsWith('/links')) return route.fulfill({ json: { data: imported && deletedVersion === null ? [link] : [], meta } });
  return route.fulfill({ json: { data: { configured: true } } });
 });
 await page.route('**/api/v1/sensors/fictional-hb-sensor', route => route.fulfill({ json: { data: sensor } }));
 await page.route('**/api/v1/reports/sensors/fictional-hb-sensor?*', route => { const report = insightReport(new URL(route.request().url())); report.statistics.mean = '23.75'; return route.fulfill({ json: { data: { ...report, sensor: { ...report.sensor, id: sensor.id, name: sensor.name, measurementType: { ...report.sensor.measurementType, ...sensor.measurementType } } } } }); });
 await page.route('**/api/v1/sensors/fictional-hb-sensor/measurements?*', route => route.fulfill({ json: { data: [{ id: 'fictional-hb-reading', value: '23.750000000', unit: '°C', observedAt: '2026-11-09T12:00:00Z', receivedAt: '2026-11-09T12:00:00Z', location: null }], meta, summary: { sensor, latest: null, status: 'fresh' } } }));
 await openModule(page, 'homebridge');
 await page.getByRole('button', { name: 'Vérifier la connexion', exact: true }).click();
 await expect(page.getByText('Homebridge est accessible.', { exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'Parcourir les appareils Homebridge', exact: true }).click();
 const selector = page.getByRole('dialog', { name: 'Sélecteur d’appareils Homebridge', exact: true });
 await (await revealTabbedControl(selector.getByRole('checkbox', { name: 'Sélectionner Fictional Homebridge temperature', exact: true }))).check();
 await selector.getByRole('button', { name: 'Suivant', exact: true }).click();
 await (await revealTabbedControl(selector.getByRole('checkbox', { name: 'Sélectionner Fictional Homebridge humidity', exact: true }))).check();
 await selector.getByRole('button', { name: 'Importer les appareils sélectionnés', exact: true }).click();
 await expect.poll(() => imports).toEqual([ids]);
 await expect(selector.getByRole('checkbox')).toBeDisabled();
 await page.keyboard.press('Escape'); await expect(selector).toHaveCount(0);
 await page.locator('.link-list').getByRole('button', { name: 'Lire Fictional Homebridge temperature maintenant', exact: true }).click();
 await expect(page.getByText('Collecte planifiée. Rechargez la liste après traitement.', { exact: true })).toBeVisible();

 await page.getByRole('button', { name: 'Suspendre la collecte', exact: true }).click();
 await expect(page.locator('.link-list').getByRole('button', { name: 'Lire Fictional Homebridge temperature maintenant', exact: true })).toBeDisabled();
 await page.getByRole('button', { name: 'Reprendre la collecte', exact: true }).click();
 await page.locator('.link-list').getByRole('button', { name: /Voir les mesures de Fictional Homebridge temperature/ }).click();
 const history = page.getByRole('dialog', { name: `Historique · ${sensor.name}`, exact: true }); await expect(history.getByTestId('report-mean')).toContainText('23,75 °C');
 await page.keyboard.press('Escape');
 denyCheck = true; await page.getByRole('button', { name: 'Vérifier la connexion', exact: true }).click();
 await expect(page.getByRole('alert').filter({ hasText: 'Homebridge' }).first()).toContainText('Homebridge');
 await page.setViewportSize({ width: 390, height: 844 });
 const selectionName = 'Sélectionner ' + sensor.name;
 const cards = page.locator('[data-view="cards"].link-list');
 const cardSelection = cards.getByRole('checkbox', { name: selectionName, exact: true });
 await cardSelection.check();
 await expect(page.getByRole('dialog')).toHaveCount(0);
 await page.getByRole('button', { name: 'Tableau', exact: true }).click();
 const table = page.getByRole('table', { name: 'Liste', exact: true });
 await expect(table.getByRole('checkbox', { name: selectionName, exact: true })).toBeChecked();
 const header = page.locator('.page-actions');
 const clearSelection = header.getByRole('button', { name: 'Tout désélectionner' });
 await expect(clearSelection).toHaveAttribute('aria-pressed', 'true');
 await clearSelection.click();
 await expect(header.getByRole('button', { name: 'Sélectionner toutes les lignes visibles' })).toHaveAttribute('aria-pressed', 'false');
 await page.getByRole('button', { name: 'Cartes', exact: true }).click();
 await expect(cardSelection).not.toBeChecked();
 await page.locator('.page-actions').getByRole('button', { name: 'Sélectionner toutes les cartes visibles' }).click();
 await expect(cardSelection).toBeChecked();
 if (process.env.CARD_BATCH_REVIEW) {
  await expect(cards.locator('.record-card-selection svg')).toBeVisible();
  await cards.locator('li').first().screenshot({ path: test.info().outputPath('homebridge-card-selected-mobile.png') });
 }
 await page.getByRole('button', { name: 'Supprimer', exact: true }).click();
 const confirmation = page.getByRole('dialog', { name: 'Supprimer · 1 sélectionnés' });
 expect(deletedVersion).toBeNull();
 await confirmation.getByRole('button', { name: 'Supprimer', exact: true }).click();
 await expect.poll(() => deletedVersion).toBe(link.version);
 await expect(cardSelection).toHaveCount(0);
 expect(failures).toEqual([]);
});

test('measurement nature creation and editing preserve substrate semantics and styled mobile selection', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const writes: Record<string, unknown>[] = [];
 let record: Record<string, unknown> | null = null;
 await page.route('**/api/v1/measurement-types**', route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: { data: record ? [record] : [], meta: { current_page: 1, last_page: 1, total: record ? 1 : 0, per_page: 20 } } });
  const input = route.request().postDataJSON(); writes.push(input);
  record = { id: 'fictional-nature-type', name: input.name, code: input.code, nature: input.nature, unit: input.unit, decimalPlaces: input.decimal_places, description: input.description, sensorsCount: 1 };
  return route.fulfill({ status: route.request().method() === 'POST' ? 201 : 200, json: { data: record } });
 });
 await openModule(page, 'catalogs'); await page.getByRole('tab', { name: 'Types de mesures', exact: true }).click();
 await page.getByRole('button', { name: 'Ajouter un type de mesure', exact: true }).click();
 const editor = page.locator('.form-dialog');
 await (await revealTabbedControl(editor.locator('#measurement-name'))).fill('Fictional substrate type'); await (await revealTabbedControl(editor.locator('#measurement-code'))).fill('fictional_substrate');
 await openSelect(editor.locator('#measurement-nature'));
 await (await revealTabbedControl(page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }))).fill('substrat');
 await page.getByRole('listbox').getByRole('option', { name: 'Humidité du substrat', exact: true }).click();
 await expect(editor.getByText('Utilisez % pour l’humidité du substrat.', { exact: false })).toBeVisible();
 await editor.getByRole('button', { name: /^(?:Enregistrer le type de mesure|Créer)$/, exact: true }).click();
 await expect(editor.page().getByRole('alert').filter({ hasText: 'nécessite l’unité %' }).first()).toContainText('nécessite l’unité %'); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#measurement-unit'))).fill('%'); await editor.getByRole('button', { name: /^(?:Enregistrer le type de mesure|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[0]?.nature).toBe('substrate_moisture'); await expect(editor).toHaveCount(0);
 await expect(page.locator('.resource-grid').getByText('Humidité du substrat', { exact: true })).toBeVisible();
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('button', { name: 'Modifier Fictional substrate type', exact: true }).click();
 await expect(editor.locator('#measurement-unit')).toBeDisabled();
 await expect(editor.locator('#measurement-nature')).toBeEnabled(); await expect(editor.locator('#measurement-nature')).toHaveAttribute('data-select-mode', 'custom');
 await (await revealTabbedControl(editor.locator('#measurement-nature'))).selectOption('air_humidity');
 await editor.getByRole('button', { name: /^(?:Enregistrer le type de mesure|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[1]?.nature).toBe('air_humidity');
 await expect(page.locator('.resource-grid').getByText('Humidité de l’air', { exact: true })).toBeVisible();
 expect(failures).toEqual([]);
});

test('unified hardware views create a contextual channel and refresh device counts', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const asset = { id: 'fictional-probe', name: 'Fictional probe', category: 'sensor', status: 'active', locations: [], plants: [], plantsCount: 0, assetTag: 'PROBE-1', manufacturer: 'Fictional', model: 'Air probe', serialNumber: null, description: null, channelsCount: 0, enabledChannelsCount: 0 };
 const type = { id: 'fictional-air-temperature', code: 'temperature', name: 'Air temperature', nature: 'temperature', unit: '°C', decimalPlaces: 1 };
 let channel: Record<string, unknown> | null = null; const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/assets?*', route => route.fulfill({ json: { data: [asset], meta } }));
 await page.route(`**/api/v1/assets/${asset.id}`, route => route.fulfill({ json: { data: asset } }));
 await page.route('**/api/v1/measurement-types?*', route => route.fulfill({ json: { data: [type], meta } }));
 await page.route('**/api/v1/sensors**', route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: { data: channel ? [channel] : [], meta: { ...meta, total: channel ? 1 : 0 } } });
  const input = route.request().postDataJSON(); writes.push(input);
  channel = { id: 'fictional-channel', name: input.name, channelKey: input.channel_key, isEnabled: input.is_enabled, expectedIntervalSeconds: null, description: null, device: asset, measurementType: type };
  asset.channelsCount = 1; asset.enabledChannelsCount = 1;
  return route.fulfill({ status: 201, json: { data: channel } });
 });
 await openModule(page, 'assets');
 await expect(page.getByTestId('nav-item-assets')).toHaveCount(1);
 await (await revealTabbedControl(page.locator('#assets-search'))).fill('Fictional');
 await page.getByRole('button', { name: 'Ouvrir Fictional probe', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional probe', exact: true });
 await expect(detail).toContainText('Air probe');
 await detail.getByRole('button', { name: 'Ajouter un canal', exact: true }).click();
 const form = page.locator('.form-dialog'); await expect(form.locator('#sensor-device')).toBeDisabled(); await expect(form.locator('#sensor-device')).toHaveValue(asset.id);
 await expect(form.locator('#sensor-type')).toBeEnabled(); await (await revealTabbedControl(form.locator('#sensor-type'))).selectOption(type.id);
 await (await revealTabbedControl(form.locator('#sensor-name'))).fill('Fictional air reading'); await (await revealTabbedControl(form.locator('#sensor-channel'))).fill('temperature');
 await form.getByRole('button', { name: /^(?:Enregistrer le canal|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[0]?.device_id).toBe(asset.id); await expect(form).toHaveCount(0);
 await expect(detail.locator('.sensor-grid')).toContainText('Fictional air reading');
 await detail.getByRole('button', { name: 'Fermer la fiche matériel', exact: true }).click();
 await expect(page.locator('#assets-search')).toHaveValue('Fictional');
 await expect(page.locator('.device-channels-summary')).toContainText('1 canal');
 await page.getByRole('tab', { name: 'Canaux de mesure', exact: true }).click(); await expect(page).toHaveURL(/\/hardware\/channels(?:\?|$)/);
 await expect(page.locator('.sensor-grid')).toContainText('Fictional air reading');
 await page.getByRole('button', { name: 'Ajouter un canal', exact: true }).click(); await expect(page.locator('.form-dialog #sensor-device')).toBeEnabled(); await page.locator('.form-dialog').getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.setViewportSize({ width: 390, height: 844 }); await page.getByRole('tab', { name: 'Matériels', exact: true }).click();
 await expect(page.locator('#assets-search')).toHaveValue('Fictional'); await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
 expect(failures).toEqual([]);
});

test('inventory precision defaults seeds to whole numbers and changes display without changing stock', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view', 'inventory.manage']);
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const stock = { id: 'fictional-decimal-stock', name: 'Fictional nutrient', sku: null, description: null, totalQuantity: '12.125', unit: 'kg', unitLocked: true, decimalPlaces: 3, isArchived: false, canEditCatalog: true, canManage: true, version: 1, isLowStock: false, threshold: '2.125' };
 const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/inventory/items**', route => {
  const path = new URL(route.request().url()).pathname;
  if (route.request().method() === 'PATCH') {
   const input = route.request().postDataJSON(); writes.push(input); stock.decimalPlaces = input.decimal_places; stock.version++;
   return route.fulfill({ json: { data: stock } });
  }
  if (path.endsWith('/fictional-decimal-stock')) return route.fulfill({ json: { data: stock } });
  return route.fulfill({ json: { data: path.endsWith('/items') ? [stock] : [], meta, canEditCatalog: true } });
 });
 await openModule(page, 'inventory');
 await expect(page.locator('.resource-metric strong')).toHaveText('12,125');
 await page.getByRole('button', { name: 'Ajouter un article', exact: true }).click();
 const form = page.locator('.form-dialog'); await expect(form.locator('#inventory-unit')).toHaveValue('piece'); await expect(form.locator('#inventory-decimals')).toHaveValue('0');
 await (await revealTabbedControl(form.locator('#inventory-unit'))).selectOption('kg'); await expect(form.locator('#inventory-decimals')).toHaveValue('3');
 await form.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await page.getByRole('button', { name: 'Modifier Fictional nutrient', exact: true }).click();
 await expect(form.locator('#inventory-decimals')).toHaveValue('3'); await (await revealTabbedControl(form.locator('#inventory-decimals'))).selectOption('1');
 await form.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click();
 await expect.poll(() => writes[0]?.decimal_places).toBe(1); await expect(form).toHaveCount(0);
 await expect(page.locator('.resource-metric strong')).toHaveText('12,1');
 expect(stock.totalQuantity).toBe('12.125');
 await page.getByRole('button', { name: 'Modifier Fictional nutrient', exact: true }).click(); await expect(form.locator('#inventory-decimals')).toHaveValue('1');
 await expect(form.locator('#inventory-threshold')).toHaveValue('2.125');
 expect(failures).toEqual([]);
});

test('long hardware names have their own readable row and preserve detail actions on desktop and mobile', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const name = 'Prise connectée du déshumidificateur de la zone de floraison';
 const asset = { id: 'long-name-device', name, category: 'socket', status: 'maintenance', location: null, assetTag: 'PROBE-1', manufacturer: 'Fictional', model: 'Humidity control', serialNumber: null, description: null, channelsCount: 0, enabledChannelsCount: 0 };
 await page.route('**/api/v1/assets?*', route => route.fulfill({ json: { data: [asset], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await page.route('**/api/v1/assets/long-name-device', route => route.fulfill({ json: { data: asset } }));
 await openModule(page, 'assets');
 const card = page.locator('.asset-node'); await expect(card.locator('.device-name')).toHaveText(name);
 for (const width of [1280, 390]) {
  await page.setViewportSize({ width, height: 900 });
  await expect.poll(() => card.evaluate(element => {
   const title = element.querySelector('h3')!; const badge = element.querySelector('.resource-badge')!;
   const outer = element.getBoundingClientRect(); const heading = title.getBoundingClientRect();
   return heading.width >= outer.width * .8 && heading.top >= badge.getBoundingClientRect().bottom && title.scrollWidth <= title.clientWidth + 1 && outer.right <= innerWidth;
  })).toBe(true);
  if (process.env.RECORD_CARD_REVIEW) await card.screenshot({ path: test.info().outputPath(`canabicultarr-resource-card-${width}.png`), animations: 'disabled' });
 }
 await card.locator('.device-name').click(); const detail = page.getByRole('dialog', { name, exact: true }); await expect(detail).toContainText('Humidity control');
 await detail.getByRole('button', { name: 'Fermer la fiche matériel', exact: true }).click(); await expect(card.locator('.device-name')).toBeVisible(); expect(failures).toEqual([]);
});

test('maintenance settings create rename and remove reusable types through dialogs', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let rows = [{ id: 'custom-one', name: 'Leaf inspection', version: 1 }]; const writes: string[] = [];
 await page.route('**/api/v1/maintenance-types**', async route => {
  const method = route.request().method(); const data = method === 'GET' ? null : route.request().postDataJSON();
  if (method === 'POST') { writes.push(method); rows.push({ id: 'custom-two', name: data.name, version: 1 }); return route.fulfill({ status: 201, json: { data: rows[1] } }); }
  if (method === 'PATCH') { writes.push(method); expect(data.version).toBe(1); rows[0] = { ...rows[0]!, name: data.name, version: 2 }; return route.fulfill({ json: { data: rows[0] } }); }
  if (method === 'DELETE') { writes.push(method); expect(data.version).toBe(2); rows = rows.slice(1); return route.fulfill({ status: 204 }); }
  return route.fulfill({ json: { data: rows } });
 });
 await openModule(page, 'catalogs'); await page.getByRole('tab', { name: 'Types d’entretien', exact: true }).click();
 await page.getByRole('button', { name: 'Ajouter un type', exact: true }).click();
 const dialog = page.locator('.form-dialog'); await (await revealTabbedControl(dialog.getByLabel('Type d’entretien personnalisé', { exact: true }))).fill('Weekly inspection');
 await dialog.getByRole('button', { name: /^(?:Enregistrer le type|Enregistrer|Créer)$/, exact: true }).click(); await expect(dialog).toHaveCount(0);
 await expect(page.locator('.resource-card')).toHaveCount(2);
 await page.getByRole('button', { name: 'Renommer Leaf inspection', exact: true }).click();
 await (await revealTabbedControl(dialog.getByLabel('Type d’entretien personnalisé', { exact: true }))).fill('Monthly inspection');
 await dialog.getByRole('button', { name: /^(?:Enregistrer le type|Enregistrer|Créer)$/, exact: true }).click(); await expect(dialog).toHaveCount(0);
 await page.setViewportSize({ width: 390, height: 844 });
 await page.getByRole('button', { name: 'Supprimer Monthly inspection', exact: true }).click();
 await expect(dialog).toContainText('Les entretiens déjà enregistrés seront conservés.');
 await dialog.getByRole('button', { name: 'Annuler', exact: true }).click(); expect(writes).toEqual(['POST', 'PATCH']);
 await page.getByRole('button', { name: 'Supprimer Monthly inspection', exact: true }).click();
 await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click(); await expect(dialog).toHaveCount(0);
 await expect(page.locator('.resource-card')).toHaveCount(1); await expect(page.locator('.resource-card')).toContainText('Weekly inspection');
 await (await revealTabbedControl(page.getByLabel('Rechercher un type', { exact: true }))).fill('not present'); await expect(page.locator('.resource-card')).toHaveCount(0);
 expect(writes).toEqual(['POST', 'PATCH', 'DELETE']); expect(failures).toEqual([]);
});


test('workspace URLs preserve browser history and reject unauthorized destinations at startup', async ({ page }) => {
 const { failures, requests } = await syntheticSession(page, 'member', []);
 await openModule(page, 'assets'); await expect(page).toHaveURL(/\/hardware$/);
 await openModule(page, 'inventory'); await expect(page).toHaveURL(/\/inventory(?:\?stock=draft-stock)?$/);
 await page.goBack(); await expect(page.getByRole('heading', { name: 'Matériels', exact: true })).toBeVisible();
 await page.goForward(); await expect(page.getByRole('heading', { name: 'Inventaire', exact: true })).toBeVisible();
 await page.reload(); await expect(page.getByRole('heading', { name: 'Inventaire', exact: true })).toBeVisible();
 await page.goto('/users'); await expect(page.getByTestId('plant-home')).toBeVisible(); await expect(page).toHaveURL(/\/$/);
 expect(requests.some(path => path === '/api/v1/users')).toBe(false);
 await page.goto('/not-a-workspace'); await expect(page.getByTestId('plant-home')).toBeVisible(); await expect(page).toHaveURL(/\/$/);
 expect(failures).toEqual([]);
});

test('lifecycle drafts guard stage cancellation, reload and navigation, then save applied stages automatically', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let configuration = { stages: structuredClone(lifecycleStages), icons: lifecycleIcons, durations: { flowering: 56, flush: 14 }, wateringDefaults: { alertDays: 3, moistureThreshold: 35 }, canManage: true, version: 1 };
 let writes = 0;
 await page.route('**/api/v1/lifecycle-settings', async route => {
  if (route.request().method() === 'PATCH') { writes++; const input = route.request().postDataJSON(); configuration = { ...configuration, ...input, version: configuration.version + 1 }; }
  return route.fulfill({ json: { data: configuration } });
 });
 await openModule(page, 'cycleSettings');
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 const stage = page.getByRole('dialog', { name: 'Modifier l’étape', exact: true });
 const flowering = page.locator('[data-stage="flowering"]');
 await flowering.getByRole('button', { name: 'Modifier l’étape', exact: true }).click(); await page.keyboard.press('Escape');
 await expect(stage).toHaveCount(0); await expect(warning).toHaveCount(0);
 await flowering.getByRole('button', { name: 'Modifier l’étape', exact: true }).click();
 await (await revealTabbedControl(stage.locator('#stage-name'))).fill('Changed stage'); await page.keyboard.press('Escape');
 await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(stage.locator('#stage-name')).toHaveValue('Changed stage');
 await stage.getByRole('button', { name: 'Valider l’étape', exact: true }).click(); await expect(stage).toHaveCount(0);
 await expect(page.getByRole('button', { name: 'Enregistrer le cycle', exact: true })).toHaveCount(0);
 await expect(page.getByRole('status').filter({ hasText: 'Cycle enregistré.' }).first()).toContainText('Cycle enregistré.');
 expect(writes).toBe(1); await page.getByTestId('nav-home').click(); await expect(page).toHaveURL(/\/(?:\?.*)?$/); await expect(warning).toHaveCount(0);
 await openModule(page, 'cycleSettings'); await (await revealTabbedControl(page.locator('#cycle-watering-days'))).fill('0');
 await page.getByRole('button', { name: 'Recharger les paramètres du cycle', exact: true }).click();
 await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.locator('#cycle-watering-days')).toHaveValue('3');
 await page.getByRole('button', { name: 'Ajouter une étape', exact: true }).click(); await (await revealTabbedControl(stage.locator('#stage-name'))).fill('Discarded new stage');
 await stage.getByRole('button', { name: 'Annuler', exact: true }).click(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(page.locator('.cycle-stage')).toHaveCount(10); await expect(page.getByRole('button', { name: 'Enregistrer le cycle', exact: true })).toHaveCount(0);
 expect(writes).toBe(1); expect(failures).toEqual([]);
});

test('cycle field feedback validates defaults, reopens rejected stages and retains graph drafts for correction', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let configuration = { stages: structuredClone(lifecycleStages), icons: lifecycleIcons, durations: { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 }, wateringDefaults: { alertDays: 3, moistureThreshold: 35 }, canManage: true, version: 1 };
 let rejection: 'nested' | 'graph' | null = 'nested'; const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/lifecycle-settings', async route => {
  if (route.request().method() === 'PATCH') {
   const input = route.request().postDataJSON(); writes.push(input);
   if (rejection) return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: rejection === 'nested' ? { 'stages.4.actions.0.target': ['Private graph text'] } : { stages: ['catalog.validation.stageInUse'] } } });
   configuration = { ...configuration, ...input, version: configuration.version + 1 };
  }
  return route.fulfill({ json: { data: configuration } });
 });
 await openModule(page, 'cycleSettings');
 await (await revealTabbedControl(page.locator('#cycle-watering-days'))).fill('0'); await (await revealTabbedControl(page.locator('#cycle-moisture'))).fill('101');
 await expectControlFocused(page.locator('#cycle-watering-days')); await expect(await revealTabbedControl(page.locator('#cycle-moisture-error'))).toBeVisible(); expect(writes).toEqual([]);
 await (await revealTabbedControl(page.locator('#cycle-watering-days'))).fill('5'); await expect(page.locator('#cycle-watering-days-error')).toHaveCount(0); await expectControlFocused(page.locator('#cycle-moisture'));
 await (await revealTabbedControl(page.locator('#cycle-moisture'))).fill('35');
 const editor = page.getByRole('dialog', { name: 'Modifier l’étape', exact: true });
 await expect(editor).toBeVisible(); await expectControlFocused(editor.locator('#action-target-0')); await expect(await revealTabbedControl(editor.locator('#action-target-0-error'))).toBeVisible(); await expect(editor).not.toContainText('Private'); await expect(page.locator('#cycle-watering-days')).toHaveValue('5');
 await (await revealTabbedControl(editor.locator('#action-target-0'))).selectOption('maturation'); await expect(editor.locator('#action-target-0-error')).toHaveCount(0);
 await editor.getByRole('tab', { name: 'Étape', exact: true }).click();
 await (await revealTabbedControl(editor.locator('#stage-duration'))).fill('0'); await editor.getByRole('button', { name: 'Valider l’étape', exact: true }).click(); await expectControlFocused(editor.locator('#stage-duration'));
 rejection = 'graph';
 await (await revealTabbedControl(editor.locator('#stage-duration'))).fill('9'); await editor.getByRole('button', { name: 'Valider l’étape', exact: true }).click(); await expect(editor).toHaveCount(0);
 await expectControlFocused(page.locator('#cycle-stages-validation')); await expect(await revealTabbedControl(page.locator('#cycle-stages-validation-error'))).toBeVisible();
 rejection = null; await page.getByRole('button', { name: 'Réessayer l’enregistrement', exact: true }).click(); await expect(page.getByRole('status').filter({ hasText: 'Cycle enregistré.' }).first()).toContainText('Cycle enregistré.'); expect(writes).toHaveLength(3);
 expect(writes[2].durations).toMatchObject({ flowering: 63 }); expect(writes[2].watering_alert_days).toBe(5); expect(failures).toEqual([]);
});

async function inventorySession(page: Page) {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const item = { id: 'draft-stock', name: 'Fictional stock', sku: 'STOCK', description: 'Original notes', type: 'other', unit: 'piece', decimalPlaces: 0, unitLocked: true, totalQuantity: '12', threshold: '2', isLowStock: false, isArchived: false, canManage: true, canEditCatalog: true, version: 1 };
 const writes: { method: string; input: Record<string, unknown> }[] = [];
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 await page.route('**/api/v1/inventory/items**', route => {
  const path = new URL(route.request().url()).pathname; const method = route.request().method();
  if (method !== 'GET') {
   const input = route.request().postDataJSON(); writes.push({ method, input });
   if (path.endsWith('/movements')) return route.fulfill({ json: { data: { id: 'fictional-movement' } } });
   Object.assign(item, { name: input.name, sku: input.sku, description: input.description, version: item.version + 1 });
   return route.fulfill({ json: { data: item } });
  }
  return route.fulfill({ json: { data: path.endsWith('/draft-stock') ? item : path.endsWith('/items') ? [item] : [], meta } });
 });
 await page.route('**/api/v1/inventory/locations?*', route => route.fulfill({ json: { data: ['one', 'two'].map(id => ({ id, name: `Fictional ${id}`, version: 1, isArchived: false })), meta: { current_page: 1, last_page: 1, total: 2, per_page: 20 } } }));
 await page.route('**/api/v1/locations/options?*', route => route.fulfill({ json: locationOptionPage(['one', 'two'].map(id => ({ id, kind: 'site', name: `Fictional ${id}`, canSelect: true, children: [] })), new URL(route.request().url())) }));
 await openModule(page, 'inventory');
 return { item, writes, failures };
}

test('inventory types filter items and save seed classification from the editor', async ({ page }) => {
 const { writes, failures } = await inventorySession(page);
 const queries: URL[] = [];
 await page.route('**/api/v1/inventory/items?*', route => { queries.push(new URL(route.request().url())); return route.fallback(); });
 await (await revealTabbedControl(page.locator('#inventory-type-filter'))).selectOption('seeds');
 await expect.poll(() => queries.at(-1)?.searchParams.get('type')).toBe('seeds');
 await page.getByRole('button', { name: 'Modifier Fictional stock', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier l’article', exact: true });
 await (await revealTabbedControl(editor.locator('#inventory-type'))).selectOption('seeds');
 await expect(editor.locator('#inventory-unit')).toBeDisabled();
 await editor.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click();
 await expect(editor).toHaveCount(0); expect(writes[0]?.input).toMatchObject({ type: 'seeds', unit: 'piece' }); expect(failures).toEqual([]);
});

test('inventory item drafts protect cancellation and Back, retain field failures and clear after save', async ({ page }) => {
 const { writes, failures } = await inventorySession(page);
 await page.getByRole('button', { name: 'Modifier Fictional stock', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier l’article', exact: true });
 const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 const submit = editor.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#inventory-name'))).fill('Temporary'); await (await revealTabbedControl(editor.locator('#inventory-name'))).fill('Fictional stock');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(editor).toHaveCount(0); await expect(warning).toHaveCount(0);
 await page.getByRole('button', { name: 'Modifier Fictional stock', exact: true }).click();
 await (await revealTabbedControl(editor.locator('#inventory-name'))).fill(''); await editor.getByRole('tab', { name: 'Stock', exact: true }).click(); await (await revealTabbedControl(editor.locator('#inventory-threshold'))).fill('1.5'); await submit.click();
 await expectControlFocused(editor.locator('#inventory-name')); await editor.getByRole('tab', { name: 'Stock', exact: true }).click(); await expect(editor.locator('#inventory-threshold-error')).toContainText('entiers'); expect(writes).toEqual([]);
 await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await (await revealTabbedControl(editor.locator('#inventory-name'))).fill('Retained item'); await editor.getByRole('tab', { name: 'Stock', exact: true }).click(); await (await revealTabbedControl(editor.locator('#inventory-threshold'))).fill('3');
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#inventory-description'))).fill('Retained notes');
 await page.keyboard.press('Escape'); await expect(warning).toBeVisible(); await page.keyboard.press('Escape'); await expect(editor).toBeVisible();
 await page.goBack(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(page).toHaveURL(/\/inventory(?:\?stock=draft-stock)?$/);
 let reject = true;
 await page.route('**/api/v1/inventory/items/draft-stock', route => route.request().method() === 'PATCH' && reject ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { sku: ['Private stock info'], description: ['Private notes'] } } }) : route.fallback());
 await submit.click(); await expectControlFocused(editor.locator('#inventory-sku')); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#inventory-description-error'))).toBeVisible(); await expect(editor).not.toContainText('Private');
 await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await (await revealTabbedControl(editor.locator('#inventory-sku'))).fill('UPDATED'); await expect(editor.locator('#inventory-sku-error')).toHaveCount(0); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#inventory-description-error'))).toBeVisible();
 await expect(editor.locator('#inventory-description')).toHaveText('Retained notes');
 reject = false; await submit.click(); await expect(editor).toHaveCount(0); expect(writes).toHaveLength(1); expect(writes[0].input).toMatchObject({ name: 'Retained item', description: 'Retained notes', threshold: '3', version: 1 });
 await openModule(page, 'home'); await expect(warning).toHaveCount(0); await expect(page).toHaveURL(/\/$/); expect(failures).toEqual([]);
});

test('inventory movements validate exact quantities and locations, retain rejected data and accept zero counts', async ({ page }) => {
 const { writes, failures } = await inventorySession(page);
 await page.getByRole('button', { name: 'Stocks et historique de Fictional stock', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional stock', exact: true }); await detail.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Enregistrer un mouvement', exact: true }); const submit = editor.getByRole('button', { name: 'Valider le mouvement', exact: true });
 await submit.click(); await expectControlFocused(editor.locator('#movement-quantity')); await expect(await revealTabbedControl(editor.locator('#inventory-destination-error'))).toBeVisible(); await expect(await revealTabbedControl(editor.locator('#movement-reason-error'))).toBeVisible();
 await (await revealTabbedControl(editor.locator('#movement-quantity'))).fill('0'); await submit.click(); await expect(editor.locator('#movement-quantity-error')).toContainText('supérieure');
 await (await revealTabbedControl(editor.locator('#movement-quantity'))).fill('0.5'); await submit.click(); await expect(editor.locator('#movement-quantity-error')).toContainText('entiers');
 await (await revealTabbedControl(editor.locator('#movement-kind'))).selectOption('transfer'); await (await revealTabbedControl(editor.locator('#movement-quantity'))).fill('2'); await (await revealTabbedControl(editor.locator('#movement-reason'))).fill('Retained reason');
 await (await revealTabbedControl(editor.locator('#inventory-source'))).selectOption('one'); await (await revealTabbedControl(editor.locator('#inventory-destination'))).selectOption('one'); await submit.click(); await expectControlFocused(editor.locator('#inventory-destination')); await expect(editor.locator('#inventory-destination-error')).toContainText('différents'); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#inventory-destination'))).selectOption('two');
 const attempts: Record<string, unknown>[] = []; let reject = true;
 await page.route('**/api/v1/inventory/items/draft-stock/movements', route => {
  if (route.request().method() !== 'POST') return route.fallback(); attempts.push(route.request().postDataJSON());
  return reject ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { 'destination.id': ['Private location'], reason: ['Private reason'] } } }) : route.fallback();
 });
 await submit.click(); await expectControlFocused(editor.locator('#inventory-destination')); await expect(editor).not.toContainText('Private'); await expect(editor.locator('#movement-reason')).toHaveValue('Retained reason'); await expect(editor.locator('#movement-quantity')).toBeEnabled();
 await (await revealTabbedControl(editor.locator('#inventory-destination'))).selectOption('one'); await expect(editor.locator('#inventory-destination-error')).toHaveCount(0); await (await revealTabbedControl(editor.locator('#inventory-destination'))).selectOption('two');
 reject = false; await submit.click(); await expect(editor).toHaveCount(0); expect(attempts).toHaveLength(2); expect(attempts[0].request_id).not.toBe(attempts[1].request_id); expect(writes).toHaveLength(1);
 await detail.getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click(); await (await revealTabbedControl(editor.locator('#movement-kind'))).selectOption('count'); await (await revealTabbedControl(editor.locator('#movement-quantity'))).fill('0'); await (await revealTabbedControl(editor.locator('#movement-reason'))).fill('Physical count'); await (await revealTabbedControl(editor.locator('#inventory-destination'))).selectOption('one'); await submit.click(); await expect(editor).toHaveCount(0);
 expect(writes).toHaveLength(2); expect(writes[1].input).toMatchObject({ kind: 'count', quantity: '0', destination: { id: 'one' } }); expect(failures).toEqual([]);
});

test('inventory uncertain writes guard departure and retry exactly the same immutable stock request', async ({ page }) => {
 const { failures } = await inventorySession(page); await openModule(page, 'home'); await openModule(page, 'inventory');
 await page.getByRole('button', { name: 'Stocks et historique de Fictional stock', exact: true }).click();
 await page.getByRole('dialog', { name: 'Fictional stock', exact: true }).getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Enregistrer un mouvement', exact: true });
 await (await revealTabbedControl(editor.locator('#movement-quantity'))).fill('4'); await (await revealTabbedControl(editor.locator('#movement-reason'))).fill('Same receipt'); await (await revealTabbedControl(editor.locator('#inventory-destination'))).selectOption('one');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); const attempts: Record<string, unknown>[] = []; const committed = new Set<unknown>();
 await page.route('**/api/v1/inventory/items/draft-stock/movements', async route => {
  if (route.request().method() !== 'POST') return route.fallback(); const input = route.request().postDataJSON(); attempts.push(input); committed.add(input.request_id);
  if (attempts.length === 1) { await pending; return route.abort('failed'); }
  return route.fulfill({ json: { data: { id: 'same-movement' } } });
 });
 await editor.getByRole('button', { name: 'Valider le mouvement', exact: true }).click();
 try {
  await expect.poll(() => attempts.length).toBe(1); await page.keyboard.press('Escape'); await expect(editor).toBeVisible(); await page.goBack(); await expect(page).toHaveURL(/\/inventory(?:\?stock=draft-stock)?$/);
 } finally { release(); }
 await expect(page.getByRole('alert').filter({ hasText: /résultat.*confirmé/ })).toBeVisible(); await expect(editor.locator('#movement-quantity')).toBeDisabled(); await expect(editor.getByRole('button', { name: 'Annuler', exact: true })).toBeDisabled();
 await page.keyboard.press('Escape'); await expect(editor).toBeVisible(); await page.goBack(); await expect(page).toHaveURL(/\/inventory(?:\?stock=draft-stock)?$/);
 let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true);
 await editor.getByRole('button', { name: 'Réessayer la même opération', exact: true }).click(); await expect(editor).toHaveCount(0);
 expect(attempts).toHaveLength(2); expect(attempts[1]).toEqual(attempts[0]); expect(committed.size).toBe(1);
 await page.getByRole('dialog', { name: 'Fictional stock', exact: true }).getByRole('button', { name: 'Fermer les détails', exact: true }).click();
 await openModule(page, 'home'); await expect(page).toHaveURL(/\/$/); expect(failures).toEqual([]);
});

test('inventory conflict reload confirms discarded item and movement drafts before replacing them', async ({ page }) => {
 const { item, writes, failures } = await inventorySession(page);
 let reject = true; await page.route('**/api/v1/inventory/items/draft-stock', route => route.request().method() === 'PATCH' && reject ? route.fulfill({ status: 409, json: { error: { code: 'inventory.errors.conflict' } } }) : route.fallback());
 await page.getByRole('button', { name: 'Modifier Fictional stock', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier l’article', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await (await revealTabbedControl(editor.locator('#inventory-name'))).fill('Unsaved stock'); await editor.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click();
 await editor.getByRole('button', { name: 'Recharger l’article actuel', exact: true }).click(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor.locator('#inventory-name')).toHaveValue('Unsaved stock');
 item.version = 2; item.name = 'Concurrent stock'; await editor.getByRole('button', { name: 'Recharger l’article actuel', exact: true }).click(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor.locator('#inventory-name')).toHaveValue('Concurrent stock');
 reject = false; await (await revealTabbedControl(editor.locator('#inventory-name'))).fill('Final stock'); await editor.getByRole('button', { name: /^(?:Enregistrer l’article|Créer)$/, exact: true }).click(); await expect(editor).toHaveCount(0); expect(writes[0].input.version).toBe(2);
 await page.getByRole('button', { name: 'Stocks et historique de Final stock', exact: true }).click(); await page.getByRole('dialog', { name: 'Final stock', exact: true }).getByRole('button', { name: 'Enregistrer un mouvement', exact: true }).click();
 const movement = page.getByRole('dialog', { name: 'Enregistrer un mouvement', exact: true }); await (await revealTabbedControl(movement.locator('#movement-quantity'))).fill('1'); await (await revealTabbedControl(movement.locator('#movement-reason'))).fill('Conflict draft'); await (await revealTabbedControl(movement.locator('#inventory-destination'))).selectOption('one');
 await page.route('**/api/v1/inventory/items/draft-stock/movements', route => route.request().method() === 'POST' ? route.fulfill({ status: 409, json: { error: { code: 'inventory.errors.conflict' } } }) : route.fallback());
 await movement.getByRole('button', { name: 'Valider le mouvement', exact: true }).click(); await movement.getByRole('button', { name: 'Recharger l’article actuel', exact: true }).click(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(movement).toHaveCount(0); await expect(page.getByRole('dialog', { name: 'Final stock', exact: true })).toBeVisible(); expect(failures).toEqual([]);
});

async function hardwareEditorSession(page: Page) {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const device = { id: 'draft-device', name: 'Fictional probe', category: 'sensor', status: 'active', location: null, assetTag: 'PROBE', manufacturer: null, model: null, serialNumber: null, description: 'Original notes', channelsCount: 1, enabledChannelsCount: 1 };
 const type = { id: 'draft-type', name: 'Fictional temperature', code: 'temperature', nature: 'temperature', unit: '°C', decimalPlaces: 2, description: 'Original type notes', sensorsCount: 1 };
 const sensor = { id: 'draft-channel', name: 'Fictional channel', channelKey: 'temperature', isEnabled: true, expectedIntervalSeconds: 300, description: 'Original channel notes', device, measurementType: type };
 const writes: { path: string; input: Record<string, unknown> }[] = []; const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 for (const resource of ['assets', 'sensors', 'measurement-types']) await page.route(`**/api/v1/${resource}**`, route => {
  const path = new URL(route.request().url()).pathname;
  if (route.request().method() !== 'GET') { const input = route.request().postDataJSON(); writes.push({ path, input }); return route.fulfill({ json: { data: resource === 'assets' ? device : resource === 'sensors' ? sensor : type } }); }
  return route.fulfill({ json: { data: path.endsWith('/assignments') ? [] : resource === 'assets' && path.endsWith('/draft-device') ? device : [resource === 'assets' ? device : resource === 'sensors' ? sensor : type], meta } });
 });
 await page.route('**/api/v1/locations/options?*', route => route.fulfill({ json: locationOptionPage([{ id: 'one', kind: 'site', name: 'Fictional site', canSelect: true, children: [] }], new URL(route.request().url())) }));
 return { failures, writes, device, type, sensor };
}

test('hardware editor protects actual drafts and maps nested field failures without losing retry values', async ({ page }) => {
 const { failures, writes } = await hardwareEditorSession(page); await openModule(page, 'assets');
 const open = page.getByRole('button', { name: 'Modifier Fictional probe', exact: true }); await open.click();
 const editor = page.getByRole('dialog', { name: 'Modifier la fiche', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); const submit = editor.getByRole('button', { name: /^(?:Enregistrer la fiche|Créer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#asset-name'))).fill('Temporary'); await (await revealTabbedControl(editor.locator('#asset-name'))).fill('Fictional probe'); await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(editor).toHaveCount(0); await expect(warning).toHaveCount(0); await open.click();
 await (await revealTabbedControl(editor.locator('#asset-name'))).fill(''); await submit.click(); await expectControlFocused(editor.locator('#asset-name')); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#asset-name'))).fill('Retained name'); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#asset-description'))).fill('Retained notes');
 await page.getByRole('tab', { name: 'Canaux de mesure', exact: true }).evaluate((tab: HTMLElement) => tab.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(page.getByRole('tab', { name: 'Matériels', exact: true })).toHaveAttribute('aria-selected', 'true'); await expect(editor.locator('#asset-name')).toHaveValue('Retained name');
 await page.keyboard.press('Escape'); await expect(warning).toBeVisible(); await page.keyboard.press('Escape'); await page.goBack(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 let reject = true; await page.route('**/api/v1/assets/draft-device', route => reject ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { asset_tag: ['Private tag'], 'locations.0.id': ['Private location'] } } }) : route.fallback());
 await submit.click(); await expectControlFocused(editor.locator('#asset-tag')); await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#asset-location-error'))).toBeVisible(); await expect(editor).not.toContainText('Private'); await expect(editor.locator('#asset-description')).toHaveText('Retained notes');
 await editor.getByRole('tab', { name: 'Technique', exact: true }).click(); await (await revealTabbedControl(editor.locator('#asset-tag'))).fill('NEW'); await expect(editor.locator('#asset-tag-error')).toHaveCount(0); await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await (await revealTabbedControl(editor.locator('#asset-location'))).selectOption('site:one'); await expect(editor.locator('#asset-location-error')).toHaveCount(0);
 reject = false; await submit.click(); await expect(editor).toHaveCount(0); expect(writes[0].input).toMatchObject({ name: 'Retained name', description: 'Retained notes', asset_tag: 'NEW', locations: [{ type: 'site', id: 'one' }] }); await openModule(page, 'home'); await expect(warning).toHaveCount(0); expect(failures).toEqual([]);
});

test('channel editor validates selections and intervals, retains failures and preserves immutable identity', async ({ page }) => {
 const { failures, writes } = await hardwareEditorSession(page); await openModule(page, 'assets'); await page.getByRole('tab', { name: 'Canaux de mesure', exact: true }).click();
 await page.getByRole('button', { name: 'Ajouter un canal', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouveau canal de mesure', exact: true }); const submit = editor.getByRole('button', { name: /^(?:Enregistrer le canal|Créer)$/, exact: true });
 await expect(editor.locator('#sensor-device')).toBeEnabled(); await submit.click(); await expectControlFocused(editor.locator('#sensor-name')); await expect(await revealTabbedControl(editor.locator('#sensor-device-error'))).toBeVisible(); await expect(await revealTabbedControl(editor.locator('#sensor-type-error'))).toBeVisible();
 await (await revealTabbedControl(editor.locator('#sensor-name'))).fill('New channel'); await (await revealTabbedControl(editor.locator('#sensor-channel'))).fill(' Temp '); await (await revealTabbedControl(editor.locator('#sensor-device'))).selectOption('draft-device'); await (await revealTabbedControl(editor.locator('#sensor-type'))).selectOption('draft-type'); await editor.getByRole('tab', { name: 'Mesure', exact: true }).click(); await (await revealTabbedControl(editor.locator('#sensor-interval'))).fill('0'); await submit.click(); await expectControlFocused(editor.locator('#sensor-interval')); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#sensor-interval'))).fill('600'); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#sensor-description'))).fill('Retained channel notes'); let reject = true;
 await page.route('**/api/v1/sensors', route => reject ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { expected_interval_seconds: ['Private interval'], description: ['Private notes'] } } }) : route.fallback());
 await submit.click(); await expectControlFocused(editor.locator('#sensor-interval')); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#sensor-description-error'))).toBeVisible(); await expect(editor).not.toContainText('Private'); await expect(editor.locator('#sensor-description')).toHaveText('Retained channel notes');
 await editor.getByRole('tab', { name: 'Mesure', exact: true }).click(); await (await revealTabbedControl(editor.locator('#sensor-interval'))).fill('900'); await expect(editor.locator('#sensor-interval-error')).toHaveCount(0); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#sensor-description-error'))).toBeVisible();
 reject = false; await submit.click(); await expect(editor).toHaveCount(0); expect(writes[0].input).toMatchObject({ channel_key: 'temp', expected_interval_seconds: 900, device_id: 'draft-device', measurement_type_id: 'draft-type' });
 await page.getByRole('button', { name: 'Modifier Fictional channel', exact: true }).click(); const edit = page.getByRole('dialog', { name: 'Modifier le canal', exact: true });
 await expect(edit.locator('#sensor-device')).toBeDisabled(); await expect(edit.locator('#sensor-type')).toBeDisabled(); await expect(edit.locator('#sensor-channel')).toBeDisabled(); await (await revealTabbedControl(edit.locator('#sensor-name'))).fill('Edited channel'); await edit.getByRole('button', { name: /^(?:Enregistrer le canal|Créer)$/, exact: true }).click(); await expect(edit).toHaveCount(0);
 expect(writes[1].input).not.toHaveProperty('device_id'); expect(writes[1].input).not.toHaveProperty('measurement_type_id'); expect(writes[1].input).not.toHaveProperty('channel_key'); expect(failures).toEqual([]);
});

test('contextual channel drafts protect their parent closure and in-flight saves until success', async ({ page }) => {
 const { failures, writes } = await hardwareEditorSession(page); await openModule(page, 'assets'); await page.getByRole('button', { name: 'Ouvrir Fictional probe', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional probe', exact: true }); await detail.getByRole('button', { name: 'Ajouter un canal', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouveau canal de mesure', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await (await revealTabbedControl(editor.locator('#sensor-name'))).fill('Context draft'); await (await revealTabbedControl(editor.locator('#sensor-channel'))).fill('moisture'); await (await revealTabbedControl(editor.locator('#sensor-type'))).selectOption('draft-type');
 await detail.getByRole('button', { name: 'Fermer la fiche matériel', exact: true }).evaluate((button: HTMLButtonElement) => button.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor.locator('#sensor-name')).toHaveValue('Context draft');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/sensors', async route => { writing = true; await pending; return route.fallback(); });
 await editor.getByRole('button', { name: /^(?:Enregistrer le canal|Créer)$/, exact: true }).click();
 try { await expect.poll(() => writing).toBe(true); await page.keyboard.press('Escape'); await expect(editor).toBeVisible(); await page.goBack(); await expect(page).toHaveURL(/\/hardware(?:\?asset=draft-device)?$/); let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true); }
 finally { release(); }
 await expect(editor).toHaveCount(0); await detail.getByRole('button', { name: 'Fermer la fiche matériel', exact: true }).click(); await expect(detail).toHaveCount(0); await openModule(page, 'home'); expect(writes).toHaveLength(1); expect(failures).toEqual([]);
});

test('measurement-type drafts preserve validation failures, prevent tab loss and keep in-use semantics locked', async ({ page }) => {
 const { failures, writes } = await hardwareEditorSession(page); await openModule(page, 'catalogs'); await page.getByRole('tab', { name: 'Types de mesures', exact: true }).click();
 await page.getByRole('button', { name: 'Ajouter un type de mesure', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Nouveau type de mesure', exact: true }); const submit = editor.getByRole('button', { name: /^(?:Enregistrer le type de mesure|Créer)$/, exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await submit.click(); await expectControlFocused(editor.locator('#measurement-name')); await (await revealTabbedControl(editor.locator('#measurement-name'))).fill('Moisture'); await (await revealTabbedControl(editor.locator('#measurement-code'))).fill('moisture'); await editor.getByRole('tab', { name: 'Mesure', exact: true }).click(); await (await revealTabbedControl(editor.locator('#measurement-nature'))).selectOption('substrate_moisture'); await (await revealTabbedControl(editor.locator('#measurement-unit'))).fill('g'); await (await revealTabbedControl(editor.locator('#measurement-decimals'))).fill('7'); await submit.click(); await expectControlFocused(editor.locator('#measurement-unit')); await expect(await revealTabbedControl(editor.locator('#measurement-decimals-error'))).toBeVisible(); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#measurement-unit'))).fill('%'); await (await revealTabbedControl(editor.locator('#measurement-decimals'))).fill('1'); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#measurement-description'))).fill('Retained type notes');
 await page.getByRole('tab', { name: 'Modèles de commentaire', exact: true }).evaluate((tab: HTMLElement) => tab.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor).toBeVisible(); await expect(page.getByRole('tab', { name: 'Types de mesures', exact: true })).toHaveAttribute('aria-selected', 'true');
 let reject = true; await page.route('**/api/v1/measurement-types', route => reject ? route.fulfill({ status: 409, json: { error: { code: 'measurementTypes.errors.codeTaken' } } }) : route.fallback());
 await submit.click(); await expectControlFocused(editor.locator('#measurement-code')); await expect(await revealTabbedControl(editor.locator('#measurement-code-error'))).toBeVisible(); await expect(editor.locator('#measurement-description')).toHaveText('Retained type notes');
 reject = false; await (await revealTabbedControl(editor.locator('#measurement-code'))).fill('moisture_new'); await submit.click(); await expect(editor).toHaveCount(0); expect(writes[0].input).toMatchObject({ unit: '%', nature: 'substrate_moisture', decimal_places: 1, code: 'moisture_new' });
 await page.getByRole('button', { name: 'Modifier Fictional temperature', exact: true }).click(); const edit = page.getByRole('dialog', { name: 'Modifier le type de mesure', exact: true }); await expect(edit.locator('#measurement-code')).toBeDisabled(); await expect(edit.locator('#measurement-unit')).toBeDisabled(); await expect(edit.locator('#measurement-decimals')).toBeDisabled(); await edit.getByRole('tab', { name: 'Mesure', exact: true }).click(); await (await revealTabbedControl(edit.locator('#measurement-nature'))).selectOption('substrate_moisture'); await edit.getByRole('button', { name: /^(?:Enregistrer le type de mesure|Créer)$/, exact: true }).click(); await expectControlFocused(edit.locator('[data-ui-select="measurement-nature"] .n-base-selection-label')); expect(writes).toHaveLength(1);
 await page.getByRole('tab', { name: 'Modèles de commentaire', exact: true }).evaluate((tab: HTMLElement) => tab.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(edit).toHaveCount(0); await expect(page.getByRole('tab', { name: 'Modèles de commentaire', exact: true })).toHaveAttribute('aria-selected', 'true'); expect(failures).toEqual([]);
});

async function locationEditorSession(page: Page) {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const records = (['site', 'room', 'zone'] as const).map((kind, index) => ({ id: `draft-${kind}`, code: kind.toUpperCase(), kind, name: `Fictional ${kind}`, description: `Notes for ${kind}`, parentId: index ? `draft-${index === 1 ? 'site' : 'room'}` : null, childrenCount: index === 2 ? 0 : 1, canSelect: true, canManage: true, wateringAlertDays: index === 0 ? 4 : null }));
 const writes: { path: string; method: string; input: Record<string, unknown> }[] = [];
 await page.route('**/api/v1/sites**', async route => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  const kind = path.includes('/zones') ? 'zone' : path.includes('/rooms') ? 'room' : 'site';
  const record = records.find(row => row.kind === kind)!;
  if (request.method() === 'GET') return route.fulfill({ json: { data: [record], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
  const input = request.postDataJSON(); writes.push({ path, method: request.method(), input });
  Object.assign(record, { name: input.name, description: input.description, wateringAlertDays: input.watering_alert_days }); return route.fulfill({ json: { data: record } });
 });
 await page.route('**/api/v1/locations/*/*', route => { const id = new URL(route.request().url()).pathname.split('/').at(-1); const record = records.find(row => row.id === id); return record ? route.fulfill({ json: { data: record } }) : route.fallback(); });
 await openModule(page, 'locations'); return { failures, writes };
}

test('location editors protect actual drafts and retain focused field feedback through rejected saves', async ({ page }) => {
 const { failures, writes } = await locationEditorSession(page);
 const open = page.getByRole('button', { name: 'Modifier Fictional site', exact: true }); await open.click();
 const editor = page.getByRole('dialog', { name: 'Modifier le site', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); const submit = editor.getByRole('button', { name: /^(?:Enregistrer le lieu|Créer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#location-name'))).fill('Temporary'); await (await revealTabbedControl(editor.locator('#location-name'))).fill('Fictional site'); await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(editor).toHaveCount(0); await expect(warning).toHaveCount(0); await open.click();
 await (await revealTabbedControl(editor.locator('#location-name'))).fill(' '); await editor.getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(editor.locator('#location-watering-days'))).fill('0'); await submit.click(); await expectControlFocused(editor.locator('#location-name')); await editor.getByRole('tab', { name: 'Conditions', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#location-watering-days-error'))).toBeVisible(); expect(writes).toEqual([]);
 await editor.getByRole('tab', { name: 'Général', exact: true }).click(); await (await revealTabbedControl(editor.locator('#location-name'))).fill('Retained site'); await expect(editor.locator('#location-name-error')).toHaveCount(0); await editor.getByRole('tab', { name: 'Conditions', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#location-watering-days-error'))).toBeVisible(); await editor.getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(editor.locator('#location-watering-days'))).fill('3.5'); await submit.click(); await expectControlFocused(editor.locator('#location-watering-days')); await (await revealTabbedControl(editor.locator('#location-watering-days'))).fill('5'); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#location-description'))).fill('Retained notes');
 await editor.getByRole('button', { name: 'Fermer le formulaire', exact: true }).click(); await expect(warning).toBeVisible(); await page.keyboard.press('Escape'); await expect(editor.locator('#location-name')).toHaveValue('Retained site');
 let rejection = 422; await page.route('**/api/v1/sites/draft-site', route => rejection ? route.fulfill({ status: rejection, json: { error: { code: rejection === 409 ? 'locations.errors.nameTaken' : 'auth.errors.validationFailed' }, errors: rejection === 422 ? { watering_alert_days: ['Private delay'], description: ['Private notes'] } : {} } }) : route.fallback());
 await submit.click(); await expectControlFocused(editor.locator('#location-watering-days')); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#location-description-error'))).toBeVisible(); await expect(editor.locator('#location-description')).toHaveText('Retained notes'); await expect(editor).not.toContainText('Private');
 await (await revealTabbedControl(editor.locator('#location-description'))).fill('Corrected notes'); await expect(editor.locator('#location-description-error')).toHaveCount(0); await editor.getByRole('tab', { name: 'Conditions', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#location-watering-days-error'))).toBeVisible(); await editor.getByRole('tab', { name: 'Conditions', exact: true }).click(); await (await revealTabbedControl(editor.locator('#location-watering-days'))).fill('6');
 rejection = 409; await submit.click(); await expectControlFocused(editor.locator('#location-name')); await expect(editor.locator('#location-name-error')).toContainText('déjà');
 await page.goBack(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 rejection = 0; await (await revealTabbedControl(editor.locator('#location-name'))).fill('Saved site'); await submit.click(); await expect(editor).toHaveCount(0); expect(writes).toEqual([{ path: '/api/v1/sites/draft-site', method: 'PATCH', input: { name: 'Saved site', description: 'Corrected notes', watering_alert_days: 6 } }]); await openModule(page, 'home'); await expect(warning).toHaveCount(0); expect(failures).toEqual([]);
});

test('location hierarchy and parent details wait for discard decisions without changing draft context', async ({ page }) => {
 const { failures, writes } = await locationEditorSession(page); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await page.getByRole('button', { name: 'Ouvrir Fictional site', exact: true }).click(); await page.getByRole('button', { name: 'Ajouter une pièce', exact: true }).click();
 const roomEditor = page.getByRole('dialog', { name: 'Nouvelle pièce', exact: true }); await (await revealTabbedControl(roomEditor.locator('#location-name'))).fill('New room'); await (await revealTabbedControl(roomEditor.locator('#location-description'))).fill('Room notes');
 const allSites = page.getByRole('button', { name: 'Tous les sites', exact: true }); await allSites.evaluate((button: HTMLButtonElement) => button.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(roomEditor.locator('#location-name')).toHaveValue('New room');
 await roomEditor.getByRole('button', { name: /^(?:Enregistrer le lieu|Créer)$/, exact: true }).click(); await expect(roomEditor).toHaveCount(0); expect(writes[0]).toEqual({ path: '/api/v1/sites/draft-site/rooms', method: 'POST', input: { name: 'New room', description: 'Room notes', watering_alert_days: null } });
 await page.getByRole('button', { name: 'Ouvrir New room', exact: true }).click(); await page.getByRole('button', { name: 'Photos et informations de Fictional zone', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional zone', exact: true }); await detail.getByRole('button', { name: 'Modifier Fictional zone', exact: true }).click(); const zoneEditor = page.getByRole('dialog', { name: 'Modifier la zone', exact: true });
 await (await revealTabbedControl(zoneEditor.locator('#location-description'))).fill('Zone draft'); await detail.getByRole('button', { name: 'Fermer le lieu', exact: true }).evaluate((button: HTMLButtonElement) => button.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(zoneEditor.locator('#location-description')).toHaveText('Zone draft');
 await page.keyboard.press('Escape'); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(zoneEditor).toHaveCount(0); await expect(detail).toBeVisible();
 await detail.getByRole('button', { name: 'Modifier Fictional zone', exact: true }).click(); await (await revealTabbedControl(zoneEditor.locator('#location-watering-days'))).fill('7'); await allSites.evaluate((button: HTMLButtonElement) => button.click()); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(zoneEditor).toHaveCount(0); await expect(detail).toHaveCount(0); await expect(page).not.toHaveURL(/[?&](?:location|site|room)=/); await expect(page.getByRole('button', { name: 'Ajouter un site', exact: true })).toBeVisible(); expect(writes).toHaveLength(1); expect(failures).toEqual([]);
});

test('pending location saves block parent closure and departure until the scoped update succeeds', async ({ page }) => {
 const { failures, writes } = await locationEditorSession(page);
 await page.getByRole('button', { name: 'Ouvrir Fictional site', exact: true }).click(); await page.getByRole('button', { name: 'Ouvrir Fictional room', exact: true }).click(); await page.getByRole('button', { name: 'Photos et informations de Fictional zone', exact: true }).click();
 const detail = page.getByRole('dialog', { name: 'Fictional zone', exact: true }); await detail.getByRole('button', { name: 'Modifier Fictional zone', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier la zone', exact: true }); await (await revealTabbedControl(editor.locator('#location-name'))).fill('Pending zone');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/sites/draft-site/rooms/draft-room/zones/draft-zone', async route => { writing = true; await pending; return route.fallback(); });
 await editor.getByRole('button', { name: /^(?:Enregistrer le lieu|Créer)$/, exact: true }).click();
 try { await expect.poll(() => writing).toBe(true); await page.keyboard.press('Escape'); await expect(editor).toBeVisible(); await detail.getByRole('button', { name: 'Fermer le lieu', exact: true }).evaluate((button: HTMLButtonElement) => button.click()); await expect(detail).toBeVisible(); await expect(editor).toBeVisible(); await page.goBack(); await expect(page).toHaveURL(/\/sites\?.*location=zone:draft-zone/); let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true); }
 finally { release(); }
 await expect(editor).toHaveCount(0); await expect(page.getByRole('dialog', { name: 'Pending zone', exact: true })).toBeVisible(); expect(writes).toEqual([{ path: '/api/v1/sites/draft-site/rooms/draft-room/zones/draft-zone', method: 'PATCH', input: { name: 'Pending zone', code: 'ZONE', description: 'Notes for zone', watering_alert_days: null } }]); await page.getByRole('dialog', { name: 'Pending zone', exact: true }).getByRole('button', { name: 'Fermer le lieu', exact: true }).click(); await openModule(page, 'home'); expect(failures).toEqual([]);
});

async function notificationEditorSession(page: Page, canManage = true) {
 const { failures } = await syntheticSession(page, canManage ? 'admin' : 'member', canManage ? ['administration.view'] : []);
 const configuration: NotificationStatus = { canManage, settings: { minimum_severity: 'info', include_recovery: true, webhook_enabled: false, locale: 'en', push_enabled: false, email_enabled: false, version: 0 }, channels: { email: { configured: true }, push: { configured: false, publicKey: null }, webhook: { configured: true, format: 'generic' } } };
 const preference: NotificationPreferences = { version: 0, locale: 'en', email_enabled: false, push_enabled: false };
 const state = { reads: 0, failRead: false }; const writes: { path: string; input: Record<string, unknown> }[] = [];
 await page.route('**/api/v1/notifications/settings', async route => {
  if (route.request().method() === 'GET') { state.reads++; return route.fulfill({ json: { data: configuration } }); }
  const input = route.request().postDataJSON(); writes.push({ path: 'settings', input }); Object.assign(configuration.settings, input, { version: configuration.settings.version + 1 }); return route.fulfill({ json: { data: configuration } });
 });
 await page.route('**/api/v1/notifications/preferences', async route => {
  if (route.request().method() === 'GET') return state.failRead ? route.fulfill({ status: 500, json: {} }) : route.fulfill({ json: { data: preference } });
  const input = route.request().postDataJSON(); writes.push({ path: 'preferences', input }); Object.assign(preference, input, { version: preference.version + 1 }); return route.fulfill({ json: { data: preference } });
 });
 await openModule(page, canManage ? 'notifications' : 'accountNotifications'); await expect(page.locator('#notification-severity')).toHaveCount(canManage ? 1 : 0);
 await expect(page.getByTestId('nav-item-notifications')).toHaveCount(0);
 await expect(page.locator('.workspace-heading [role=tablist]')).toHaveCount(canManage ? 0 : 1);
 await expect(page.getByRole('tablist')).toHaveCount(1);
 return { failures, writes, state, configuration, preference };
}

test('notification channel drafts survive tabs and field failures while personal preferences live in the profile', async ({ page }) => {
 const { failures, writes } = await notificationEditorSession(page);
 await expect(page.getByRole('button', { name: 'Enregistrer les réglages de l’installation', exact: true })).toHaveCount(0);
 await expect(page.getByRole('tab', { name: 'Mes préférences', exact: true })).toHaveCount(0);
 await expect(page.locator('#notification-email')).toHaveCount(0);
 let reject = true; await page.route('**/api/v1/notifications/settings', route => route.request().method() === 'PATCH' && reject ? route.fulfill({ status: 422, json: { error: { code: 'notifications.errors.inputInvalid' }, errors: { minimum_severity: ['Private severity'], locale: ['Private locale'] } } }) : route.fallback());
 await (await revealTabbedControl(page.locator('#notification-global-email'))).check(); await (await revealTabbedControl(page.locator('#notification-severity'))).selectOption('warning');
 await page.getByRole('tab', { name: 'Général', exact: true }).click(); await page.getByRole('tab', { name: 'Notifications', exact: true }).click();
 await expect(page.locator('#notification-global-email')).toBeChecked();
 await expect(page.getByRole('tab', { name: 'Historique des envois', exact: true })).toHaveCount(0);
 await expect(page.locator('#notification-severity-error')).toBeVisible(); await expect(await revealTabbedControl(page.locator('#notification-webhook-locale-error'))).toBeVisible(); await expect(page.locator('.notifications-card')).not.toContainText('Private');
 await (await revealTabbedControl(page.locator('#notification-severity'))).selectOption('critical'); await (await revealTabbedControl(page.locator('#notification-webhook-locale'))).selectOption('fr'); reject = false; await expect.poll(() => writes.length).toBe(1);
 await openModule(page, 'accountNotifications'); await expect(page).toHaveURL(/\/account\/notifications$/); await expect(page.locator('#notification-email')).toBeVisible(); await expect(page.locator('#notification-global-email')).toHaveCount(0);
 await (await revealTabbedControl(page.locator('#notification-email'))).check();
 await expect.poll(() => writes.map(row => row.path)).toEqual(['settings', 'preferences']); await expect(page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true })).toBeEnabled(); await openModule(page, 'home'); expect(failures).toEqual([]);
});

test('profile notification drafts guard profile tabs and reload, retain conflicts and survive failed reads', async ({ page }) => {
 const { failures, writes, state, preference } = await notificationEditorSession(page, false); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); const reload = page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true });
 let reject = true; await page.route('**/api/v1/notifications/preferences', route => route.request().method() === 'PATCH' && reject ? route.fulfill({ status: 409, json: { error: { code: 'notifications.errors.conflict' } } }) : route.fallback());
 await (await revealTabbedControl(page.locator('#notification-email'))).check(); await page.getByRole('tab', { name: 'Sécurité', exact: true }).click(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(page).toHaveURL(/\/account\/notifications$/);
 await expect(page.getByText(/Ces réglages ont changé dans une autre session/)).toBeVisible();
 await expect(page.locator('#notification-email')).toBeChecked();
 state.failRead = true; await reload.click(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.locator('#notification-email')).toBeChecked(); await expect(reload).toBeEnabled();
 state.failRead = false; preference.version = 4; preference.locale = 'fr'; await reload.click(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.locator('#notification-email')).not.toBeChecked(); await expect(page.locator('#notification-locale')).toHaveValue('fr');
 reject = false; await (await revealTabbedControl(page.locator('#notification-email'))).check(); await expect.poll(() => writes).toEqual([{path:'preferences',input:{email_enabled:true,push_enabled:false,locale:'fr',version:4}}]); await expect(reload).toBeEnabled(); await openModule(page,'account'); expect(failures).toEqual([]);
});

test('pending personal notification saves block profile navigation until completion', async ({ page }) => {
 const { failures, writes } = await notificationEditorSession(page, false);
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/notifications/preferences', async route => { if (route.request().method() === 'PATCH') { writing = true; await pending; } return route.fallback(); });
 await (await revealTabbedControl(page.locator('#notification-email'))).check();
 try {
  await expect.poll(() => writing).toBe(true);
  await expect(page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true })).toBeDisabled();
  await page.getByRole('tab', { name: 'Sécurité', exact: true }).click(); await expect(page).toHaveURL(/\/account\/notifications$/);
  await expect(page.getByRole('dialog')).toHaveCount(0);
 } finally { release(); }
 await expect(page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true })).toBeEnabled();
 await expect.poll(() => writes.length).toBe(1);
 await page.getByRole('tab', { name: 'Profil', exact: true }).click(); await expect(page).toHaveURL(/\/account$/); expect(failures).toEqual([]);
});

test('pending notification writes guard navigation and reload, then permit clean departure after success', async ({ page }) => {
 const { failures, writes } = await notificationEditorSession(page); await (await revealTabbedControl(page.locator('#notification-global-email'))).check();
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/notifications/settings', async route => { if (route.request().method() === 'PATCH') { writing = true; await pending; } return route.fallback(); });

 try { await expect.poll(() => writing).toBe(true); await expect(page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true })).toBeDisabled(); await openModule(page, 'home'); await expect(page).toHaveURL(/\/settings\/notifications$/); let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true); }
 finally { release(); }
 await expect(page.getByText('Réglages des notifications mis à jour.', { exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true })).toBeEnabled(); await openModule(page, 'home'); expect(writes).toHaveLength(1); expect(failures).toEqual([]);
});

test('personal notification errors remain editable without exposing global controls to a member', async ({ page }) => {
 const { failures, writes } = await notificationEditorSession(page, false); await openNotificationSection(page, 'preferences'); await expect(page.locator('#notification-push')).toBeDisabled(); await expect(page.locator('#notification-global-email')).toHaveCount(0);
 const email = page.getByRole('switch', {name:'Recevoir les alertes par e-mail',exact:true});
 await expect(email).not.toBeChecked(); await email.focus(); await page.keyboard.press('Space'); await expect(email).toBeChecked();
 await page.locator('label[for="notification-email"]').click(); await expect(email).not.toBeChecked();
 await page.locator('label[for="notification-push"]').click(); await expect(page.locator('#notification-push')).not.toBeChecked();
 expect(writes).toEqual([]);
 await (await revealTabbedControl(email)).check();
 let reject = true; await page.route('**/api/v1/notifications/preferences', route => route.request().method() === 'PATCH' && reject ? route.fulfill({ status: 422, json: { error: { code: 'notifications.errors.inputInvalid' }, errors: { email_enabled: ['Private email'], locale: ['Private locale'] } } }) : route.fallback());
 await expect(page.locator('#notification-email')).toHaveAttribute('aria-describedby', 'notification-email-error'); await expect(await revealTabbedControl(page.locator('#notification-locale-error'))).toBeVisible(); await expect(page.getByTestId('notification-preferences')).not.toContainText('Private');
 await (await revealTabbedControl(page.locator('#notification-email'))).uncheck(); await expect(page.locator('#notification-email-error')).toHaveCount(0); await expect(await revealTabbedControl(page.locator('#notification-locale-error'))).toBeVisible(); await (await revealTabbedControl(page.locator('#notification-locale'))).selectOption('fr'); reject = false; await expect(page.getByRole('button', { name: 'Recharger les réglages des notifications', exact: true })).toBeEnabled(); await expect.poll(() => writes).toEqual([{ path: 'preferences', input: { email_enabled: false, push_enabled: false, locale: 'fr', version: 0 } }]); await openModule(page, 'home'); expect(failures).toEqual([]);
});

async function userEditorSession(page: Page, self = false) {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const managed = { id: self ? 'fictional-user' : 'managed-user', name: 'Fictional managed', email: self ? 'fictional@example.test' : 'managed@example.test', isActive: true, permissionGrants: [], permissionDenials: [], locationAccess: null, permissionRoles: [{ id: self ? 'administrator' : 'profile-one', name: self ? 'Administrator' : 'Fictional profile', permissions: self ? ['administration.view'] : ['sensors.manage'] }]};
 const writes: Record<string, unknown>[] = [];
 await page.route('**/api/v1/users**', async route => {
  const request = route.request();
  if (request.method() === 'GET') return route.fulfill({ json: { data: [managed], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
  const input = request.postDataJSON(); writes.push(input); const saved = { ...managed, ...input, isActive: input.is_active, permissionGrants: input.permission_grants, locationAccess: input.location_access };
  Object.assign(managed, saved); return route.fulfill({ json: { data: saved } });
 });
 await page.route('**/api/v1/permission-roles?*', route => route.fulfill({ json: { data: [{ id: 'profile-one', name: 'Fictional profile', description: null, permissions: ['sensors.manage'], isArchived: false, version: 1, assignedUsers: 0 }, { id: 'profile-two', name: 'Observer', permissions: [], isArchived: false }, { id: 'administrator', name: 'Administrator', permissions: ['administration.view'], isArchived: false }], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } }));
 await page.route('**/api/v1/locations/options?*', route => route.fulfill({ json: locationOptionPage([{ id: 'site-one', name: 'Fictional site', kind: 'site', canSelect: true, children: [] }], new URL(route.request().url())) }));
 await openModule(page, 'users'); return { failures, writes, managed };
}

test('personal identity edits retain drafts and field advice, then update the profile without changing OIDC choices', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']); const writes: Record<string, unknown>[] = []; let reject = true;
 await page.route('**/api/v1/account/profile', route => {
  if (reject) return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { email: ['users.validation.emailTaken'], name: ['Private name'] } } });
  const input = route.request().postDataJSON(); writes.push(input); return route.fulfill({ json: { data: { id: 'fictional-user', ...input, role: 'admin', isActive: true, permissions: ['administration.view'], oidcLinked: false, locationAccess: null } } });
 });
 await openModule(page, 'account'); const open = page.getByRole('button', { name: 'Modifier mon profil', exact: true }); await open.click(); const editor = page.getByRole('dialog', { name: 'Modifier mon profil', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); const save = editor.getByRole('button', { name: 'Enregistrer les modifications', exact: true });
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Temporary'); await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Fictional User'); await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(editor).toHaveCount(0); await open.click();
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill(''); await (await revealTabbedControl(editor.locator('#profile-email'))).fill('bad'); await save.click(); await expectControlFocused(editor.locator('#profile-name')); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Retained profile'); await (await revealTabbedControl(editor.locator('#profile-email'))).fill('taken@example.test'); await save.click(); await expectControlFocused(editor.locator('#profile-name')); await expect(editor.locator('#profile-email-error')).toContainText('déjà'); await expect(editor).not.toContainText('Private');
 await page.keyboard.press('Escape'); await expect(warning).toBeVisible(); await page.keyboard.press('Escape'); await expect(editor.locator('#profile-name')).toHaveValue('Retained profile'); await page.goBack(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Saved profile'); await expect(editor.locator('#profile-name-error')).toHaveCount(0); await expect(await revealTabbedControl(editor.locator('#profile-email-error'))).toBeVisible(); await (await revealTabbedControl(editor.locator('#profile-email'))).fill('profile@example.test'); reject = false; await save.click(); await expect(editor).toHaveCount(0); await expect(page.locator('.profile-identity')).toContainText('Saved profile'); expect(writes).toEqual([{ name: 'Saved profile', email: 'profile@example.test', locale: 'fr' }]); await openModule(page, 'home'); expect(failures).toEqual([]);
});

test('user creation validates passwords and maps nested access errors while retaining the complete draft', async ({ page }) => {
 const { failures, writes } = await userEditorSession(page); await page.getByRole('button', { name: 'Ajouter un compte', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Nouveau compte', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le compte|Créer)$/, exact: true });
 await save.click(); await expectControlFocused(editor.locator('#user-name')); await editor.getByRole('tab', { name: 'Mot de passe', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#user-password-error'))).toBeVisible(); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#user-name'))).fill('New managed'); await (await revealTabbedControl(editor.locator('#user-email'))).fill(' NEW@example.test '); await (await revealTabbedControl(editor.locator('#user-password'))).fill('Fictional secret 123!'); await (await revealTabbedControl(editor.locator('#user-permission-profile'))).selectOption('profile-one'); await (await revealTabbedControl(editor.locator('#user-confirmation'))).fill('Mismatch'); await save.click(); await expectControlFocused(editor.locator('#user-confirmation')); await expect(editor.locator('#user-confirmation')).toHaveAttribute('aria-describedby', 'user-confirmation-error'); expect(writes).toEqual([]); await (await revealTabbedControl(editor.locator('#user-confirmation'))).fill('Fictional secret 123!');
 let reject = true; await page.route('**/api/v1/users', route => reject ? route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { permission_role_ids: ['Private profile'], 'permission_grants.0': ['Private permission'], 'location_access.0.id': ['Private location'] } } }) : route.fallback());
 await save.click(); await expectControlFocused(editor.locator('#user-permission-profile')); await expect(await revealTabbedControl(editor.locator('#user-location-grants-error'))).toBeVisible(); await expect(editor).not.toContainText('Private'); await expect(editor.locator('#user-password')).toHaveValue('Fictional secret 123!');
 await expect(await revealTabbedControl(editor.locator('#user-permission-grants-error'))).toBeVisible(); await (await revealTabbedControl(editor.locator('#user-permission-profile'))).selectOption('profile-two'); await expect(editor.locator('#user-permission-profile-error')).toHaveCount(0); await (await revealTabbedControl(editor.getByLabel(/Gérer les plantes et les actions de culture/).and(editor.locator('select')))).selectOption('allow'); await expect(editor.locator('#user-permission-grants-error')).toHaveCount(0); await expect(await revealTabbedControl(editor.locator('#user-location-grants-error'))).toBeVisible(); await (await revealTabbedControl(editor.locator('#user-location-grants .location-all').getByRole('switch'))).uncheck(); await (await revealTabbedControl(editor.locator('#user-location-access'))).selectOption('site:site-one'); reject = false; await save.click(); await expect(editor).toHaveCount(0);
 expect(writes[0]).toMatchObject({ name: 'New managed', email: 'new@example.test', permission_role_ids: ['profile-one', 'profile-two'], permission_grants: ['plants.manage'], location_access: [{ type: 'site', id: 'site-one' }], password: 'Fictional secret 123!', password_confirmation: 'Fictional secret 123!' }); expect(failures).toEqual([]);
});

test('user access and password drafts protect profile-tab navigation and erase credentials after discard', async ({ page }) => {
 const { failures, writes } = await userEditorSession(page); await page.getByRole('button', { name: 'Modifier Fictional managed', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier le compte', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await (await revealTabbedControl(editor.locator('#user-password'))).fill('Discarded secret 123!'); await (await revealTabbedControl(editor.locator('#user-confirmation'))).fill('Discarded secret 123!'); await (await revealTabbedControl(editor.getByLabel(/Gérer les plantes et les actions de culture/).and(editor.locator('select')))).selectOption('allow'); await (await revealTabbedControl(editor.locator('#user-location-grants .location-all').getByRole('switch'))).uncheck(); await (await revealTabbedControl(editor.locator('#user-location-access'))).selectOption('site:site-one');
 const profiles = page.getByTestId('workspace-section-profiles'); await profiles.evaluate((tab: HTMLElement) => tab.click()); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(page.getByTestId('workspace-section-users')).toHaveAttribute('aria-selected', 'true'); await expect(editor.locator('#user-password')).toHaveValue('Discarded secret 123!'); await expect(editor.getByRole('button', { name: 'Retirer Fictional site', exact: true })).toBeVisible();
 await profiles.evaluate((tab: HTMLElement) => tab.click()); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0); await expect(page.getByTestId('workspace-section-profiles')).toHaveAttribute('aria-selected', 'true'); await openModule(page, 'users'); await page.getByRole('button', { name: 'Modifier Fictional managed', exact: true }).click(); await editor.getByRole('tab', { name: 'Mot de passe', exact: true }).click(); await expect(editor.locator('#user-password')).toHaveValue(''); await expect(editor.locator('#user-confirmation')).toHaveValue(''); await editor.getByRole('tab', { name: 'Emplacements', exact: true }).click(); await expect(editor.locator('#user-location-grants .location-all').getByRole('switch')).toBeChecked();
 expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toContain('Discarded secret'); await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(editor).toHaveCount(0); expect(writes).toEqual([]); expect(failures).toEqual([]);
});

test('self administration retains server safeguards, blocks in-flight departure and revokes sensitive successful edits', async ({ page }) => {
 const { failures, writes } = await userEditorSession(page, true); await page.getByRole('button', { name: 'Modifier Fictional managed', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier le compte', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le compte|Créer)$/, exact: true }); await expect(editor.locator('#user-active')).toBeDisabled();
 let reject = true; let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/users/fictional-user', async route => { if (reject) return route.fulfill({ status: 409, json: { error: { code: 'users.errors.lastAdministrator' } } }); writing = true; await pending; return route.fallback(); });
 await editor.getByRole('tab', { name: 'Profils et droits', exact: true }).click(); await editor.getByLabel('Retirer Administrateur', { exact: true }).click(); await (await revealTabbedControl(editor.locator('#user-permission-profile'))).selectOption('profile-two'); await save.click(); await expectControlFocused(editor.locator('#user-permission-profile')); await expect(editor.locator('#user-permission-profile-error')).toContainText('administrateur'); expect(writes).toEqual([]); await editor.getByLabel('Retirer Observateur', { exact: true }).click(); await (await revealTabbedControl(editor.locator('#user-permission-profile'))).selectOption('administrator'); await (await revealTabbedControl(editor.locator('#user-name'))).fill('Renamed managed'); reject = false; await save.click();
 try { await expect.poll(() => writing).toBe(true); await page.keyboard.press('Escape'); await expect(editor).toBeVisible(); await page.goBack(); await expect(page).toHaveURL(/\/users$/); let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true); }
 finally { release(); }
 await expect(editor).toHaveCount(0); expect(writes[0]).not.toHaveProperty('password'); await page.getByRole('button', { name: 'Modifier Renamed managed', exact: true }).click(); await (await revealTabbedControl(editor.locator('#user-password'))).fill('Fictional-password-123!'); await editor.locator('#user-confirmation').fill('Fictional-password-123!'); await save.click(); await expect(editor).toHaveCount(0); await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible(); await expect(page.locator('#login-email')).toHaveValue('fictional@example.test'); expect(writes).toHaveLength(2); expect(failures).toEqual([]);
});

async function accessProfileEditorSession(page: Page, archived = false) {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const state = {
  rows: [{ id: 'fictional-profile', name: 'Fictional operators', description: 'Original notes', permissions: ['assets.manage'], isArchived: archived, version: 3, assignedUsers: archived ? 0 : 2 }],
  writes: [] as { method: string; input: Record<string, unknown> }[], reads: 0, failRead: false,
  rejection: null as { status: number; code: string; fields?: Record<string, string[]> } | null,
  pending: null as Promise<void> | null,
 };
 await page.route('**/api/v1/permission-roles**', async route => {
  const request = route.request();
  if (request.method() === 'GET') {
   state.reads++;
   if (state.failRead) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
   return route.fulfill({ json: { data: state.rows, meta: { current_page: 1, last_page: 1, per_page: 20, total: state.rows.length } } });
  }
  const input = request.postDataJSON(); state.writes.push({ method: request.method(), input });
  if (state.pending) await state.pending;
  if (state.rejection) return route.fulfill({ status: state.rejection.status, json: { error: { code: state.rejection.code }, errors: state.rejection.fields ?? {} } });
  const profile = { id: request.method() === 'POST' ? 'created-profile' : 'fictional-profile', name: input.name, description: input.description, permissions: input.permissions, isArchived: input.is_archived, version: request.method() === 'POST' ? 1 : Number(input.version) + 1, assignedUsers: request.method() === 'POST' ? 0 : 2 };
  if (request.method() === 'POST') state.rows.push(profile); else state.rows = state.rows.map(row => row.id === profile.id ? profile : row);
  return route.fulfill({ json: { data: profile } });
 });
 await openModule(page, 'profiles');
 await expect(page.getByRole('button', { name: 'Modifier Fictional operators', exact: true })).toBeVisible();
 return { state, failures };
}

test('access profile creation retains rejected notes and nested permissions with trusted field feedback', async ({ page }) => {
 const { state, failures } = await accessProfileEditorSession(page);
 await page.getByRole('button', { name: 'Créer un profil', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouveau profil d’accès', exact: true });
 const save = editor.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true });
 await save.click(); await expectControlFocused(editor.locator('#profile-name')); expect(state.writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Draft operators'); await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#profile-description'))).fill('Draft notes');
 state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { description: ['Private notes rejection'], 'permissions.0': ['Private permission rejection'] } };
 await save.click(); await expectControlFocused(editor.locator('#profile-description')); await expect(editor.locator('#profile-description')).toHaveAttribute('aria-describedby', /profile-description-error/);
 await editor.getByRole('tab', { name: 'Droits', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#profile-permissions-error'))).toBeVisible(); await expect(editor).not.toContainText('Private'); await expect(editor.locator('#profile-name')).toHaveValue('Draft operators');
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await (await revealTabbedControl(editor.locator('#profile-description'))).fill('Corrected notes'); await expect(editor.locator('#profile-description-error')).toHaveCount(0); await editor.getByRole('tab', { name: 'Droits', exact: true }).click(); await expect(await revealTabbedControl(editor.locator('#profile-permissions-error'))).toBeVisible();
 const permission = editor.getByRole('switch', { name: /Gérer les plantes et les actions de culture/ });
 await expect(permission).not.toBeChecked(); await permission.focus(); await page.keyboard.press('Space'); await expect(permission).toBeChecked(); await expect(editor.locator('#profile-permissions-error')).toHaveCount(0);
 state.rejection = { status: 422, code: 'accessProfiles.errors.nameTaken' };
 await save.click(); await expectControlFocused(editor.locator('#profile-name')); await expect(editor.locator('#profile-name-error')).toContainText('porte déjà ce nom');
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Available operators'); await expect(editor.locator('#profile-name-error')).toHaveCount(0);
 await editor.getByRole('tab', { name: 'Droits', exact: true }).click(); await permission.click(); await expect(permission).not.toBeChecked(); state.rejection = null;
 const controlId = await permission.getAttribute('id'); await editor.locator(`label[for="${controlId}"]`).click(); await expect(permission).toBeChecked();
 let release!: () => void; state.pending = new Promise<void>(resolve => { release = resolve; });
 try { await save.click(); await expect(permission).toBeDisabled(); } finally { release(); }
 await expect(editor).toHaveCount(0);
 expect(state.writes.at(-1)).toMatchObject({ method: 'POST', input: { name: 'Available operators', description: 'Corrected notes', permissions: ['plants.manage'], is_archived: false } }); expect(state.writes.at(-1)!.input).not.toHaveProperty('version');
 await expect(page.getByText('Le profil d’accès a été créé.', { exact: true })).toBeVisible(); expect(failures).toEqual([]);
});

test('access profile copies are protected new drafts and profile-tab navigation preserves reverted edits', async ({ page }) => {
 const { state, failures } = await accessProfileEditorSession(page, true);
 await page.getByRole('button', { name: 'Créer à partir de Fictional operators', exact: true }).click();
 const copy = page.getByRole('dialog', { name: 'Nouveau profil d’accès', exact: true });
 await expect(copy.locator('#profile-name')).toHaveValue('Copie de Fictional operators'); await expect(copy.locator('#profile-description')).toHaveText('Original notes'); await expect(copy.locator('#profile-archived')).not.toBeChecked(); await expect(copy.locator('.permission-choice [role="switch"][aria-checked="true"]')).toHaveCount(1);
 await page.keyboard.press('Escape'); const discard = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); await expect(discard).toBeVisible(); await page.keyboard.press('Escape'); await expect(discard).toHaveCount(0); await expect(copy).toBeVisible();
 await copy.getByRole('button', { name: 'Annuler', exact: true }).click(); await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(copy).toHaveCount(0);
 await page.getByRole('button', { name: 'Modifier Fictional operators', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier le profil d’accès', exact: true });
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Temporarily changed'); await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Fictional operators'); await page.keyboard.press('Escape'); await expect(editor).toHaveCount(0); await expect(discard).toHaveCount(0);
 await page.getByRole('button', { name: 'Modifier Fictional operators', exact: true }).click(); await (await revealTabbedControl(editor.locator('#profile-description'))).fill('Remember this profile draft');
 const users = page.getByTestId('workspace-section-users'); await users.evaluate(element => (element as HTMLElement).click()); await discard.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(page.getByTestId('workspace-section-profiles')).toHaveAttribute('aria-selected', 'true'); await expect(editor.locator('#profile-description')).toHaveText('Remember this profile draft');
 await users.evaluate(element => (element as HTMLElement).click()); await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0); await expect(users).toHaveAttribute('aria-selected', 'true');
 expect(state.writes).toEqual([]); expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain('Remember this profile draft'); expect(failures).toEqual([]);
});

test('access profile conflicts preserve drafts until a confirmed successful reload adopts the current version', async ({ page }) => {
 const { state, failures } = await accessProfileEditorSession(page);
 await page.getByRole('button', { name: 'Modifier Fictional operators', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier le profil d’accès', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('My conflicting changes'); state.rejection = { status: 409, code: 'accessProfiles.errors.conflict' };
 await save.click(); await expect(page.getByRole('alert').filter({ hasText: 'Ce profil a changé.' }).first()).toBeVisible(); await expect(save).toBeDisabled(); expect(state.writes[0].input.version).toBe(3);
 const reload = editor.getByRole('button', { name: 'Recharger les profils', exact: true }); const discard = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 const reads = state.reads; await reload.click(); await discard.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); expect(state.reads).toBe(reads); await expect(editor.locator('#profile-name')).toHaveValue('My conflicting changes');
 state.failRead = true; await reload.click(); await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(reload).toBeEnabled(); await expect(editor.locator('#profile-name')).toHaveValue('My conflicting changes'); await expect(save).toBeDisabled(); expect(state.reads).toBe(reads + 1);
 state.failRead = false; state.rows[0] = { ...state.rows[0], name: 'Server renamed operators', description: 'Server notes', version: 4 };
 await reload.click(); await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0);
 await page.getByRole('button', { name: 'Modifier Server renamed operators', exact: true }).click(); await expect(editor.locator('#profile-description')).toHaveText('Server notes'); await expect(save).toBeEnabled();
 state.rejection = null; await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Resolved operators'); await save.click(); await expect(editor).toHaveCount(0); expect(state.writes.at(-1)!.input.version).toBe(4); expect(failures).toEqual([]);
});

test('access profile saves block departure and retain assigned-profile archive feedback without replaying successful writes', async ({ page }) => {
 const { state, failures } = await accessProfileEditorSession(page);
 await page.getByRole('button', { name: 'Modifier Fictional operators', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier le profil d’accès', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#profile-archived'))).check(); state.rejection = { status: 409, code: 'accessProfiles.errors.assigned' };
 await save.click(); await expectControlFocused(editor.locator('#profile-archived')); await expect(editor.locator('#profile-archived-error')).toContainText('Retirez toutes les attributions'); await expect(editor.locator('#profile-archived')).toBeChecked();
 await (await revealTabbedControl(editor.locator('#profile-archived'))).uncheck(); await expect(editor.locator('#profile-archived-error')).toHaveCount(0); await (await revealTabbedControl(editor.getByLabel(/Gérer les plantes et les actions de culture/))).check(); state.rejection = null;
 let release!: () => void; state.pending = new Promise<void>(resolve => { release = resolve; });
 try {
  await save.click(); await expect(editor.locator('#profile-name')).toBeDisabled(); await page.keyboard.press('Escape'); await expect(editor).toBeVisible();
  const url = page.url(); await page.getByTestId('workspace-section-users').evaluate(element => (element as HTMLElement).click()); await expect(page).toHaveURL(url); await expect(page.getByTestId('workspace-section-profiles')).toHaveAttribute('aria-selected', 'true');
  const warning = page.waitForEvent('dialog'); const reloadPage = page.reload({ timeout: 1500 }).catch(() => null); const prompt = await warning; expect(prompt.type()).toBe('beforeunload'); await prompt.dismiss(); await reloadPage;
  state.failRead = true; release(); await expect(editor).toHaveCount(0); await expect(page.getByText('Le profil d’accès a été modifié.', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Recharger les profils', exact: true })).toBeEnabled(); expect(state.writes).toHaveLength(2); expect(state.writes.at(-1)!.input).toMatchObject({ version: 3, is_archived: false, permissions: ['assets.manage', 'plants.manage'] });
  state.failRead = false; await page.getByRole('button', { name: 'Recharger les profils', exact: true }).click(); await expect(page.getByRole('button', { name: 'Recharger les profils', exact: true })).toBeEnabled(); expect(state.writes).toHaveLength(2);
 } finally { release(); }
 expect(failures).toEqual([]);
});

test('access profile authorization loss closes the protected draft and leaves administration', async ({ page }) => {
 const { state, failures } = await accessProfileEditorSession(page);
 await page.getByRole('button', { name: 'Modifier Fictional operators', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier le profil d’accès', exact: true });
 await (await revealTabbedControl(editor.locator('#profile-name'))).fill('Unauthorized draft'); state.rejection = { status: 403, code: 'auth.errors.forbidden' };
 await page.route('**/api/v1/auth/user', route => route.fulfill({ json: { data: { id: 'fictional-user', name: 'Fictional User', email: 'fictional@example.test', role: 'member', permissions: [], isActive: true, oidcLinked: false, locationAccess: null } } }));
 await editor.getByRole('button', { name: /^(?:Enregistrer le profil|Créer)$/, exact: true }).click(); await expect(editor).toHaveCount(0); await expect(page.getByTestId('plant-home')).toBeVisible(); await expect(page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true })).toHaveCount(0); expect(state.writes).toHaveLength(1); expect(failures).toEqual([]);
});

async function journalFileEditorSession(page: Page, module: 'events' | 'documents') {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const timestamp = '2026-11-10T09:10:11.123456Z';
 const state = {
  event: { id: 'fictional-event', title: 'Fictional observation', body: 'Original observation', category: 'observation', severity: 'info', occurredAt: timestamp, subject: null, version: 3, author: { id: 'fictional-user', name: 'Fictional User' }, canEdit: true, createdAt: timestamp, updatedAt: timestamp },
  file: { id: 'fictional-file', title: 'Fictional manual', description: 'Original manual notes', originalName: 'manual.txt', mediaType: 'text/plain', format: 'text', sizeBytes: 15, sha256: '', version: 3, isArchived: false, folderId: null as string | null, subject: null, uploader: { id: 'fictional-user', name: 'Fictional User' }, canEdit: true, disk: 'documents', createdAt: timestamp, updatedAt: timestamp },
  folders: [{ id: 'manuals', name: 'Manuals', parentId: null as string | null, version: 2, canEdit: true }, { id: 'pumps', name: 'Pumps', parentId: 'manuals', version: 1, canEdit: true }, { id: 'other', name: 'Other', parentId: null as string | null, version: 1, canEdit: true }],
  writes: [] as { path: string; method: string; input: Record<string, unknown>; body: string }[],
  rejection: null as { status: number; code: string; fields?: Record<string, string[]> } | null,
  failShow: false, failFolders: false, failList: false, pending: null as Promise<void> | null,
 };
 const meta = { current_page: 1, last_page: 1, per_page: 20, total: 1 };
 const handler = async (route: import('@playwright/test').Route) => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  if (request.method() === 'GET') {
   if ((state.failShow && /\/(fictional-file|fictional-event)$/.test(path)) || (state.failFolders && path.includes('/document-folders')) || (state.failList && /\/(events|documents)$/.test(path))) return route.fulfill({ status: 503, json: { error: { code: 'auth.errors.requestFailed' } } });
   if (path.endsWith('/storage-options')) return route.fulfill({ json: { data: { default: 'documents_s3', disks: ['documents', 'documents_s3'] } } });
   if (path.includes('/document-folders')) {
    const url = new URL(request.url());
    const describe = (folder: typeof state.folders[number]) => ({ ...folder, hasChildren: state.folders.some(row => row.parentId === folder.id) });
    if (path.endsWith('/paths')) return route.fulfill({ json: { data: state.folders.map(describe) } });
    if (path.endsWith('/document-folders')) { const folders = state.folders.filter(folder => folder.parentId === (url.searchParams.get('parent_id') || null)); return route.fulfill({ json: { data: folders.map(describe), meta: { ...meta, total: folders.length } } }); }
    const folder = state.folders.find(row => path.endsWith('/' + row.id));
    if (!folder) return route.fulfill({ status: 404, json: { error: { code: 'documents.validation.folderInvalid' } } });
    const ancestors = [folder]; let parent = folder.parentId;
    while (parent) { const ancestor = state.folders.find(row => row.id === parent)!; ancestors.unshift(ancestor); parent = ancestor.parentId; }
    return route.fulfill({ json: { data: describe(folder), ancestors: ancestors.map(describe) } });
   }
   if (path.endsWith('/revisions')) return route.fulfill({ json: { data: [], meta } });
   if (path.endsWith('/fictional-event')) return route.fulfill({ json: { data: state.event } });
   if (path.endsWith('/fictional-file')) return route.fulfill({ json: { data: state.file } });
   return route.fulfill({ json: { data: path.endsWith('/events') ? [state.event] : [state.file], meta } });
  }
  const multipart = request.headers()['content-type']?.includes('multipart/form-data');
  const input = multipart ? {} : request.postDataJSON(); state.writes.push({ path, method: request.method(), input, body: request.postDataBuffer()?.toString() ?? '' });
  if (state.pending) await state.pending;
  if (state.rejection) return route.fulfill({ status: state.rejection.status, json: { error: { code: state.rejection.code }, errors: state.rejection.fields ?? {} } });
  if (path.includes('/document-folders')) {
   const previous = state.folders.find(folder => path.endsWith('/' + folder.id));
   const folder = { id: previous?.id ?? 'created-folder', name: input.name, parentId: input.parent_id, version: (previous?.version ?? 0) + 1, canEdit: true };
   state.folders = previous ? state.folders.map(row => row.id === folder.id ? folder : row) : [...state.folders, folder];
   return route.fulfill({ json: { data: folder } });
  }
  if (path.includes('/events')) { state.event = { ...state.event, ...input, occurredAt: input.occurred_at, version: state.event.version + 1 }; return route.fulfill({ json: { data: state.event } }); }
  if (!multipart) state.file = { ...state.file, ...input, folderId: input.folder_id ?? null, version: state.file.version + 1 };
  return route.fulfill({ json: { data: state.file } });
 };
 await page.route('**/api/v1/events**', handler); await page.route('**/api/v1/documents**', handler); await page.route('**/api/v1/document-folders**', handler);
 await page.route('**/api/v1/locations/options?*', route => route.fulfill({ json: locationOptionPage([{ kind: 'site', id: 'site-one', name: 'Fictional site', canSelect: true, children: [] }], new URL(route.request().url())) }));
 await openModule(page, module);
 await expect(page.getByRole('button', { name: module === 'events' ? 'Corriger Fictional observation' : 'Modifier Fictional manual', exact: true })).toBeVisible();
 return { state, failures };
}
const draftWarning = (page: Page) => page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });

test('journal fields retain rejected nested context and clear only corrected advice', async ({ page }) => {
 const { state, failures } = await journalFileEditorSession(page, 'events');
 await page.getByRole('button', { name: 'Ajouter une entrée', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Nouvelle entrée', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer l’entrée|Créer)$/, exact: true });
 await save.click(); await expectControlFocused(editor.locator('#event-title')); await expect(await revealTabbedControl(editor.locator('#event-body-error'))).toBeVisible(); expect(state.writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#event-title'))).fill('New observation'); await (await revealTabbedControl(editor.locator('#event-body'))).fill('Retained observation'); await (await revealTabbedControl(editor.locator('#event-context-kind'))).selectOption('location'); await save.click(); await expectControlFocused(editor.locator('#events-subject')); expect(state.writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#events-subject'))).selectOption('site:site-one');
 state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { 'subject.id': ['Private context'], body: ['Private body'] } }; await save.click();
 await expectControlFocused(editor.locator('#events-subject')); await expect(editor.locator('#events-subject')).toHaveAttribute('aria-describedby', 'events-subject-error'); await expect(editor).not.toContainText('Private');
 await (await revealTabbedControl(editor.locator('#event-body'))).fill('Corrected observation'); await expect(editor.locator('#event-body-error')).toHaveCount(0); await expect(await revealTabbedControl(editor.locator('#events-subject-error'))).toBeVisible();
 await (await revealTabbedControl(editor.locator('#events-subject'))).selectOption(''); await (await revealTabbedControl(editor.locator('#events-subject'))).selectOption('site:site-one'); state.rejection = null; await save.click(); await expect(editor).toHaveCount(0);
 expect(state.writes.at(-1)!.input).toMatchObject({ title: 'New observation', body: 'Corrected observation', subject: { type: 'site', id: 'site-one' } }); expect(state.writes.at(-1)!.input.occurred_at).toMatch(/Z$/); expect(failures).toEqual([]);
});

test('journal correction drafts survive closure and failed conflict reloads while preserving unchanged timestamp precision', async ({ page }) => {
 const { state, failures } = await journalFileEditorSession(page, 'events');
 await page.getByRole('button', { name: 'Corriger Fictional observation', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Corriger l’entrée', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer l’entrée|Créer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#event-title'))).fill('Temporary'); await (await revealTabbedControl(editor.locator('#event-title'))).fill('Fictional observation'); await page.keyboard.press('Escape'); await expect(editor).toHaveCount(0); await expect(draftWarning(page)).toHaveCount(0);
 await page.getByRole('button', { name: 'Corriger Fictional observation', exact: true }).click(); await (await revealTabbedControl(editor.locator('#event-body'))).fill('Pending correction'); await page.keyboard.press('Escape'); await draftWarning(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 state.rejection = { status: 409, code: 'events.errors.conflict' }; await save.click(); await expect(save).toBeDisabled(); expect(state.writes[0].input.occurred_at).toBe('2026-11-10T09:10:11.123456Z');
 const reload = editor.getByRole('button', { name: 'Recharger la version actuelle', exact: true }); state.failShow = true;
 await reload.click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(reload).toBeEnabled(); await expect(editor.locator('#event-body')).toHaveValue('Pending correction'); await expect(save).toBeDisabled();
 state.failShow = false; state.event = { ...state.event, body: 'Server correction', version: 4 };
 await reload.click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor.locator('#event-body')).toHaveValue('Server correction'); await expect(save).toBeEnabled();
 await (await revealTabbedControl(editor.locator('#event-body'))).fill('Resolved correction'); state.rejection = null; await save.click(); await expect(editor).toHaveCount(0); expect(state.writes.at(-1)!.input.version).toBe(4); expect(failures).toEqual([]);
});

test('file imports protect attachment-only drafts and preserve selected files through rejected and pending uploads', async ({ page }) => {
 const { state, failures } = await journalFileEditorSession(page, 'documents');
 await page.getByRole('button', { name: 'Ajouter un document', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Ajouter un document', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le document|Importer)$/, exact: true });
 await expect(editor.locator('#document-storage option[value="documents_s3"]')).toBeAttached(); await page.keyboard.press('Escape'); await expect(editor).toHaveCount(0); await expect(draftWarning(page)).toHaveCount(0);
 await page.getByRole('button', { name: 'Ajouter un document', exact: true }).click(); await save.click(); await expectControlFocused(editor.locator('#document-file-selection')); expect(state.writes).toEqual([]);
 const attachment = { name: 'new-note.txt', mimeType: 'text/plain', buffer: Buffer.from('Fictional retained file content') };
 await editor.locator('#document-file').setInputFiles(attachment); await (await revealTabbedControl(editor.locator('#document-title'))).fill(''); await page.keyboard.press('Escape'); await draftWarning(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor.locator('.upload-files')).toContainText('new-note.txt');
 await (await revealTabbedControl(editor.locator('#document-title'))).fill('Uploaded note'); state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { file: ['Private rejection'] } }; await save.click(); await expectControlFocused(editor.locator('#document-file-selection')); await expect(editor).not.toContainText('Private');
 await expect(editor.locator('.upload-files')).toContainText('new-note.txt'); await editor.locator('#document-file').setInputFiles(attachment); await expect(editor.locator('#document-file-error')).toHaveCount(0);
 state.rejection = null; let release!: () => void; state.pending = new Promise<void>(resolve => { release = resolve; });
 try {
  await save.click(); await expect(editor.locator('#document-file')).toBeDisabled(); await page.keyboard.press('Escape'); await expect(editor).toBeVisible();
  const url = page.url(); await page.goBack(); await expect(page).toHaveURL(url); await expect(draftWarning(page)).toHaveCount(0);
  let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true);
  state.failList = true; release(); await expect(editor).toHaveCount(0); await expect(page.getByText('Document ajouté.', { exact: true })).toBeVisible(); expect(state.writes).toHaveLength(2); expect(state.writes.at(-1)!.body).toContain('Fictional retained file content');
 } finally { release(); }
 expect(failures).toEqual([]);
});

test('file metadata and context validation retain conflict drafts until a successful current-record reload', async ({ page }) => {
 const { state, failures } = await journalFileEditorSession(page, 'documents');
 await page.getByRole('button', { name: 'Modifier Fictional manual', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier les informations du document', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le document|Importer)$/, exact: true });
 await (await revealTabbedControl(editor.locator('#document-description'))).fill('Pending manual notes'); state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { folder_id: ['Private folder'], 'subject.id': ['Private subject'] } };
 await save.click(); await expectControlFocused(editor.locator('#document-folder')); await expect(editor.locator('#document-description')).toHaveText('Pending manual notes'); await expect(editor).not.toContainText('Private');
 await (await revealTabbedControl(editor.locator('#document-folder'))).selectOption('manuals'); await expect(editor.locator('#document-folder-error')).toHaveCount(0); await expect(editor.locator('#documents-subject-error')).toHaveCount(0);
 await (await revealTabbedControl(editor.locator('#document-context-kind'))).selectOption('location'); await save.click(); await expectControlFocused(editor.locator('#documents-subject')); await (await revealTabbedControl(editor.locator('#documents-subject'))).selectOption('site:site-one');
 state.rejection = { status: 409, code: 'documents.errors.conflict' }; await save.click(); await expect(save).toBeDisabled(); const reload = editor.getByRole('button', { name: 'Recharger le document actuel', exact: true }); state.failShow = true;
 await reload.click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(reload).toBeEnabled(); await expect(editor.locator('#document-description')).toHaveText('Pending manual notes');
 state.failShow = false; state.file = { ...state.file, description: 'Server manual notes', version: 4 }; await reload.click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor.locator('#document-description')).toHaveText('Server manual notes');
 state.rejection = null; await (await revealTabbedControl(editor.locator('#document-title'))).fill('Resolved manual'); await save.click(); await expect(editor).toHaveCount(0); expect(state.writes.at(-1)!.input.version).toBe(4); expect(state.writes.at(-1)!.input).not.toHaveProperty('file'); expect(failures).toEqual([]);
});

test('folder edits protect hierarchy drafts and recover current versions after name and parent errors', async ({ page }) => {
 const { state, failures } = await journalFileEditorSession(page, 'documents');
 await page.getByRole('button', { name: 'Modifier le dossier Manuals', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Modifier le dossier', exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le dossier|Créer)$/, exact: true });
 await expect(editor.locator('#folder-parent option[value="pumps"]')).toHaveCount(0); await (await revealTabbedControl(editor.locator('#folder-name'))).fill('bad/name'); await save.click(); await expectControlFocused(editor.locator('#folder-name')); expect(state.writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#folder-name'))).fill('Pending folder'); state.rejection = { status: 422, code: 'documents.errors.folderNameTaken' }; await save.click(); await expect(editor.locator('#folder-name-error')).toContainText('porte déjà ce nom');
 await (await revealTabbedControl(editor.locator('#folder-name'))).fill('Available folder'); state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { parent_id: ['Private parent'] } }; await save.click(); await expectControlFocused(editor.locator('#folder-parent')); await expect(editor).not.toContainText('Private');
 await (await revealTabbedControl(editor.locator('#folder-parent'))).selectOption('other'); await expect(editor.locator('#folder-parent-error')).toHaveCount(0); await page.keyboard.press('Escape'); await draftWarning(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor.locator('#folder-parent')).toHaveValue('other');
 state.rejection = { status: 409, code: 'documents.errors.folderConflict' }; await save.click(); const reload = editor.getByRole('button', { name: 'Recharger le dossier actuel', exact: true }); state.failFolders = true;
 await reload.click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(reload).toBeEnabled(); await expect(editor.locator('#folder-name')).toHaveValue('Available folder'); await expect(save).toBeDisabled();
 state.failFolders = false; state.folders[0] = { ...state.folders[0], name: 'Server folder', version: 5 }; await reload.click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor.locator('#folder-name')).toHaveValue('Server folder');
 state.rejection = null; await (await revealTabbedControl(editor.locator('#folder-name'))).fill('Resolved folder'); await save.click(); await expect(editor).toHaveCount(0); expect(state.writes.at(-1)!.input.version).toBe(5); expect(failures).toEqual([]);
});

for (const parent of ['', 'manuals']) test(`file moves create a destination inside the same dialog (${parent || 'root'})`, async ({ page }) => {
 if (parent) await page.setViewportSize({ width: 390, height: 844 });
 const { state, failures } = await journalFileEditorSession(page, 'documents');
 await page.getByRole('button', { name: 'Déplacer Fictional manual', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Déplacer Fictional manual', exact: true });
 await (await revealTabbedControl(editor.locator('#file-destination'))).selectOption(parent);
 await editor.getByRole('button', { name: 'Nouveau dossier', exact: true }).click();
 const name = editor.locator('#move-folder-name'); const create = editor.getByRole('button', { name: 'Créer le dossier', exact: true }); const move = editor.getByRole('button', { name: 'Déplacer', exact: true });
 await expectControlFocused(name); await expect(move).toBeDisabled(); await create.click();
 await expect(await revealTabbedControl(editor.locator('#move-folder-name-error'))).toBeVisible(); expect(state.writes).toEqual([]);
 await (await revealTabbedControl(name)).fill('New destination'); await expect(editor.locator('#move-folder-name-error')).toHaveCount(0);
 if (parent) {
  await page.keyboard.press('Escape'); await draftWarning(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(name).toHaveValue('New destination');
  state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { name: ['documents.errors.folderNameTaken'] } }; await create.click();
  await expectControlFocused(name); await expect(editor.locator('#move-folder-name-error')).toContainText('déjà');
  await expect(editor.locator('#file-destination')).toHaveValue(parent); expect(state.file.folderId).toBeNull();
  state.rejection = null; await (await revealTabbedControl(name)).fill('Manuals');
 }
 await create.click(); await expect(name).toHaveCount(0);
 await expect(editor.locator('#file-destination')).toHaveValue('created-folder'); await expectControlFocused(editor.locator('#file-destination')); await expect(move).toBeEnabled();
 expect(state.writes.at(-1)?.input).toEqual({ name: parent ? 'Manuals' : 'New destination', parent_id: parent || null }); expect(state.file.folderId).toBeNull();
 if (parent) await expect(editor.locator('#file-destination option[value="created-folder"]')).toHaveText('Manuals / Manuals');
 await expect(page.getByRole('dialog')).toHaveCount(1);
 await move.click(); await expect(editor).toHaveCount(0);
 expect(state.writes.at(-1)?.input).toEqual({ version: 3, folder_id: 'created-folder' }); expect(failures).toEqual([]);
});

test('file moves retain destination errors and refresh stale versions before retrying', async ({ page }) => {
 const { state, failures } = await journalFileEditorSession(page, 'documents');
 await page.getByRole('button', { name: 'Déplacer Fictional manual', exact: true }).click(); const editor = page.getByRole('dialog', { name: 'Déplacer Fictional manual', exact: true }); const save = editor.getByRole('button', { name: 'Déplacer', exact: true });
 await (await revealTabbedControl(editor.locator('#file-destination'))).selectOption('manuals'); state.rejection = { status: 422, code: 'auth.errors.validationFailed', fields: { folder_id: ['Private destination'] } }; await save.click(); await expectControlFocused(editor.locator('#file-destination')); await expect(await revealTabbedControl(editor.locator('#file-destination-error'))).toBeVisible(); await expect(editor).not.toContainText('Private');
 await (await revealTabbedControl(editor.locator('#file-destination'))).selectOption(''); await (await revealTabbedControl(editor.locator('#file-destination'))).selectOption('other'); await expect(editor.locator('#file-destination-error')).toHaveCount(0); await page.keyboard.press('Escape'); await draftWarning(page).getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor.locator('#file-destination')).toHaveValue('other');
 state.rejection = { status: 409, code: 'documents.errors.conflict' }; await save.click(); await expect(save).toBeDisabled(); state.file = { ...state.file, folderId: 'manuals', version: 4 };
 await editor.getByRole('button', { name: 'Recharger le document actuel', exact: true }).click(); await draftWarning(page).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor.locator('#file-destination')).toHaveValue('manuals');
 state.rejection = null; await (await revealTabbedControl(editor.locator('#file-destination'))).selectOption(''); await (await revealTabbedControl(editor.locator('#file-destination'))).selectOption('other'); await save.click(); await expect(editor).toHaveCount(0); expect(state.writes.at(-1)!.input).toEqual({ version: 4, folder_id: 'other' }); expect(failures).toEqual([]);
});


test('delivery history lives in the journal, paginates independently and guards event drafts', async ({ page }) => {
 await page.clock.install();
 const { failures, requests } = await syntheticSession(page, 'admin', ['administration.view']);
 const reads: URL[] = []; let failRead = false;
 await page.route('**/api/v1/notifications/deliveries?*', route => {
  const url = new URL(route.request().url()); reads.push(url);
  if (failRead) return route.fulfill({status:500,json:{error:{code:'remote.private.details'}}});
  const current = Number(url.searchParams.get('page'));
  return route.fulfill({json:{data:[{id:`delivery-${current}`,episodeId:'episode',event:'opened',channel:'email',status:current === 1 ? 'sent' : 'pending',attempts:1,errorCode:null,createdAt:'2026-11-10T09:00:00Z',sentAt:null,nextAttemptAt:'2026-11-10T10:00:00Z'}],meta:{current_page:current,last_page:2,total:21,per_page:Number(url.searchParams.get('per_page'))}}});
 });
 await openModule(page, 'notifications'); await expect(page.locator('.notifications-card')).toBeVisible();
 await expect(page.getByRole('tab', {name:'Historique des envois',exact:true})).toHaveCount(0); expect(reads).toEqual([]);
 await openModule(page, 'events');
 await (await revealTabbedControl(page.locator('#events-search'))).fill('Retained event filter');
 await page.getByRole('button', {name:'Ajouter une entrée',exact:true}).click();
 await (await revealTabbedControl(page.locator('#event-title'))).fill('Unsaved journal draft');
 await page.clock.runFor(350);
 // A modal prevents pointer tab selection; programmatic activation exercises the tab's draft guard.
 await page.locator('.journal-tabs').getByRole('tab',{name:'Historique des envois',exact:true}).evaluate((tab:HTMLElement)=>tab.click());
 const warning=page.getByRole('dialog',{name:'Modifications non enregistrées',exact:true});
 await expect(warning).toBeVisible(); await warning.getByRole('button',{name:'Continuer à modifier',exact:true}).click();
 await expect(page.locator('#event-title')).toHaveValue('Unsaved journal draft'); expect(reads).toEqual([]);
 await page.getByRole('button',{name:'Annuler',exact:true}).click(); await warning.getByRole('button',{name:'Abandonner les modifications',exact:true}).click();
 const settingsReads=requests.filter(path=>path.endsWith('/notifications/settings')).length;
 await openNotificationSection(page,'history');
 const history=page.getByTestId('notification-history'); await expect(history).toContainText('Accepté par le service');
 await expect(page.locator('#events-search')).toBeHidden(); await expect(page.getByRole('button',{name:'Ajouter une entrée',exact:true})).toHaveCount(0);
 const pages=page.getByRole('navigation',{name:'Pages de l’historique des envois',exact:true});
 await (await revealTabbedControl(pages.locator('select'))).selectOption('10'); await expect.poll(()=>reads.at(-1)?.searchParams.get('per_page')).toBe('10');
 await pages.getByRole('button',{name:'Suivant',exact:true}).click(); await expect(history).toContainText('En attente');
 failRead=true; await page.getByRole('button',{name:'Actualiser l’historique des envois',exact:true}).click(); await expect(page.getByRole('alert').first()).toBeVisible(); await expect(history).not.toContainText('remote.private.details'); await expect(history).toContainText('En attente');
 failRead=false; await page.getByRole('button',{name:'Actualiser l’historique des envois',exact:true}).click(); await expect(history.getByRole('alert')).toHaveCount(0);
 expect(requests.filter(path=>path.endsWith('/notifications/settings'))).toHaveLength(settingsReads);
 await page.getByRole('tab',{name:'Événements',exact:true}).click(); await expect(page.locator('#events-search')).toHaveValue('Retained event filter'); const readCount=reads.length; await openNotificationSection(page,'history'); await expect(history).toContainText('En attente'); expect(reads).toHaveLength(readCount); expect(failures).toEqual([]);
});


test('settings header reload targets the active connection and protects its draft', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 let connectionReads = 0;
 await page.route('**/api/v1/connection-settings/homebridge', route => {
  if (route.request().method() === 'PATCH') {
   return route.fulfill({ status: 422, json: { error: { code: 'auth.errors.validationFailed' }, errors: { 'values.HOMEBRIDGE_URL': ['connectionSettings.invalid'] } } });
  }
  connectionReads++;
  return route.fulfill({ json: { data: { version: 1, fields: [{ name: 'HOMEBRIDGE_URL', type: 'url', value: 'https://bridge.example.test', locked: false, hasValue: true }] } } });
 });
 await openModule(page, 'instanceSettings');
 await page.getByRole('tab', { name: 'Connexions', exact: true }).click();
 await page.getByRole('tab', { name: 'Homebridge', exact: true }).click();
 const input = page.locator('#connection-HOMEBRIDGE_URL');
 await expect(input).toHaveValue('https://bridge.example.test');
 const reload = page.locator('.settings-heading').getByRole('button');
 await reload.click(); await expect.poll(() => connectionReads).toBe(2);
 await (await revealTabbedControl(input)).fill('https://draft.example.test'); await reload.click();
 const discard = page.getByRole('dialog'); await expect(discard).toBeVisible();
 await discard.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await expect(input).toHaveValue('https://draft.example.test'); expect(connectionReads).toBe(2);
 await reload.click(); await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await expect(input).toHaveValue('https://bridge.example.test'); expect(connectionReads).toBe(3);
 expect(failures).toEqual([]);
});

async function catalogEditorSession(page: Page) {
 const session = await syntheticSession(page, 'admin', ['administration.view']);
 let template = { id: 'template-one', name: 'Weekly note', body: 'Original {{ plant.name }}', isArchived: false, version: 1 };
 let maintenance = { id: 'type-one', name: 'Leaf inspection', version: 1 };
 const writes: { kind: string; input: Record<string, unknown> }[] = [];
 await page.route('**/api/v1/comment-templates**', route => {
  const method = route.request().method();
  if (method !== 'GET') { const input = route.request().postDataJSON(); writes.push({ kind: 'template', input }); template = { ...template, ...input, isArchived: input.is_archived, version: template.version + 1 }; return route.fulfill({ json: { data: template } }); }
  return route.fulfill({ json: { data: [template], canManage: true, meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
 });
 await page.route('**/api/v1/maintenance-types**', route => {
  const method = route.request().method();
  if (method !== 'GET') { const input = route.request().postDataJSON(); writes.push({ kind: 'maintenance', input }); maintenance = { ...maintenance, ...input, version: maintenance.version + 1 }; return route.fulfill({ json: { data: maintenance } }); }
  return route.fulfill({ json: { data: [maintenance] } });
 });
 await openModule(page, 'catalogs');
 return { ...session, writes, updateRecords: () => { template = { ...template, name: 'Current template', version: 2 }; maintenance = { ...maintenance, name: 'Current type', version: 2 }; } };
}

test('template editor documents action and plant paths with searchable insertion and examples', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 });
 const { failures, writes } = await catalogEditorSession(page);
 await page.getByRole('button', { name: 'Nouveau modèle', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Créer un modèle de commentaire', exact: true });
 const reference = editor.locator('.variable-reference'); await reference.locator('summary').click();
 await expect(reference).toContainText('avant son enregistrement');
 await (await revealTabbedControl(reference.getByLabel('Catégorie', { exact: true }).and(reference.locator('select')))).selectOption('action');
 await (await revealTabbedControl(reference.getByLabel('Rechercher une variable', { exact: true }))).fill('withProducts');
 await reference.getByRole('button', { name: 'Insérer action.withProducts', exact: true }).click();
 await expect(editor.locator('#comment-template-body')).toHaveValue('{{ action.withProducts }}');
 await expect(editor.locator('pre')).toHaveText('false');
 await (await revealTabbedControl(reference.getByLabel('Catégorie', { exact: true }).and(reference.locator('select')))).selectOption('measurements'); await (await revealTabbedControl(reference.getByLabel('Rechercher une variable', { exact: true }))).fill('plant.measurements.0.unit');
 await expect(reference.getByRole('button', { name: 'Insérer plant.measurements.0.unit', exact: true })).toBeVisible();
 expect(writes).toEqual([]); expect(failures).toEqual([]);
});

test('catalog drafts protect closure and tab selection, and unchanged forms close freely', async ({ page }) => {
 const { failures, writes } = await catalogEditorSession(page);
 const templateTab = page.getByRole('tab', { name: 'Modèles de commentaire', exact: true });
 const maintenanceTab = page.getByRole('tab', { name: 'Types d’entretien', exact: true });
 await page.getByRole('button', { name: 'Nouveau modèle', exact: true }).click();
 let editor = page.getByRole('dialog', { name: 'Créer un modèle de commentaire', exact: true });
 await editor.getByRole('button', { name: 'Créer', exact: true }).click(); await expectControlFocused(page.locator('#comment-template-name'));
 await expect(await revealTabbedControl(page.locator('#comment-template-body-error'))).toBeVisible(); expect(writes).toEqual([]);
 await (await revealTabbedControl(page.locator('#comment-template-name'))).fill('Draft'); await (await revealTabbedControl(page.locator('#comment-template-body'))).fill('Draft {{ plant.name }}');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(editor).toBeVisible();
 await maintenanceTab.evaluate(element => (element as HTMLElement).click());
 await page.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(templateTab).toHaveAttribute('aria-selected', 'true'); await expect(page.locator('#comment-template-body')).toHaveValue('Draft {{ plant.name }}');
 await (await revealTabbedControl(page.locator('#comment-template-name'))).fill(''); await (await revealTabbedControl(page.locator('#comment-template-body'))).fill(''); await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await expect(editor).toHaveCount(0);
 await maintenanceTab.click(); await page.getByRole('button', { name: 'Renommer Leaf inspection', exact: true }).click();
 editor = page.getByRole('dialog', { name: 'Renommer un type d’entretien', exact: true }); const name = editor.getByLabel('Type d’entretien personnalisé', { exact: true });
 await (await revealTabbedControl(name)).fill('Changed'); await templateTab.evaluate(element => (element as HTMLElement).click());
 await page.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(maintenanceTab).toHaveAttribute('aria-selected', 'true'); await expect(name).toHaveValue('Changed');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click(); await page.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0);
 expect(failures).toEqual([]); expect(writes).toEqual([]);
});

test('template field failures and conflicts retain drafts until a successful list reload', async ({ page }) => {
 const { failures, writes, updateRecords } = await catalogEditorSession(page);
 let response: 'fields' | 'conflict' | 'success' = 'fields'; let failRead = false;
 await page.route('**/api/v1/comment-templates**', route => {
  if (route.request().method() === 'GET') return failRead ? route.fulfill({ status: 500, json: { error: { code: 'private.remote.details' } } }) : route.fallback();
  if (response === 'fields') return route.fulfill({ status: 422, json: { error: { code: 'private.remote.details' }, errors: { name: ['commentTemplates.errors.nameTaken'], body: ['Private body diagnostic'] } } });
  if (response === 'conflict') return route.fulfill({ status: 409, json: { error: { code: 'commentTemplates.errors.conflict' } } });
  return route.fallback();
 });
 await page.getByRole('button', { name: 'Modifier Weekly note', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier le modèle de commentaire', exact: true }); const save = editor.getByRole('button', { name: 'Enregistrer', exact: true });
 await (await revealTabbedControl(page.locator('#comment-template-name'))).fill('Draft name'); await (await revealTabbedControl(page.locator('#comment-template-body'))).fill('Draft text'); await save.click();
 await expectControlFocused(page.locator('#comment-template-name')); await expect(editor).not.toContainText('Private body diagnostic'); await expect(page.locator('#comment-template-name-error')).toContainText('nom');
 await (await revealTabbedControl(page.locator('#comment-template-name'))).fill('Corrected'); await expect(page.locator('#comment-template-name-error')).toHaveCount(0); await expect(await revealTabbedControl(page.locator('#comment-template-body-error'))).toBeVisible();
 response = 'conflict'; await save.click(); await expect(save).toBeDisabled(); const reload = editor.getByRole('button', { name: 'Recharger les modèles actuels', exact: true });
 failRead = true; await reload.click(); await page.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.getByRole('alert').first()).toBeVisible(); await expect(page.locator('#comment-template-body')).toHaveValue('Draft text'); await expect(save).toBeDisabled();
 failRead = false; updateRecords(); await reload.click(); await page.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0);
 await page.getByRole('button', { name: 'Modifier Current template', exact: true }).click(); await expect(page.locator('#comment-template-name')).toHaveValue('Current template');
 response = 'success'; await (await revealTabbedControl(page.locator('#comment-template-body'))).fill('Saved current text'); await save.click(); await expect(editor).toHaveCount(0); expect(writes).toHaveLength(1); expect(writes[0]!.input.version).toBe(2); expect(failures).toEqual([]);
});

test('maintenance names show trusted advice and recover current versions without losing rejected drafts', async ({ page }) => {
 const { failures, writes, updateRecords } = await catalogEditorSession(page);
 await page.getByRole('tab', { name: 'Types d’entretien', exact: true }).click();
 let response: 'fields' | 'conflict' | 'success' = 'fields'; let failRead = false;
 await page.route('**/api/v1/maintenance-types**', route => {
  if (route.request().method() === 'GET') return failRead ? route.fulfill({ status: 500, json: { error: { code: 'private.remote.details' } } }) : route.fallback();
  if (response === 'fields') return route.fulfill({ status: 422, json: { error: { code: 'plants.maintenance.nameTaken' }, errors: { name: ['Private name diagnostic'] } } });
  if (response === 'conflict') return route.fulfill({ status: 409, json: { error: { code: 'plants.errors.conflict' } } });
  return route.fallback();
 });
 await page.getByRole('button', { name: 'Renommer Leaf inspection', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Renommer un type d’entretien', exact: true }); const name = editor.getByLabel('Type d’entretien personnalisé', { exact: true }); const save = editor.getByRole('button', { name: /^(?:Enregistrer le type|Enregistrer|Créer)$/, exact: true });
 await (await revealTabbedControl(name)).fill(''); await save.click(); await expectControlFocused(name); expect(writes).toEqual([]);
 await (await revealTabbedControl(name)).fill('Draft inspection'); await save.click(); await expectControlFocused(name); await expect(name).toHaveAttribute('aria-invalid', 'true'); await expect(editor).not.toContainText('Private name diagnostic');
 await (await revealTabbedControl(name)).fill('Corrected inspection'); await expect(name).toHaveAttribute('aria-invalid', 'false'); response = 'conflict'; await save.click(); await expect(save).toBeDisabled();
 const reload = editor.getByRole('button', { name: 'Recharger la liste', exact: true }); failRead = true; await reload.click(); await page.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.getByRole('alert').first()).toBeVisible(); await expect(name).toHaveValue('Corrected inspection'); await expect(save).toBeDisabled();
 failRead = false; updateRecords(); await reload.click(); await page.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0);
 await page.getByRole('button', { name: 'Renommer Current type', exact: true }).click(); response = 'success'; await (await revealTabbedControl(name)).fill('Saved inspection'); await save.click(); await expect(editor).toHaveCount(0); expect(writes).toHaveLength(1); expect(writes[0]!.input.version).toBe(2); expect(failures).toEqual([]);
});

test('pending catalog writes block departure and successful saves are not replayed after failed refresh', async ({ page }) => {
 const { failures, writes } = await catalogEditorSession(page);
 await page.getByRole('button', { name: 'Modifier Weekly note', exact: true }).click(); await (await revealTabbedControl(page.locator('#comment-template-body'))).fill('Pending text');
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false; let saved = false;
 await page.route('**/api/v1/comment-templates**', async route => {
  if (route.request().method() === 'PATCH') { writing = true; await pending; saved = true; return route.fallback(); }
  if (saved) return route.fulfill({ status: 500, json: { error: { code: 'private.remote.details' } } }); return route.fallback();
 });
 const editor = page.getByRole('dialog', { name: 'Modifier le modèle de commentaire', exact: true }); await editor.getByRole('button', { name: 'Enregistrer', exact: true }).click();
 try {
  await expect.poll(() => writing).toBe(true); await expect(editor.getByRole('button', { name: 'Annuler', exact: true })).toBeDisabled();
  await page.getByRole('tab', { name: 'Types d’entretien', exact: true }).evaluate(element => (element as HTMLElement).click()); await expect(page.getByRole('tab', { name: 'Modèles de commentaire', exact: true })).toHaveAttribute('aria-selected', 'true');
  await page.goBack(); await expect(page).toHaveURL(/\/settings\/catalogs$/);
  let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true);
 } finally { release(); }
 await expect(editor).toHaveCount(0); await expect(page.getByRole('alert').first()).toBeVisible(); expect(writes).toHaveLength(1);
 await page.locator('.settings-heading').getByRole('button', { name: 'Recharger la liste', exact: true }).click(); expect(writes).toHaveLength(1); expect(failures).toEqual([]);
});

test('maintenance deletion blocks tab and browser departure even without an editable draft', async ({ page }) => {
 const { failures, writes } = await catalogEditorSession(page);
 await page.getByRole('tab', { name: 'Types d’entretien', exact: true }).click();
 await page.getByRole('button', { name: 'Supprimer Leaf inspection', exact: true }).click();
 let release: () => void = () => {}; const pending = new Promise<void>(resolve => { release = resolve; }); let writing = false;
 await page.route('**/api/v1/maintenance-types/*', async route => { if (route.request().method() === 'DELETE') { writing = true; await pending; } return route.fallback(); });
 const editor = page.getByRole('dialog', { name: 'Supprimer un type d’entretien', exact: true }); await editor.getByRole('button', { name: 'Supprimer', exact: true }).click();
 try {
  await expect.poll(() => writing).toBe(true); await expect(editor.getByRole('button', { name: 'Annuler', exact: true })).toBeDisabled();
  await page.getByRole('tab', { name: 'Modèles de commentaire', exact: true }).evaluate(element => (element as HTMLElement).click()); await expect(page.getByRole('tab', { name: 'Types d’entretien', exact: true })).toHaveAttribute('aria-selected', 'true');
  await page.goBack(); await expect(page).toHaveURL(/\/settings\/catalogs\/maintenance-types$/);
  let warned = false; page.once('dialog', async prompt => { warned = prompt.type() === 'beforeunload'; await prompt.dismiss(); }); await page.reload({ timeout: 1500 }).catch(() => null); expect(warned).toBe(true);
 } finally { release(); }
 await expect(editor).toHaveCount(0); expect(writes).toHaveLength(1); expect(writes[0]!.input.version).toBe(1); expect(failures).toEqual([]);
});

test('settings contains lifecycle and catalogs with contextual actions and retained drafts across tabs', async ({ page }) => {
 const { failures, writes } = await catalogEditorSession(page);
 const settings = page.getByTestId('instance-settings');
 await expect(page.getByTestId('nav-item-cycleSettings')).toHaveCount(0); await expect(page.getByTestId('nav-item-catalogs')).toHaveCount(0);
 await expect(settings.getByRole('heading', { name: 'Réglages', exact: true })).toHaveCount(1);
 await expect(settings.locator('.settings-heading').getByRole('button', { name: 'Nouveau modèle', exact: true })).toBeVisible();
 await settings.locator('.settings-heading').getByRole('button', { name: 'Nouveau modèle', exact: true }).click();
 const template = page.getByRole('dialog', { name: 'Créer un modèle de commentaire', exact: true }); await expect(template).toBeVisible(); await template.getByRole('button', { name: 'Annuler', exact: true }).click();
 await settings.locator('.settings-tabs > .n-tabs-nav').getByRole('tab', { name: 'Cycle', exact: true }).click();
 await expect(page.locator('#cycle-watering-days')).toHaveValue('3');
 await (await revealTabbedControl(page.locator('#cycle-watering-days'))).fill('0'); await expect(settings.locator('.settings-heading').getByRole('button', { name: 'Enregistrer le cycle', exact: true })).toHaveCount(0);
 await settings.locator('.settings-tabs > .n-tabs-nav').getByRole('tab', { name: 'Général', exact: true }).click();
 await settings.locator('.settings-heading').getByRole('button', { name: 'Recharger les paramètres', exact: true }).click();
 await expect(page.locator('#instance-site-title')).toHaveValue('Canabicultarr');
 await settings.locator('.settings-tabs > .n-tabs-nav').getByRole('tab', { name: 'Cycle', exact: true }).click(); await expect(page.locator('#cycle-watering-days')).toHaveValue('0');
 await page.getByTestId('nav-home').click(); const discard = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }); await expect(discard).toBeVisible(); await discard.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await settings.locator('.settings-heading').getByRole('button', { name: 'Recharger les paramètres du cycle', exact: true }).click(); await discard.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(page.locator('#cycle-watering-days')).toHaveValue('3');
 await settings.locator('.settings-heading').getByRole('button', { name: 'Ajouter une étape', exact: true }).click(); await expect(page.getByRole('dialog', { name: 'Modifier l’étape', exact: true })).toBeVisible(); await page.keyboard.press('Escape');
 await settings.locator('.settings-tabs > .n-tabs-nav').getByRole('tab', { name: 'Modèles et types', exact: true }).click(); await page.getByRole('tab', { name: 'Types d’entretien', exact: true }).click();
 await settings.locator('.settings-heading').getByRole('button', { name: 'Ajouter un type', exact: true }).click(); await expect(page.getByRole('dialog', { name: 'Ajouter un type d’entretien', exact: true })).toBeVisible(); await page.keyboard.press('Escape');
 expect(writes).toEqual([]); expect(failures).toEqual([]);
});

test('nested settings direct links restore the section and keep administrator-only sections protected', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 await page.goto('/settings/lifecycle'); await expect(page.locator('#cycle-flush')).toBeVisible(); await expect(page.getByTestId('nav-item-instanceSettings')).toHaveAttribute('aria-current', 'page');
 await page.reload(); await expect(page.locator('#cycle-flush')).toBeVisible(); await expect(page.getByRole('tab', { name: 'Cycle', exact: true })).toHaveAttribute('aria-selected', 'true');
 await page.goto('/settings/catalogs/maintenance-types');
 await expect(page.getByRole('tab', { name: 'Types d’entretien', exact: true })).toHaveAttribute('aria-selected', 'true');
 await expect(page.getByRole('tab', { name: 'Modèles de commentaire', exact: true })).toBeVisible();
 const breadcrumb = page.getByRole('navigation', { name: 'Fil d’Ariane', exact: true });
 await expect(breadcrumb.locator('.breadcrumb-link')).toHaveText(['Administration', 'Réglages']);
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis Réglages', exact: true }).click();
 await expect(page.getByRole('menuitem')).toHaveText(['Réglages', 'Utilisateurs', 'Inventaire', 'Fichiers']);
 await page.keyboard.press('Escape');
 await page.getByRole('tab', { name: 'Cycle', exact: true }).click();
 await expect(page).toHaveURL(/\/settings\/lifecycle$/);
 await expect(page.locator('#cycle-flush')).toBeVisible();
 await page.goBack();
 await expect(page.getByRole('tab', { name: 'Types d’entretien', exact: true })).toHaveAttribute('aria-selected', 'true');
 await page.goForward();
 await expect(page.locator('#cycle-flush')).toBeVisible();
 await expect(breadcrumb.locator('.breadcrumb-link')).toHaveText(['Administration', 'Réglages']);

 await syntheticSession(page, 'member', []); await page.goto('/settings/lifecycle'); await expect(page).toHaveURL(/\/$/);
 await openModule(page, 'instanceSettings'); await expect(page.getByRole('tab', { name: 'Cycle', exact: true })).toHaveCount(0); await expect(page.getByRole('tab', { name: 'Modèles et types', exact: true })).toHaveCount(0);
 expect(failures).toEqual([]);
});

async function ingestionEditorSession(page: Page, kind: 'http' | 'mqtt') {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view', 'integrations.manage']);
 const sensor = { id: 'sensor-one', name: 'Fictional manual sensor', isEnabled: true, device: { id: 'device-one', name: 'Fictional hardware', status: 'active' }, measurementType: { unit: '%', name: 'Moisture' } };
 const state = { listFails: false, reject: '', version: 1, writes: [] as { path: string; input: Record<string, unknown> }[], hold: null as Promise<void> | null };
 let record = { id: 'source-one', sensor, version: 1, isEnabled: true, expiresAt: '2027-01-01T12:00:00Z', credentialCurrent: true, lastReceivedAt: null, topic: 'fictional/moisture', instanceCurrent: true, lastAttemptAt: null, lastError: null };
 await page.route('**/api/v1/sensors?**', route => route.fulfill({ json: { data: [sensor], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } }));
 await page.route(`**/api/v1/integrations/${kind}**`, async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/mqtt')) return route.fulfill({ json: { data: { configured: true } } });
  if (route.request().method() === 'GET') {
   if (state.listFails) return route.fulfill({ status: 503, json: { error: { code: 'untrusted-provider-detail' } } });
   record.version = state.version;
   return route.fulfill({ json: { data: [record], meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 } } });
  }
  const input = route.request().postDataJSON(); state.writes.push({ path, input });
  if (state.hold) await state.hold;
  if (state.reject === 'fields') return route.fulfill({ status: 422, json: { error: { code: 'untrusted-provider-detail' }, errors: { [kind === 'http' ? 'expires_days' : 'topic']: ['untrusted-provider-detail'] } } });
  if (state.reject === 'conflict') return route.fulfill({ status: 409, json: { error: { code: `${kind === 'http' ? 'httpIngestion' : 'mqtt'}.errors.conflict` } } });
  state.version++; record = { ...record, version: state.version, isEnabled: input.is_enabled === undefined ? true : Boolean(input.is_enabled) };
  return route.fulfill({ json: { data: kind === 'http' ? { source: record, token: route.request().method() === 'PATCH' ? null : `synthetic-token-${state.version}` } : record } });
 });
 await openModule(page, kind);
 const card = page.locator(kind === 'http' ? '.http-card' : '.mqtt-card');
 const add = page.getByRole('button', { name: kind === 'http' ? 'Ajouter une source HTTP' : 'Associer un topic', exact: true });
 await expect(add).toBeEnabled();
 return { failures, state, card, add, sensor };
}

for (const kind of ['http', 'mqtt'] as const) test(`${kind} source editor protects actual drafts and provides trusted local and API field advice`, async ({ page }) => {
 const { failures, state, add } = await ingestionEditorSession(page, kind);
 const editor = page.locator('.form-dialog'); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 const submit = editor.locator('button[type=submit]'); const input = page.locator(kind === 'http' ? '#http-expiry-days' : '#mqtt-topic');
 await add.click(); await page.keyboard.press('Escape'); await expect(editor).toHaveCount(0); await expect(warning).toHaveCount(0);
 await add.click(); await (await revealTabbedControl(input)).fill(kind === 'http' ? '30' : 'fictional/draft'); await page.keyboard.press('Escape'); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click();
 await page.goBack(); await expect(warning).toBeVisible(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(input).toHaveValue(kind === 'http' ? '30' : 'fictional/draft');
 await (await revealTabbedControl(editor.locator(kind === 'http' ? '#httpIngestion-sensor' : '#mqtt-sensor'))).selectOption('sensor-one');
 await (await revealTabbedControl(input)).fill(kind === 'http' ? '1.5' : 'fictional/+/moisture'); await submit.click(); await expect(input).toHaveAttribute('aria-invalid', 'true'); expect(state.writes).toEqual([]);
 await (await revealTabbedControl(input)).fill(kind === 'http' ? '45' : 'fictional/moisture'); await expect(input).toHaveAttribute('aria-invalid', 'false'); state.reject = 'fields'; await submit.click(); await expect(input).toHaveAttribute('aria-invalid', 'true'); await expect(editor).not.toContainText('untrusted-provider-detail'); await expect(input).toHaveValue(kind === 'http' ? '45' : 'fictional/moisture');
 await page.keyboard.press('Escape'); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(editor).toHaveCount(0);
 expect(failures).toEqual([]);
});

test('HTTP source keeps its one-time credential when a successful create or renewal is followed by a failed list refresh', async ({ page }) => {
 const { failures, state, add, card } = await ingestionEditorSession(page, 'http');
 await add.click(); const editor = page.locator('.form-dialog'); await (await revealTabbedControl(editor.locator('#httpIngestion-sensor'))).selectOption('sensor-one'); state.listFails = true;
 await editor.locator('button[type=submit]').click(); await expect(editor).toHaveCount(0); await expect(page.locator('#http-one-time-token')).toHaveValue('synthetic-token-2');
 await expect(page.getByRole('alert').filter({ hasText: 'sans répéter l’action' }).first()).toBeVisible(); expect(state.writes).toHaveLength(1);
 state.listFails = false; await card.getByRole('button', { name: 'Recharger les sources', exact: true }).click(); await expect(page.locator('#http-one-time-token')).toHaveValue('synthetic-token-2');
 await card.getByRole('button', { name: 'Renouveler le jeton de Fictional manual sensor', exact: true }).click();
 await expect(editor.locator('#http-expiry-days')).toHaveValue('90'); await expect(editor.locator('#httpIngestion-sensor')).toHaveCount(0); await (await revealTabbedControl(editor.locator('#http-expiry-days'))).fill('30'); state.listFails = true;
 await editor.getByRole('button', { name: 'Renouveler le jeton', exact: true }).click(); await expect(editor).toHaveCount(0); await expect(page.locator('#http-one-time-token')).toHaveValue('synthetic-token-3'); expect(state.writes[1]!.input).toEqual({ version: 2, expires_days: 30 });
 expect(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).not.toContain('synthetic-token-');
 await card.getByRole('button', { name: 'Masquer le jeton', exact: true }).click(); await expect(page.locator('#http-one-time-token')).toHaveCount(0);
 expect(state.writes).toHaveLength(2); expect(failures).toEqual([]);
});

for (const kind of ['http', 'mqtt'] as const) test(`${kind} source conflict blocks stale row actions until a successful reload adopts the current version`, async ({ page }) => {
 const { failures, state, card } = await ingestionEditorSession(page, kind);
 const pause = card.getByRole('button', { name: kind === 'http' ? 'Mettre Fictional manual sensor en pause' : 'Suspendre Fictional manual sensor', exact: true });
 const reload = card.getByRole('button', { name: kind === 'http' ? 'Recharger les sources' : 'Recharger', exact: true });
 const response = (method: string, status: number) => page.waitForResponse(result => result.request().method() === method && result.url().includes(`/integrations/${kind}/sources`) && result.status() === status);
 state.reject = 'conflict'; const rejected = response('PATCH', 409); await pause.click(); await rejected; await expect(reload).toBeEnabled(); await expect(pause).toBeDisabled();
 state.listFails = true; const failedRead = response('GET', 503); await reload.click(); await failedRead; await expect(reload).toBeEnabled(); await expect(pause).toBeDisabled();
 state.listFails = false; state.reject = ''; state.version = 7; const refreshed = response('GET', 200); await reload.click(); await refreshed; await expect(pause).toBeEnabled(); await pause.click(); await expect(card.getByRole('button', { name: 'Reprendre Fictional manual sensor', exact: true })).toBeVisible();
 expect(state.writes).toHaveLength(2); expect(state.writes[1]!.input.version).toBe(7); expect(failures).toEqual([]);
});

test('HTTP renewal keeps its draft and stale protection until a confirmed successful reload', async ({ page }) => {
 const { failures, state, card } = await ingestionEditorSession(page, 'http');
 await card.getByRole('button', { name: 'Renouveler le jeton de Fictional manual sensor', exact: true }).click();
 const editor = page.locator('.form-dialog'); const days = editor.locator('#http-expiry-days'); const submit = editor.getByRole('button', { name: 'Renouveler le jeton', exact: true }); const warning = page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true });
 await (await revealTabbedControl(days)).fill('30'); state.reject = 'conflict'; await submit.click(); await expect(submit).toBeDisabled();
 const reload = editor.getByRole('button', { name: 'Recharger les sources', exact: true }); await reload.click(); await warning.getByRole('button', { name: 'Continuer à modifier', exact: true }).click(); await expect(days).toHaveValue('30');
 state.listFails = true; await reload.click(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(days).toHaveValue('30'); await expect(submit).toBeDisabled();
 state.listFails = false; state.reject = ''; state.version = 9; await reload.click(); await warning.getByRole('button', { name: 'Abandonner les modifications', exact: true }).click(); await expect(days).toHaveValue('90'); await expect(submit).toBeEnabled(); await submit.click(); await expect(editor).toHaveCount(0); expect(state.writes[1]!.input.version).toBe(9); expect(failures).toEqual([]);
});

test('MQTT source creation blocks departure during its write and is not replayed after a refresh failure', async ({ page }) => {
 const { failures, state, add, card } = await ingestionEditorSession(page, 'mqtt');
 await add.click(); const editor = page.locator('.form-dialog'); await (await revealTabbedControl(editor.locator('#mqtt-sensor'))).selectOption('sensor-one'); await (await revealTabbedControl(editor.locator('#mqtt-topic'))).fill('fictional/new');
 let release!: () => void; state.hold = new Promise<void>(resolve => { release = resolve; });
 try {
  await editor.locator('button[type=submit]').click(); await expect.poll(() => state.writes.length).toBe(1); await page.keyboard.press('Escape'); await expect(editor).toBeVisible(); await page.goBack(); await expect(editor).toBeVisible();
  let warned = false; page.once('dialog', async dialog => { warned = dialog.type() === 'beforeunload'; await dialog.dismiss(); }); await page.reload({ timeout: 1000 }).catch(() => null); expect(warned).toBe(true); state.listFails = true;
 } finally { release(); }
 await expect(editor).toHaveCount(0); await expect(page.getByRole('alert').filter({ hasText: 'sans répéter l’action' }).first()).toBeVisible(); state.listFails = false; await card.getByRole('button', { name: 'Recharger', exact: true }).click(); await expect(card.getByRole('button', { name: 'Suspendre Fictional manual sensor', exact: true })).toBeEnabled(); expect(state.writes).toHaveLength(1); expect(failures).toEqual([]);
});

async function referenceCatalogSession(page: Page) {
 const session = await ingestionEditorSession(page, 'http');
 const requests: { search: string; page: number }[] = [];
 const state = { fail: false, deny: false, hold: null as Promise<void> | null };
 const catalog = Array.from({ length: 2000 }, (_, i) => ({ ...session.sensor, id: `sensor-${i + 1}`, name: `Fictional sensor ${i + 1}`, isEnabled: i !== 1 }));
 await page.route('**/api/v1/sensors?**', async route => {
  const url = new URL(route.request().url()); const search = url.searchParams.get('search') ?? ''; const current = Number(url.searchParams.get('page') ?? 1);
  requests.push({ search, page: current });
  if (search === 'slow' && state.hold) await state.hold;
  await new Promise(resolve => setTimeout(resolve, 8));
  if (state.deny) return route.fulfill({ status: 403, json: { error: { code: 'untrusted-provider-detail' } } });
  if (state.fail) return route.fulfill({ status: 503, json: { error: { code: 'untrusted-provider-detail' } } });
  const filtered = catalog.filter(row => row.name.toLowerCase().includes(search.toLowerCase()));
  await route.fulfill({ json: { data: filtered.slice((current - 1) * 20, current * 20), meta: { current_page: current, last_page: Math.max(1, Math.ceil(filtered.length / 20)), per_page: 20, total: filtered.length } } });
 });
 return { ...session, state, requests };
}

test('reference catalog workload stays bounded and supports keyboard paging and remote search', async ({ page }) => {
 const { failures, add, requests } = await referenceCatalogSession(page);
 const started = Date.now(); await add.click(); const select = page.locator('#httpIngestion-sensor'); await expect(select).toBeEnabled();
 console.log(JSON.stringify({ referenceWorkload: { records: 2000, requests: requests.length, options: await select.locator('option').count(), readyMs: Date.now() - started } }));
 expect(requests).toHaveLength(1); await expect(select.locator('option')).toHaveCount(21); await expect(select.locator('option[value="sensor-2"]')).toBeDisabled();
 await openSelect(select); const filter = page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }); await expectControlFocused(filter);
 await page.keyboard.press('Tab'); await expectControlFocused(page.getByRole('button', { name: 'Afficher plus', exact: true })); await page.keyboard.press('Enter');
 await expect(select.locator('option')).toHaveCount(41); expect(requests).toHaveLength(2); await expectControlFocused(page.getByRole('button', { name: 'Afficher plus', exact: true }));
 await page.keyboard.press('Shift+Tab'); await expectControlFocused(filter); await (await revealTabbedControl(filter)).fill('1999'); await expect(page.locator('[data-select-popup]').getByRole('option', { name: /Fictional sensor 1999/ })).toBeVisible(); await page.locator('[data-select-popup]').getByRole('option', { name: /Fictional sensor 1999/ }).click(); await expect(select).toHaveValue('sensor-1999');
 await openSelect(select); await (await revealTabbedControl(filter)).fill('no matching sensor'); await expect.poll(() => requests.at(-1)?.search).toBe('no matching sensor'); await expect(select.locator('option')).toHaveCount(2); await expect(select).toHaveValue('sensor-1999');
 await page.keyboard.press('Escape'); expect(failures).toEqual([]);
});

test('reference searches ignore stale responses and preserve choices across retry and access loss', async ({ page }) => {
 const { failures, add, requests, state } = await referenceCatalogSession(page); await add.click(); const select = page.locator('#httpIngestion-sensor'); await expect(select).toBeEnabled(); await (await revealTabbedControl(select)).selectOption('sensor-1');
 await openSelect(select); const filter = page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }); state.hold = new Promise<void>(resolve => { setTimeout(resolve, 1000); });
 const slowResponse = page.waitForResponse(response => new URL(response.url()).searchParams.get('search') === 'slow'); await (await revealTabbedControl(filter)).fill('slow'); await expect.poll(() => requests.at(-1)?.search).toBe('slow'); await (await revealTabbedControl(filter)).fill('1999'); await expect(select.locator('option[value="sensor-1999"]')).toHaveCount(1); await slowResponse;
 await expect(select).toHaveValue('sensor-1'); await expect(select.locator('option[value="sensor-1999"]')).toHaveCount(1);
 state.fail = true; await (await revealTabbedControl(filter)).fill('1988'); const reference = page.locator('.reference-select'); await expect(reference.getByRole('alert')).toBeVisible(); await expect(reference).not.toContainText('untrusted-provider-detail'); await expect(select).toHaveValue('sensor-1');
 state.fail = false; await reference.getByRole('button', { name: 'Recharger la liste', exact: true }).click(); await expect(select.locator('option[value="sensor-1988"]')).toBeEnabled(); await expect(select).toHaveValue('sensor-1');
 state.deny = true; await openSelect(select); await (await revealTabbedControl(page.getByRole('textbox', { name: 'Filtrer les options…', exact: true }))).fill('access revoked'); await expect(page.locator('.form-dialog')).toHaveCount(0); expect(failures).toEqual([]);
});

test('mobile reference catalog searches and loads additional pages inside the styled selector', async ({ page }) => {
 await page.setViewportSize({ width: 390, height: 844 }); const { failures, add, requests } = await referenceCatalogSession(page); await add.click(); const select = page.locator('#httpIngestion-sensor'); await expect(select).toBeEnabled(); await expect(select).toHaveAttribute('data-select-mode', 'custom'); expect(requests).toHaveLength(1);
 await openSelect(select);
 const popup = page.locator('[data-select-popup]');
 await popup.getByRole('button', { name: 'Afficher plus', exact: true }).click();
 await expect(select.locator('option')).toHaveCount(41);
 await popup.getByRole('option', { name: /^Fictional sensor 1 ·/ }).click();
 await expect(select).toHaveValue('sensor-1');
 await openSelect(select);
 await popup.getByRole('textbox').fill('1999');
 await expect(select).toHaveValue('sensor-1');
 await popup.getByRole('option', { name: /^Fictional sensor 1999 ·/ }).click();
 await expect(select).toHaveValue('sensor-1999');
 await expect(popup).toHaveCount(0);
 expect(failures).toEqual([]);
});


test('breadcrumb neighbor menus navigate workspaces, sibling pages and grouped sections on mobile', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', []);
 const breadcrumb = page.getByRole('navigation', { name: 'Fil d’Ariane', exact: true });
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis Tableau de bord', exact: true }).click();
 await page.getByRole('menuitem', { name: 'Culture', exact: true }).click();
 await expect(page).toHaveURL(/\/plants(?:\?|$)/);
 const choose = breadcrumb.getByRole('button', { name: 'Choisir une page depuis Plantes', exact: true });
 await page.mouse.move(0, 0);
 await choose.focus(); await page.keyboard.press('ArrowDown');
 await expect(choose).toHaveAttribute('aria-expanded', 'true');
 await expect(page.getByRole('menuitem', { name: 'Variétés', exact: true })).toBeVisible();
 await page.keyboard.press('Escape'); await expect(choose).toHaveAttribute('aria-expanded', 'false');
 await expectControlFocused(choose);
 await page.keyboard.press('Enter');
 await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
 await expect(page).toHaveURL(/\/strains(?:\?|$)/);
 await expect(breadcrumb.locator('[aria-current=page]')).toHaveText('Variétés');
 await openModule(page, 'mqtt');
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis MQTT', exact: true }).click();
 await page.getByRole('menuitem', { name: 'HTTP', exact: true }).click();
 await expect(page).toHaveURL(/\/integrations\/http(?:\?|$)/);
 await openModule(page, 'profiles');
 await breadcrumb.locator('.breadcrumb-segment').nth(1).getByRole('button', { name: /^Choisir une page/ }).click();
 await page.getByRole('menuitem', { name: 'Inventaire', exact: true }).click();
 await expect(page).toHaveURL(/\/inventory(?:\?|$)/);
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis Inventaire', exact: true }).click();
 await expect(page.getByRole('menuitem', { name: 'Fichiers', exact: true })).toBeInViewport();
 await expect.poll(() => page.getByRole('menuitem', { name: 'Fichiers', exact: true }).evaluate(el => {
  let opacity = 1; let current: Element | null = el;
  while (current) { opacity *= Number(getComputedStyle(current).opacity); current = current.parentElement; }
  return opacity;
 })).toBe(1);
 await page.screenshot({ path: test.info().outputPath('breadcrumb-neighbors-desktop.png') });
 await page.keyboard.press('Escape');
 await page.setViewportSize({ width: 390, height: 844 });
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis Inventaire', exact: true }).click();
 await expect(page.getByRole('menuitem', { name: 'Fichiers', exact: true })).toBeInViewport();
 await expect.poll(() => page.getByRole('menuitem', { name: 'Fichiers', exact: true }).evaluate(el => {
  let opacity = 1; let current: Element | null = el;
  while (current) { opacity *= Number(getComputedStyle(current).opacity); current = current.parentElement; }
  return opacity;
 })).toBe(1);
 await page.screenshot({ path: test.info().outputPath('breadcrumb-neighbors-mobile.png') });
 await page.getByRole('menuitem', { name: 'Fichiers', exact: true }).click();
 await expect(page).toHaveURL(/\/files(?:\?|$)/);
 expect(await breadcrumb.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
 expect(failures).toEqual([]);
});

test('breadcrumb menus expose only the workspaces and sections allowed for the user', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'member', []);
 const breadcrumb = page.getByRole('navigation', { name: 'Fil d’Ariane', exact: true });
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis Tableau de bord', exact: true }).click();
 await expect(page.getByRole('menuitem', { name: 'Intégrations', exact: true })).toHaveCount(0);
 await page.getByRole('menuitem', { name: 'Administration', exact: true }).click();
 await expect(page).toHaveURL(/\/settings(?:\?|$)/);
 await breadcrumb.getByRole('button', { name: 'Choisir une page depuis Réglages', exact: true }).click();
 await expect(page.getByRole('menuitem')).toHaveText(['Réglages', 'Inventaire', 'Fichiers']);
 await page.keyboard.press('Escape');
 expect(failures).toEqual([]);
});

test('data settings distinguish one-time deletion from daily retention without changing the rule before confirmation', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', []);
 let policyDays: number | null = null; let version = 1;
 const previews: { days: number; mode: string; version: number }[] = [];
 const confirmations: string[] = [];
 await page.route('**/api/v1/measurement-retention**', async route => {
  const path = new URL(route.request().url()).pathname;
  const input = route.request().method() === 'GET' ? null : route.request().postDataJSON();
  if (path.endsWith('/preview')) {
   previews.push(input);
   return route.fulfill({ json: { data: { token: input.mode, mode: input.mode, days: input.days, cutoffAt: '2026-10-01T00:00:00Z', count: 4, countIsExact: true, expiresAt: '2026-12-01T00:00:00Z' } } });
  }
  if (path.endsWith('/confirm')) {
   expect(input.confirmed).toBe(true); confirmations.push(input.token);
   if (input.token === 'policy') { policyDays = previews.at(-1)!.days; version++; }
   return route.fulfill({ json: { data: {} } });
  }
  if (route.request().method() === 'PATCH') { expect(input.version).toBe(version); policyDays = null; version++; }
  return route.fulfill({ json: { data: { policy: { days: policyDays, version, approvedAt: null }, runs: [] } } });
 });
 await openModule(page, 'instanceSettings');
 await page.getByRole('tab', { name: 'Données', exact: true }).click();
 const automatic = page.getByRole('region', { name: 'Conservation automatique', exact: true });
 const manual = page.getByRole('region', { name: 'Purge ponctuelle', exact: true });
 await expect(automatic.getByText('Conservation illimitée')).toBeVisible();
 await expect(manual.getByText(/une seule fois/)).toBeVisible();
 await manual.getByRole('button', { name: 'Purger les anciennes données' }).click();
 let dialog = page.getByRole('dialog', { name: 'Purger les anciennes données' });
 await expect(dialog.getByText(/reste inchangée/)).toBeVisible();
 await expect(dialog.getByRole('button', { name: 'Confirmer la purge' })).toHaveCount(0);
 await dialog.getByRole('button', { name: 'Voir l’aperçu' }).click();
 await expect.poll(() => previews.at(-1)).toEqual({ days: 30, mode: 'manual', version: 1 });
 await dialog.getByRole('button', { name: 'Confirmer la purge' }).click();
 await expect(dialog).toHaveCount(0);
 await expect(automatic.getByText('Conservation illimitée')).toBeVisible();
 await automatic.getByRole('button', { name: 'Configurer', exact: true }).click();
 dialog = page.getByRole('dialog', { name: 'Conservation automatique' });
 await expect(dialog.getByText(/purge immédiate/)).toBeVisible();
 await (await revealTabbedControl(dialog.getByLabel('Ancienneté en jours'))).fill('60');
 await dialog.getByRole('button', { name: 'Voir l’aperçu' }).click();
 await expect.poll(() => previews.at(-1)).toEqual({ days: 60, mode: 'policy', version: 1 });
 expect(policyDays).toBeNull();
 await dialog.getByRole('button', { name: 'Activer et purger' }).click();
 await expect(automatic.getByText('Conserver 60 jours')).toBeVisible();
 expect(confirmations).toEqual(['manual', 'policy']);
 await automatic.getByRole('button', { name: 'Tout conserver' }).click();
 await expect(automatic.getByText('Conservation illimitée')).toBeVisible();
 expect(failures).toEqual([]);
});

test('about settings show technologies, safe project links and truthful update states with retry', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', []);
 let status = 'available'; let fail = false; let reads = 0;
 await page.route('**/api/v1/application-info', async route => {
  reads++;
  if (fail) return route.fulfill({ status: 503, json: { error: { code: 'about.unavailable' } } });
  return route.fulfill({ json: { data: { version: '0.1.0', repositoryUrl: 'https://github.com/kinouzero/canabicultarr', runtime: { php: '8.5.12', laravel: '13.0.0' }, update: { status, latestVersion: status === 'available' ? '0.2.0' : null, releaseUrl: status === 'available' ? 'https://github.com/kinouzero/canabicultarr/releases/tag/v0.2.0' : null, prerelease: false, checkedAt: '2026-11-16T12:00:00Z' } } } });
 });
 await openModule(page, 'instanceSettings');
 expect(reads).toBe(0);
 await page.getByRole('tab', { name: 'À propos', exact: true }).click();
 const about = page.getByRole('region', { name: 'À propos', exact: true });
 const reload = page.getByTestId('instance-settings').locator('.settings-heading').getByRole('button', { name: 'Recharger les paramètres', exact: true });
 await reload.click();
 await expect(about.getByText('Mise à jour disponible', { exact: true })).toBeVisible();
 await expect(about.getByRole('link', { name: 'GitHub', exact: true })).toHaveAttribute('href', 'https://github.com/kinouzero/canabicultarr');
 await expect(about.getByRole('link', { name: 'Notes de version' })).toHaveAttribute('href', 'https://github.com/kinouzero/canabicultarr/releases/tag/v0.2.0');
 await expect(about.getByRole('link', { name: 'Naive UI', exact: true })).toBeVisible();
 await expect(about.getByRole('link', { name: 'Laravel', exact: true })).toBeVisible();
 await expect(about.getByRole('heading', { name: 'Technologies', exact: true })).toBeVisible();

 status = 'noRelease'; await reload.click();
 await expect(about.getByText('Aucune release publiée', { exact: true })).toBeVisible();
 await expect(about.getByRole('link', { name: 'Notes de version' })).toHaveCount(0);
 status = 'unavailable'; await reload.click();
 await expect(about.getByText('Vérification indisponible', { exact: true })).toBeVisible();
 fail = true; await reload.click();
 const failureMessage = page.locator('.feedback-stack .n-notification').filter({hasText:'Impossible de charger'});
 await expect(failureMessage).toBeVisible();
 await failureMessage.getByRole('button', {name:'close',exact:true}).focus();
 await page.keyboard.press('Enter'); await expect(failureMessage).toHaveCount(0);
 await reload.click(); await expect(failureMessage).toBeVisible();
 fail = false; status = 'current'; await reload.click();
 await expect(about.getByText('À jour', { exact: true })).toBeVisible();
 expect(failures).toEqual([]);
});

test('footer update alert opens About, shares checks, refreshes after expiry and clears unavailable results', async ({ page }) => {
 await page.clock.install();
 const { failures } = await syntheticSession(page, 'member', []);
 let status: 'available' | 'current' = 'available'; let fail = false; let reads = 0;
 await page.route('**/api/v1/application-info', async route => {
  reads++;
  if (fail) return route.fulfill({ status: 503, json: { error: { code: 'about.unavailable' } } });
  return route.fulfill({ json: { data: applicationInfoFixture(status) } });
 });
 await page.reload();
 const footer = page.getByRole('contentinfo');
 const alert = footer.getByRole('button', { name: 'La version 0.2.0 est disponible. Ouvrir À propos.', exact: true });
 await expect(alert).toBeVisible(); expect(reads).toBe(1);

 await alert.click();
 await expect(page.getByRole('tab', { name: 'À propos', exact: true })).toHaveAttribute('aria-selected', 'true');
 const about = page.getByRole('region', { name: 'À propos', exact: true });
 await expect(about.getByText('Mise à jour disponible', { exact: true })).toBeVisible(); expect(reads).toBe(1);
 await page.getByRole('tab', {name:'Général',exact:true}).click();
 await alert.click();
 await expect(page.getByRole('tab', {name:'À propos',exact:true})).toHaveAttribute('aria-selected','true');
 expect(reads).toBe(1);
 const reload = page.getByTestId('instance-settings').locator('.settings-heading').getByRole('button', { name: 'Recharger les paramètres', exact: true });
 status = 'current'; await reload.click(); await expect(alert).toHaveCount(0); expect(reads).toBe(2);
 status = 'available'; await reload.click(); await expect(alert).toBeVisible();
 fail = true; await reload.click(); await expect(alert).toHaveCount(0);
 fail = false; await reload.click(); await expect(alert).toBeVisible();
 const previousReads = reads; status = 'current';
 await page.clock.fastForward(11 * 60 * 60 * 1000);
 expect(reads).toBe(previousReads);
 await page.clock.fastForward(60 * 60 * 1000);
 await expect(alert).toHaveCount(0); expect(reads).toBe(previousReads + 1);
 expect(failures).toEqual([]);
});

test('hardware editing tabs support keyboard navigation, conditional controls and mobile drafts without writes', async ({ page }) => {
 const { failures, writes } = await hardwareEditorSession(page); await openModule(page, 'assets');
 await page.getByRole('button', { name: 'Modifier Fictional probe', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier la fiche', exact: true });
 await expect(editor.getByRole('tab', { name: 'Commandes', exact: true })).toHaveCount(0);
 await editor.getByRole('tab', { name: 'Général', exact: true }).focus(); await page.keyboard.press('End');
 await expect(editor.getByRole('tab', { name: 'Notes', exact: true })).toHaveAttribute('aria-selected', 'true');
 await (await revealTabbedControl(editor.locator('#asset-description'))).fill('Draft across tabs'); await page.keyboard.press('Tab');
 await editor.getByRole('tab', { name: 'Notes', exact: true }).focus(); await page.keyboard.press('Home');
 await expect(editor.locator('#asset-name')).toBeVisible();
 await (await revealTabbedControl(editor.locator('#asset-category'))).selectOption('pump');
 await editor.getByRole('tab', { name: 'Commandes', exact: true }).click();
 await expect(editor.getByRole('tabpanel', { name: 'Commandes', exact: true })).toBeVisible();
 await editor.getByRole('tab', { name: 'Général', exact: true }).click();
 await page.setViewportSize({ width: 390, height: 844 });
 await editor.getByRole('tab', { name: 'Notes', exact: true }).click(); await expect(editor.locator('#asset-description')).toHaveText('Draft across tabs');
 await editor.getByRole('button', { name: 'Annuler', exact: true }).click();
 await page.getByRole('dialog', { name: 'Modifications non enregistrées', exact: true }).getByRole('button', { name: 'Abandonner les modifications', exact: true }).click();
 await page.getByRole('button', { name: 'Modifier Fictional probe', exact: true }).click();
 await expect(editor.getByRole('tab', { name: 'Général', exact: true })).toHaveAttribute('aria-selected', 'true');
 await expect(editor.getByRole('tab', { name: 'Commandes', exact: true })).toHaveCount(0);
 expect(writes).toEqual([]); expect(failures).toEqual([]);
});

test('user profile tags retain native close controls and save the selected access profiles', async ({ page }) => {
 const { failures, writes } = await userEditorSession(page);
 await expect(page.getByRole('button', { name: 'Modifier Fictional managed', exact: true })).toBeVisible();
 await page.emulateMedia({ colorScheme: 'dark' });
 await page.getByRole('button', { name: 'Modifier Fictional managed', exact: true }).click();
 const editor = page.getByRole('dialog', { name: 'Modifier le compte', exact: true });
 await editor.getByRole('tab', { name: 'Profils et droits', exact: true }).click();
 const chips = editor.locator('.user-profile-chips');
 await expect(chips).toContainText('Fictional profile');
 await chips.getByRole('button', { name: 'Retirer Fictional profile', exact: true }).focus(); await page.keyboard.press('Enter');
 await expect(chips.locator('.n-tag')).toHaveCount(0); expect(writes).toEqual([]);
 await (await revealTabbedControl(editor.locator('#user-permission-profile'))).selectOption('profile-two');
 await expect(chips).toContainText('Observateur');
 await editor.getByRole('button', { name: 'Enregistrer le compte', exact: true }).click();
 await expect(editor).toHaveCount(0);
 expect(writes).toHaveLength(1); expect(writes[0]).toMatchObject({ permission_role_ids: ['profile-two'] });
 expect(failures).toEqual([]);
});

for (const provider of ['home-assistant', 'homebridge'] as const) test(`${provider} entity picker preserves import limits and usable padded content`, async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', ['administration.view']);
 const rows = Array.from({ length: 12 }, (_, index) => ({ id: `device:${provider}_${index}`, name: `Entity ${index}`, category: 'sensor', entityCount: 3, importedCount: index === 0 ? 3 : 0, entities: ['Temperature', 'Humidity', 'Battery'].map((name, channel) => ({ id: `${provider}-${index}-${channel}`, name, unit: channel ? '%' : '°C' })), available: index !== 1, imported: index === 0 }));
 const writes: string[][] = []; let imported = false; let release!: () => void;
 const pending = new Promise<void>(resolve => { release = resolve; });
 await page.route(`**/api/v1/integrations/${provider}**`, async route => {
  const path = new URL(route.request().url()).pathname;
  if (path.endsWith('/import')) { writes.push(route.request().postDataJSON().device_ids); await pending; imported = true; return route.fulfill({ status: 201, json: { data: { devicesCreated: 10, sensorsCreated: 30, typesCreated: 3, alreadyImported: 0 } } }); }
  if (path.endsWith('/entities')) return route.fulfill({ json: { data: rows.map(row => ({ ...row, imported: row.imported || imported && writes[0]!.includes(row.id) })), meta: { current_page: 1, last_page: 1, total: 12, per_page: 20 } } });
  if (path.endsWith('/links')) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
  return route.fulfill({ json: { data: { configured: true } } });
 });
 await openModule(page, provider === 'homebridge' ? 'homebridge' : 'ha');
 await page.getByRole('button', { name: provider === 'homebridge' ? 'Parcourir les appareils Homebridge' : 'Parcourir les appareils Home Assistant', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: provider === 'homebridge' ? 'Sélecteur d’appareils Homebridge' : 'Sélecteur d’appareils Home Assistant', exact: true });
 const checkbox = (index: number) => dialog.getByRole('checkbox', { name: `Sélectionner Entity ${index}`, exact: true });
 await expect(checkbox(0)).toBeDisabled(); await expect(checkbox(1)).toBeEnabled();
 await expect(dialog.getByRole('checkbox')).toHaveCount(12);
 await expect(dialog.locator('.entity-picker-list > li').nth(1)).toContainText('3 canaux');
 await expect(dialog.locator('.entity-picker-list > li').nth(1)).toContainText('Battery');
 await expect(dialog.locator('.entity-picker-list > li').nth(1)).toContainText('Indisponible');
 await checkbox(1).focus(); await page.keyboard.press('Space');
 for (let index = 2; index <= 10; index++) await (await revealTabbedControl(checkbox(index))).check();
 await expect(checkbox(11)).toBeDisabled(); await expect(dialog.locator('.entity-picker-count')).toContainText('10 / 10');
 await (await revealTabbedControl(checkbox(2))).uncheck(); await expect(checkbox(11)).toBeEnabled(); await (await revealTabbedControl(checkbox(11))).check();
 for (const width of [1280, 390]) {
  await page.setViewportSize({ width, height: 844 });
  const insets = await dialog.locator(':scope > .n-card-content').evaluate(node => [getComputedStyle(node).paddingInlineStart, getComputedStyle(node).paddingInlineEnd].map(parseFloat));
  expect(insets.every(value => value > 0)).toBe(true);
  expect(await dialog.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
  await expect(dialog.locator(':scope > .n-card__footer')).toBeInViewport();
  await dialog.locator(':scope > .n-card-content').evaluate(node => { node.scrollTop = 0; });
 }
 const submit = dialog.getByRole('button', { name: 'Importer les appareils sélectionnés', exact: true });
 try { await submit.click(); await expect.poll(() => writes.length).toBe(1); await expect(checkbox(1)).toBeDisabled(); await expect(dialog.locator('.dialog-close')).toBeDisabled(); } finally { release(); }
 await expect(submit).toBeDisabled(); await expect(dialog.locator('.entity-picker-count')).toContainText('0 / 10');
 expect(writes[0]).toEqual(rows.filter((_, index) => index !== 0 && index !== 2).map(row => row.id));
 await expect(dialog.locator('.dialog-close')).toBeEnabled(); await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); expect(failures).toEqual([]);
});


for (const editable of [true, false]) test(`record status tags ${editable ? 'update only status fields' : 'stay read-only without permissions'}`, async ({ page }) => {
 const { failures, requests } = await syntheticSession(page, editable ? 'admin' : 'member', []);
 const meta = { current_page: 1, last_page: 1, total: 1, per_page: 20 };
 const asset = { id: 'status-device', name: 'Status hardware', category: 'socket', status: 'active', locations: [], plants: [], plantsCount: 0, channelsCount: 1, enabledChannelsCount: 1 };
 const strain = { id: 'status-strain', name: 'Status strain', version: 1, isArchived: false, canManage: editable, seedStock: null, durationOverrides: {} };
 const sensor = { id: 'status-sensor', name: 'Status channel', channelKey: 'reading', isEnabled: true, expectedIntervalSeconds: 60, description: null, device: asset, measurementType: { id: 'status-type', name: 'Temperature', code: 'temp', unit: '°C', nature: 'temperature', decimalPlaces: 1 } };
 const changes: { path: string; body: Record<string, unknown> }[] = [];
 let holdSensor = false; let releaseSensor = () => {};
 for (const [path, record] of [['assets', asset], ['sensors', sensor], ['strains', strain]] as const) {
  await page.route(`**/api/v1/${path}?*`, route => route.fulfill({ json: { data: [record], meta } }));
  await page.route(`**/api/v1/${path}/${record.id}`, async route => {
   if (route.request().method() === 'PATCH') {
    const body = route.request().postDataJSON(); changes.push({ path, body });
    if (path === 'sensors' && holdSensor) await new Promise<void>(resolve => { releaseSensor = resolve; });
    if (path === 'assets') asset.status = body.status;
    if (path === 'sensors') sensor.isEnabled = body.is_enabled;
    if (path === 'strains') { strain.isArchived = body.is_archived; strain.version++; }
   }
   return route.fulfill({ json: { data: record } });
  });
 }
 await openModule(page, 'catalog');
 if (editable) {
  const change = page.getByRole('button', { name: 'Changer le statut : Status strain', exact: true });
  await expect(change).toBeEnabled(); await change.click();
  await expect.poll(() => changes.find(row => row.path === 'strains')?.body).toEqual({ version: 1, is_archived: true });
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(change).toBeEnabled(); await change.click();
  await expect.poll(() => changes.filter(row => row.path === 'strains').at(-1)?.body).toEqual({ version: 2, is_archived: false });
  await expect(change).toBeEnabled();
  await page.locator('.strain-photo-card .open-plant').click();
  const detail = page.getByRole('dialog');
  await detail.getByRole('button', { name: 'Changer le statut : Status strain', exact: true }).click();
  await expect.poll(() => changes.filter(row => row.path === 'strains').at(-1)?.body).toEqual({ version: 3, is_archived: true });
  await expect(detail).toBeVisible();
  await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0);
 } else await expect(page.getByRole('button', { name: 'Changer le statut : Status strain', exact: true })).toHaveCount(0);
 await openModule(page, 'assets');
 if (editable) {
  await page.getByRole('button', { name: 'Changer le statut : Status hardware', exact: true }).click();
  await page.locator('.n-base-select-option').filter({ hasText: 'Maintenance' }).click();
  await expect.poll(() => changes.find(row => row.path === 'assets')?.body).toEqual({ status: 'maintenance' });
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', { name: 'Tableau', exact: true }).click();
  await page.getByRole('button', { name: 'Changer le statut : Status hardware', exact: true }).click();
  await page.locator('.n-base-select-option').filter({ hasText: 'En service' }).click();
  await expect.poll(() => changes.filter(row => row.path === 'assets').at(-1)?.body).toEqual({ status: 'active' });
 } else await expect(page.getByRole('button', { name: 'Changer le statut : Status hardware', exact: true })).toHaveCount(0);
 await openModule(page, 'sensors');
 if (editable) {
  holdSensor = true;
  const change = page.getByRole('button', { name: 'Changer le statut : Status channel', exact: true });
  await change.click();
  await expect(change).toBeDisabled();
  await change.evaluate((node: HTMLButtonElement) => node.click());
  await expect.poll(() => changes.filter(row => row.path === 'sensors').length).toBe(1);
  releaseSensor();
  await expect.poll(() => changes.find(row => row.path === 'sensors')?.body).toEqual({ is_enabled: false });
  await expect(change).toBeEnabled();
  holdSensor = false; await change.click();
  await expect.poll(() => changes.filter(row => row.path === 'sensors').at(-1)?.body).toEqual({ is_enabled: true });
  await expect(change).toBeEnabled();
  await page.getByRole('button', { name: 'Tableau', exact: true }).click();
  await change.focus(); await page.keyboard.press('Space');
  await expect.poll(() => changes.filter(row => row.path === 'sensors').at(-1)?.body).toEqual({ is_enabled: false });
 } else await expect(page.getByRole('button', { name: 'Changer le statut : Status channel', exact: true })).toHaveCount(0);
 expect(requests.some(path => /\/(power|activate|stop)$/.test(path))).toBe(false);
 expect(failures).toEqual([]);
});


test('empty seed stock disables plant creation on strain cards and details and in the creation selector', async ({ page }) => {
 const { failures } = await syntheticSession(page, 'admin', []);
 const meta = { current_page: 1, last_page: 1, total: 3, per_page: 20 };
 const rows = [0, 1, null].map((quantity, index) => ({ id: `seed-strain-${index}`, name: `Seed strain ${index}`, durationOverrides: {}, isArchived: false, version: 1, canManage: true, seedStock: quantity === null ? null : { id: `stock-${index}`, name: 'Seed stock', quantity: `${quantity}.000`, isArchived: false, scoped: false } }));
 await page.route('**/api/v1/strains?*', route => route.fulfill({ json: { data: rows, meta } }));
 for (const row of rows) await page.route(`**/api/v1/strains/${row.id}`, route => route.fulfill({ json: { data: row } }));
 await page.setViewportSize({ width: 700, height: 900 });
 await openModule(page, 'catalog');
 const empty = page.getByTestId('strain-row-seed-strain-0');
 await expect(empty.getByRole('button', { name: 'Ajouter une plante', exact: true })).toBeDisabled();
 await expect(page.getByTestId('strain-row-seed-strain-1').getByRole('button', { name: 'Ajouter une plante', exact: true })).toBeEnabled();
 await expect(page.getByTestId('strain-row-seed-strain-2').getByRole('button', { name: 'Ajouter une plante', exact: true })).toBeEnabled();
 await empty.locator('.open-plant').click();
 const detail = page.getByRole('dialog');
 await expect(detail.getByRole('button', { name: 'Ajouter une plante', exact: true })).toBeDisabled();
 await page.keyboard.press('Escape'); await expect(detail).toHaveCount(0);
 await openModule(page, 'plants');
 await page.getByRole('button', { name: 'Ajouter une plante', exact: true }).click();
 const editor = page.getByRole('dialog');
 await expect(editor.locator('#plant-strain-select option[value="seed-strain-0"]')).toBeDisabled();
 await expect(editor.locator('#plant-strain-select option[value="seed-strain-1"]')).toBeEnabled();
 await (await revealTabbedControl(editor.locator('#plant-strain-select'))).selectOption('seed-strain-1');
 await expect(editor.getByRole('button', { name: 'Créer', exact: true })).toBeEnabled();
 expect(failures).toEqual([]);
});
