<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import en from '../locales/en/email-change.json';
import fr from '../locales/fr/email-change.json';
defineProps<{ email: string; busy: boolean }>();
defineEmits<{ resend: []; cancel: [] }>();
const { t, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { emailChange: en }); mergeLocaleMessage('fr', { emailChange: fr });
</script>
<template><Message type="info" closable role="status" class="email-change-notice"><strong>{{ t('emailChange.pending', { email }) }}</strong>
  <p>{{ t('emailChange.pendingHelp') }}</p>
  <div class="email-change-actions"><Button attr-type="button" type="default" :disabled="busy" @click="$emit('resend')"><template #icon><AppIcon name="mail" /></template><span>{{ t('emailChange.resend') }}</span></Button>
   <Button attr-type="button" type="default" :disabled="busy" @click="$emit('cancel')"><template #icon><AppIcon name="close" /></template><span>{{ t('emailChange.cancel') }}</span></Button></div></Message></template>
<style scoped>
.email-change-notice { overflow-wrap: anywhere; }.email-change-notice p { margin: var(--space-2) 0 var(--space-3); }.email-change-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
