import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import type { Plugin } from 'vite';
import { offlineDraftRejections } from '../src/lib/offline-draft-rejections.ts';
import en from '../src/locales/en/common.json' with { type: 'json' };
import fr from '../src/locales/fr/common.json' with { type: 'json' };
import plantsEn from '../src/locales/en/plants.json' with { type: 'json' };
import plantsFr from '../src/locales/fr/plants.json' with { type: 'json' };

const phaseLabels = { en: plantsEn.phases, fr: plantsFr.phases };

function escape(value: string) {
  return value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]!);
}

function rejectionMessages(messages: { offlinePlants: object; plants: object }) {
  return Object.fromEntries(Object.values(offlineDraftRejections).map(key => {
    const value = key.split('.').reduce<unknown>((source, part) =>
      source && typeof source === 'object' ? (source as Record<string, unknown>)[part] : undefined, messages);
    if (typeof value !== 'string') throw new Error('Missing offline rejection message: ' + key);
    return [key, value];
  }));
}

export function publicOfflineFallback(): Plugin {
  return {
    name: 'canabicultarr-public-offline',
    apply: 'build',
    generateBundle() {
      const script = [new URL('../src/lib/offline-sites.js', import.meta.url), new URL('../pwa/offline-sites-view.js', import.meta.url)]
        .map(path => readFileSync(path, 'utf8')).join('\n');
      const scriptHash = 'sha256-' + createHash('sha256').update(script).digest('base64');
      const sections = (['en', 'fr'] as const).map(locale => {
        const messages = { en, fr }[locale];
        const rejections = rejectionMessages({ offlinePlants: messages.offlinePlants, plants: { en: plantsEn, fr: plantsFr }[locale] });
        return `
        <section lang="${locale}" aria-labelledby="title-${locale}">
          <h1 id="title-${locale}">${escape(messages.pwa.offlineTitle)}</h1>
          <p>${escape(messages.pwa.offlineDescription)}</p>
          <a href="/">${escape(messages.pwa.retry)}</a>
          <div hidden data-offline-sites data-error="${escape(messages.offlineSites.unlockError)}"
            data-queue-full="${escape(messages.offlineSites.queueFull)}"
            data-photo-invalid="${escape(messages.offlinePlants.photoInvalid)}"
            data-attachment-invalid="${escape(messages.offlineSites.attachmentInvalid)}"
            data-attachment-limit="${escape(messages.offlineSites.attachmentLimit)}"
            data-device-storage-full="${escape(messages.offlineSites.deviceStorageFull)}">
            <h2>${escape(messages.offlineSites.title)}</h2>
            <p>${escape(messages.offlineSites.storageRisk)}</p>
            <form>
              <label>${escape(messages.offlineSites.passphrase)} <input type="password" required minlength="12" maxlength="256" autocomplete="off"></label>
              <button type="submit" data-sites="${escape(messages.offlineSites.unlock)}"
                data-drafts="${escape(messages.offlineSites.unlockDrafts)}">${escape(messages.offlineSites.unlock)}</button>
            </form>
            <p role="status"></p>
            <div hidden data-offline-results>
              <ul></ul>
              <p hidden data-offline-empty>${escape(messages.offlineSites.empty)}</p>
              <p hidden data-offline-drafts-only>${escape(messages.offlineSites.draftsOnly)}</p>
              <p hidden data-offline-pending data-label="${escape(messages.offlineJournal.pending)}"></p>
              <details hidden data-offline-drafts
                data-labels="${escape(JSON.stringify({
                  photo: messages.offlinePlants.photoFile, planned: messages.offlinePlants.planned,
                  watering: messages.offlinePlants.watering, fertilized: messages.offlinePlants.withFertilizer,
                  volume: messages.offlinePlants.volumeValue,
                }))}"
                data-rejections="${escape(JSON.stringify(rejections))}">
                <summary>${escape(messages.offlineJournal.review)}</summary>
                <ul></ul>
              </details>
              <p hidden data-offline-truncated>${escape(messages.offlineSites.truncated)}</p>
              <details hidden data-offline-plants data-phases="${escape(JSON.stringify(phaseLabels[locale]))}">
                <summary>${escape(messages.offlinePlants.title)}</summary>
                <label>${escape(messages.offlinePlants.search)} <input type="search" autocomplete="off"></label>
                <ul></ul>
                <p hidden data-offline-plants-empty>${escape(messages.offlinePlants.empty)}</p>
                <p hidden data-offline-plants-truncated>${escape(messages.offlinePlants.truncated)}</p>
                <form hidden data-offline-maintenance>
                  <label>${escape(messages.offlinePlants.plant)} <select name="plant" required></select></label>
                  <label>${escape(messages.offlinePlants.maintenanceType)} <input name="maintenance_type" required maxlength="80" autocomplete="off"></label>
                  <label>${escape(messages.offlinePlants.comment)} <textarea name="comment" maxlength="2000" autocomplete="off"></textarea></label>
                  <label>${escape(messages.offlinePlants.attachment)}
                    <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.txt,.csv">
                    <small>${escape(messages.offlinePlants.attachmentHelp)}</small>
                  </label>
                  <button type="submit">${escape(messages.offlinePlants.queue)}</button>
                </form>
                <form hidden data-offline-watering>
                  <label>${escape(messages.offlinePlants.wateringPlant)} <select name="plant" required></select></label>
                  <label>${escape(messages.offlinePlants.volume)} <input type="number" name="volume" min="0.001" max="1000000" step="0.001" inputmode="decimal"></label>
                  <label hidden data-offline-fertilizer-choice>${escape(messages.offlinePlants.withFertilizer)}
                    <input type="checkbox" name="with_fertilizer">
                  </label>
                  <fieldset hidden data-offline-fertilizers data-source="${escape(messages.offlinePlants.stockSource)}"
                    data-dose="${escape(messages.offlinePlants.dose)}">
                    <legend>${escape(messages.offlinePlants.fertilizers)}</legend>
                  </fieldset>
                  <label>${escape(messages.offlinePlants.wateringComment)} <textarea name="comment" maxlength="2000" autocomplete="off"></textarea></label>
                  <label>${escape(messages.offlinePlants.attachment)}
                    <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.txt,.csv">
                    <small>${escape(messages.offlinePlants.attachmentHelp)}</small>
                  </label>
                  <button type="submit" data-fertilized="${escape(messages.offlinePlants.queueFertilized)}"
                    data-plain="${escape(messages.offlinePlants.queueWatering)}">${escape(messages.offlinePlants.queueWatering)}</button>
                </form>
                <form hidden data-offline-photo>
                  <label>${escape(messages.offlinePlants.photoPlant)} <select name="plant" required></select></label>
                  <label>${escape(messages.offlinePlants.photoFile)}
                    <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp" required>
                  </label>
                  <button type="submit">${escape(messages.offlinePlants.queuePhoto)}</button>
                </form>
                <form hidden data-offline-creation>
                  <label>${escape(messages.offlinePlants.plantName)} <input name="plant_name" maxlength="120" autocomplete="off"></label>
                  <label>${escape(messages.offlinePlants.strain)} <select name="strain" required></select></label>
                  <label>${escape(messages.offlinePlants.location)} <select name="location" required></select></label>
                  <button type="submit">${escape(messages.offlinePlants.queueCreation)}</button>
                </form>
              </details>
              <details hidden data-offline-files>
                <summary>${escape(messages.offlineFiles.title)}</summary>
                <label>${escape(messages.offlineFiles.choose)} <select></select></label>
                <div data-offline-file-preview></div>
                <a hidden download>${escape(messages.offlineFiles.download)}</a>
              </details>
              <div hidden data-offline-journal-copy>
                <h3>${escape(messages.offlineJournal.recent)}</h3>
                <ul></ul>
              </div>
              <form hidden data-offline-journal>
                <label>${escape(messages.offlineJournal.site)} <select name="site" required></select></label>
                <label>${escape(messages.offlineJournal.title)} <input name="title" required maxlength="160" autocomplete="off"></label>
                <label>${escape(messages.offlineJournal.body)} <textarea name="body" required maxlength="2000" autocomplete="off"></textarea></label>
                <button type="submit">${escape(messages.offlineJournal.queue)}</button>
              </form>
              <button type="button" data-offline-lock>${escape(messages.offlineSites.lock)}</button>
            </div>
            <p role="status" data-offline-maintenance-status data-queued="${escape(messages.offlinePlants.queued)}" data-failed="${escape(messages.offlinePlants.queueError)}"></p>
            <p role="status" data-offline-watering-status data-recipe-expired="${escape(messages.offlinePlants.recipeExpired)}" data-queued="${escape(messages.offlinePlants.wateringQueued)}" data-failed="${escape(messages.offlinePlants.queueError)}"></p>
            <p role="status" data-offline-photo-status data-queued="${escape(messages.offlinePlants.photoQueued)}" data-failed="${escape(messages.offlinePlants.queueError)}"></p>
            <p role="status" data-offline-creation-status data-queued="${escape(messages.offlinePlants.creationQueued)}" data-failed="${escape(messages.offlinePlants.creationError)}"></p>
            <p role="status" data-offline-queue-status data-queued="${escape(messages.offlineJournal.queued)}" data-failed="${escape(messages.offlineJournal.queueError)}"></p>
          </div>
        </section>`;
      }).join('');
      const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src '${scriptHash}'; style-src 'unsafe-inline'; img-src blob:; base-uri 'none'; form-action 'none'">
<title>Canabicultarr</title><style>
*{box-sizing:border-box}body{margin:0;padding:2rem 1rem;background:#f1f6f3;color:#17372b;font:1rem/1.6 system-ui,sans-serif}
main{max-width:38rem;margin:auto}header{font-weight:700;font-size:1.25rem}section{margin:1.5rem 0;padding:1.5rem;background:white;border:1px solid #c1d3c8;border-radius:1rem}
h1{font-size:1.5rem;margin-top:0}a{display:inline-block;background:#17372b;color:white;padding:.6rem 1rem;border-radius:.5rem;text-decoration:none}a:focus-visible{outline:3px solid #10b981;outline-offset:3px}
[data-offline-sites]{margin-top:1rem;border-top:1px solid #c1d3c8}form{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end}label{display:grid;gap:.25rem}input,button,select,textarea{font:inherit;padding:.35rem .6rem;max-width:100%}button{cursor:pointer}[data-offline-fertilizers]{width:100%;min-width:0}[data-offline-fertilizers]>div{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:.5rem}li{overflow-wrap:anywhere}[data-offline-plants]{margin:1rem 0}[data-offline-plants] summary{cursor:pointer;font-weight:600}[data-offline-plants] label{margin-top:.5rem}[data-offline-plants] form+form{margin-top:1rem}[data-offline-plants] li{margin-bottom:.5rem}[data-offline-plants] small{display:block}[data-offline-files]{margin:1rem 0}[data-offline-files] img{display:block;max-width:100%;max-height:25rem;margin:.5rem 0}[data-offline-files] pre{max-height:18rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}[data-offline-drafts]{margin:1rem 0}[data-offline-drafts] summary{cursor:pointer}[data-offline-drafts] small{display:block}[data-offline-drafts] li p,[data-offline-journal-copy] li p{margin:.25rem 0;white-space:pre-wrap}[hidden]{display:none!important}
</style></head><body><!-- canabicultarr-public-offline --><main><header>Canabicultarr</header>${sections}</main><script type="module">${script}</script></body></html>`;
      const template = readFileSync(new URL('../pwa/sw.js', import.meta.url), 'utf8').replace('__PUSH_MESSAGES__', JSON.stringify({ en: en.pushNotifications, fr: fr.pushNotifications }));
      const version = createHash('sha256').update(html).update(template).digest('hex').slice(0, 20);
      const integrity = 'sha256-' + createHash('sha256').update(html).digest('base64');
      this.emitFile({ type: 'asset', fileName: 'offline.html', source: html });
      this.emitFile({ type: 'asset', fileName: 'sw.js', source: template.replace('__OFFLINE_SCRIPT_HASH__', scriptHash).replace('__OFFLINE_VERSION__', version).replace('__OFFLINE_INTEGRITY__', integrity) });
    },
  };
}
