<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import FormDialog from './FormDialog.vue';
const emit = defineEmits<{ decision: [discard: boolean] }>();
const { t } = useI18n({ useScope: 'global' });
</script>
<template><FormDialog :visible="true" size="compact" icon="edit" :title="t('modalActions.unsavedTitle')" :close-label="t('modalActions.keepEditing')" @update:visible="!$event && emit('decision', false)"><p>{{ t('modalActions.unsavedHelp') }}</p>
  <template #footer><Button type="default" @click="emit('decision', false)"><template #icon><AppIcon name="edit" /></template><span>{{ t('modalActions.keepEditing') }}</span></Button><Button type="error" @click="emit('decision', true)"><template #icon><AppIcon name="trash" /></template><span>{{ t('modalActions.discardChanges') }}</span></Button></template></FormDialog></template>
