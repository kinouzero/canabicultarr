<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FertilizerPeriod, LifecycleStage } from '../lib/lifecycle';
import type { InventoryItem } from '../api/inventory';
import { previewFertilizerPeriods } from '../lib/fertilizer-periods';
import InventoryRecipeEditor from './InventoryRecipeEditor.vue';
import FertilizerTimeline from './FertilizerTimeline.vue';
import ListActionButton from './ListActionButton.vue';
const stage = defineModel<LifecycleStage>({ required: true });
const props = defineProps<{ durationDays: number | null; flushDays?: number }>();
const emit = defineEmits<{ failure: [cause: unknown] }>();
const { t } = useI18n(); const id = useId();
const selected = ref(stage.value.fertilizer_periods?.[0]?.id ?? '');
const periods = computed(() => stage.value.fertilizer_periods ?? []);
watch(periods, value => { if (!value.some(period => period.id === selected.value)) selected.value = value[0]?.id ?? ''; });
const period = computed(() => periods.value.find(period => period.id === selected.value));
const names = ref(new Map<string, string>());
function catalog(items: InventoryItem[]) { for (const item of items) names.value.set(item.id, item.name); }
const preview = computed(() => previewFertilizerPeriods(periods.value, props.durationDays, names.value, props.durationDays !== null && stage.value.flush ? Math.max(0, props.durationDays - (props.flushDays ?? 0)) : props.durationDays));
const rows = computed({ get: () => (period.value?.products ?? []).map(row => ({ inventory_item_id: row.inventory_item_id, quantity: row.dose_per_liter })), set: rows => { if (period.value) period.value.products = rows.map(row => ({ inventory_item_id: row.inventory_item_id, dose_per_liter: row.quantity })); } });
function add() {
 const next: FertilizerPeriod = { id: crypto.randomUUID(), name: null, unit: 'days', start: 0, end: null, products: [] };
 stage.value.fertilizer_periods = [...periods.value, next]; selected.value = next.id;
}
function remove() { stage.value.fertilizer_periods = periods.value.filter(value => value.id !== selected.value); }
</script>
<template>
 <div class="fertilizer-period-editor dialog-section dialog-wide">
  <div class="field watering-volume"><label :for="id"><AppIcon name="water" />{{ t('plants.fertilizers.defaultVolume') }}</label><input :id="id" v-model="stage.water_liters" type="text" inputmode="decimal" :placeholder="t('plants.fertilizers.noDefault')" /></div>
  <div class="period-editor-heading"><h4><AppIcon name="leaf" />{{ t('plants.fertilizerSchedule.title') }}<span class="period-count">{{ periods.length }}</span></h4><ListActionButton icon="plus" :label="t('plants.fertilizerSchedule.add')" :disabled="periods.length >= 20" @click="add" /></div>
  <p class="record-note">{{ t('plants.fertilizerSchedule.help') }}</p>
  <p v-if="!periods.length" class="record-note">{{ t('plants.fertilizerSchedule.empty') }}</p>
  <FertilizerTimeline v-else :periods="preview" :duration-days="durationDays" :selected-id="selected" />
  <section v-if="period" class="period-fields">
   <div class="period-choice"><div class="field"><label :for="`${id}-period`">{{ t('plants.fertilizerSchedule.choose') }}</label><select :id="`${id}-period`" v-model="selected" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-for="(entry, index) in periods" :key="entry.id" :value="entry.id">{{ entry.name || t('plants.fertilizerSchedule.period', { count: index + 1 }) }}</option></select></div><ListActionButton icon="trash" severity="danger" :label="t('plants.fertilizerSchedule.remove')" @click="remove" /></div>
   <div class="field period-name"><label :for="`${id}-name`">{{ t('plants.fertilizerSchedule.name') }}</label><input :id="`${id}-name`" v-model="period.name" type="text" maxlength="80" /></div>
   <div class="period-bounds">
    <div class="field"><label :for="`${id}-unit`">{{ t('plants.fertilizerSchedule.unit') }}</label><select :id="`${id}-unit`" v-model="period.unit" v-app-select="{ filter: false }"><option value="days">{{ t('plants.fertilizerSchedule.units.days') }}</option><option value="weeks">{{ t('plants.fertilizerSchedule.units.weeks') }}</option><option value="percent" :disabled="durationDays === null">{{ t('plants.fertilizerSchedule.units.percent') }}</option></select></div>
    <div class="field"><label :for="`${id}-start`">{{ t('plants.fertilizerSchedule.start') }}</label><input :id="`${id}-start`" v-model.number="period.start" type="number" min="0" step="1" /></div>
    <div class="field"><label :for="`${id}-end`">{{ t('plants.fertilizerSchedule.end') }}</label><input :id="`${id}-end`" :value="period.end ?? ''" type="number" min="1" step="1" :placeholder="t('plants.fertilizerSchedule.stageEnd')" @input="period.end = ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value)" /></div>
   </div>
   <InventoryRecipeEditor :key="period.id" v-model="rows" kind="fertilizer" @items="catalog" @failure="emit('failure', $event)" />
  </section>
  <p v-if="stage.flush && flushDays" class="record-note">{{ t('plants.fertilizerSchedule.flush', { count: flushDays }) }}</p>
 </div>
</template>
<style scoped>
.fertilizer-period-editor { display: grid; gap: var(--ui-content-gap); min-width: 0; }.fertilizer-period-editor p { margin: 0; }.watering-volume { max-width: 22rem; }.watering-volume label,.period-editor-heading h4 { display: flex; align-items: center; gap: var(--ui-icon-gap); }.watering-volume svg,.period-editor-heading svg { width: 1rem; height: 1rem; flex-shrink: 0; }.period-editor-heading,.period-choice { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }.period-editor-heading h4 { margin: 0; font-size: .85rem; }.period-count { font-size: .7rem; font-weight: 500; color: var(--theme-muted); background: var(--theme-subtle); padding: .15rem .45rem; border-radius: 1rem; }.period-fields { display: grid; padding-block: var(--space-4); border-block: 1px solid var(--theme-border); gap: var(--ui-grid-gap); }.period-choice { align-items: end; }.period-choice .field { flex: 1; }.period-choice :deep(button) { flex-shrink: 0; }.period-bounds { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); align-items: start; gap: var(--space-3); }.field { display: grid; gap: var(--space-2); margin: 0; min-width: 0; }input,select { width: 100%; min-width: 0; }@media(max-width:500px) { .period-bounds { grid-template-columns: 1fr; } }
</style>
