<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ name: string; url?: string; size?: number }>(), { size: 36 });
const failedUrl = ref('');
const initials = computed(() => props.name.trim().split(/\s+/u).filter(Boolean).slice(0, 2).map(part => Array.from(part)[0]).join('').toLocaleUpperCase());
function imageFailed(event: Event) {
 failedUrl.value = (event.currentTarget as HTMLImageElement).src;
}
</script>

<template>
 <span class="user-avatar" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true">
  <img v-if="url && failedUrl !== url" :key="url" :src="url" alt="" :width="size" :height="size" referrerpolicy="no-referrer" decoding="async" @error="imageFailed" />
  <span v-else>{{ initials }}</span>
 </span>
</template>

<style scoped>
.user-avatar { display: inline-grid; place-items: center; flex-shrink: 0; overflow: hidden; border-radius: 50%; background: var(--theme-subtle, #edf1e8); color: var(--theme-muted, #405b37); font-size: .85em; font-weight: 650; vertical-align: middle; }
.user-avatar img { display: block; width: 100%; height: 100%; object-fit: cover; }
</style>
