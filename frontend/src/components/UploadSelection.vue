<script setup lang="ts">
import { computed, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NUpload, NUploadDragger, type UploadFileInfo, type UploadInst } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import DetailDialog from './DetailDialog.vue';
import ListActionButton from './ListActionButton.vue';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/uploads.json';
import fr from '../locales/fr/uploads.json';
const props = withDefaults(defineProps<{ modelValue: File[]; accept?: string; inputId?: string; maxFiles?: number; disabled?: boolean; state?: 'pending' | 'uploading' | 'done' | 'error'; states?: ReadonlyMap<File, 'pending' | 'uploading' | 'done' | 'error'> }>(), { accept: '.pdf,.jpg,.jpeg,.png,.webp,.txt,.csv', maxFiles: 1 });
const emit = defineEmits<{ 'update:modelValue': [files: File[]] }>();
const { t, locale, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { uploads: en }, fr: { uploads: fr } });
const upload = ref<UploadInst | null>(null);
const fallbackId = useId(); const id = computed(() => props.inputId ?? `upload-${fallbackId}`);
const limitError = ref(false);
const selectionDisabled = computed(() => props.disabled || props.maxFiles > 1 && props.modelValue.length >= props.maxFiles);
const nativeAccept = computed(() => {
 const types = props.accept.split(',').map(value => value.trim());
 return types.some(value => value.startsWith('image/') || /^\.(jpg|jpeg|png|webp)$/.test(value)) ? [...types.filter(value => !value.startsWith('image/') && !/^\.(jpg|jpeg|png|webp)$/.test(value)), 'image/*'].join(',') : props.accept;
});
const previews = ref(new Map<File, { url?: string; text?: string; pdf?: boolean; failed?: boolean }>());
const activeFile = ref<File | null>(null);
const activePreview = computed(() => activeFile.value ? previews.value.get(activeFile.value) : undefined);
const rasterTypes = ['image/jpeg', 'image/png', 'image/webp'];
function release(file: File) {
 const preview = previews.value.get(file);
 if (preview?.url) { URL.revokeObjectURL(preview.url); delete (file as File & { objectURL?: string }).objectURL; }
 previews.value.delete(file);
}
watch(() => props.modelValue, (files, previous) => {
 if (activeFile.value && !files.includes(activeFile.value)) activeFile.value = null;
 if (previous && previous.some(file => !files.includes(file))) upload.value?.clear();
 for (const file of previews.value.keys()) if (!files.includes(file)) release(file);
 for (const file of files) {
  if (previews.value.has(file)) continue;
  const primeFile = file as File & { objectURL?: string };
  if (rasterTypes.includes(file.type) && file.size <= 10485760) previews.value.set(file, { url: primeFile.objectURL ?? URL.createObjectURL(file) });
  else if (file.size <= 10485760 && (file.type === 'application/pdf' || !file.type && /\.pdf$/i.test(file.name))) {
   previews.value.set(file, { url: URL.createObjectURL(new Blob([file], { type: 'application/pdf' })), pdf: true });
  }
  else {
   previews.value.set(file, {});
   if (file.size <= 10485760 && (/\.(txt|csv)$/i.test(file.name) || ['text/plain', 'text/csv'].includes(file.type))) {
    void file.slice(0, 2048).text().then(text => { if (previews.value.has(file)) previews.value.set(file, { text }); }).catch(() => { if (previews.value.has(file)) previews.value.set(file, { failed: true }); });
   }
  }
 }
}, { immediate: true });
onUnmounted(() => { for (const file of previews.value.keys()) release(file); });
const fileErrors = ref<string[]>([]);
function selected({ file }: { file: UploadFileInfo }) {
 if (!file.file || selectionDisabled.value) return;
 fileErrors.value = [];
 const raw = file.file;
 const allowed = props.accept.split(',').some(type => type.trim().endsWith('/*') ? raw.type.startsWith(type.trim().slice(0, -1)) : type.trim().startsWith('.') ? raw.name.toLowerCase().endsWith(type.trim().toLowerCase()) : raw.type === type.trim());
 if (!allowed) { fileErrors.value = [t('uploads.invalidType')]; return; }
 if (raw.size > 10485760) { fileErrors.value = [t('uploads.tooLarge')]; return; }
 select([raw]);
}
function select(files: File[]) {
 const merged = props.maxFiles === 1 && files.length ? [files[files.length - 1]!] : [...props.modelValue];
 for (const file of props.maxFiles === 1 ? [] : files) if (!merged.some(row => row.name === file.name && row.size === file.size && row.lastModified === file.lastModified)) merged.push(file);
 limitError.value = merged.length > props.maxFiles;
 if (limitError.value) {
  for (const file of files) if (!props.modelValue.includes(file)) { const url = (file as File & { objectURL?: string }).objectURL; if (url) URL.revokeObjectURL(url); }
  upload.value?.clear(); return;
 }
 for (const file of files) if (!merged.includes(file)) { const url = (file as File & { objectURL?: string }).objectURL; if (url) URL.revokeObjectURL(url); }
 emit('update:modelValue', merged);
}
function remove(file: File) { limitError.value = false; emit('update:modelValue', props.modelValue.filter(row => row !== file)); }
function size(bytes: number) { const divisor = bytes < 1024 ? 1 : bytes < 1048576 ? 1024 : 1048576; return new Intl.NumberFormat(locale.value, { style: 'unit', unit: divisor === 1 ? 'byte' : divisor === 1024 ? 'kilobyte' : 'megabyte', maximumFractionDigits: 1 }).format(bytes / divisor); }
function status(file: File) { return props.states?.get(file) ?? props.state ?? 'pending'; }
function kind(file: File) { return rasterTypes.includes(file.type) ? 'image' : file.type === 'application/pdf' || /\.pdf$/i.test(file.name) ? 'pdf' : 'file'; }
</script>
<template><div :id="`${id}-selection`" class="upload-selection" tabindex="-1" :aria-busy="disabled" data-testid="upload-selection"><NUpload ref="upload" :default-upload="false" :show-file-list="false" :multiple="maxFiles > 1" :accept="nativeAccept" :disabled="selectionDisabled" :file-list="[]" :input-props="{ id, disabled: selectionDisabled, 'aria-label': t('uploads.choose') }" @change="selected"><NUploadDragger class="upload-drop-target"><AppIcon :name="accept.startsWith('image/') ? 'image' : 'file'" /><strong>{{ t('uploads.dropTitle') }}</strong><span class="upload-desktop-hint">{{ t('uploads.dropHint') }}</span><span class="upload-mobile-hint">{{ t('uploads.mobileHint') }}</span><small>{{ t('uploads.limit') }}</small></NUploadDragger></NUpload>
    <Message v-for="message in fileErrors" :key="message" type="error" closable role="alert">{{ message }}</Message>
    <Message v-if="limitError" type="warning" closable role="alert">{{ t('uploads.tooMany', { count: maxFiles }) }}</Message>
    <ul v-if="modelValue.length" class="upload-files"><li v-for="file in modelValue" :key="`${file.name}-${file.size}-${file.lastModified}`" :data-upload-state="status(file)"><div class="upload-preview"><img v-if="previews.get(file)?.url && !previews.get(file)?.pdf && !previews.get(file)?.failed" :src="previews.get(file)?.url" :alt="file.name" @error="previews.set(file, { ...previews.get(file), failed: true })" />
      <span v-else-if="previews.get(file)?.text !== undefined" class="upload-text-thumbnail" aria-hidden="true" v-text="previews.get(file)?.text" />
      <AppIcon v-else :name="kind(file)" /></div>
     <div class="upload-info"><strong :title="file.name">{{ file.name }}</strong><small>{{ file.type || t('uploads.unknownType') }} · {{ size(file.size) }}</small><span class="upload-status" role="status"><AppIcon :name="status(file) === 'done' ? 'check' : status(file) === 'error' ? 'close' : status(file) === 'uploading' ? 'refresh' : 'clock'" />{{ t(`uploads.states.${status(file)}`) }}</span></div>
     <div class="upload-file-actions"><ListActionButton icon="search" :label="t('uploads.preview', { name: file.name })" @click="activeFile = file" />
      <ListActionButton icon="trash" :label="t('uploads.remove', { name: file.name })" :disabled="disabled" @click="remove(file)" /></div></li></ul>
  <DetailDialog v-if="activeFile" icon="expand" :title="activeFile.name" :close-label="t('uploads.closePreview')" @close="activeFile = null"><div class="upload-expanded-preview"><iframe v-if="activePreview?.pdf" :src="activePreview.url" :title="t('uploads.preview', { name: activeFile.name })" referrerpolicy="no-referrer" />
    <img v-else-if="activePreview?.url && !activePreview.failed" :src="activePreview.url" :alt="activeFile.name" />
    <pre v-else-if="activePreview?.text !== undefined" tabindex="0" v-text="activePreview.text" />
    <Message v-else type="info" closable>{{ t('uploads.previewFailed') }}</Message></div></DetailDialog></div></template>
