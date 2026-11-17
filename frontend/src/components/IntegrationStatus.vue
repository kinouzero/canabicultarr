<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { NTag as Tag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
defineProps<{ icon: string; status: 'configured' | 'missing' | 'connected' | 'ready' | 'loading' | 'unknown'; reception?: boolean }>();
const { t } = useI18n();
</script>
<template><section class="integration-status" :aria-label="t(reception ? 'integrationPages.reception' : 'integrationPages.connection')"><span class="integration-status-icon"><AppIcon :name="icon" /></span>
  <div class="integration-status-body"><div class="integration-status-heading"><h3>{{ t(reception ? 'integrationPages.reception' : 'integrationPages.connection') }}</h3><Tag :type="status === 'missing' ? 'warning' : status === 'connected' || status === 'ready' ? 'success' : 'default'">{{ t(`integrationPages.${status}`) }}</Tag></div>
   <div v-if="$slots.default" class="integration-status-description"><slot /></div></div>
  <div v-if="$slots.actions" class="integration-status-actions"><slot name="actions" /></div></section></template>
