import { insightRoutes } from '../e2e/insights-fixture';
import { lifecycleStages, lifecycleIcons } from '../e2e/lifecycle-fixture';
import { openModule } from '../e2e/navigation';
import { test, expect, type Page } from '../coverage/fixture';

async function publicLogin(page: Page) {
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/lifecycle-settings')) return route.fulfill({ json: { data: { stages: lifecycleStages, icons: lifecycleIcons, durations: { flowering: 56 }, version: 1, canManage: false } } });
    if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: false } } });
    if (path.endsWith('/application-version')) return route.fulfill({ json: { data: { version: '0.1.0', updateAvailable: false } } });
    if (path.endsWith('/account/ui-preferences')) return route.fulfill({ json: { data: {} } });
    if (path.endsWith('/branding')) return route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, oidcProviderName: 'OIDC', oidcProviderIcon: null, version: 1 } } });
    if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
    if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
    return route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
  });
  await page.goto('/');
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
}
async function controlled(page: Page) {
  await page.evaluate(async () => { await navigator.serviceWorker.ready; });
  await page.reload();
  await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBe(true);
}

test('production metadata supplies install icons and translated manual installation help', { tag: '@cross-engine' }, async ({ page }) => {
  await publicLogin(page);
  const manifest = await page.evaluate(async () => {
    const link = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')!;
    return (await fetch(link.href)).json();
  });
  expect(manifest).toMatchObject({ id: '/', name: 'Canabicultarr', start_url: '/', scope: '/', display: 'standalone' });
  expect(manifest.icons).toEqual(expect.arrayContaining([
    expect.objectContaining({ sizes: '192x192', purpose: 'any' }), expect.objectContaining({ sizes: '512x512', purpose: 'maskable' }),
  ]));
  const dimensions = await page.evaluate(async () => {
    const result = [];
    for (const path of ['/icons/app-192.png', '/icons/app-512.png', '/icons/app-maskable-512.png', '/icons/apple-touch-icon.png']) {
      const image = new Image(); image.src = path; await image.decode(); result.push([image.naturalWidth, image.naturalHeight]);
    }
    return result;
  });
  expect(dimensions).toEqual([[192,192],[512,512],[512,512],[180,180]]);
  await expect(page.locator('.app-shell')).toHaveCount(1);
  await page.locator('#language').selectOption('fr');
  await page.getByText('Aide à l’installation', { exact: true }).click();
  await expect(page.getByText('Dans le menu du navigateur, choisissez Installer l’application', { exact: false })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  if (process.env.PLAYWRIGHT_PWA_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_PWA_SCREENSHOT, fullPage: true, animations: 'disabled' });
});

