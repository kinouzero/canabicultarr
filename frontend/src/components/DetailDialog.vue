<script setup lang="ts">
import '../styles/record-actions.css';
import ModalHeading from './ModalHeading.vue';
import { useDialogEscape } from '../lib/dialog-escape';
import { nextTick, onUnmounted } from 'vue';
import { NModal, NCard, NButton } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import Message from './AppMessage.vue';

const props = defineProps<{ title: string; closeLabel: string; busy?: boolean; error?: string; record?: boolean; module?: boolean; size?: 'compact'; icon?: string }>();
const emit = defineEmits<{ close: [] }>();
const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
function close() { if (!props.busy) emit('close'); }
const dialogAttributes = useDialogEscape(() => true, close);
onUnmounted(() => { void nextTick(() => { if (opener?.isConnected && !opener.closest('[inert]')) opener.focus(); }); });
</script>

<template> <NModal :show="true" :mask-closable="false" :close-on-esc="false" @update:show="value => !value && close()"><NCard v-bind="dialogAttributes" role="dialog" aria-modal="true" :aria-label="title" :bordered="false" class="app-dialog detail-dialog" :class="{ 'record-dialog': record, 'module-dialog': module }" :style="{ width: size === 'compact' ? '36rem' : record ? '72rem' : module ? '52rem' : '64rem', maxWidth: 'calc(100vw - 2rem)' }"><template #header><slot name="header"><ModalHeading :title="title" :icon="icon ?? 'folder'" /></slot></template>
  <template #header-extra><NButton class="dialog-close" text :disabled="busy" :aria-label="closeLabel" @click="close"><AppIcon name="close" /></NButton></template>
  <div class="dialog-body"><Message v-if="error" closable type="error" role="alert">{{ error }}</Message>
  <slot /></div><template v-if="$slots.footer" #footer><slot name="footer" /></template></NCard></NModal></template>

<style scoped>
.detail-title { margin: 0; overflow-wrap: anywhere; font-size: 1.2rem; }
</style>
