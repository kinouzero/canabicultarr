import { readFile } from 'node:fs/promises';
import { chromium, expect } from '../../../frontend/node_modules/@playwright/test/index.mjs';

const url = process.env.CANABICULTARR_DESKTOP_WIZARD_URL;
if (!url) throw new Error('A disposable Linux wizard URL is required.');

const paletteFile = new URL('../../../frontend/branding/palette.json', import.meta.url);
const { colors } = JSON.parse(await readFile(paletteFile, 'utf8'));
const browser = await chromium.launch({ channel: 'chromium' });
try {
  for (const colorScheme of ['light', 'dark']) {
    const page = await browser.newPage({ locale: 'fr-FR', colorScheme, viewport: { width: 760, height: 720 } });
    await page.goto(url);
    await expect(page.getByRole('heading', { name: 'Configurer Canabicultarr' })).toBeVisible();
    const logo = page.locator('header img');
    await expect(logo).toBeVisible();
    await expect.poll(() => logo.evaluate(image => image.complete && image.naturalWidth > 0)).toBe(true);
    const background = await page.locator('body').evaluate(body => getComputedStyle(body).backgroundColor);
    const hex = colors[colorScheme === 'light' ? 'light_background' : 'dark_background'];
    const channels = hex.slice(1).match(/../g).map(channel => parseInt(channel, 16));
    expect(background).toBe(`rgb(${channels.join(', ')})`);
    await page.getByRole('button', { name: 'Utiliser Gmail' }).click();
    await expect(page.getByLabel('Serveur SMTP')).toHaveValue('smtp.gmail.com');
    await page.close();
  }

  console.log('Linux wizard logo, light and dark palettes, and Gmail preset passed in Chromium.');
} finally {
  await browser.close();
}
