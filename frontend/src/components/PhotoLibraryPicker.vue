<script setup lang="ts">
import { photoThumbnailUrl } from '../lib/photo-urls';
import { onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import PhotoPlaceholderMark from './PhotoPlaceholderMark.vue';
import ImagePreview from './ImagePreview.vue';
import CollectionPagination from './CollectionPagination.vue';
import { ApiError } from '../api/auth';
import type { LibraryPhoto } from '../api/photos';
import type { Page } from '../api/types';

const props = defineProps<{ disabled?: boolean; fetchPage: (search: string, page: number) => Promise<Page<LibraryPhoto>> }>();
const emit = defineEmits<{ select: [id: string]; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const id = useId();
const search = ref('');
const rows = ref<LibraryPhoto[]>([]);
const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const failed = ref(false);
const preview = ref<LibraryPhoto | null>(null);
const broken = ref(new Set<string>());
let sequence = 0;
let requestedPage = 1;
let timer: ReturnType<typeof setTimeout> | undefined;

async function load(next = 1) {
 const current = ++sequence; requestedPage = next;
 loading.value = true; failed.value = false; rows.value = []; broken.value = new Set();
 try {
  const result = await props.fetchPage(search.value.trim(), next);
  if (current !== sequence) return;
  rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (cause) {
  if (current !== sequence) return;
  failed.value = true;
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { if (current === sequence) loading.value = false; }
}
watch(search, () => {
 clearTimeout(timer); sequence++; rows.value = []; loading.value = true; failed.value = false;
 timer = setTimeout(() => { void load(); }, 300);
});
watch(() => props.fetchPage, () => { clearTimeout(timer); void load(); }, { immediate: true });
onUnmounted(() => { sequence++; clearTimeout(timer); });
</script>

<template><section class="photo-library" :aria-label="t('recordDetails.gallery')" :aria-busy="loading"><label class="photo-search" :for="id"><AppIcon name="search" /><InputText v-model:value="search" :placeholder="t('photoActions.search')" :disabled="disabled" :maxlength="160" :input-props="{ 'id': id, type: 'search', 'aria-label': t('photoActions.search') }"></InputText></label>
  <Message v-if="failed" type="error" closable role="alert">{{ t('recordDetails.photosFailed') }} <Button type="default" :disabled="disabled" @click="load(requestedPage)"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.retry') }}</span></Button></Message>
  <p v-else-if="loading || !rows.length" class="photo-library-status" role="status">{{ t(loading ? 'recordDetails.loadingPhotos' : 'photoActions.empty') }}</p>
  <ul v-else class="photo-library-grid"><li v-for="photo in rows" :key="photo.id" class="photo-library-item"><button type="button" class="photo-library-choice" :disabled="disabled || photo.disabled" :aria-label="t('photoActions.chooseNamed', { name: photo.name })" @click="emit('select', photo.id)"><span class="photo-library-image"><img v-if="!broken.has(photo.id)" :src="photoThumbnailUrl(photo.url)" :alt="photo.name" loading="lazy" decoding="async" @error="broken.add(photo.id)" /><span v-else class="photo-library-missing"><PhotoPlaceholderMark /><span>{{ t('recordDetails.photoUnavailable') }}</span></span></span>
     <span class="photo-library-caption"><strong :title="photo.name">{{ photo.name }}</strong><small v-if="photo.detail !== photo.name" :title="photo.detail">{{ photo.detail }}</small></span></button>
    <ListActionButton class="photo-library-enlarge" icon="expand" :label="t('photoActions.previewNamed', { name: photo.name })" :disabled="disabled || broken.has(photo.id)" @click="preview = photo" /></li></ul>
  <CollectionPagination v-if="!failed" :page="page" :last-page="lastPage" :total="loading ? 0 : total" :label="t('photoActions.pages')" :disabled="disabled || loading" @page="load" />
  <ImagePreview v-if="preview" :url="preview.url" :name="preview.name" @close="preview = null" /></section></template>

<style scoped>
.photo-library { min-width: 0; }
.photo-search { display: flex; position: relative; margin-bottom: var(--ui-content-gap); }
.photo-search > svg { position: absolute; inset-inline-start: .8rem; top: 50%; transform: translateY(-50%); width: 1rem; height: 1rem; color: var(--theme-muted); pointer-events: none; }
.photo-search input { width: 100%; padding-inline-start: var(--space-10) !important; }
.photo-library-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 10rem), 1fr)); gap: var(--space-3); padding: 0; margin: 0; list-style: none; }
.photo-library-item { position: relative; min-width: 0; }
.photo-library-choice { display: flex; flex-direction: column; width: 100%; height: 100%; padding: 0; overflow: hidden; border: 1px solid var(--theme-border); border-radius: .8rem; background: var(--theme-surface); color: var(--theme-text); font: inherit; text-align: start; cursor: pointer; }
.photo-library-choice:hover:not(:disabled) { border-color: var(--theme-focus); background: var(--theme-subtle); }
.photo-library-choice:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.photo-library-choice:disabled { opacity: .55; cursor: default; }
.photo-library-image { display: grid; place-items: center; width: 100%; aspect-ratio: 4 / 3; overflow: hidden; background: var(--theme-subtle); }
.photo-library-image > img { display: block; width: 100%; height: 100%; object-fit: contain; }
.photo-library-missing { display: grid; justify-items: center; gap: var(--space-2); padding: var(--space-4); color: var(--theme-muted); font-size: .75rem; text-align: center; }
.photo-library-caption { display: grid; gap: var(--space-1); width: 100%; min-width: 0; padding: var(--space-3) var(--space-3); box-sizing: border-box; }
.photo-library-caption strong, .photo-library-caption small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.photo-library-caption strong { font-size: .8rem; font-weight: 600; }.photo-library-caption small { color: var(--theme-muted); font-size: .72rem; }
.photo-library-enlarge { position: absolute; top: .45rem; right: .45rem; box-shadow: 0 1px 5px color-mix(in srgb, var(--theme-photo-overlay) 15%, transparent); }
.photo-library-status { padding: var(--space-8) var(--space-2); text-align: center; color: var(--theme-muted); font-size: .85rem; }
@media (max-width: 500px) { .photo-library-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-2); } }
</style>
