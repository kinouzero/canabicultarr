import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';

// Preserve supplied browser/app icons, including maskable artwork; derive interface assets.
const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
try {
  const page = await browser.newPage();
  for (const [name, width] of [['mark', 256], ['wordmark', 960], ['monochrome', 256]]) {
    const png = await readFile(new URL(`../branding/${name}.png`, import.meta.url));
    const result = await page.evaluate(async ({ data, width }) => {
      const image = new Image();
      image.src = 'data:image/png;base64,' + data;
      await image.decode();
      const source = document.createElement('canvas');
      source.width = image.width; source.height = image.height;
      const context = source.getContext('2d');
      context.drawImage(image, 0, 0);
      const pixels = context.getImageData(0, 0, source.width, source.height).data;
      let left = source.width, top = source.height, right = 0, bottom = 0;
      for (let y = 0; y < source.height; y++) for (let x = 0; x < source.width; x++) {
        if (pixels[(y * source.width + x) * 4 + 3] > 8) {
          left = Math.min(left, x); top = Math.min(top, y);
          right = Math.max(right, x); bottom = Math.max(bottom, y);
        }
      }
      const croppedWidth = right - left + 1, croppedHeight = bottom - top + 1;
      const canvas = document.createElement('canvas');
      canvas.width = width; canvas.height = Math.round(width * croppedHeight / croppedWidth);
      const output = canvas.getContext('2d');
      output.imageSmoothingQuality = 'high';
      output.drawImage(image, left, top, croppedWidth, croppedHeight, 0, 0, canvas.width, canvas.height);
      return { data: canvas.toDataURL('image/webp', .92).split(',')[1], height: canvas.height };
    }, { data: png.toString('base64'), width });
    await writeFile(new URL(`../public/branding/${name}.webp`, import.meta.url), Buffer.from(result.data, 'base64'));
    console.log(`Generated ${name}.webp (${width}×${result.height}).`);
  }
} finally { await browser.close(); }
