import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createI18n } from 'vue-i18n';
import { registerModuleMessages } from '../src/lib/locale-messages.ts';

test('many card mounts register each immutable dictionary once while preserving both locales and later modules', () => {
 const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: { app: 'Workspace' }, fr: { app: 'Espace' } } });
 let merges = 0;
 const merge = (locale: string, messages: Record<string, object>) => { merges++; i18n.global.mergeLocaleMessage(locale, messages); };
 const en = { title: 'Plant' }; const fr = { title: 'Plante' };
 for (let i = 0; i < 1000; i++) registerModuleMessages(merge, { en: { card: en }, fr: { card: fr } });
 assert.equal(merges, 2);
 assert.equal(i18n.global.t('app'), 'Workspace');
 assert.equal(i18n.global.t('card.title'), 'Plant');
 i18n.global.locale.value = 'fr';
 assert.equal(i18n.global.t('card.title'), 'Plante');
 registerModuleMessages(merge, { fr: { card: fr, gallery: { title: 'Photos' } } });
 assert.equal(merges, 3);
 assert.equal(i18n.global.t('card.title'), 'Plante');
 assert.equal(i18n.global.t('gallery.title'), 'Photos');
 registerModuleMessages(merge, { fr: { card: { title: 'Updated translation' } } });
 assert.equal(i18n.global.t('card.title'), 'Updated translation');
});

test('independent composers each receive messages and a failed merge can be retried', () => {
 const messages = { en: { card: { title: 'Plant' } } };
 for (let i = 0; i < 2; i++) {
  const i18n = createI18n({ legacy: false, locale: 'en' });
  registerModuleMessages(i18n.global.mergeLocaleMessage, messages);
  assert.equal(i18n.global.t('card.title'), 'Plant');
 }
 let attempts = 0;
 const merge = () => { attempts++; if (attempts === 1) throw new Error('Fixture merge failure'); };
 assert.throws(() => registerModuleMessages(merge, messages));
 registerModuleMessages(merge, messages);
 registerModuleMessages(merge, messages);
 assert.equal(attempts, 2);
});
