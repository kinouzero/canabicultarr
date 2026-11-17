<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { NButton } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';

const props = defineProps<{ focusTarget?: HTMLElement | null }>();
const { t } = useI18n();
const visible = ref(false);

function updateVisibility() {
 visible.value = window.scrollY >= 384;
}

function backToTop() {
 props.focusTarget?.focus({ preventScroll: true });
 const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
 window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
}

onMounted(() => {
 updateVisibility();
 window.addEventListener('scroll', updateVisibility, { passive: true });
});
onUnmounted(() => window.removeEventListener('scroll', updateVisibility));
</script>

<template>
 <NButton v-if="visible" class="back-to-top" circle :bordered="false" :aria-label="t('navigation.backToTop')" :title="t('navigation.backToTop')" @click="backToTop">
  <template #icon><AppIcon name="arrowUp" /></template>
 </NButton>
</template>

<style scoped>
.back-to-top {
 position: fixed;
 inset-inline-end: calc(var(--space-4) + env(safe-area-inset-right, 0px));
 inset-block-end: calc(var(--space-4) + env(safe-area-inset-bottom, 0px));
 z-index: 20;
 width: 2.75rem;
 height: 2.75rem;
 border: 1px solid var(--theme-border);
 color: var(--theme-text);
 background: var(--theme-surface);
 box-shadow: 0 .2rem .7rem color-mix(in srgb, var(--theme-shadow) 12%, transparent);
}
.back-to-top:is(:hover, :focus-visible) { color: var(--theme-focus); border-color: var(--theme-focus); }
.back-to-top:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
</style>
