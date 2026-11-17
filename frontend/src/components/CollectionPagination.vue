<script setup lang="ts">
import { pageSizes } from '../lib/collection-page-size';
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';
import ListActionButton from './ListActionButton.vue';
withDefaults(defineProps<{ pageSize?: number; page: number; lastPage: number; total: number; disabled?: boolean; label?: string; previousLabel?: string; nextLabel?: string }>(), { disabled: false });
const emit = defineEmits<{ page: [value: number]; 'update:pageSize': [value: number] }>();
const id = useId();
const { t } = useI18n();
</script>
<template>
 <nav class="collection-pagination" :aria-label="label || t('collection.pagination')">
  <span class="collection-result-count" role="status">{{ t('collection.results', { count: total }, total) }}</span>
  <label v-if="pageSize !== undefined" class="collection-page-size" :for="`${id}-page-size`"><span>{{ t('collection.pageSize') }}</span><select :id="`${id}-page-size`" :aria-label="t('collection.pageSize')" :value="pageSize" :disabled="disabled" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" @change="emit('update:pageSize', Number(($event.target as HTMLSelectElement).value))"><option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option></select></label>
  <div class="collection-page-controls">
   <ListActionButton icon="arrowLeft" :label="previousLabel || t('collection.previous')" :disabled="disabled || page <= 1" @click="emit('page', page - 1)" />
   <span class="collection-page-number">{{ t('collection.page', { current: page, total: Math.max(1, lastPage) }) }}</span>
   <ListActionButton icon="arrow" :label="nextLabel || t('collection.next')" :disabled="disabled || page >= lastPage" @click="emit('page', page + 1)" />
  </div>
 </nav>
</template>
<style scoped>
.collection-pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-2) var(--ui-grid-gap); margin-top: var(--ui-content-gap); padding-top: var(--space-3); border-top: 1px solid var(--theme-border); font-size: .75rem; color: var(--theme-muted); }
.collection-page-size { display: flex; align-items: center; gap: var(--space-2); margin-left: auto; font-size: inherit; font-weight: 400; }.collection-page-size select { width: 5.5rem; min-width: 0; }.collection-page-size + .collection-page-controls { margin-left: 0; }
.collection-result-count { font-variant-numeric: tabular-nums; }
.collection-page-controls { display: flex; align-items: center; gap: var(--space-2); margin-left: auto; }
.collection-page-number { white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>
