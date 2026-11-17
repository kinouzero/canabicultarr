import encodeQR from 'qr';

export function qrSvg(url: string): string {
 const svg = encodeQR(url, 'svg', { ecc: 'quartile', border: 4, scale: 8 });
 const bounds = svg.match(/viewBox="0 0 (\d+) (\d+)"/);
 if (!bounds) throw new Error('QR dimensions unavailable');
 // Explicit dimensions and an opaque quiet zone survive PNG export and dark viewers.
 const close = svg.indexOf('>', svg.indexOf('<svg '));
 if (close < 0) throw new Error('QR root unavailable');
 return `<svg width="${bounds[1]}" height="${bounds[2]}" ${svg.slice(svg.indexOf('<svg ') + 5, close)}><rect width="100%" height="100%" fill="white"/>${svg.slice(close + 1)}`;
}

export function qrImage(svg: string): string { return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`; }

export async function qrPng(svg: string): Promise<Blob> {
 const image = new Image(); image.src = qrImage(svg); await image.decode();
 const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
 const context = canvas.getContext('2d');
 if (!context) throw new Error('Canvas unavailable');
 context.drawImage(image, 0, 0);
 return new Promise((resolve, reject) => canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Image unavailable')), 'image/png'));
}

export async function printQrLabel(label: { title: string; name: string; id: string; url: string; svg: string }): Promise<() => void> {
 const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
 const restoreFocus = () => requestAnimationFrame(() => { if (opener?.isConnected) opener.focus(); });
 const frame = document.createElement('iframe');
 let expiry: ReturnType<typeof setTimeout> | undefined;
 const dispose = () => { clearTimeout(expiry); frame.remove(); };
 frame.title = label.title; frame.setAttribute('aria-hidden', 'true'); frame.setAttribute('sandbox', 'allow-same-origin allow-modals');
 frame.style.cssText = 'position:fixed;width:1px;height:1px;bottom:0;left:0;border:0;opacity:0;pointer-events:none';
 document.body.append(frame);
 try {
  const doc = frame.contentDocument; const view = frame.contentWindow;
  if (!doc || !view) throw new Error('Print frame unavailable');
  doc.title = label.title; doc.documentElement.lang = document.documentElement.lang;
  const style = doc.createElement('style');
  style.textContent = '@page{margin:10mm}body{margin:0;color:#000;background:#fff;font:12px/1.4 sans-serif}.label{width:60mm;max-width:100%;margin:auto;break-inside:avoid;text-align:center}img{width:46mm;max-width:100%;height:auto}h1{font-size:16px;margin:0 0 3mm;overflow-wrap:anywhere}p{margin:2mm 0;overflow-wrap:anywhere}code{font-size:9px}';
  doc.head.append(style);
  const section = doc.createElement('section'); section.className = 'label';
  const title = doc.createElement('h1'); title.textContent = label.name;
  const image = doc.createElement('img'); image.src = qrImage(label.svg); image.alt = label.title;
  const id = doc.createElement('p'); const code = doc.createElement('code'); code.textContent = label.id; id.append(code);
  const url = doc.createElement('p'); url.textContent = label.url;
  section.append(title, image, id, url); doc.body.append(section);
  await image.decode();
  view.addEventListener('afterprint', () => { dispose(); restoreFocus(); }, { once: true });
  // Some browsers do not emit afterprint for a canceled print preview.
  expiry = setTimeout(dispose, 60000);
  view.focus(); view.print();
  restoreFocus();
  return dispose;
 } catch (cause) { dispose(); throw cause; }
}
