<script setup lang="ts" generic="T extends { id: string }">
import '../styles/collection-layout.css';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NCheckbox, NTable, NSpin } from 'naive-ui';
import type { ApiError } from '../api/auth';
import RecordBatchAction from './RecordBatchAction.vue';
import CollectionLayout from './CollectionLayout.vue';
import CollectionSelectAllButton from './CollectionSelectAllButton.vue';
import type { CardSelection } from './RecordCardSelection.vue';
import { useCollectionView } from '../lib/collection-view';

defineOptions({ inheritAttrs: false });
const props = defineProps<{
  rows: T[];
  columns: { key: string; label: string }[];
  loading?: boolean;
  canSelect?: (row: T) => boolean;
  remove?: (row: T) => Promise<unknown>;
  locked?: boolean;
  rowName?: (row: T) => string;
  preferenceKey?: string;
  gridClass?: string;
  staticGrid?: boolean;
  selectionTarget?: HTMLElement | null;
}>();
const emit = defineEmits<{ removed: []; accessLost: [cause: ApiError] }>();
defineSlots<{
  [name: string]: ((props: { row: T }) => unknown) | undefined;
} & {
  'batch-actions'?: (props: { rows: T[]; clear: (ids: string[]) => void }) => unknown;
  cards?: (props: { selectionFor: (row: T) => CardSelection | undefined }) => unknown;
}>();
const { t } = useI18n();
const mode = useCollectionView(() => props.preferenceKey ?? '');
const showsCards = computed(() => !!props.preferenceKey && mode.value === 'cards');
const selected = ref<string[]>([]);
const selectedIds = computed(() => new Set(selected.value));
const selectable = computed(() => props.rows.filter(row => props.canSelect?.(row)));
const selectedRows = computed(() => selectable.value.filter(row => selectedIds.value.has(row.id)));
const allSelected = computed(() => !!selectable.value.length && selectedRows.value.length === selectable.value.length);
const blocked = computed(() => !!(props.loading || props.locked));
watch(selectable, rows => {
  const visible = new Set(rows.map(row => row.id));
  selected.value = selected.value.filter(id => visible.has(id));
});
function select(id: string, checked: boolean) {
  selected.value = checked ? [...new Set([...selected.value, id])] : selected.value.filter(value => value !== id);
}
function selectAll(checked: boolean) {
  selected.value = checked ? selectable.value.map(row => row.id) : [];
}
function selectionFor(row: T): CardSelection | undefined {
  if (!props.canSelect?.(row)) return;
  return { label: t('collection.batch.select', { name: props.rowName?.(row) ?? row.id }), checked: selectedIds.value.has(row.id), disabled: blocked.value, toggle: checked => select(row.id, checked) };
}
function clear(ids: string[]) {
  selected.value = selected.value.filter(id => !ids.includes(id));
}
function removed(ids: string[]) {
  clear(ids);
  emit('removed');
}
</script>

<template>
  <NSpin class="collection-table-container" :show="!!loading" :inert="$attrs.inert as boolean | undefined" :aria-hidden="$attrs['aria-hidden'] as boolean | undefined">
    <div v-if="selectable.length" class="collection-batch-toolbar" :hidden="!!selectionTarget && !selectedRows.length">
      <Teleport :to="selectionTarget ?? 'body'" :disabled="!selectionTarget">
        <CollectionSelectAllButton :label="t(showsCards ? 'collection.batch.selectAllCards' : 'collection.batch.selectAll')" :pressed="allSelected || (!!selectedRows.length && 'mixed')" :disabled="blocked" @toggle="selectAll(!allSelected)" />
      </Teleport>
      <span v-if="selectedRows.length" role="status">{{ t('collection.batch.selected', { count: selectedRows.length }) }}</span>
      <RecordBatchAction v-if="selectedRows.length && remove" :rows="selectedRows" :action="remove" :name="rowName ?? (row => row.id)" :disabled="blocked" :label="t('recordDetails.delete')" icon="trash" destructive @applied="removed" @access-lost="emit('accessLost', $event)" />
      <slot v-if="selectedRows.length" name="batch-actions" :rows="selectedRows" :clear="clear" />
    </div>
    <CollectionLayout v-if="showsCards" :preference-key="preferenceKey!" :grid-class="gridClass" :static-grid="staticGrid">
      <slot name="cards" :selection-for="selectionFor" />
    </CollectionLayout>
    <div v-else :data-view="preferenceKey ? 'rows' : undefined" :aria-busy="!!loading" class="collection-scroll" :class="{ 'collection-table-view': preferenceKey }" role="region" tabindex="0" :aria-label="t('collection.tableScroll')">
      <NTable v-bind="$attrs" class="collection-table" size="small" striped :single-line="false" :aria-label="t('collection.table')">
        <thead><tr>
          <th v-if="canSelect" scope="col" class="collection-column-selection">
            <span class="sr-only">{{ t('collection.selection') }}</span>
          </th>
          <th v-for="column in columns" :key="column.key" :class="`collection-column-${column.key}`" scope="col">
            <slot :name="`heading-${column.key}`" :row="rows[0]!">{{ column.label }}</slot>
          </th>
        </tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td v-if="canSelect" class="collection-column-selection">
              <NCheckbox v-if="canSelect(row)" :checked="selectedIds.has(row.id)" :disabled="blocked" :aria-label="t('collection.batch.select', { name: rowName?.(row) ?? row.id })" @update:checked="select(row.id, $event)" />
            </td>
            <td v-for="column in columns" :key="column.key" :class="`collection-column-${column.key}`"><slot :name="column.key" :row="row" /></td>
          </tr>
          <tr v-if="!rows.length"><td :colspan="columns.length + Number(!!canSelect)"><slot name="empty" :row="rows[0]!" /></td></tr>
        </tbody>
      </NTable>
    </div>
  </NSpin>
</template>

<style scoped>
.collection-table-container { min-width: 0; max-width: 100%; }
.collection-scroll { max-width: 100%; overflow: auto; }
.collection-table { width: 100%; min-width: 48rem; table-layout: auto; }
.collection-batch-toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: var(--ui-control-gap); padding-block: var(--ui-control-gap); }
.collection-batch-toolbar[hidden] { display: none; }
</style>
