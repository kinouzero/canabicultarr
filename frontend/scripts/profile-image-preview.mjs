import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { resolve, basename } from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// Optional fixture-only benchmark; never connects to an application or user storage.
const files = process.argv.slice(2).map(path => ({ name: basename(path), bytes: readFileSync(resolve(path)) }));
if (!files.length || files.some(file => file.bytes.length > 10485760)) throw new Error('Supply image fixtures of at most 10 MiB each.');
const server = createServer(async (request, response) => {
  const file = files.find(file => `/${encodeURIComponent(file.name)}` === request.url?.split('?')[0]);
  if (!file) { response.writeHead(200, { 'Content-Type': 'text/html' }); response.end('<!doctype html><title>Image fixture benchmark</title>'); return; }
  response.writeHead(200, { 'Content-Type': file.name.endsWith('.webp') ? 'image/webp' : 'image/jpeg', 'Content-Length': file.bytes.length, 'Cache-Control': 'no-store' });
  // Reproducible 10 Mbit/s local transport with 80 ms initial latency.
  await new Promise(done => setTimeout(done, 80));
  for (let offset = 0; offset < file.bytes.length && !response.destroyed; offset += 65536) {
    response.write(file.bytes.subarray(offset, offset + 65536));
    await new Promise(done => setTimeout(done, 65536 * 8 / 10000));
  }
  response.end();
});
await new Promise(done => server.listen(0, '127.0.0.1', done));
const origin = `http://127.0.0.1:${server.address().port}`;
try {
  for (const mobile of [false, true]) for (const file of files) {
    const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
    try {
      const page = await browser.newPage({ viewport: mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 }, isMobile: mobile, deviceScaleFactor: mobile ? 2 : 1 });
      const session = await browser.newBrowserCDPSession();
      const rss = async () => {
        const { processInfo } = await session.send('SystemInfo.getProcessInfo');
        const ids = processInfo.map(process => process.id).filter(Number.isInteger);
        return execFileSync('ps', ['-o', 'rss=', '-p', ids.join(',')], { encoding: 'utf8' }).trim().split(/\s+/).reduce((sum, value) => sum + Number(value) * 1024, 0);
      };
      await page.goto(origin); const initialRss = await rss(); let peakRss = initialRss; let sampling = true;
      const samples = (async () => { while (sampling) { peakRss = Math.max(peakRss, await rss()); await new Promise(done => setTimeout(done, 50)); } })();
      let result;
      try {
        result = await page.evaluate(async name => {
          const image = new Image(); image.style.cssText = 'width:100%;max-width:900px;max-height:72dvh;object-fit:contain'; document.body.append(image);
          const start = performance.now(); image.src = `/${encodeURIComponent(name)}`; await image.decode();
          const resource = performance.getEntriesByName(image.src).at(-1);
          return { openMs: Math.round(performance.now() - start), encodedBodyBytes: resource?.encodedBodySize, transferBytes: resource?.transferSize, width: image.naturalWidth, height: image.naturalHeight, decodedRgbaBytes: image.naturalWidth * image.naturalHeight * 4 };
        }, file.name);
        await page.waitForTimeout(150);
      } finally { sampling = false; await samples; }
      console.log(JSON.stringify({ file: file.name, viewport: mobile ? 'mobile-emulated' : 'desktop', ...result, browserRssBaselineBytes: initialRss, browserRssPeakBytes: peakRss, browserRssDeltaBytes: peakRss - initialRss }));
    } finally { await browser.close(); }
  }
} finally { await new Promise(done => server.close(done)); }
