<script setup lang="ts">
import RecordQrButton from './RecordQrButton.vue';
import AppIcon from './AppIcon.vue';
import { onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import DetailDialog from './DetailDialog.vue';
import RichNotes from './RichNotes.vue';
import { documentsApi } from '../api/documents';
import { ApiError } from '../api/auth';
const props = defineProps<{ file: { id: string; originalName: string; mediaType: string; description?: string | null } }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const loading = ref(true); const failed = ref(false); const url = ref(''); const text = ref('');
const kind = ref<'image' | 'pdf' | 'text' | ''>(''); const truncated = ref(false);
let controller: AbortController | null = null;
function release() { controller?.abort(); if (url.value.startsWith('blob:')) URL.revokeObjectURL(url.value); url.value = ''; }
async function load() {
 release(); const request = new AbortController(); controller = request;
 loading.value = true; failed.value = false; text.value = ''; truncated.value = false; kind.value = '';
 try {
  const preview = await documentsApi.preview(props.file.id, request.signal, ['image/jpeg', 'image/png', 'image/webp'].includes(props.file.mediaType));
  if (request.signal.aborted) return;
  if (preview.pdfUrl) { kind.value = 'pdf'; url.value = preview.pdfUrl; return; }
  const { blob } = preview;
  if (!blob) throw new Error('Missing preview content');
  // Trust only the server-detected MIME, never file extensions or arbitrary markup.
  const mime = blob.type.split(';')[0];
  if (['image/jpeg', 'image/png', 'image/webp'].includes(mime ?? '')) kind.value = 'image';
  else if (['text/plain', 'text/csv', 'application/csv'].includes(mime ?? '')) {
   kind.value = 'text'; truncated.value = preview.truncated;
   const content = await blob.text();
   if (request.signal.aborted) return;
   text.value = content;
  } else throw new Error('Unsupported preview type');
  if (kind.value !== 'text') url.value = URL.createObjectURL(blob);
 } catch (cause) {
  if (request.signal.aborted) return;
  failed.value = true;
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { if (!request.signal.aborted) loading.value = false; }
}
watch(() => props.file.id, () => { void load(); }, { immediate: true });
onUnmounted(release);
</script>
<template><DetailDialog icon="expand" :title="file.originalName" :close-label="t('filePreview.close')" @close="emit('close')"><nav class="record-actions" :aria-label="t('recordDetails.actions')"><div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: 'file', id: file.id, name: file.originalName }" /></div></nav>
  <RichNotes v-if="file.description" :value="file.description" class="preview-notes" />
  <div class="file-preview" :aria-busy="loading"><p v-if="loading" role="status">{{ t('filePreview.loading') }}</p>
   <div v-else-if="failed" class="preview-error"><Message type="error" closable role="alert">{{ t('filePreview.unavailable') }}</Message><Button :aria-label="t('filePreview.retry')" type="default" @click="load"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.retry') }}</span></Button></div>
   <template v-else><img v-if="kind === 'image'" :src="url" :alt="file.originalName" class="file-image" @error="failed = true" />
    <template v-else-if="kind === 'pdf'"><p class="pdf-help">{{ t('filePreview.pdfHelp') }}</p><iframe :src="url" :title="file.originalName" class="file-pdf" referrerpolicy="no-referrer" /></template>
    <template v-else-if="kind === 'text'"><Message v-if="truncated" type="info" closable>{{ t('filePreview.truncated') }}</Message><pre class="file-text" tabindex="0">{{ text }}</pre></template></template></div></DetailDialog></template>
<style scoped>
.preview-notes { margin-bottom: var(--ui-content-gap); }
.file-preview { min-width: 0; }.file-image { display: block; width: 100%; max-height: 72dvh; object-fit: contain; border-radius: .8rem; background: var(--theme-subtle, #f1f4ee); }.file-pdf { display: block; width: 100%; height: 70dvh; border: 1px solid var(--theme-border, #dce5d4); border-radius: .6rem; background: var(--theme-subtle, #f1f4ee); }.file-text { padding: var(--space-4); margin: 0; max-height: 70dvh; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; border: 1px solid var(--theme-border, #e0e8d9); border-radius: .65rem; background: var(--theme-subtle, #f7f9f4); color: var(--theme-text, #344632); font: .82rem/1.65 ui-monospace, monospace; }.pdf-help { margin: 0 0 var(--space-3); color: var(--theme-muted, #68785e); }.preview-error { display: grid; gap: var(--space-3); justify-items: start; }
</style>
