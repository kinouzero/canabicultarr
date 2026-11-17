<script setup lang="ts">
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { inventoryApi, type InventoryItem } from '../api/inventory';
import type { StockRecipe } from '../lib/plant-inventory';
import ReferenceSelect from './ReferenceSelect.vue';
import ListActionButton from './ListActionButton.vue';
import AppIcon from './AppIcon.vue';
const rows = defineModel<StockRecipe[]>({ default: () => [] });
const props = defineProps<{ disabled?: boolean; kind: 'fertilizer' | 'substrate' }>();
const emit = defineEmits<{ failure: [cause: unknown]; items: [items: InventoryItem[]] }>();
const { t } = useI18n();
const key = (name: string) => `plants.${props.kind === 'substrate' ? 'substrates' : 'fertilizers'}.${name}`; const id = useId();
const items = ref(new Map<string, InventoryItem>());
async function fetchItems(search: string, page: number) {
 const result = await inventoryApi.list({ search, type: props.kind, state: 'active', per_page: '100' }, page);
 for (const item of result.data) items.value.set(item.id, item);
 emit('items', [...items.value.values()]);
 return { ...result, data: result.data.map(item => ({ id: item.id, name: item.name, detail: props.kind === 'fertilizer' ? `${item.unit}/L` : item.unit })) };
}
function add(itemId: string) {
 if (!itemId || rows.value.some(row => row.inventory_item_id === itemId)) return;
 rows.value = [...rows.value, { inventory_item_id: itemId, quantity: props.kind === 'fertilizer' ? items.value.get(itemId)?.defaultDosePerLiter ?? '1' : '1' }];
}
</script>
<template>
 <section class="stage-fertilizers dialog-wide" :data-testid="`recipe-${kind}`">
  <h4><AppIcon name="leaf" />{{ t(key('title')) }}</h4>
  <p class="record-note">{{ t(key('stageHelp')) }}</p>
  <ReferenceSelect :id="`${id}-product`" :label="t(key('add'))" :fetch-page="fetchItems" :exclude-ids="rows.map(row => row.inventory_item_id)" :disabled="disabled || rows.length >= 20" @select="add" @access-lost="emit('failure', $event)" />
  <div v-for="(row, index) in rows" :key="row.inventory_item_id" class="stage-product">
   <div class="field"><label :for="`${id}-dose-${index}`">{{ items.get(row.inventory_item_id)?.name ?? t('plants.fertilizers.unavailable') }} · {{ t(key('dose'), { unit: items.get(row.inventory_item_id)?.unit ?? '—' }) }}</label><input :id="`${id}-dose-${index}`" :value="row.quantity" @input="rows = rows.map((value, rowIndex) => rowIndex === index ? { ...value, quantity: ($event.target as HTMLInputElement).value } : value)" type="text" inputmode="decimal" :disabled="disabled" /></div>
   <ListActionButton type="button" icon="trash" severity="danger" :label="t(key('remove'))" :disabled="disabled" @click="rows = rows.filter((_, rowIndex) => rowIndex !== index)" />
  </div>
 </section>
</template>
<style scoped>
.stage-fertilizers { display: grid; gap: var(--space-3); min-width: 0; padding: 0; border: 0; }h4 { display: flex; gap: var(--space-1-5); align-items: center; margin: 0; font-size: .85rem; }h4 svg { width: 1rem; height: 1rem; }p { margin: 0; }.field { display: grid; gap: var(--space-1-5); margin: 0; min-width: 0; }input { width: 100%; min-width: 0; }.stage-product { display: flex; gap: var(--space-3); align-items: end; }.stage-product .field { flex: 1; }.stage-product label { overflow-wrap: anywhere; }
</style>
