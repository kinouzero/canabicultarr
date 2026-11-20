import { test, expect, withBrowserCoverage } from '../coverage/fixture';

test('a mobile pairing link stays local and requires an explicit confirmation', { tag: '@cross-engine' }, async ({ page, context, baseURL, browserName }) => {
  const origin = 'https://canabicultarr.test';
  const code = 'm'.repeat(43);
  const id = '00000000-0000-4000-8000-000000000001';
  const user = {
    id, name: 'Fictional Member', email: 'member@example.test', role: 'member', isActive: true,
    permissions: [], permissionRoles: [], oidcLinked: false, locationAccess: null,
  };
  let signedIn = true;
  let paired = false;
  await context.route(url => url.origin === origin || url.origin === baseURL, async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (!url.pathname.startsWith('/api/v1/')) {
      return route.fulfill({ response: await route.fetch({ url: baseURL + url.pathname + url.search }) });
    }
    const endpoint = url.pathname.slice('/api/v1/'.length);
    const data = (value: unknown, status = 200) => route.fulfill({ status, json: { data: value } });
    switch (endpoint) {
      case 'setup': return data({ requiresSetup: false, localEnabled: true, registrationEnabled: false });
      case 'auth/oidc': return data({ enabled: false, result: null });
      case 'auth/user': return signedIn ? data(user) : route.fulfill({ status: 401, json: { error: { code: 'auth.errors.unauthenticated' } } });
      case 'auth/csrf': return route.fulfill({ status: 204 });
      case 'auth/login': signedIn = true; return data(user);
      case 'account/security': return data({ localPasswordAvailable: true, otherSessions: 0 });
      case 'account/mobile-devices': return data(paired ? [{ id, label: 'Test phone', current: true }] : []);
      case 'account/mobile-devices/pairing': return data({ code, expires_at: '2026-11-19T21:00:00Z' }, 201);
      case 'account/mobile-devices/redeem':
        expect(signedIn).toBe(true);
        expect(request.postDataJSON()).toEqual({ code, label: 'Test phone' });
        paired = true;
        return data({ id }, 201);
      case `account/mobile-devices/${id}`:
        expect(request.method()).toBe('DELETE');
        paired = false;
        return route.fulfill({ status: 204 });
      default: return data([]);
    }
  });

  await page.goto('/account/security');
  await expect(page.getByRole('region', { name: 'Mobile devices' })).toContainText('enable HTTPS on the local network');
  await expect(page.getByRole('button', { name: 'Pair a phone', exact: true })).toHaveCount(0);

  await page.goto(origin + '/account/security');
  await page.getByRole('button', { name: 'Pair a phone', exact: true }).click();
  const link = await page.getByLabel('Pairing link').inputValue();
  expect(link).toBe(`${origin}/#mobile-pairing=${code}`);
  await expect(page.getByRole('img', { name: 'Mobile pairing QR code' })).toBeVisible();

  await withBrowserCoverage(await context.newPage(), browserName, async phone => {
    signedIn = false;
    await phone.goto(link);
    await expect(phone).toHaveURL(origin + '/');
    await expect(phone.getByText('Sign in to the account that created this mobile pairing code.')).toBeVisible();
    expect(paired).toBe(false);
    await phone.locator('#login-email').fill(user.email);
    await phone.locator('#login-password').fill('Fictional test password');
    await phone.getByRole('button', { name: 'Sign in', exact: true }).click();
    const confirm = phone.getByRole('dialog', { name: 'Pair this phone' });
    await expect(confirm).toBeVisible();
    expect(paired).toBe(false);
    await confirm.getByLabel('Device name').fill('Test phone');
    await confirm.getByRole('button', { name: 'Pair this phone', exact: true }).click();
    await expect(confirm.getByText('This phone is paired.')).toBeVisible();
    expect(paired).toBe(true);
    expect(await phone.evaluate(key => Object.values(sessionStorage).some(value => value.includes(key))
      || Object.values(localStorage).some(value => value.includes(key)), code)).toBe(false);

    await phone.goto(origin + '/account/security');
    await expect(phone.getByText('This browser', { exact: true })).toBeVisible();
    await phone.getByRole('button', { name: 'Remove registration', exact: true }).click();
    await phone.getByRole('dialog', { name: 'Remove registration' }).getByRole('button', { name: 'Remove registration', exact: true }).click();
    await expect(phone.getByText('This browser', { exact: true })).toHaveCount(0);
    expect(paired).toBe(false);

    await phone.getByRole('button', { name: 'Use a pairing link', exact: true }).click();
    const enterLink = phone.getByRole('dialog', { name: 'Use a pairing link' });
    const nextCode = 'r'.repeat(43);
    await enterLink.getByLabel('Pairing link').fill(`https://other.test/#mobile-pairing=${nextCode}`);
    await enterLink.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(phone.getByRole('alert').filter({ hasText: 'Enter a pairing link for this server.' })).toBeVisible();
    await enterLink.getByLabel('Pairing link').fill(`${origin}/#mobile-pairing=${nextCode}`);
    await enterLink.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(confirm).toBeVisible();
    expect(paired).toBe(false);
  });
});
