<script setup lang="ts">
import { ref, watch } from 'vue';
import PhotoPlaceholderMark from './PhotoPlaceholderMark.vue';
const props = withDefaults(defineProps<{ src?: string | null; alt?: string; emptyLabel?: string; errorLabel?: string }>(), { alt: '' });
const broken = ref(false);
watch(() => props.src, () => { broken.value = false; });
</script>
<template>
 <span class="record-card-media">
  <img v-if="src && !broken" :src="src" :alt="alt" loading="lazy" @error="broken = true" />
  <span v-else class="record-card-placeholder plant-placeholder"><PhotoPlaceholderMark /><span v-if="broken ? errorLabel : emptyLabel" class="record-card-placeholder-label">{{ broken ? errorLabel : emptyLabel }}</span></span>
  <slot />
 </span>
</template>
<style scoped>
.record-card-media { display: block; position: relative; width: 100%; height: var(--record-card-media-height, 11.5rem); overflow: hidden; background: var(--theme-subtle); }
.record-card-media > img { display: block; width: 100%; height: 100%; object-fit: cover; }
.record-card-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--space-3); width: 100%; height: 100%; padding: var(--space-10) var(--space-3); box-sizing: border-box; background: radial-gradient(ellipse at 50% 65%, var(--theme-border), var(--theme-subtle) 75%); color: var(--theme-muted); }
.record-card-placeholder :deep(.photo-placeholder-mark) { display: block; flex: 0 1 auto; width: 4rem; height: 5rem; margin: 0; mask-position: center; }
.record-card-placeholder-label { max-width: 100%; font-size: .6rem; line-height: 1.4; opacity: .8; text-align: center; }
</style>