test('installation help directs insecure LAN connections to trusted HTTPS', { tag: '@cross-engine' }, async ({ page }) => {
  await page.addInitScript(() => Object.defineProperty(window, 'isSecureContext', { value: false }));
  await publicLogin(page);
  await page.getByText('Installation help', { exact: true }).click();
  await expect(page.getByText('To install on your phone', { exact: false })).toBeVisible();
  await expect(page.getByText('Connect to your Canabicultarr server', { exact: false })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Install application', exact: true })).toHaveCount(0);
});

test('native install prompt is offered on a click, consumed once, and confirmation follows appinstalled', async ({ page }) => {
  await publicLogin(page);
  await page.evaluate(() => {
    const event = new Event('beforeinstallprompt', { cancelable: true });
    Object.assign(event, { prompt: async () => { document.documentElement.dataset.promptCount = String(Number(document.documentElement.dataset.promptCount ?? 0) + 1); }, userChoice: Promise.resolve({ outcome: 'accepted' }) });
    window.dispatchEvent(event);
  });
  await page.getByRole('button', { name: 'Install application', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Install application', exact: true })).toHaveCount(0);
  expect(await page.locator('html').getAttribute('data-prompt-count')).toBe('1');
  await expect(page.getByText('Application installed.', { exact: true })).toHaveCount(0);
  await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')));
  await expect(page.getByText('Application installed.', { exact: true })).toBeVisible();
  await expect(page.locator('summary')).toHaveCount(0);
});

test('a previously opened app can display the public offline page', { tag: '@cross-engine' }, async ({ page, context }) => {
  await publicLogin(page);
  await page.evaluate(async () => { await navigator.serviceWorker.ready; });
  await context.setOffline(true);
  const response = await page.goto('/');
  expect(response?.status()).toBe(503);
  await expect(page.getByRole('heading', { name: 'Connection unavailable', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Connexion indisponible', exact: true })).toBeVisible();
});

test('offline reload exposes only bilingual public fallback, never API responses, and recovers online', async ({ page, context }) => {
  await publicLogin(page); await controlled(page);
  await page.locator('#language').selectOption('fr');
  await page.locator('#login-email').fill('fictional-private-account@example.test');
  await page.route('**/api/v1/auth/user?cache-probe=1', route => route.fulfill({ json: { data: { name: 'Fictional private response', email: 'fictional-private-account@example.test' } } }));
  const before = await page.evaluate(async () => {
    const response = await fetch('/api/v1/auth/user?cache-probe=1');
    if ((await response.json()).data.email !== 'fictional-private-account@example.test') throw new Error('Fictional private response not received.'); await fetch('/api/v1/reports/sensors/fictional/export');
    const keys = await caches.keys();
    return Promise.all(keys.map(async key => ({ key, urls: (await (await caches.open(key)).keys()).map(request => new URL(request.url).pathname) })));
  });
  expect(before).toHaveLength(1); expect(before[0]!.urls).toEqual(['/offline.html']);
  await context.setOffline(true);
  await expect(page.getByText('Vous semblez hors ligne.', { exact: false })).toBeVisible();
  const response = await page.reload(); expect(response?.status()).toBe(503);
  await expect(page.getByRole('heading', { name: 'Connection unavailable', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Connexion indisponible', exact: true })).toBeVisible();
  await expect(page.getByRole('textbox')).toHaveCount(0);
  expect(await page.content()).not.toContain('fictional-private-account@example.test');
  const privateFetch = await page.evaluate(async () => {
    try { await fetch('/api/v1/auth/user'); return 'unexpected-response'; } catch { return 'network-error'; }
  });
  expect(privateFetch).toBe('network-error');
  expect(response?.headers()['cache-control']).toBe('no-store');
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  if (process.env.PLAYWRIGHT_PWA_OFFLINE_SCREENSHOT) await page.screenshot({ path: process.env.PLAYWRIGHT_PWA_OFFLINE_SCREENSHOT, fullPage: true });
  await context.setOffline(false);
  await page.getByRole('link', { name: 'Réessayer', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible();
});

test('cancelled or failed installation does not claim success and still allows manual help', async ({ page }) => {
  await publicLogin(page);
  for (const failure of [false, true]) {
    await page.evaluate(failure => {
      const event = new Event('beforeinstallprompt', { cancelable: true });
      Object.assign(event, { prompt: async () => { if (failure) throw new Error('Fictional prompt failure'); }, userChoice: Promise.resolve({ outcome: 'dismissed' }) });
      window.dispatchEvent(event);
    }, failure);
    await page.getByRole('button', { name: 'Install application', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Install application', exact: true })).toHaveCount(0);
    await expect(page.getByText('Application installed.', { exact: true })).toHaveCount(0);
    if (failure) await expect(page.getByText('The installation prompt could not open.', { exact: false })).toBeVisible();
  }
  await page.getByText('Installation help', { exact: true }).click();
  await expect(page.getByText('In your browser menu, choose Install app', { exact: false })).toBeVisible();
});

test('production browser opt-in uses the real worker handshake, keeps endpoints private and unsubscribes on logout', async ({ page, context }) => {
  await insightRoutes(page, 'en');
  await context.grantPermissions(['notifications']);
  await page.addInitScript(() => {
    let current: PushSubscription | null = null;
    Object.defineProperty(PushManager.prototype, 'getSubscription', { value: async () => current });
    Object.defineProperty(PushManager.prototype, 'subscribe', { value: async (options: PushSubscriptionOptionsInit) => {
      document.documentElement.dataset.pushSubscribeCalls = String(Number(document.documentElement.dataset.pushSubscribeCalls ?? 0) + 1);
      const value = options.applicationServerKey;
      const key = typeof value === 'string' || !value ? null : ArrayBuffer.isView(value) ? new Uint8Array(value.buffer, value.byteOffset, value.byteLength).slice().buffer : value;
      const endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-private-capability';
      current = { endpoint, expirationTime: null, options: { applicationServerKey: key, userVisibleOnly: true }, getKey: () => null,
        toJSON: () => ({ endpoint, keys: { p256dh: 'fictional-public-key', auth: 'fictional-auth-key' } }),
        unsubscribe: async () => { current = null; document.documentElement.dataset.pushUnsubscribeCalls = String(Number(document.documentElement.dataset.pushUnsubscribeCalls ?? 0) + 1); return true; },
      } as PushSubscription;
      return current;
    } });
  });
  const publicKey = Buffer.concat([Buffer.from([4]), Buffer.alloc(64, 1)]).toString('base64url');
  let signedIn = true;
  let preferences = { email_enabled: false, push_enabled: false, locale: 'en', version: 0 };
  let subscriptions: { id: string; createdAt: string; lastUsedAt: string | null; currentKey: boolean; accountCurrent: boolean }[] = [];
  const subscriptionId = '00000000-0000-4000-8000-000000000001';
  await page.route('**/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();
    if (path.endsWith('/lifecycle-settings')) return route.fulfill({ json: { data: { stages: lifecycleStages, icons: lifecycleIcons, durations: { flowering: 56 }, version: 1, canManage: false } } });
    if (path.endsWith('/instance-settings')) return route.fulfill({ json: { data: { dateFormat: 'd/m/Y', timeFormat: '24', version: 1, canManage: false } } });
    if (path.endsWith('/application-version')) return route.fulfill({ json: { data: { version: '0.1.0', updateAvailable: false } } });
    if (path.endsWith('/account/ui-preferences')) return route.fulfill({ json: { data: {} } });
    if (path.endsWith('/branding')) return route.fulfill({ json: { data: { siteTitle: 'Canabicultarr', siteSubtitle: null, oidcProviderName: 'OIDC', oidcProviderIcon: null, version: 1 } } });
    if (path.endsWith('/setup')) return route.fulfill({ json: { data: { requiresSetup: false } } });
    if (path.endsWith('/auth/oidc')) return route.fulfill({ json: { data: { enabled: false, result: null } } });
    if (path.endsWith('/sites')) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });
    if (path.endsWith('/auth/user')) return route.fulfill(signedIn ? { json: { data: { id: subscriptionId, name: 'Fictional Push Member', email: 'push@example.test', role: 'member', isActive: true, permissions: [], oidcLinked: false } } } : { status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
    if (path.endsWith('/auth/csrf')) return route.fulfill({ status: 204 });
    if (path.endsWith('/auth/logout')) { signedIn = false; subscriptions = []; return route.fulfill({ status: 204 }); }
    if (path.endsWith('/notifications/settings')) return route.fulfill({ json: { data: { canManage: false, settings: { email_enabled: true, push_enabled: true, webhook_enabled: false, include_recovery: true, minimum_severity: 'info', locale: 'en', version: 1 }, channels: { email: { configured: true }, push: { configured: true, publicKey }, webhook: { configured: false, format: 'generic' } } } } });
    if (path.endsWith('/notifications/preferences')) return route.fulfill({ json: { data: preferences } });
    if (path.endsWith('/notifications/deliveries')) return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
    if (path.endsWith('/notifications/subscriptions/remove-browser')) { expect(route.request().postDataJSON().endpoint).toContain('fictional-private-capability'); subscriptions = []; return route.fulfill({ status: 204 }); }
    if (path.endsWith('/notifications/subscriptions') && method === 'POST') {
      expect(route.request().postDataJSON().endpoint).toContain('fictional-private-capability');
      subscriptions = [{ id: subscriptionId, createdAt: new Date().toISOString(), lastUsedAt: null, currentKey: true, accountCurrent: true }]; preferences = { ...preferences, push_enabled: true, version: preferences.version + 1 };
      return route.fulfill({ status: 201, json: { data: { id: subscriptionId, preferences } } });
    }
    if (path.endsWith('/notifications/subscriptions')) return route.fulfill({ json: { data: subscriptions } });
    return route.fallback();
  });
  await page.goto('/'); await openModule(page, 'accountNotifications');
  await expect(page.getByRole('button', { name: 'Enable this browser', exact: true })).toBeEnabled();
  expect(await page.locator('html').getAttribute('data-push-subscribe-calls')).toBeNull();
  await page.getByRole('button', { name: 'Enable this browser', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Remove subscription 1', exact: true })).toBeVisible();
  await expect(page.locator('#notification-push')).toBeChecked();
  expect(await page.content()).not.toContain('fictional-private-capability');
  const cachesAfterSubscribe = await page.evaluate(async () => (await Promise.all((await caches.keys()).map(async key => (await (await caches.open(key)).keys()).map(r => new URL(r.url).pathname)))).flat());
  expect(cachesAfterSubscribe).toEqual(['/offline.html']);
  await page.getByRole('button', { name: 'Disable this browser', exact: true }).click(); await expect(page.getByText('No browser subscriptions registered.', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Enable this browser', exact: true }).click(); await expect(page.getByRole('button', { name: 'Remove subscription 1', exact: true })).toBeVisible();
  await openModule(page, 'home'); await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  await expect.poll(() => page.locator('html').getAttribute('data-push-unsubscribe-calls')).toBe('2');
});

test('recovery links stay out of URLs, browser storage and offline caches, and reload discards the capability', async ({ page }) => {
  await publicLogin(page); await controlled(page);
  await page.route('**/api/v1/auth/password-recovery', route => route.fulfill({ json: { data: { enabled: true } } }));
  const token = 'a'.repeat(64);
  await page.goto('/#reset-password=' + token);
  await expect(page.getByRole('heading', { name: 'Choose a new password', exact: true })).toBeVisible();
  await expect(page.locator('#recovery-email')).toBeVisible();
  expect(new URL(page.url()).hash).toBe('');
  await page.locator('#recovery-email').fill('fictional-recovery@example.test');
  await page.locator('#recovery-password').fill('A fictional unsent recovery password');
  const stored = await page.evaluate(async () => ({ local: JSON.stringify(localStorage), session: JSON.stringify(sessionStorage), cache: await Promise.all((await caches.keys()).map(async key => (await (await caches.open(key)).keys()).map(request => request.url))) }));
  for (const secret of [token, 'fictional-recovery@example.test', 'A fictional unsent recovery password']) expect(JSON.stringify(stored)).not.toContain(secret);
  expect(stored.cache.flat().map(url => new URL(url).pathname)).toEqual(['/offline.html']);
  await page.reload(); await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  await page.route('**/api/v1/auth/password-recovery', route => route.fulfill({ json: { data: { enabled: false } } }));
  await page.getByRole('button', { name: 'Forgot password?', exact: true }).click();
  await expect(page.getByText('Password recovery is not enabled on this installation.', { exact: false })).toBeVisible();
  await expect(page.locator('#recovery-email')).toHaveCount(0);
});
