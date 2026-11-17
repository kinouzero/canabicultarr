<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import PhotoPlaceholderMark from './PhotoPlaceholderMark.vue';
import { photoThumbnailUrl } from '../lib/photo-urls';
import { photosApi } from '../api/photos';
import { ApiError } from '../api/auth';
const props = withDefaults(defineProps<{ name: string; reference?: string; photoUrl?: string | null; strainId?: string; image?: boolean; icon?: string; label?: string; disabled?: boolean; interactive?: boolean }>(), { interactive: true });
const emit = defineEmits<{ open: []; failure: [cause: unknown] }>();
const fetched = ref<string | null>(null); const broken = ref(false); let alive = true; let sequence = 0; const element = ref<HTMLElement | null>(null); let observer: IntersectionObserver | undefined;
async function load() {
 const current = ++sequence;
 if (!props.strainId) return;
 try { const result = await photosApi.list('strain', props.strainId); if (alive && current === sequence) fetched.value = result.data[0]?.url ?? null; }
 catch (cause) { if (alive && current === sequence && cause instanceof ApiError && [401, 403].includes(cause.status)) emit('failure', cause); }
}
onMounted(() => {
 if (!props.strainId) return;
 if (!element.value || typeof IntersectionObserver === 'undefined') { void load(); return; }
 observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) { observer?.disconnect(); void load(); } }, { rootMargin: '150px' });
 observer.observe(element.value);
});
onUnmounted(() => { alive = false; observer?.disconnect(); });
watch(() => props.strainId, () => { fetched.value = null; broken.value = false; void load(); });
watch(() => props.photoUrl, () => { broken.value = false; });
</script>
<template>
 <component ref="element" :is="interactive === false ? 'div' : 'button'" :type="interactive === false ? undefined : 'button'" class="collection-identity" :disabled="disabled" :aria-label="label || name" @click="interactive !== false && emit('open')">
  <span class="collection-thumbnail"><img v-if="(photoUrl || fetched) && !broken" :src="photoThumbnailUrl((photoUrl || fetched)!)" alt="" loading="lazy" @error="broken = true" /><PhotoPlaceholderMark v-else-if="image || strainId" /><AppIcon v-else :name="icon || 'folder'" /></span>
  <span class="collection-identity-text"><strong :title="name">{{ name }}</strong><small v-if="reference" :title="reference">{{ reference }}</small></span>
 </component>
</template>

<style scoped>
.collection-thumbnail { display: flex; align-items: center; justify-content: center; position: relative; padding: 0; line-height: 0; }
.collection-thumbnail :deep(.photo-placeholder-mark) { display: block; position: static; width: 65%; height: 80%; margin: 0; flex: 0 0 auto; mask-position: center; mask-size: contain; }
</style>
