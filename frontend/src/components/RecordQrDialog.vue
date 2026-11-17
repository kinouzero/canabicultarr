<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/qr-labels.json';
import fr from '../locales/fr/qr-labels.json';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import DetailDialog from './DetailDialog.vue';
import AppIcon from './AppIcon.vue';
import { recordUrl, type QrRecord } from '../lib/record-link';
import { qrSvg, qrImage, qrPng, printQrLabel } from '../lib/qr-label';
import { downloadBlob } from '../lib/download';
const props = defineProps<{ record: QrRecord }>();
const emit = defineEmits<{ close: [] }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { qr: en }, fr: { qr: fr } }); const error = ref(false); const busy = ref(false);
let removePrint: (() => void) | undefined; let disposed = false;
onUnmounted(() => { disposed = true; removePrint?.(); });
const label = computed(() => {
 try { const url = recordUrl(props.record, window.location.origin); return { url, svg: qrSvg(url) }; }
 catch { return null; }
});
async function download(format: 'svg' | 'png') {
 if (!label.value || busy.value) return;
 busy.value = true; error.value = false;
 try {
  const blob = format === 'svg' ? new Blob([label.value.svg], { type: 'image/svg+xml' }) : await qrPng(label.value.svg);
  downloadBlob(blob, `${props.record.type}-${props.record.id}.qr.${format}`);
 } catch { error.value = true; } finally { busy.value = false; }
}
async function print() {
 if (!label.value || busy.value) return;
 busy.value = true; error.value = false;
 try {
  removePrint?.();
  removePrint = await printQrLabel({ ...label.value, name: props.record.name, id: props.record.id, title: t('qr.title') });
  if (disposed) removePrint();
 }
 catch { error.value = true; } finally { busy.value = false; }
}
</script>
<template>
 <DetailDialog size="compact" icon="qrcode" :title="t('qr.title')" :close-label="t('qr.close')" :busy="busy" @close="emit('close')">
  <Message v-if="error || !label" type="error" closable role="alert">{{ t('qr.error') }}</Message>
  <div v-if="label" class="qr-label">
   <div class="qr-preview">
    <div class="qr-image"><img :src="qrImage(label.svg)" :alt="t('qr.image', { name: record.name })" /></div>
    <strong>{{ record.name }}</strong><code :title="record.id">{{ record.id }}</code>
   </div>
   <div class="qr-options">
    <p class="qr-help">{{ t('qr.help') }}</p>
    <div class="qr-address"><h4>{{ t('qr.linkLabel') }}</h4><a :href="label.url" class="qr-link"><AppIcon name="externalLink" /><span>{{ label.url }}</span></a></div>
    <div class="qr-note"><AppIcon name="help" /><p>{{ t('qr.addressHelp') }}</p></div>
   </div>
  </div>
  <template v-if="label" #footer><div class="qr-downloads">
   <Button :disabled="busy" type="default" @click="download('svg')"><template #icon><AppIcon name="download" /></template>{{ t('qr.svg') }}</Button>
   <Button :disabled="busy" type="default" @click="download('png')"><template #icon><AppIcon name="image" /></template>{{ t('qr.png') }}</Button>
   <Button class="qr-print" :disabled="busy" type="primary" @click="print"><template #icon><AppIcon name="printer" /></template>{{ t('qr.print') }}</Button>
  </div></template>
 </DetailDialog>
</template>
<style scoped>
.qr-label { display: grid; grid-template-columns: minmax(0, 13rem) minmax(0, 1fr); gap: var(--ui-grid-gap); align-items: center; }
.qr-preview { display: grid; gap: var(--space-1-5); text-align: center; overflow-wrap: anywhere; min-width: 0; }
.qr-image { background: white; border: 1px solid var(--theme-border); border-radius: .75rem; padding: var(--space-2); }
.qr-image img { width: 100%; height: auto; aspect-ratio: 1; display: block; }
.qr-preview strong { font-size: .85rem; line-height: 1.5; }
.qr-preview code { display: block; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .65rem; color: var(--theme-muted); }
.qr-options { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.qr-label .qr-help, .qr-note p { margin: 0; font-size: .75rem; line-height: 1.6; color: var(--theme-muted); }
.qr-address { display: grid; gap: var(--space-1-5); min-width: 0; }
.qr-address h4 { margin: 0; font-size: .75rem; font-weight: 600; }
.qr-link { display: flex; align-items: start; gap: var(--ui-icon-gap); min-width: 0; font-size: .75rem; line-height: 1.6; color: var(--theme-text); text-underline-offset: .2em; }
.qr-link span { min-width: 0; overflow-wrap: anywhere; }
.qr-link svg, .qr-note > svg { width: 1rem; height: 1rem; flex: 0 0 1rem; margin-top: .15rem; }
.qr-note { display: flex; align-items: start; gap: var(--ui-icon-gap); color: var(--theme-muted); }
.qr-downloads { display: flex; flex-wrap: wrap; gap: var(--ui-control-gap); width: 100%; }
.qr-downloads > .n-button { flex: 1 1 0; min-width: 0; }
@media (max-width: 600px) {
 .qr-label { grid-template-columns: minmax(0, 1fr); }
 .qr-preview { width: 100%; max-width: 12rem; margin-inline: auto; }
 .qr-downloads > .qr-print { flex-basis: 100%; }
}
</style>
