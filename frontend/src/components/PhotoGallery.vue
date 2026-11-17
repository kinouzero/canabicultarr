<script setup lang="ts">
import { photoThumbnailUrl } from '../lib/photo-urls';
import PhotoPlaceholderMark from './PhotoPlaceholderMark.vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import ImagePreview from './ImagePreview.vue';
import { photosApi, type RecordPhoto } from '../api/photos';
import { ApiError } from '../api/auth';
const props = defineProps<{ kind: 'plant' | 'strain' | 'site' | 'room' | 'zone'; recordId: string; name: string; coverUrl?: string | null }>();
const emit = defineEmits<{ failure: [error: unknown] }>();
const { t } = useI18n();
const rows = ref<RecordPhoto[]>([]); const activeId = ref(''); const loading = ref(false); const failed = ref(false); const broken = ref(new Set<string>()); const enlarged = ref(false);
const currentPage = ref(0); const lastPage = ref(1); const total = ref(0); let sequence = 0;
const photos = computed(() => props.coverUrl && !rows.value.some(row => row.isCover) ? [{ id: 'cover', name: props.name, url: props.coverUrl, createdAt: null, isCover: true }, ...rows.value] : rows.value);
const active = computed(() => photos.value.find(row => row.id === activeId.value) ?? photos.value[0]);
const activeIndex = computed(() => photos.value.findIndex(row => row.id === active.value?.id));
async function load(page = 1) {
 const current = ++sequence; loading.value = true; failed.value = false;
 try {
  const result = await photosApi.list(props.kind, props.recordId, page); if (current !== sequence) return;
  const previous = active.value; rows.value = page === 1 ? result.data : [...rows.value, ...result.data.filter(row => !rows.value.some(existing => existing.id === row.id))];
  if (previous?.isCover) activeId.value = photos.value.find(row => row.isCover)?.id ?? '';
  currentPage.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (error) { if (current === sequence) { failed.value = true; if (error instanceof ApiError && [401, 403].includes(error.status)) emit('failure', error); } }
 finally { if (current === sequence) loading.value = false; }
}
function move(direction: number) { const next = photos.value[(activeIndex.value + direction + photos.value.length) % photos.value.length]; if (next) activeId.value = next.id; }
function unavailable(url: string) { broken.value = new Set([...broken.value, url]); }
watch(() => [props.recordId, props.coverUrl], () => { rows.value = []; activeId.value = ''; broken.value = new Set(); currentPage.value = 0; total.value = 0; enlarged.value = false; void load(); }, { immediate: true });
onUnmounted(() => { sequence++; });
</script>
<template>
 <section class="photo-gallery" :aria-label="t('recordDetails.gallery')" @keydown.left.prevent="move(-1)" @keydown.right.prevent="move(1)">
  <div class="gallery-stage" :class="{ 'has-photo': active && !broken.has(active.url), 'has-overlay': !!$slots.overlay }" :aria-busy="loading">
   <button v-if="active && !broken.has(active.url)" class="gallery-enlarge" :aria-label="t('recordDetails.enlargePhoto', { name: active.name })" @click="enlarged = true"><img :src="photoThumbnailUrl(active.url)" :alt="active.name" @error="unavailable(active.url)" /><span class="enlarge-symbol"><AppIcon name="expand" /></span></button>
   <div v-else class="gallery-empty"><PhotoPlaceholderMark /><span>{{ t(loading ? 'recordDetails.loadingPhotos' : active ? 'recordDetails.photoUnavailable' : 'recordDetails.noPhotos') }}</span></div>
   <template v-if="photos.length > 1"><button class="gallery-arrow previous" :aria-label="t('recordDetails.previousPhoto')" @click="move(-1)"><AppIcon name="chevron" /></button><button class="gallery-arrow next" :aria-label="t('recordDetails.nextPhoto')" @click="move(1)"><AppIcon name="chevron" /></button></template>
   <div v-if="$slots.overlay" class="gallery-overlay"><slot name="overlay" /></div>
   <div v-if="$slots.actions" class="gallery-actions"><slot name="actions" /></div>
   <span v-if="active" class="gallery-position">{{ activeIndex + 1 }} / {{ Math.max(total, photos.length) }}</span>
  </div>
  <div v-if="photos.length > 1" class="gallery-thumbnails" :aria-label="t('recordDetails.choosePhoto')">
   <button v-for="(photo, index) in photos" :key="photo.id" :aria-label="t('recordDetails.photoNumber', { number: index + 1 })" :aria-current="active?.id === photo.id ? 'true' : undefined" @click="activeId = photo.id"><img v-if="!broken.has(photo.url)" :src="photoThumbnailUrl(photo.url)" alt="" loading="lazy" @error="unavailable(photo.url)" /><PhotoPlaceholderMark v-else /></button>
  </div>
  <div v-if="failed || currentPage < lastPage" class="gallery-footer"><span v-if="failed" role="status">{{ t('recordDetails.photosFailed') }}</span><button v-if="failed || currentPage < lastPage" :disabled="loading" @click="load(failed ? Math.max(1, currentPage + 1) : currentPage + 1)"><AppIcon name="image" class="button-icon" />{{ t(failed ? 'recordDetails.retryPhotos' : 'recordDetails.morePhotos') }}</button></div>
  <ImagePreview v-if="enlarged && active" :url="active.url" :name="active.name" @close="enlarged = false" />
 </section>
</template>
<style scoped>
.gallery-actions { position: absolute; bottom: .65rem; left: .65rem; display: flex; align-items: center; gap: var(--space-1-5); }
.photo-gallery .gallery-actions :deep(.record-photo-actions) { display: inline-flex; align-items: center; justify-content: center; width: 2.1rem; height: 2.1rem; min-width: 2.1rem; min-height: 2.1rem; padding: 0; border: 1px solid var(--theme-border); border-radius: 50%; background: color-mix(in srgb, var(--theme-surface) 88%, transparent); color: var(--theme-muted); backdrop-filter: blur(6px); box-shadow: 0 1px 4px color-mix(in srgb, var(--theme-shadow) 8%, transparent); }
.photo-gallery .gallery-actions :deep(.record-photo-actions:not(:disabled):hover) { color: var(--theme-text); background: var(--theme-surface); }
.photo-gallery .gallery-actions :deep(.record-photo-actions:focus-visible) { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.photo-gallery .gallery-actions :deep(.record-photo-actions svg) { width: 1rem; height: 1rem; flex-shrink: 0; }

.gallery-overlay { position: absolute; top: .75rem; right: .75rem; max-width: calc(100% - 4.5rem); pointer-events: none; }
.gallery-stage.has-overlay .enlarge-symbol { left: .75rem; right: auto; }
.photo-gallery { min-width: 0; }.gallery-stage { position: relative; aspect-ratio: 4 / 3; border-radius: 1rem; overflow: hidden; background: radial-gradient(ellipse at 30% 30%, var(--theme-subtle, #e4eddb), var(--theme-subtle, #f0f3eb)); border: 1px solid var(--theme-border, #e3eadc); }.gallery-enlarge { border: 0; display: block; width: 100%; height: 100%; padding: 0; background: none; cursor: zoom-in; }.gallery-enlarge > img { width: 100%; height: 100%; object-fit: cover; display: block; }.gallery-empty { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--space-3); color: var(--theme-muted, #7e9272); font-size: .8rem; }.enlarge-symbol { position: absolute; top: .75rem; right: .75rem; border-radius: .6rem; background: var(--theme-surface, #fffdf5); padding: var(--space-2); color: var(--theme-text, #284530); }.enlarge-symbol svg { width: 1rem; height: 1rem; display: block; }.gallery-position { position: absolute; bottom: .7rem; right: .8rem; padding: var(--space-1) var(--space-2); border-radius: 2rem; background: color-mix(in srgb, var(--theme-photo-overlay) 72%, transparent); color: var(--theme-photo-text); font-size: .72rem; pointer-events: none; }.gallery-arrow { display: grid; place-items: center; position: absolute; top: calc(50% - 1rem); width: 2rem; height: 2rem; border: 0; border-radius: 50%; background: var(--theme-surface, #fff); color: var(--theme-text, #284530); cursor: pointer; }.gallery-arrow svg { width: 1rem; height: 1rem; }.previous { left: .6rem; }.previous svg { transform: rotate(90deg); }.next { right: .6rem; }.next svg { transform: rotate(-90deg); }.gallery-thumbnails { display: flex; gap: var(--space-2); padding: var(--space-3) var(--space-0-5) var(--space-0-5); overflow-x: auto; }.gallery-thumbnails button { width: 3.5rem; height: 3.5rem; border-radius: .6rem; border: 2px solid transparent; padding: 2px; background: var(--theme-subtle, #f0f3ec); flex-shrink: 0; cursor: pointer; }.gallery-thumbnails button[aria-current] { border-color: var(--theme-focus, #6c8b51); }.gallery-thumbnails img { display: block; height: 100%; width: 100%; object-fit: cover; border-radius: .35rem; }.gallery-thumbnails .photo-placeholder-mark { width: 1.5rem; height: 1.9rem; }.gallery-footer { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); font-size: .75rem; color: var(--theme-muted, #6c795e); margin-top: var(--space-3); }.gallery-footer button { background: none; border: 0; color: var(--theme-muted, #466337); cursor: pointer; font: inherit; text-decoration: underline; }button:focus-visible { outline: 2px solid var(--theme-focus, #648752); outline-offset: 2px; }@media(max-width: 700px) { .gallery-stage { aspect-ratio: 16 / 10; } }

</style>
