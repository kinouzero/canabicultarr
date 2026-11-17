<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import FormDialog from './FormDialog.vue';
import AppIcon from './AppIcon.vue';
defineProps<{ name: string; message: string; busy: boolean; error?: string }>();
const emit = defineEmits<{ cancel: []; confirm: [] }>();
const { t } = useI18n({ useScope: 'global' });
</script>
<template><FormDialog :visible="true" icon="trash" size="compact" :title="t('modalActions.deleteTitle', { name })" :close-label="t('actions.cancel')" :busy="busy" :error="error" @update:visible="!$event && emit('cancel')"><p>{{ message }}</p>
  <template #footer><Button :aria-label="t('actions.cancel')" type="default" :disabled="busy" @click="emit('cancel')"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button type="error" :loading="busy" @click="emit('confirm')"><AppIcon name="trash" /><span>{{ t('actions.delete') }}</span></Button></template></FormDialog></template>