<style scoped>
.upload-selection { min-width: 0; position: relative; border: 1px dashed var(--theme-border); border-radius: .8rem; background: var(--theme-surface); padding: var(--ui-compact-inset); }
.upload-selection:focus-within, .upload-selection:has([data-p-highlight=true]) { border-color: var(--theme-accent); }
.upload-drop-target { display: flex; width: 100%; flex-direction: column; align-items: center; justify-content: center; gap: var(--space-1-5); border: 0; background: transparent !important; color: var(--theme-muted) !important; padding: var(--space-4) var(--space-2) !important; cursor: pointer; font: inherit; text-align: center; }
.upload-drop-target::after { content: ''; position: absolute; inset: 0; border-radius: .8rem; }
.upload-drop-target:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 3px; }
.upload-drop-target:disabled { cursor: default; }
.upload-drop-target > svg { width: 1.65rem !important; height: 1.65rem !important; color: var(--theme-accent); }
.upload-drop-target strong { color: var(--theme-text); font-size: .85rem; font-weight: 550; }
.upload-drop-target span, .upload-drop-target small { font-size: .73rem; }
.upload-mobile-hint { display: none; }
.upload-files :deep(button), .upload-selection :deep(.n-alert) { position: relative; z-index: 1; }
@media (pointer: coarse) { .upload-desktop-hint { display: none; } .upload-mobile-hint { display: inline; } }
.upload-files { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 13rem), 1fr)); align-items: stretch; gap: var(--space-3); list-style: none; padding: 0; margin: var(--space-2) 0 0; }
.upload-files li { position: relative; z-index: 1; display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; padding: var(--ui-compact-inset); border: 1px solid var(--theme-border); border-radius: .75rem; background: var(--theme-surface); }
.upload-preview { display: grid; place-items: center; width: 100%; height: 7.5rem; border-radius: .5rem; overflow: hidden; background: var(--theme-subtle); color: var(--theme-muted); }
.upload-preview img { display: block; width: 100%; height: 100%; object-fit: contain; }
.upload-text-thumbnail { display: block; height: 100%; width: 100%; padding: var(--space-1-5); overflow: hidden; white-space: pre-wrap; overflow-wrap: anywhere; font: .55rem/1.4 ui-monospace, monospace; text-align: start; }
.upload-preview > svg { width: 1.5rem; height: 1.5rem; }
.upload-file-actions { display: flex; justify-content: flex-end; align-items: center; gap: var(--space-1-5); margin-top: auto; }
.upload-expanded-preview { min-width: 0; }
.upload-expanded-preview img { display: block; width: 100%; max-height: 70dvh; object-fit: contain; border-radius: .6rem; }
.upload-expanded-preview iframe { display: block; width: 100%; height: 70dvh; border: 0; border-radius: .6rem; }
.upload-expanded-preview pre { margin: 0; max-height: 70dvh; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; padding: var(--space-3); border-radius: .6rem; background: var(--theme-subtle); font: .8rem/1.5 ui-monospace, monospace; }
.upload-info { display: grid; gap: var(--space-1); min-width: 0; }
.upload-info strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .85rem; font-weight: 550; }
.upload-info small { color: var(--theme-muted); font-size: .72rem; overflow-wrap: anywhere; }
.upload-status { display: inline-flex; align-items: center; gap: var(--space-1-5); color: var(--theme-muted); font-size: .72rem; }
[data-upload-state=done] .upload-status { color: var(--theme-success); }
[data-upload-state=error] .upload-status { color: var(--theme-danger); }
[data-upload-state=uploading] .upload-status { color: var(--theme-info); }
</style>
