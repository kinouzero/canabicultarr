<script setup lang="ts">
import { NTabPane } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import AppTabs from './AppTabs.vue';

const tab = defineModel<string>({ required: true });
const { t } = useI18n();
const themeOverrides = { tabPaddingMediumBar: 'var(--space-2) var(--space-2)', tabGapMediumBar: 'var(--space-2)' };
</script>

<template>
 <AppTabs v-model:value="tab" type="bar" class="plant-action-tabs" :theme-overrides="themeOverrides" :aria-label="t('plants.confirmAction')">
  <NTabPane name="action" display-directive="show">
   <template #tab><AppIcon name="edit" />{{ t('plants.actionTab') }}</template>
   <slot name="action" />
  </NTabPane>
  <NTabPane v-if="$slots.inventory" name="inventory" display-directive="show">
   <template #tab><AppIcon name="inventory" />{{ t('editorTabs.stock') }}</template>
   <slot name="inventory" />
  </NTabPane>
  <NTabPane name="comment" display-directive="show">
   <template #tab><AppIcon name="message" />{{ t('plants.comment') }}</template>
   <slot name="comment" />
  </NTabPane>
  <NTabPane v-if="$slots.attachments" name="attachments" display-directive="show">
   <template #tab><AppIcon name="file" />{{ t('plants.attachments.title') }}</template>
   <slot name="attachments" />
  </NTabPane>
 </AppTabs>
</template>

<style scoped>
@media (max-width: 36rem) {
 .plant-action-tabs.app-tabs :deep(.n-tabs-tab) {
  padding-inline: var(--space-1);
 }
 .plant-action-tabs :deep(.n-tabs-tab-pad) {
  width: var(--space-1);
 }
 .plant-action-tabs :deep(.n-tabs-tab__label) {
  flex-direction: column;
  gap: var(--space-1);
 }
}
</style>
