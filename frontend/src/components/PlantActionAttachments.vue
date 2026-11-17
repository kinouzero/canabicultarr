<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import UploadSelection from './UploadSelection.vue';
import { registerModuleMessages } from '../lib/locale-messages';
import uploadEn from '../locales/en/uploads.json';
import uploadFr from '../locales/fr/uploads.json';
import DocumentStorageSelect from './DocumentStorageSelect.vue';
import ListActionButton from './ListActionButton.vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import ReferenceSelect from './ReferenceSelect.vue';
import { documentsApi } from '../api/documents';
import type { LocationContext } from '../api/types';
import type { PlantAttachment } from '../api/plants';
const props = defineProps<{ modelValue: PlantAttachment[]; location: LocationContext; name: string; disabled: boolean; validationError?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: PlantAttachment[]]; busy: [value: boolean]; pending: [value: boolean]; failure: [cause: unknown] }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { uploads: uploadEn }, fr: { uploads: uploadFr } });
const uploading = ref(false); const retained = ref(false);
const files = ref<File[]>([]);
const states = ref(new Map<File, 'pending' | 'uploading' | 'done' | 'error'>());
const completed = new Map<File, string>();
const existing = computed(() => props.modelValue.filter(document => !Array.from(completed.values()).includes(document.id)));
const pending = computed(() => files.value.some(file => states.value.get(file) !== 'done'));
watch(pending, value => emit('pending', value), { immediate: true });
onUnmounted(() => emit('pending', false));
const selectionLimit = computed(() => 10 - props.modelValue.length + files.value.filter(file => completed.has(file)).length);
function selectFiles(next: File[]) {
 const removed = files.value.filter(file => !next.includes(file)).map(file => completed.get(file)).filter(Boolean);
 if (removed.length) emit('update:modelValue', props.modelValue.filter(document => !removed.includes(document.id)));
 for (const file of files.value) if (!next.includes(file)) { completed.delete(file); states.value.delete(file); }
 files.value = next;
}
const options = new Map<string, PlantAttachment>();
async function fetchPage(search: string, page: number) {
 const result = await documentsApi.list({ search }, page);
 for (const document of result.data) options.set(document.id, document);
 return { ...result, data: result.data.map(document => ({ id: document.id, name: document.title, detail: document.originalName, disabled: document.isArchived })) };
}
function choose(id: string) {
 const document = options.get(id);
 if (document && props.modelValue.length < 10 && !props.modelValue.some(row => row.id === id)) emit('update:modelValue', [...props.modelValue, document]);

}
async function upload() {
 if (!files.value.length || uploading.value || props.disabled) return;
 uploading.value = true; emit('busy', true);
 let attached = [...props.modelValue];
 try {
  for (const file of files.value) {
   if (completed.has(file)) continue;
   states.value.set(file, 'uploading');
   try {
    const data = new FormData(); if (uploadDisk.value) data.append('disk', uploadDisk.value); data.append('file', file); data.append('title', file.name);
    data.append('subject[type]', props.location.type); data.append('subject[id]', props.location.id);
    const document = await documentsApi.upload(data);
    completed.set(file, document.id); states.value.set(file, 'done'); retained.value = true;
    attached = [...attached, document]; emit('update:modelValue', attached);
   } catch (cause) { states.value.set(file, 'error'); emit('failure', cause); break; }
  }
 } finally { uploading.value = false; emit('busy', false); }
}
const uploadDisk = ref('');
</script>

<template><fieldset id="plant-care-attachments" tabindex="-1" :aria-invalid="!!validationError" :aria-describedby="validationError ? 'plant-care-attachments-error' : undefined" class="action-attachments" :disabled="disabled || uploading"><legend><AppIcon name="file" />{{ t('plants.attachments.title') }}</legend>
  <p>{{ t('plants.attachments.help') }}</p><small v-if="validationError" id="plant-care-attachments-error" class="field-error" role="alert">{{ t(validationError) }}</small>
  <Message v-if="retained" role="status" type="info" closable>{{ t('plants.attachments.retained') }}</Message>
  <ul><li v-for="document in existing" :key="document.id"><span>{{ document.originalName }}</span><ListActionButton icon="trash" :label="t('plants.attachments.remove', { name: document.originalName })" :disabled="disabled || uploading" @click="emit('update:modelValue', modelValue.filter(row => row.id !== document.id))" /></li></ul>
  <ReferenceSelect :label="t('plants.attachments.choose')" :fetch-page="fetchPage" :disabled="disabled || uploading || modelValue.length >= 10" :exclude-ids="modelValue.map(row => row.id)" @select="choose" @access-lost="emit('failure', $event)" />
  <DocumentStorageSelect v-model="uploadDisk" :disabled="disabled || uploading" />
  <UploadSelection :model-value="files" :max-files="selectionLimit" :states="states" :disabled="disabled || uploading" @update:model-value="selectFiles" />
  <small v-if="pending" class="attachment-pending">{{ t('uploads.pendingHelp') }}</small>
  <Button class="attachment-submit" :aria-label="t('plants.attachments.upload')" :loading="uploading" :disabled="disabled || uploading || !files.some(file => !completed.has(file))" @click="upload" type="primary"><template #icon><AppIcon name="file" /></template><span>{{ t('uploads.confirm') }}</span></Button></fieldset></template>

<style scoped>
.action-attachments { border: 0; padding: 0; margin: var(--ui-content-gap) 0; min-width: 0; }
.attachment-submit { margin-top: var(--space-2); }
legend { font-weight: 600; } p { font-size: .85rem; line-height: 1.6; } ul { padding: 0; list-style: none; }
li, .attachment-buttons { display: flex; gap: var(--space-3); align-items: center; flex-wrap: wrap; } li { justify-content: space-between; margin: var(--space-2) 0; } span { overflow-wrap: anywhere; min-width: 0; }
</style>
