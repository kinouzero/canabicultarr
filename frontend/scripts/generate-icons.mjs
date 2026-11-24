import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';

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

  const mark = await readFile(new URL('../branding/mark.png', import.meta.url));
  const palette = JSON.parse(await readFile(new URL('../branding/palette.json', import.meta.url), 'utf8'));
  const installIcons = [
    { name: 'app-192', size: 192 },
    { name: 'pwa-512', size: 512 },
    { name: 'app-maskable-512', size: 512, scale: 0.82 },
    { name: 'apple-touch-icon', size: 180 },
  ];
  const images = await page.evaluate(async ({ data, icons }) => {
    const mark = new Image();
    mark.src = 'data:image/png;base64,' + data;
    await mark.decode();
    return icons.map(({ name, size, background, scale = 0.96 }) => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = size;
      const context = canvas.getContext('2d');
      context.fillStyle = background;
      context.fillRect(0, 0, size, size);
      context.imageSmoothingQuality = 'high';
      // Leave more room around the maskable mark for launcher crops.
      const width = size * scale;
      const inset = (size - width) / 2;
      context.drawImage(mark, inset, inset, width, width);
      return { name, data: canvas.toDataURL('image/png').split(',')[1] };
    });
  }, {
    data: mark.toString('base64'),
    icons: installIcons.flatMap(icon => [
      { ...icon, background: palette.colors.dark_background },
      { ...icon, name: icon.name + '-light', background: palette.colors.light_background },
    ]),
  });
  for (const { name, data } of images) {
    await writeFile(new URL(`../public/icons/${name}.png`, import.meta.url), Buffer.from(data, 'base64'));
  }

  const manifest = JSON.parse(await readFile(new URL('../public/manifest.webmanifest', import.meta.url), 'utf8'));
  const lightManifest = {
    ...manifest,
    icons: manifest.icons.map(icon => ({ ...icon, src: icon.src.replace('.png', '-light.png') })),
  };
  await writeFile(new URL('../public/manifest-light.webmanifest', import.meta.url), JSON.stringify(lightManifest, null, 2) + '\n');
} finally { await browser.close(); }
