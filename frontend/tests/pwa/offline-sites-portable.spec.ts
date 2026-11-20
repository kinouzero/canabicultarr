import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from '@playwright/test';
import { test, expect } from '../coverage/fixture';

test('the cached offline page reads saved records and queues plant actions', { tag: '@cross-engine' }, async ({ page, context }) => {
  const source = await readFile(new URL('../../src/lib/offline-sites.js', import.meta.url), 'utf8');
  const site = 'Fictional site on a paired browser';
  const passphrase = 'fictional offline phrase';
  const id = '00000000-0000-4000-8000-000000000001';
  await page.goto('/icons/app-192.png');
  await page.evaluate(async ({ source, site, passphrase, id }) => {
    const moduleUrl = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
    try {
      const store = await import(moduleUrl);
      const { revision } = await store.readOfflineState();
      await store.saveOfflineSites({
        journalSiteIds: [id],
        plantCatalog: {
          version: 'fixture-plants', truncated: false,
          creation: { locations: [{ id, type: 'site', name: site }], strains: [{ id, name: 'Fixture strain' }] },
          plants: [
            { id, name: 'Saved plant', strain: 'Fixture strain', location: [site], phase: 'growth', phaseName: 'Custom phase', version: 7, canPhotograph: true, canMaintain: true, canWater: false },
            { id: '00000000-0000-4000-8000-000000000002', name: 'Watering fixture', strain: 'Fixture strain', location: [site],
              phase: 'growth', phaseName: null, version: 3, canMaintain: false, canWater: true, waterLiters: '1.000',
              wateringRecipe: { validUntil: null, products: [
                { id, name: 'Fixture fertilizer', unit: 'ml', dose: '2.000', balances: [{ id, name: 'Fixture shelf' }] },
              ] } },
          ],
        },
        journal: [{ id, type: 'upsert', site_id: id, title: 'Saved journal note', body: 'Available offline.', occurred_at: new Date().toISOString() }],
        sites: [{ id, name: site }], truncated: false, accountId: id, deviceId: id, version: 'fixture-1',
        savedAt: new Date().toISOString(), expiresAt: new Date(Date.now() + 3_600_000).toISOString(),
      }, passphrase, revision, {
        ticket: 'fixture', expiresAt: new Date(Date.now() + 3_600_000).toISOString(),
        files: [{ id, title: 'Saved image', name: 'saved.png', mediaType: 'image/png',
          base64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0wAAAABJRU5ErkJggg==' }],
      });
      await navigator.serviceWorker.register('/sw.js', { scope: '/' });
      await navigator.serviceWorker.ready;
    } finally {
      URL.revokeObjectURL(moduleUrl);
    }
  }, { source, site, passphrase, id });

  await context.setOffline(true);
  await page.goto('/');
  const english = page.locator('section[lang="en"]');
  await expect(english.getByText(/Pending actions are stored in this browser/)).toBeVisible();
  await english.getByLabel('Offline passphrase').fill(passphrase);
  await english.getByRole('button', { name: 'Unlock sites' }).click();
  await expect(english.getByRole('listitem').and(english.getByText(site, { exact: true }))).toBeVisible();
  await expect(english.getByText('Saved journal note', { exact: true })).toBeVisible();
  await english.getByText('Offline files', { exact: true }).click();
  const image = english.getByRole('img', { name: 'Saved image' });
  await expect(image).toBeVisible();
  await expect.poll(() => image.evaluate(element => (element as HTMLImageElement).naturalWidth)).toBe(1);
  await english.getByText('Plant records', { exact: true }).click();
  await expect(english.getByRole('listitem').getByText('Saved plant', { exact: true })).toBeVisible();
  await expect(english.getByText(/Custom phase/)).toBeVisible();
  await english.getByLabel('Note title').fill('Portable test note');
  await english.getByLabel('Journal note').fill('Queued without a network.');
  await english.getByRole('button', { name: 'Queue journal note' }).click();
  await expect(english.getByText('The journal note is saved', { exact: false })).toBeVisible();
  await english.getByLabel('Offline passphrase').fill(passphrase);
  await english.getByRole('button', { name: 'Unlock sites' }).click();
  await english.getByText('Plant records', { exact: true }).click();
  await expect(english.getByText('Pending actions (1)')).toBeVisible();
  await english.getByLabel('Maintenance type').fill('Inspection');
  const file = { name: 'offline-photo.jpg', mimeType: 'image/jpeg', buffer: Buffer.from([0xff, 0xd8, 0xff, 0xd9]) };
  const attachment = english.locator('[data-offline-maintenance] input[type="file"]');
  const submitMaintenance = english.getByRole('button', { name: 'Queue maintenance' });
  await attachment.setInputFiles({ ...file, buffer: Buffer.alloc(2 * 1024 * 1024 + 1) });
  await submitMaintenance.click();
  await expect(english.getByText(/file up to 2 MiB/)).toBeVisible();
  await attachment.setInputFiles(file);
  await page.evaluate(() => {
    const original = IDBObjectStore.prototype.put;
    IDBObjectStore.prototype.put = function () {
      IDBObjectStore.prototype.put = original;
      throw new DOMException('Simulated browser storage limit', 'QuotaExceededError');
    };
  });
  await submitMaintenance.click();
  await expect(english.getByText(/browser has run out of storage/)).toBeVisible();
  await expect(english.getByText('Pending actions (1)')).toBeVisible();
  await expect(english.getByLabel('Maintenance type')).toHaveValue('Inspection');
  await submitMaintenance.click();
  await expect(english.getByText('Maintenance is saved', { exact: false })).toBeVisible();
  await english.getByLabel('Offline passphrase').fill(passphrase);
  await english.getByRole('button', { name: 'Unlock sites' }).click();
  await english.getByText('Plant records', { exact: true }).click();
  await expect(english.getByLabel('Water volume (L)')).toHaveValue('1.000');
  await english.getByLabel('Watering with fertilizer').check();
  await english.getByLabel('Water volume (L)').fill('1.25');
  await english.getByLabel('Dose per liter (ml/L)').fill('2.5');
  await english.getByRole('button', { name: 'Queue watering with fertilizer' }).click();
  await expect(english.getByText('Watering is saved', { exact: false })).toBeVisible();
  await english.getByLabel('Offline passphrase').fill(passphrase);
  await english.getByRole('button', { name: 'Unlock sites' }).click();
  await english.getByText('Plant records', { exact: true }).click();
  await english.getByLabel('New plant name').fill('Offline planned plant');
  await english.getByRole('button', { name: 'Queue planned plant' }).click();
  await expect(english.getByText('The planned plant is saved', { exact: false })).toBeVisible();

  await english.getByLabel('Offline passphrase').fill(passphrase);
  await english.getByRole('button', { name: 'Unlock sites' }).click();
  await english.getByText('Plant records', { exact: true }).click();
  await english.getByLabel('Gallery photo', { exact: true }).setInputFiles(file);
  await english.getByRole('button', { name: 'Queue gallery photo' }).click();
  await expect(english.getByText('The photo is saved in the offline queue.')).toBeVisible();

  await context.setOffline(false);
  await page.goto('/icons/app-192.png');
  const { state, saved } = await page.evaluate(async ({ source, passphrase }) => {
    const moduleUrl = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
    try {
      const store = await import(moduleUrl);
      const original = await store.readOfflineDrafts(passphrase);
      await store.updateOfflineDrafts([], passphrase, new Map([[original.drafts[2].operation_id, 'offlinePlants.timeInvalid']]));
      const now = Date.now;
      Date.now = () => now() + 3_600_001;
      try {
        const state = await store.readOfflineState();
        const saved = await store.readOfflineDrafts(passphrase);
        return { state, saved };
      } finally {
        Date.now = now;
      }
    } finally {
      URL.revokeObjectURL(moduleUrl);
    }
  }, { source, passphrase });
  expect(state).toMatchObject({ metadata: null, library: null, outbox: { count: 5 } });
  expect(saved.drafts[1]).toMatchObject({ kind: 'photo', plant_id: id, version: 7 });
  expect(saved.drafts[2].attachment).toMatchObject({ name: file.name, size: file.buffer.length, base64: file.buffer.toString('base64') });
  expect(saved.drafts[3]).toMatchObject({ kind: 'watering', version: 3, water_liters: '1.250',
    fertilizers: [{ inventory_item_id: id, balance_id: id, dose_per_liter: '2.500', unit: 'ml' }] });
  expect(saved.drafts[4]).toMatchObject({ kind: 'creation', name: 'Offline planned plant', strain_id: id, location: { id, type: 'site' } });

  await page.clock.install();
  await context.setOffline(true);
  await page.goto('/');
  await expect(english.getByText('Portable test note', { exact: true })).toHaveCount(0);
  const unlockDrafts = english.getByRole('button', { name: 'Unlock pending actions' });
  await english.getByLabel('Offline passphrase').fill('invalid fixture passphrase');
  await unlockDrafts.click();
  await expect(english.getByText(/Could not unlock/)).toBeVisible();
  await expect(english.getByText('Portable test note', { exact: true })).toHaveCount(0);
  await english.getByLabel('Offline passphrase').fill(passphrase);
  await unlockDrafts.click();
  await expect(english.getByText('Pending actions (5)', { exact: true })).toBeVisible();
  await english.getByText('Review pending actions', { exact: true }).click();
  await expect(english.getByText('Portable test note', { exact: true })).toBeVisible();
  await expect(english.getByText(/The server refused the saved date/)).toBeVisible();
  await expect(english.getByText('offline-photo.jpg', { exact: true }).first()).toBeVisible();
  await expect(english.getByText('Plant records', { exact: true })).toBeHidden();
  await expect(english.getByText(site, { exact: true })).toHaveCount(0);
  await page.clock.fastForward(5 * 60_000 + 1);
  await expect(english.getByText('Portable test note', { exact: true })).toHaveCount(0);
});

test('pending actions survive a complete browser restart', async ({ baseURL }) => {
  const profile = await mkdtemp(join(tmpdir(), 'cana-offline-'));
  const options = {
    baseURL,
    channel: 'chromium',
    executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
  };
  const passphrase = 'fictional restart phrase';
  const id = '00000000-0000-4000-8000-000000000001';
  const source = await readFile(new URL('../../src/lib/offline-sites.js', import.meta.url), 'utf8');

  try {
    const first = await chromium.launchPersistentContext(profile, options);
    try {
      const page = first.pages()[0];
      await page.goto('/icons/app-192.png');
      await page.evaluate(async ({ source, passphrase, id }) => {
        const moduleUrl = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
        try {
          const store = await import(moduleUrl);
          const { revision } = await store.readOfflineState();
          await store.saveOfflineSites({
            sites: [{ id, name: 'Restart fixture' }], journalSiteIds: [id],
            truncated: false, accountId: id, deviceId: id, version: 'restart',
            savedAt: new Date().toISOString(), expiresAt: new Date(Date.now() + 3_600_000).toISOString(),
          }, passphrase, revision);
          await navigator.serviceWorker.register('/sw.js', { scope: '/' });
          await navigator.serviceWorker.ready;
        } finally {
          URL.revokeObjectURL(moduleUrl);
        }
      }, { source, passphrase, id });
      await first.setOffline(true);
      await page.goto('/');
      const english = page.locator('section[lang="en"]');
      await english.getByLabel('Offline passphrase').fill(passphrase);
      await english.getByRole('button', { name: 'Unlock sites' }).click();
      await english.getByLabel('Note title').fill('Note saved before shutdown');
      await english.getByLabel('Journal note').fill('Queued before closing the browser.');
      await english.getByRole('button', { name: 'Queue journal note' }).click();
      await expect(english.getByText('The journal note is saved', { exact: false })).toBeVisible();
    } finally {
      await first.close();
    }

    const restarted = await chromium.launchPersistentContext(profile, { ...options, offline: true });
    try {
      const page = restarted.pages()[0];
      await page.goto('/');
      const english = page.locator('section[lang="en"]');
      const savedNote = english.getByText('Note saved before shutdown', { exact: true });
      await expect(savedNote).toHaveCount(0);
      await english.getByLabel('Offline passphrase').fill(passphrase);
      await english.getByRole('button', { name: 'Unlock sites' }).click();
      await expect(english.getByText('Pending actions (1)', { exact: true })).toBeVisible();
      await english.getByText('Review pending actions', { exact: true }).click();
      await expect(savedNote).toBeVisible();
    } finally {
      await restarted.close();
    }
  } finally {
    await rm(profile, { recursive: true, force: true });
  }
});
