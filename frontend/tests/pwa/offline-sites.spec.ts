import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { createServer } from 'node:https';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test, expect } from '../coverage/fixture';

// The isolated loopback server uses a test certificate. Worker requests need the
// browser process flag in addition to the page context setting.
test.use({ ignoreHTTPSErrors: true, launchOptions: {
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
  args: ['--ignore-certificate-errors'],
} });

test('a paired browser saves, unlocks, and retries offline notes and drops a revoked encrypted site copy', async ({ page, context, baseURL }) => {
  const fixture = await mkdtemp(join(tmpdir(), 'cana-offline-tls-'));
  const keyPath = join(fixture, 'key.pem');
  const certPath = join(fixture, 'cert.pem');
  const id = '00000000-0000-4000-8000-000000000001';
  let revoked = false;
  let fileRevoked = false;
  let deployWorkerUpdate = false;
  const fileBody = 'Offline text includes <script>unsafe</script> as literal text.';
  const file = {
    id, title: 'Saved private file', name: 'offline.txt', size: Buffer.byteLength(fileBody),
    mediaType: 'text/plain', sha256: createHash('sha256').update(fileBody).digest('hex'),
  };

  const journalRequests: (string | null)[] = [];
  const oldNote = { type: 'upsert', id, site_id: id, title: 'First downloaded note', body: 'Private <script>note</script>',
    occurred_at: '2026-11-19T12:00:00.000000Z' };
  const newNote = { ...oldNote, id: '00000000-0000-4000-8000-000000000002', title: 'New downloaded note' };
  const version = 'fixture-1';
  let plantVersion = 'fixture-plants-1';
  const plantName = 'Fictional saved plant <script>private</script>';
  const maintenanceRequests: Record<string, unknown>[] = [];
  const attachments: { id: string | undefined; version: string | undefined; body: string }[] = [];
  const galleryUploads: string[] = [];
  const attachmentText = 'Offline private inspection photo replacement';
  const uploaded: { operation_id: string; title: string; body: string; site_id: string; account_id: string; device_id: string }[] = [];
  const site = 'Fictional garden <script>private</script>';
  const passphrase = 'fictional offline directory phrase';
  const account = {
    id, name: 'Fictional Member', email: 'member@example.test', role: 'member', isActive: true,
    permissions: [], permissionRoles: [], oidcLinked: false, locationAccess: null,
  };
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
    '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', keyPath, '-out', certPath],
  { stdio: 'ignore' });
  const server = createServer({ key: await readFile(keyPath), cert: await readFile(certPath) }, async (request, response) => {
    const path = request.url ?? '/';
    if (!path.startsWith('/api/v1/')) {
      try {
        const upstream = await fetch(baseURL + path, { headers: { Accept: request.headers.accept ?? '*/*' } });
        response.writeHead(upstream.status, { 'Content-Type': upstream.headers.get('Content-Type') ?? 'text/plain' });
        if (path === '/sw.js' && deployWorkerUpdate) {
          const worker = await upstream.text();
          response.end(worker.replace(/(const CACHE_NAME = CACHE_PREFIX \+ )'[^']+'/, "$1'fixture-upgrade'"));
          return;
        }
        response.end(Buffer.from(await upstream.arrayBuffer()));
      } catch { response.writeHead(500).end(); }
      return;
    }
    const endpoint = path.slice('/api/v1/'.length);
    if (endpoint === 'account/mobile-devices/journal-notes' && request.method === 'POST') {
      const packets: Buffer[] = [];
      for await (const packet of request) packets.push(packet);
      uploaded.push(JSON.parse(Buffer.concat(packets).toString()));
      if (uploaded.length === 1) {
        // Simulate a committed write whose response was lost on the network.
        response.writeHead(201, { 'Content-Type': 'application/json', 'Content-Length': 100 });
        response.write('{"data":');
        setTimeout(() => response.destroy(), 100);
      } else {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ data: { id } }));
      }
      return;
    }
    if (endpoint.startsWith('account/mobile-devices/offline-files?')) {
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ data: [file] }));
      return;
    }
    if (endpoint === 'account/mobile-devices/offline-files/selection') {
      const packets: Buffer[] = [];
      for await (const packet of request) packets.push(packet);
      const input = JSON.parse(Buffer.concat(packets).toString());
      const allowed = !input.ticket || !fileRevoked;
      const data = input.ticket ? { valid: true } : {
        files: [file], ticket: 'fixture-file-token', expiresAt: new Date(Date.now() + 86_400_000).toISOString(),
      };
      response.writeHead(allowed ? 200 : 409, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify(allowed ? { data } : { error: { code: 'auth.errors.conflict' } }));
      return;
    }
    if (endpoint === 'account/mobile-devices/offline-files/' + id) {
      response.writeHead(200, { 'Content-Type': 'text/plain' });
      response.end(fileBody);
      return;
    }
    if (endpoint === 'account/mobile-devices/offline-attachments/' + id && request.method === 'POST') {
      const packets: Buffer[] = [];
      for await (const packet of request) packets.push(packet);
      const body = Buffer.concat(packets).toString();
      const field = (name: string) => body.match(new RegExp('name="' + name + '"\r\n\r\n([^\r\n]+)'))?.[1];
      if (field('gallery') === '1') {
        galleryUploads.push(field('upload_id') ?? '');
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ data: { id, gallery: true } }));
        return;
      }
      attachments.push({ id: field('upload_id'), version: field('version'), body });
      if (attachments.length === 1) {
        response.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': 100 });
        response.write('{"data":');
        // The browser must time out a response that stopped after a partial body.
      } else {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ data: { id } }));
      }
      return;
    }
    if (endpoint === 'account/mobile-devices/offline-maintenance/' + id && request.method === 'POST') {
      const packets: Buffer[] = [];
      for await (const packet of request) packets.push(packet);
      maintenanceRequests.push(JSON.parse(Buffer.concat(packets).toString()));
      const futureTimestamp = maintenanceRequests.length === 1;
      response.writeHead(futureTimestamp ? 422 : 409, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ error: { code: futureTimestamp ? 'plants.errors.timeInvalid' : 'plants.errors.conflict' } }));
      return;
    }
    if (endpoint.startsWith('account/mobile-devices/offline-plants?')) {
      const query = new URLSearchParams(endpoint.split('?')[1]);
      const plants = plantVersion === 'fixture-plants-1'
        ? [{ id, name: plantName, strain: 'Fixture strain', location: [site], phase: 'growth', phaseName: null, version: 7, canPhotograph: true, canMaintain: true }]
        : [];
      const data = query.has('check') ? { version: plantVersion } : { version: plantVersion, plants, truncated: false };
      response.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      response.end(JSON.stringify({ data }));
      return;
    }
    if (endpoint.startsWith('account/mobile-devices/offline-journal?')) {
      const cursor = new URLSearchParams(endpoint.split('?')[1]).get('cursor');
      journalRequests.push(cursor);
      if (journalRequests.length === 2) {
        response.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': 100 });
        response.write('{"data":');
        setTimeout(() => response.destroy(), 100);
        return;
      }
      const changes = cursor ? [{ id, type: 'remove' }, newNote] : [oldNote];
      response.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      response.end(JSON.stringify({ data: { changes, cursor: 'fixture-cursor', hasMore: false } }));
      return;
    }
    const data: Record<string, unknown> = {
      setup: { requiresSetup: false, localEnabled: true },
      'auth/oidc': { enabled: false, result: null },
      'auth/user': account,
      'account/security': { localPasswordAvailable: true, otherSessions: 0 },
      'account/ui-preferences': {},
      'account/mobile-devices': revoked ? [] : [{ id, label: 'Fixture phone', current: true }],
      'account/mobile-devices/offline-sites': {
        accountId: id, deviceId: id, version, savedAt: new Date().toISOString(),
        expiresAt: new Date(Date.now() + 86_400_000).toISOString(),
        sites: [{ id, name: site }], truncated: false, journalSiteIds: [id],
      },
    };
    response.writeHead(revoked && endpoint.endsWith('/offline-sites') ? 403 : 200,
      { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify({ data: data[endpoint] ?? [] }));
  });
  try {
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'https://127.0.0.1:' + (server.address() as AddressInfo).port;
    await page.goto(origin + '/account/security');
    await page.getByRole('button', { name: 'Choose offline files' }).click();
    await page.getByText('Offline files', { exact: true }).locator('..').locator('.n-base-selection-tags').click();
    await page.getByText(file.title, { exact: true }).click();
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Save offline copy' }).click();
    await expect(page.getByText('Copy saved until', { exact: false })).toBeVisible();
    await expect(page.getByText('Files saved for offline use (1)', { exact: true })).toBeVisible();
    await page.evaluate(async () => { await navigator.serviceWorker.ready; });
    const raw = await page.evaluate(async () => new Promise<string>((resolve, reject) => {
      const database = indexedDB.open('canabicultarr-offline-sites');
      database.onerror = () => reject(database.error);
      database.onsuccess = () => {
        const read = database.result.transaction('directory').objectStore('directory').get('current');
        read.onsuccess = () => { resolve(JSON.stringify(read.result)); database.result.close(); };
        read.onerror = () => reject(read.error);
      };
    }));
    expect(raw).toContain('ciphertext');
    expect(raw).not.toContain(site);
    expect(raw).not.toContain(passphrase);
    expect(raw).not.toContain(oldNote.body);
    expect(raw).not.toContain(plantName);
    expect(raw).not.toContain(fileBody);
    expect(raw).not.toContain(file.title);
    expect(raw).not.toContain('fixture-cursor');

    await context.setOffline(true);
    await page.goto(origin + '/');
    const english = page.locator('section[lang="en"]');
    await expect(english.getByRole('listitem').and(english.getByText(site, { exact: true }))).toHaveCount(0);
    await english.getByLabel('Offline passphrase').fill('incorrect fixture phrase');
    await english.getByRole('button', { name: 'Unlock sites' }).click();
    await expect(english.getByText('Could not unlock', { exact: false })).toBeVisible();
    await english.getByLabel('Offline passphrase').fill(passphrase);
    await english.getByRole('button', { name: 'Unlock sites' }).click();
    await expect(english.getByRole('listitem').and(english.getByText(site, { exact: true }))).toBeVisible();
    await expect(english.getByText(oldNote.body, { exact: true })).toBeVisible();
    await english.getByText('Offline files', { exact: true }).click();
    await expect(english.getByText(fileBody, { exact: true })).toBeVisible();
    const savedLink = english.getByRole('link', { name: 'Download saved file' });
    await expect(savedLink).toHaveAttribute('download', 'offline.txt');
    const downloadEvent = page.waitForEvent('download');
    await savedLink.click();
    const download = await downloadEvent;
    expect(await readFile(await download.path(), 'utf8')).toBe(fileBody);
    await download.delete();

    await english.getByText('Plant records', { exact: true }).click();
    await expect(english.getByRole('listitem').getByText(plantName, { exact: true })).toBeVisible();
    const plantFilter = english.getByLabel('Filter saved plants');
    await plantFilter.fill('absent');
    await expect(english.getByRole('listitem').getByText(plantName, { exact: true })).toHaveCount(0);
    await plantFilter.fill('fixture strain');
    await expect(english.getByRole('listitem').getByText(plantName, { exact: true })).toBeVisible();
    await english.getByRole('button', { name: 'Lock copy' }).click();
    await expect(english.getByRole('listitem').and(english.getByText(site, { exact: true }))).toHaveCount(0);
    await expect(english.getByText(fileBody, { exact: true })).toHaveCount(0);
    fileRevoked = true;


    await context.setOffline(false);
    await page.goto(origin + '/account/security');
    await expect(page.getByText('Files saved for offline use (1)', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Copy saved until', { exact: false })).toBeVisible();

    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Save offline copy' }).click();
    await expect(page.getByText('The offline copy could not be updated.', { exact: false })).toBeVisible();
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Save offline copy' }).click();
    await expect.poll(() => journalRequests).toEqual([null, 'fixture-cursor', 'fixture-cursor']);
    await expect(page.getByLabel('Offline passphrase')).toBeEnabled();
    await context.setOffline(true);
    await page.goto(origin + '/');
    await english.getByLabel('Offline passphrase').fill(passphrase);
    await english.getByRole('button', { name: 'Unlock sites' }).click();
    await expect(english.getByText(newNote.title)).toBeVisible();
    await expect(english.getByText(oldNote.title)).toHaveCount(0);
    const title = 'Morning observation';
    const body = 'Draft while offline';
    await english.getByLabel('Note title').fill(title);
    await english.getByLabel('Journal note').fill(body);
    await english.getByRole('button', { name: 'Queue journal note' }).click();
    await expect(english.getByText('The journal note is saved', { exact: false })).toBeVisible();
    expect(uploaded).toHaveLength(0);

    await english.getByLabel('Offline passphrase').fill(passphrase);
    await english.getByRole('button', { name: 'Unlock sites' }).click();
    await english.getByText('Plant records', { exact: true }).click();
    await english.getByLabel('Maintenance type').fill('Inspection');
    await english.getByLabel('Maintenance comment').fill('Check fixture');
    await english.locator('[data-offline-maintenance] input[type="file"]').setInputFiles({
      name: 'inspection.txt', mimeType: 'text/plain', buffer: Buffer.from(attachmentText),
    });
    await english.getByRole('button', { name: 'Queue maintenance' }).click();
    await expect(english.getByText('Maintenance is saved', { exact: false })).toBeVisible();
    expect(maintenanceRequests).toHaveLength(0);

    await context.setOffline(false);
    await page.goto(origin + '/icons/app-192.png');
    deployWorkerUpdate = true;
    await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready;
      await registration.update();
    });
    await expect.poll(() => page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready;
      return registration.waiting?.state;
    })).toBe('installed');
    // Release the old controlled document so the waiting worker can activate.
    await page.goto('about:blank');
    await page.goto(origin + '/icons/app-192.png');
    await expect.poll(() => page.evaluate(async () =>
      (await caches.keys()).filter(key => key.startsWith('canabicultarr-public-offline-')),
    )).toEqual(['canabicultarr-public-offline-fixture-upgrade']);

    await context.setOffline(true);
    await page.goto(origin + '/');
    await english.getByLabel('Offline passphrase').fill(passphrase);
    await english.getByRole('button', { name: 'Unlock sites' }).click();
    await expect(english.getByText('Pending actions (2)', { exact: true })).toBeVisible();
    await expect(english.getByText(newNote.title)).toBeVisible();

    plantVersion = 'fixture-plants-2';
    await context.setOffline(false);
    await page.goto(origin + '/account/security');
    await expect(page.getByText('Pending actions (2)', { exact: true })).toBeVisible();
    await expect(page.getByText('Copy saved until', { exact: false })).toHaveCount(0);
    const otherTab = await context.newPage();
    try {
      await otherTab.goto(origin + '/icons/app-192.png');
      await otherTab.evaluate(() => new Promise<void>(acquired => {
        void navigator.locks.request('canabicultarr-offline-sync', () => {
          acquired();
          return new Promise<void>(() => {});
        });
      }));
      await page.getByLabel('Offline passphrase').fill(passphrase);
      await page.getByRole('button', { name: 'Send pending actions' }).click();
      await expect.poll(() => page.evaluate(async () =>
        (await navigator.locks.query()).pending?.some(lock => lock.name === 'canabicultarr-offline-sync'),
      )).toBe(true);
      expect(uploaded).toHaveLength(0);
    } finally {
      await otherTab.close();
    }
    await expect(page.getByText('Some actions were not sent', { exact: false })).toBeVisible();
    await expect(page.getByText('Pending actions (2)', { exact: true })).toBeVisible();
    await page.clock.install();
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Send pending actions' }).click();
    await expect.poll(() => attachments.length).toBe(1);
    await page.clock.fastForward(60_500);
    await expect(page.getByText('Actions sent (1)', { exact: true })).toBeVisible();
    await expect(page.getByText('Pending actions (1)', { exact: true })).toBeVisible();
    expect(attachments).toHaveLength(1);
    expect(maintenanceRequests).toHaveLength(0);
    await page.goto(origin + '/');
    await expect(page.getByText('Pending actions (1)', { exact: true })).toBeVisible();
    await context.setOffline(true);
    await page.getByRole('button', { name: 'Unlock to sync' }).click();
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Auto-sync for 15 minutes' }).click();
    await expect(page.getByText('Automatic sync is on for this app session.')).toBeVisible();
    expect(attachments).toHaveLength(1);
    await page.getByTestId('nav-account').click();
    await expect(page).toHaveURL(/\/account$/);
    await expect(page.getByText('Automatic sync is on for this app session.')).toBeVisible();
    await context.setOffline(false);
    await expect.poll(() => maintenanceRequests.length).toBe(1);
    await page.getByRole('tab', { name: 'Security' }).click();
    await expect(page.getByLabel('Offline passphrase')).toBeEnabled();
    await page.getByRole('button', { name: 'Stop auto-sync' }).click();
    await expect(page.getByText('Automatic sync is on for this app session.')).toHaveCount(0);
    expect(attachments[0].id).toMatch(/^[a-f0-9-]{36}$/);
    expect(attachments[0].id).toEqual(attachments[1].id);
    expect(attachments[1].body).toContain(attachmentText);
    expect(attachments[1].version).toBe('7');
    expect(maintenanceRequests).toHaveLength(1);
    expect(maintenanceRequests[0]).toMatchObject({
      account_id: id, device_id: id, type: 'Inspection', version: 7, comment: 'Check fixture', attachment_id: attachments[0].id,
    });
    await page.reload();
    await expect(page.getByText('Pending actions (1)', { exact: true })).toBeVisible();
    const rejectionInClearText = await page.evaluate(() => new Promise<boolean>((resolve, reject) => {
      const db = indexedDB.open('canabicultarr-offline-sites');
      db.onerror = () => reject(db.error);
      db.onsuccess = () => {
        const read = db.result.transaction('directory').objectStore('directory').get('current');
        read.onsuccess = () => {
          resolve(JSON.stringify(read.result).includes('timeInvalid'));
          db.result.close();
        };
      };
    }));
    expect(rejectionInClearText).toBe(false);
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Review pending actions' }).click();
    await expect(page.getByText('inspection.txt', { exact: true })).toBeVisible();
    const review = page.getByRole('dialog', { name: 'Review pending actions' });
    await expect(review.getByText('The server refused the saved date and time.', { exact: false })).toBeVisible();
    await expect(review.locator('time')).toHaveAttribute('datetime', String(maintenanceRequests[0].occurred_at));
    await page.keyboard.press('Escape');
    await expect(review).toHaveCount(0);
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Send pending actions' }).click();
    await expect.poll(() => maintenanceRequests.length).toBe(2);
    await expect(page.getByLabel('Offline passphrase')).toBeEnabled();
    expect(maintenanceRequests[1]).toEqual(maintenanceRequests[0]);
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Review pending actions' }).click();
    await expect(page.getByText('This plant changed since you saved the offline copy.', { exact: false })).toBeVisible();
    await page.getByRole('button', { name: 'Discard this draft' }).click();
    await expect(page.getByText('Pending actions (1)', { exact: true })).toHaveCount(0);
    expect(uploaded).toHaveLength(2);
    expect(uploaded[0]).toEqual(uploaded[1]);
    expect(uploaded[0]).toMatchObject({ site_id: id, title, body, account_id: id, device_id: id });

    plantVersion = 'fixture-plants-1';
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Save offline copy' }).click();
    await expect(page.getByText('Copy saved until', { exact: false })).toBeVisible();
    await context.setOffline(true);
    await page.goto(origin + '/');
    await english.getByLabel('Offline passphrase').fill(passphrase);
    await english.getByRole('button', { name: 'Unlock sites' }).click();
    await english.getByText('Plant records', { exact: true }).click();
    await english.getByLabel('Gallery photo', { exact: true }).setInputFiles({
      name: 'offline.png', mimeType: 'image/png', buffer: Buffer.from('fictional photo'),
    });
    await english.getByRole('button', { name: 'Queue gallery photo' }).click();
    await expect(english.getByText('The photo is saved in the offline queue.')).toBeVisible();
    await context.setOffline(false);
    await page.goto(origin + '/');
    await page.getByRole('button', { name: 'Unlock to sync' }).click();
    await page.getByLabel('Offline passphrase').fill(passphrase);
    await page.getByRole('button', { name: 'Auto-sync for 15 minutes' }).click();
    await expect.poll(() => galleryUploads).toEqual([expect.stringMatching(/^[a-f0-9-]{36}$/)]);
    await page.getByRole('button', { name: 'Stop auto-sync' }).click();
    revoked = true;
    const recheck = page.waitForResponse(response => response.url().endsWith('/offline-sites') && response.status() === 403);
    await page.goto(origin + '/account/security');
    await recheck;
    await expect.poll(async () => await page.evaluate(async () => new Promise<boolean>(resolve => {
      const database = indexedDB.open('canabicultarr-offline-sites');
      database.onsuccess = () => {
        const read = database.result.transaction('directory').objectStore('directory').get('current');
        read.onsuccess = () => { resolve(read.result.snapshot === null); database.result.close(); };
      };
    }))).toBe(true);
    await context.setOffline(true);
    await page.goto(origin + '/');
    await expect(english.getByRole('button', { name: 'Unlock sites' })).toHaveCount(0);
  } finally {
    server.closeAllConnections();
    await new Promise<void>(resolve => server.close(() => resolve()));
    await rm(fixture, { recursive: true, force: true });
  }
});
