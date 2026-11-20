import { text } from 'node:stream/consumers';
import { chromium, devices, expect } from '../../frontend/node_modules/@playwright/test/index.mjs';

const { origin, credentials, fingerprint, version } = JSON.parse(await text(process.stdin));
const browser = await chromium.launch({
  channel: 'chromium',
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
  args: ['--ignore-certificate-errors-spki-list=' + fingerprint],
});

try {
  const context = await browser.newContext({ ...devices['Pixel 7'], locale: 'en-US' });
  const page = await context.newPage();
  await page.goto(origin);
  await expect.poll(() => page.evaluate(() => window.isSecureContext)).toBe(true);
  await page.locator('#login-email').fill(credentials.email);
  await page.locator('#login-password').fill(credentials.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByTestId('nav-home')).toBeAttached();
  if (version) {
    await expect(page.locator('footer .footer-version')).toContainText(version);
    await page.goto(origin + '/settings/about');
    await expect(page.locator('.about-versions').getByText(version, { exact: true })).toBeVisible();
    const info = await page.evaluate(async () => {
      const response = await fetch('/api/v1/application-info', { headers: { Accept: 'application/json' } });
      return { status: response.status, version: (await response.json()).data?.version };
    });
    expect(info).toEqual({ status: 200, version });
  }
  await page.goto(origin + '/account/security');

  await page.getByRole('button', { name: 'Pair a phone', exact: true }).click();
  await expect(page.getByRole('img', { name: 'Mobile pairing QR code' })).toBeVisible();
  const link = await page.locator('#mobile-pairing-link').inputValue();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Use a pairing link' }).click();
  await page.locator('#mobile-pairing-entered-link').fill(link);
  await page.getByRole('button', { name: 'Continue', exact: true }).click();
  await page.getByLabel('Device name').fill('Desktop browser fixture');
  await page.getByRole('button', { name: 'Pair this phone', exact: true }).click();
  await expect(page.getByText('This phone is paired.', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Done', exact: true }).click();

  const passphrase = 'Fictional Desktop offline passphrase';
  await page.getByLabel('Offline passphrase').fill(passphrase);
  await page.getByRole('button', { name: 'Save offline copy' }).click();
  await expect(page.getByText('Copy saved until', { exact: false })).toBeVisible();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);

  await context.setOffline(true);
  const fallback = await page.goto(origin);
  expect(fallback.status()).toBe(503);
  const offline = page.locator('section[lang="en"]');
  await offline.getByLabel('Offline passphrase').fill(passphrase);
  await offline.getByRole('button', { name: 'Unlock sites' }).click();
  await expect(offline.getByRole('listitem').getByText('Mobile sync fixture', { exact: true })).toBeVisible();
  const title = 'Desktop browser offline note';
  await offline.getByLabel('Note title').fill(title);
  await offline.getByLabel('Journal note').fill('Queued with mobile browser emulation and the network disabled.');
  await offline.getByRole('button', { name: 'Queue journal note' }).click();
  await expect(offline.getByText('The journal note is saved', { exact: false })).toBeVisible();

  await context.setOffline(false);
  await page.goto(origin);
  await page.getByRole('button', { name: 'Unlock to sync' }).click();
  await page.getByLabel('Offline passphrase').fill(passphrase);
  const receipt = page.waitForResponse(response => response.url().endsWith('/journal-notes') && response.ok());
  await page.getByRole('button', { name: 'Auto-sync for 15 minutes' }).click();
  await receipt;

  const synced = await page.evaluate(async noteTitle => {
    const base = '/api/v1/account/mobile-devices';
    const headers = { Accept: 'application/json' };
    const snapshot = await (await fetch(base + '/offline-sites', { headers })).json();
    const journal = await (await fetch(base + '/offline-journal?version=' + snapshot.data.version, { headers })).json();
    return journal.data.changes.filter(change => change.title === noteTitle).length;
  }, title);
  expect(synced).toBe(1);
  console.log('HTTPS: mobile Chromium pairing, encrypted offline bootstrap, and one synchronized journal note passed.');
} finally {
  await browser.close();
}
