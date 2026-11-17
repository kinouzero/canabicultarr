import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import type { Plugin } from 'vite';
import en from '../src/locales/en/common.json' with { type: 'json' };
import fr from '../src/locales/fr/common.json' with { type: 'json' };

function escape(value: string) {
  return value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]!);
}

export function publicOfflineFallback(): Plugin {
  return {
    name: 'canabicultarr-public-offline',
    apply: 'build',
    generateBundle() {
      const sections = Object.entries({ en, fr }).map(([locale, messages]) => `
        <section lang="${locale}" aria-labelledby="title-${locale}">
          <h1 id="title-${locale}">${escape(messages.pwa.offlineTitle)}</h1>
          <p>${escape(messages.pwa.offlineDescription)}</p>
          <a href="/">${escape(messages.pwa.retry)}</a>
        </section>`).join('');
      const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
<title>Canabicultarr</title><style>
*{box-sizing:border-box}body{margin:0;padding:2rem 1rem;background:#f1f6f3;color:#17372b;font:1rem/1.6 system-ui,sans-serif}
main{max-width:38rem;margin:auto}header{font-weight:700;font-size:1.25rem}section{margin:1.5rem 0;padding:1.5rem;background:white;border:1px solid #c1d3c8;border-radius:1rem}
h1{font-size:1.5rem;margin-top:0}a{display:inline-block;background:#17372b;color:white;padding:.6rem 1rem;border-radius:.5rem;text-decoration:none}a:focus-visible{outline:3px solid #10b981;outline-offset:3px}
</style></head><body><!-- canabicultarr-public-offline --><main><header>Canabicultarr</header>${sections}</main></body></html>`;
      const template = readFileSync(new URL('../pwa/sw.js', import.meta.url), 'utf8').replace('__PUSH_MESSAGES__', JSON.stringify({ en: en.pushNotifications, fr: fr.pushNotifications }));
      const version = createHash('sha256').update(html).update(template).digest('hex').slice(0, 20);
      const integrity = 'sha256-' + createHash('sha256').update(html).digest('base64');
      this.emitFile({ type: 'asset', fileName: 'offline.html', source: html });
      this.emitFile({ type: 'asset', fileName: 'sw.js', source: template.replace('__OFFLINE_VERSION__', version).replace('__OFFLINE_INTEGRITY__', integrity) });
    },
  };
}
