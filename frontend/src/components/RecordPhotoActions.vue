<script setup lang="ts">
import AppTabs from './AppTabs.vue';
import UploadSelection from './UploadSelection.vue';
import DocumentStorageSelect from './DocumentStorageSelect.vue';
import { registerModuleMessages } from '../lib/locale-messages';
import uploadEn from '../locales/en/uploads.json';
import uploadFr from '../locales/fr/uploads.json';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NTabPane } from 'naive-ui';




import AppIcon from './AppIcon.vue';
import FormDialog from './FormDialog.vue';
import ListActionButton from './ListActionButton.vue';
import PhotoLibraryPicker from './PhotoLibraryPicker.vue';
import type { Page } from '../api/types';
import type { LibraryPhoto } from '../api/photos';
import type { ApiError } from '../api/auth';
const props = defineProps<{ uploadFile: (file: File, disk?: string) => Promise<boolean>; uploadError?: string; disabled?: boolean; overlay?: boolean; hasPhoto: boolean; fetchPhotos: (search: string, page: number) => Promise<Page<LibraryPhoto>> }>();
const emit = defineEmits<{ select: [id: string | null]; accessLost: [cause: ApiError] }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { uploads: uploadEn }, fr: { uploads: uploadFr } });
const open = ref(false); const mode = ref('upload'); const disk = ref('');
const files = ref<File[]>([]); const uploading = ref(false); const uploadState = ref<'pending' | 'uploading' | 'done' | 'error'>('pending');
function show() { if (props.disabled) return; files.value = []; disk.value = ''; mode.value = 'upload'; uploadState.value = 'pending'; open.value = true; }
async function submit() {
 if (!files.value[0] || uploading.value || props.disabled) return;
 uploading.value = true; uploadState.value = 'uploading';
 try { if (await props.uploadFile(files.value[0], disk.value || undefined)) { uploadState.value = 'done'; open.value = false; files.value = []; } else uploadState.value = 'error'; }
 catch { uploadState.value = 'error'; }
 finally { uploading.value = false; }
}
function choose(id: string) { if (!id || uploading.value || props.disabled) return; open.value = false; emit('select', id); }
function remove() { open.value = false; emit('select', null); }
</script>
<template>
 <Button v-if="overlay" class="record-photo-actions" attr-type="button" type="default" :aria-label="t('photoActions.manage')" :title="t('photoActions.manage')" data-action-icon="image" :disabled="disabled" @click.stop="show">
  <template #icon><AppIcon name="image" /></template>
 </Button>
 <ListActionButton v-else class="record-photo-actions" icon="image" :label="t('photoActions.manage')" :disabled="disabled" @click.stop="show" />
 <FormDialog v-if="open" :visible="true" icon="image" :size="mode === 'library' ? 'wide' : 'standard'" :title="t('uploads.photoTitle')" :close-label="t('actions.cancel')" :busy="uploading || disabled" :error="uploadState === 'error' ? uploadError || t('uploads.failed') : ''" @update:visible="!$event && (open = false)"><AppTabs type="bar" v-model:value="mode" class="button-tabs photo-import-tabs" animated><NTabPane name="upload" :disabled="uploading || disabled" display-directive="show:lazy"><template #tab><AppIcon name="image" />{{ t('uploads.importTab') }}</template><UploadSelection v-model="files" accept="image/jpeg,image/png,image/webp" :disabled="uploading || disabled" :state="uploadState" @update:model-value="uploadState = 'pending'" /><DocumentStorageSelect v-model="disk" :disabled="uploading || disabled" /></NTabPane><NTabPane name="library" :disabled="uploading || disabled" display-directive="show:lazy"><template #tab><AppIcon name="library" />{{ t('uploads.libraryTab') }}</template><PhotoLibraryPicker v-if="mode === 'library'" :fetch-page="fetchPhotos" :disabled="uploading || disabled" @select="choose" @access-lost="emit('accessLost', $event)" /></NTabPane></AppTabs>
  <template #footer><Button v-if="hasPhoto" type="error" :disabled="uploading || disabled" @click="remove"><template #icon><AppIcon name="unlink" /></template><span>{{ t('photoActions.removePhoto') }}</span></Button><Button type="default" :disabled="uploading || disabled" @click="open = false"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button v-if="mode === 'upload'" :disabled="!files.length || disabled || uploading" :loading="uploading" @click="submit" type="primary"><template #icon><AppIcon name="image" /></template><span>{{ t('uploads.confirm') }}</span></Button></template></FormDialog></template>
<style scoped>
.photo-import-tabs :deep(.n-tabs-pane-wrapper) { padding-inline: 0; }

.photo-import-tabs :deep(.n-tabs-nav), .photo-import-tabs :deep(.n-tabs-wrapper) { border: 0; background: transparent; }
.photo-import-tabs :deep(.n-tabs-nav-scroll-content) { border: 0; background: transparent; gap: var(--space-2); flex-wrap: wrap; }
.photo-import-tabs :deep(.n-tabs-bar) { display: none; }
.photo-import-tabs :deep(.n-tabs-tab) { display: inline-flex; align-items: center; font-weight: 550; border: 1px solid var(--theme-border); background: var(--theme-surface); color: var(--theme-muted); border-radius: .6rem; padding: var(--space-2) var(--space-3); gap: var(--space-1-5); margin: 0; }
.photo-import-tabs :deep(.n-tabs-tab--active) { background: var(--theme-subtle); color: var(--theme-accent); border-color: var(--theme-accent); }
</style>
