<script setup lang="ts">
import ModalHeading from './ModalHeading.vue';
import { useDialogEscape } from '../lib/dialog-escape';
import { nextTick, onUnmounted } from 'vue';
import { NModal, NCard, NButton } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import { useI18n } from 'vue-i18n';
const { t } = useI18n();
import Message from './AppMessage.vue';
const props = defineProps<{ visible: boolean; title: string; closeLabel: string; busy?: boolean; error?: string; icon?: string; size?: 'compact' | 'standard' | 'wide' }>();
const emit = defineEmits<{ 'update:visible': [visible: boolean]; closed: [] }>();
const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
function update(visible: boolean) { if (!props.busy) emit('update:visible', visible); }
const dialogAttributes = useDialogEscape(() => props.visible, () => update(false));
onUnmounted(() => { void nextTick(() => { if (opener?.isConnected && !opener.closest('[inert]')) opener.focus(); }); });
</script>
<template> <NModal @after-leave="emit('closed')" :show="visible" :mask-closable="false" :close-on-esc="false" @update:show="update"><NCard v-bind="dialogAttributes" role="dialog" aria-modal="true" :aria-label="title" :bordered="false" class="app-dialog form-dialog" :style="{ width: size === 'compact' ? '38rem' : size === 'wide' ? '60rem' : '46rem', maxWidth: 'calc(100vw - 2rem)' }"><template #header><ModalHeading :title="title" :icon="icon ?? 'edit'" /></template>
  <template #header-extra><NButton class="dialog-close" text :disabled="busy" :aria-label="t('modalActions.closeForm')" @click="update(false)"><AppIcon name="close" /></NButton></template>
  <div class="dialog-body"><Message v-if="error" type="error" closable role="alert">{{ error }}</Message>
  <slot name="feedback" />
  <slot /></div>
  <template v-if="$slots.footer" #footer><slot name="footer" /></template></NCard></NModal></template>
