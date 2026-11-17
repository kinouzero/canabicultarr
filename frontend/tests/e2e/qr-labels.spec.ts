import { readFile } from 'node:fs/promises';
import { test, expect, type Page } from '../coverage/fixture';
import decodeQR from 'qr/decode.js';
import { insightRoutes } from './insights-fixture';

async function stockRoutes(page: Page) {
 const session = await insightRoutes(page);
 const stock = { id: 'stock-qr', name: 'Seed <script>alert(1)</script>', type: 'seeds', unit: 'piece', totalQuantity: '4', threshold: null, decimalPlaces: 0, isLowStock: false, isArchived: false, version: 1, canManage: false, description: null, sku: null };
 const mutations: string[] = [];
 await page.route('**/api/v1/inventory/items**', route => {
  const path = new URL(route.request().url()).pathname;
  if (route.request().method() !== 'GET') mutations.push(path);
  if (path.endsWith('/stock-qr')) return route.fulfill({ json: { data: stock } });
  if (path.endsWith('/missing')) return route.fulfill({ status: 404, json: { error: { code: 'auth.notFound' } } });
  return route.fulfill({ json: { data: path.endsWith('/items') ? [stock] : [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } } });
 });
 return { ...session, stock, mutations };
}

async function decodedImage(page: Page, source: string) {
 const image = await page.evaluate(async src => {
  const img = new Image(); img.src = src; await img.decode();
  const canvas = document.createElement('canvas'); canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
  const context = canvas.getContext('2d')!; context.drawImage(img, 0, 0);
  return { width: canvas.width, height: canvas.height, data: [...context.getImageData(0, 0, canvas.width, canvas.height).data] };
 }, source);
 return decodeQR({ ...image, data: Uint8Array.from(image.data) });
}

test('readers can export decodable QR images and print escaped record labels without changing records', async ({ page }, testInfo) => {
 const { stock, errors, mutations } = await stockRoutes(page);
 await page.goto('/inventory?stock=stock-qr&q=private');
 const opener = page.getByRole('button', { name: 'QR code', exact: true });
 for (const width of [1440, 390]) {
  await page.setViewportSize({ width, height: 1000 });
  const offset = await opener.evaluate(button => {
   const frame = button.getBoundingClientRect(); const icon = button.querySelector('svg')!.getBoundingClientRect();
   return { x: Math.abs(frame.x + frame.width / 2 - icon.x - icon.width / 2), y: Math.abs(frame.y + frame.height / 2 - icon.y - icon.height / 2) };
  });
  expect(offset.x).toBeLessThanOrEqual(1); expect(offset.y).toBeLessThanOrEqual(1);
 }
 await page.setViewportSize({ width: 1440, height: 1000 }); await opener.click();
 const dialog = page.getByRole('dialog', { name: 'Étiquette QR', exact: true }); await expect(dialog).toBeVisible();
 await expect(dialog.getByRole('heading', { name: 'Étiquette QR', level: 3, exact: true })).toBeVisible();
 await expect(dialog.locator(':scope > .n-card__footer')).toBeInViewport();
 const target = `${new URL(page.url()).origin}/inventory?stock=stock-qr`;
 await expect(dialog.locator('.qr-link')).toHaveAttribute('href', target);
 await expect(dialog.locator('.qr-preview strong')).toHaveText(stock.name);
 await dialog.screenshot({ path: testInfo.outputPath('qr-desktop.png') });
 for (const format of ['SVG', 'PNG']) {
  const download = page.waitForEvent('download'); await dialog.getByRole('button', { name: format, exact: true }).click();
  const file = await download; expect(file.suggestedFilename()).toBe(`stock-stock-qr.qr.${format.toLowerCase()}`);
  const bytes = await readFile((await file.path())!);
  const source = format === 'SVG' ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(bytes.toString())}` : `data:image/png;base64,${bytes.toString('base64')}`;
  expect(await decodedImage(page, source)).toBe(target);
 }
 // Intercept only this iframe's native print call; no system printer is involved.
 await page.evaluate(() => {
  const append = document.body.append.bind(document.body);
  document.body.append = (...nodes: (Node | string)[]) => {
   append(...nodes);
   for (const node of nodes) if (node instanceof HTMLIFrameElement && node.contentWindow) node.contentWindow.print = () => node.setAttribute('data-print-requested', 'true');
  };
 });
 await dialog.getByRole('button', { name: 'Imprimer', exact: true }).click();
 const frame = page.locator('iframe[data-print-requested="true"]'); await expect(frame).toHaveCount(1);
 await expect(frame.contentFrame().locator('h1')).toHaveText(stock.name);
 await expect(frame.contentFrame().locator('script')).toHaveCount(0);
 await expect(frame.contentFrame().locator('p').last()).toHaveText(target);
 await page.keyboard.press('Escape'); await expect(dialog).toHaveCount(0); await expect(opener).toBeFocused();
 await expect(frame).toHaveCount(0);
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});

test('mobile QR labels retain their parent and missing record links cannot produce a label', async ({ page }, testInfo) => {
 const { errors, mutations } = await stockRoutes(page); await page.setViewportSize({ width: 390, height: 844 });
 await page.goto('/inventory?stock=stock-qr');
 await page.getByRole('button', { name: 'QR code', exact: true }).click();
 const dialog = page.getByRole('dialog', { name: 'Étiquette QR', exact: true }); await expect(dialog).toBeVisible();
 await expect(dialog.getByRole('heading', { name: 'Étiquette QR', level: 3, exact: true })).toBeVisible();
 await expect(dialog.locator(':scope > .n-card__footer')).toBeInViewport();
 expect(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
 await dialog.screenshot({ path: testInfo.outputPath('qr-mobile.png') });
 await dialog.getByRole('button', { name: 'Fermer l’étiquette QR', exact: true }).click();
 expect(new URL(page.url()).searchParams.get('stock')).toBe('stock-qr');
 await page.goto('/inventory?stock=missing');
 await expect(page.getByRole('alert')).toBeVisible();
 await expect(page.getByRole('button', { name: 'QR code', exact: true })).toHaveCount(0);
 expect(mutations).toEqual([]); expect(errors).toEqual([]);
});
