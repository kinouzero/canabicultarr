<script setup lang="ts">
import { NTag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
defineProps<{ label: string; disabled?: boolean }>();
defineEmits<{ remove: [] }>();
</script>
<template><NTag><template v-if="$slots.icon" #icon><slot name="icon" /></template><slot /><button type="button" class="tag-remove" :aria-label="label" :disabled="disabled" @click.stop="$emit('remove')"><AppIcon name="close" /></button></NTag></template>
<style scoped>
.tag-remove { display: inline-flex; align-items: center; justify-content: center; padding: 0; margin-inline-start: var(--space-1-5); border: 0; background: none; color: inherit; cursor: pointer; }
.tag-remove:disabled { cursor: default; opacity: .5; }
</style>
