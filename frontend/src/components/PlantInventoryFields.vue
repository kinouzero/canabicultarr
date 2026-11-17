<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import ReferenceSelect from './ReferenceSelect.vue';
import FertilizerTimeline from './FertilizerTimeline.vue';
import DisclosureSummary from './DisclosureSummary.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import type { FertilizerPlan } from '../lib/fertilizer-periods';
import { inventoryApi, type StockBalance } from '../api/inventory';
import { plantsApi, type Plant } from '../api/plants';
import { inventoryLocationLabel } from '../lib/inventory-location';
import { emptyInventoryDraft, fertilizerAmount, validDose, type PlantInventoryDraft } from '../lib/plant-inventory';
const props = withDefaults(defineProps<{ plant: Pick<Plant, 'id'>; enabled: boolean; disabled?: boolean; action?: string; withFertilizer?: boolean; at?: string | null }>(), { withFertilizer: true });
const model = defineModel<PlantInventoryDraft>({ required: true });
const emit = defineEmits<{ failure: [cause: unknown]; dirty: [value: boolean] }>();
const { t } = useI18n();
const key = (name: string) => `plants.${props.action ? 'substrates' : 'fertilizers'}.${name}`; const id = useId();
const schedule = ref<FertilizerPlan | null>(null);
const outdated = ref(false);
const loading = ref(false); const loaded = ref(false); const loadError = ref(false); const canConsume = ref(false); const error = ref('');
let baseline = '';
const suggestionsDirty = computed(() => loaded.value && JSON.stringify(model.value) !== baseline);
const suggestionGuard = useUnsavedChanges(suggestionsDirty, loading, { guardRoute: false });
async function refresh() { if (await suggestionGuard.confirmDiscard()) await load(); }
let active = true; let sequence = 0;
onUnmounted(() => { active = false; sequence++; });
async function load() {
 const current = ++sequence; loading.value = true; loadError.value = false;
 try {
  const result = await plantsApi.consumables(props.plant.id, props.action, props.at && !Number.isNaN(new Date(props.at).getTime()) ? new Date(props.at).toISOString() : undefined);
  if (!active || current !== sequence) return;
  canConsume.value = result.canConsume; schedule.value = result.schedule ?? null; outdated.value = false;
  if (!model.value.waterLiters) model.value.waterLiters = result.waterLiters ?? '';
  model.value.rows = result.products.map(product => ({ product, quantity: product.quantity, balanceId: product.balances.length === 1 ? product.balances[0].id : '' }));
  model.value.currentContainer = result.currentContainer;
  model.value.container = result.container ? { product: result.container, balanceId: result.container.balances.length === 1 ? result.container.balances[0].id : '', previous: '' } : undefined;
  baseline = JSON.stringify(model.value);
  loaded.value = true; emit('dirty', false);
 } catch (cause) { if (active && current === sequence) { loadError.value = true; emit('failure', cause); } }
 finally { if (active && current === sequence) loading.value = false; }
}
watch(() => props.at, () => {
 if (props.action || !props.enabled) return;
 if (suggestionsDirty.value) { outdated.value = true; return; }
 void load();
});
watch([() => props.plant.id, () => props.action], () => { sequence++; model.value = emptyInventoryDraft(); schedule.value = null; outdated.value = false; baseline = ''; loaded.value = false; loading.value = false; });
watch([() => props.plant.id, () => props.action, () => props.enabled], () => { if (props.enabled && !loaded.value && !loading.value) void load(); }, { immediate: true });
watch(model, () => { error.value = ''; if (loaded.value) emit('dirty', JSON.stringify(model.value) !== baseline); }, { deep: true });
async function fetchPots(search: string, page: number) {
 const result = await inventoryApi.list({ type: 'container', state: 'active', search, per_page: '100' }, page);
 return { ...result, data: result.data.map(item => ({ id: item.id, name: item.name, detail: item.capacityLiters ? `${Number(item.capacityLiters)} L` : t('plants.containers.noCapacity'), disabled: item.unit !== 'piece' || !item.capacityLiters })) };
}
async function choosePot(itemId: string) {
 if (!itemId || props.disabled) return;
 loading.value = true;
 try {
  const item = await inventoryApi.show(itemId); const balances: StockBalance[] = []; let page = 1, last = 1;
  do { const result = await inventoryApi.balances(itemId, page); balances.push(...result.data.filter(row => Number(row.quantity) > 0)); last = result.meta.last_page; page++; } while (page <= last);
  if (!active) return;
  model.value.container = { product: { id: item.id, name: item.name, unit: item.unit, capacityLiters: item.capacityLiters, quantity: '1', available: !item.isArchived && item.type === 'container' && item.unit === 'piece' && !!item.capacityLiters, balances }, balanceId: balances.length === 1 ? balances[0].id : '', previous: model.value.container?.previous ?? '' };
 } catch (cause) { if (active) emit('failure', cause); }
 finally { if (active) loading.value = false; }
}
function amount(quantity: string) { return props.action ? (validDose(quantity) ? Number(quantity).toFixed(3) : null) : fertilizerAmount(quantity, model.value.waterLiters); }
function validate() {
 if (!props.enabled) return true;
 error.value = loading.value || loadError.value || !loaded.value ? 'plants.stock.loadRequired' : '';
 if (model.value.waterLiters && !validDose(model.value.waterLiters)) error.value = 'plants.fertilizers.invalidVolume';
 for (const row of props.withFertilizer === false ? [] : model.value.rows) {
  const balance = row.product.balances.find(value => value.id === row.balanceId);
  const used = amount(row.quantity);
  if (!canConsume.value) error.value = 'plants.stock.permission';
  else if (!row.product.available) error.value = 'plants.stock.invalidProduct';
  else if (!balance) error.value = 'plants.stock.invalidSource';
  else if (!used || row.product.unit === 'piece' && Number(used) % 1 !== 0) error.value = 'plants.stock.invalidQuantity';
  else if (Number(used) > Number(balance.quantity)) error.value = 'plants.stock.insufficientStock';
  if (error.value) break;
 }
 const container = model.value.container;
 if (container) {
  const balance = container.product.balances.find(row => row.id === container.balanceId);
  if (!canConsume.value) error.value = 'plants.stock.permission';
  else if (!container.product.available) error.value = 'plants.containers.invalid';
  else if (!balance) error.value = 'plants.stock.invalidSource';
  else if (Number(balance.quantity) < 1) error.value = 'plants.stock.insufficientStock';
  else if (model.value.currentContainer && !container.previous) error.value = 'plants.containers.choosePrevious';
 }
 if (error.value) { void nextTick(() => document.getElementById(`${id}-section`)?.focus()); return false; }
 return true;
}
defineExpose({ validate });
</script>
<template>
 <DiscardChangesDialog v-if="suggestionGuard.pending.value" @decision="suggestionGuard.finish" />
 <section v-show="enabled" :id="`${id}-section`" class="stock-fields dialog-wide" tabindex="-1" :data-testid="action ? 'action-substrates' : 'watering-fertilizers'">
  <p v-if="loading" role="status">{{ t('plants.loading') }}</p>
  <Message v-if="loadError" type="error" closable>{{ t('plants.stock.loadRequired') }}</Message>
  <Button v-if="loadError" attr-type="button" type="default" :disabled="disabled || loading" @click="refresh">
   <template #icon><AppIcon name="refresh" /></template>{{ t('actions.refresh') }}
  </Button>

  <template v-if="loaded">
   <Message v-if="withFertilizer !== false && !canConsume && model.rows.length" type="warning" closable>{{ t('plants.stock.permission') }}</Message>
   <Message v-if="outdated" type="info" closable>{{ t('plants.fertilizerSchedule.dateChanged') }}
    <Button attr-type="button" :disabled="disabled || loading" type="primary" @click="refresh">
     <template #icon><AppIcon name="refresh" /></template>{{ t('plants.fertilizerSchedule.refresh') }}
    </Button>
   </Message>
   <details v-if="schedule?.periods.length" v-remember-disclosure="'watering-fertilizer-schedule'">
    <DisclosureSummary :level="3" icon="leaf">{{ t('plants.fertilizerSchedule.title') }}</DisclosureSummary>
    <FertilizerTimeline :periods="schedule.periods" :duration-days="schedule.durationDays" />
    <p class="record-note">{{ t('plants.fertilizerSchedule.help') }}</p>
   </details>
   <div v-if="!action" class="field">
    <label :for="`${id}-volume`">{{ t('plants.fertilizers.volume') }}</label>
    <input :id="`${id}-volume`" v-model="model.waterLiters" type="text" inputmode="decimal" :disabled="disabled" placeholder="1" />
   </div>

   <section v-if="withFertilizer !== false" class="stock-section">
    <div class="stock-heading">
     <h4><AppIcon :name="action ? 'box' : 'leaf'" />{{ t(key('title')) }}</h4>
     <p class="record-note">{{ t(key(model.rows.length ? 'help' : 'empty')) }}</p>
    </div>
    <div v-for="(row, index) in model.rows" :key="row.product.id" class="stock-item fertilizer-row">
     <header>
      <strong>{{ row.product.name || t('plants.fertilizers.unavailable') }}</strong>
      <ListActionButton type="button" icon="trash" severity="danger" :label="t(key('remove'))" :disabled="disabled" @click="model.rows.splice(index, 1)" />
     </header>
     <p v-if="!row.product.available" class="field-error">{{ t('plants.stock.invalidProduct') }}</p>
     <div class="stock-inputs">
      <div class="field">
       <label :for="`${id}-dose-${index}`">{{ t(key('dose'), { unit: row.product.unit }) }}</label>
       <input :id="`${id}-dose-${index}`" v-model="row.quantity" type="text" inputmode="decimal" :disabled="disabled || !canConsume" />
      </div>
      <div class="field">
       <label :for="`${id}-source-${index}`">{{ t('plants.fertilizers.source') }}</label>
       <select :id="`${id}-source-${index}`" v-model="row.balanceId" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled || !canConsume">
        <option value="" disabled>{{ t('plants.fertilizers.chooseSource') }}</option>
        <option v-for="balance in row.product.balances" :key="balance.id" :value="balance.id">{{ inventoryLocationLabel(balance.location) }} · {{ Number(balance.quantity) }} {{ row.product.unit }}</option>
       </select>
      </div>
     </div>
     <small v-if="!action" class="fertilizer-total">{{ t('plants.fertilizers.used', { quantity: amount(row.quantity) ?? '—', unit: row.product.unit }) }}</small>
    </div>
   </section>

   <section v-if="action && (action.startsWith('repot') || model.container || model.currentContainer)" class="stock-section">
    <div class="stock-heading">
     <h4><AppIcon name="pot" />{{ t('plants.containers.title') }}</h4>
     <p v-if="model.currentContainer" class="record-note">{{ t('plants.containers.current', { name: model.currentContainer.name, capacity: Number(model.currentContainer.capacityLiters) }) }}</p>
    </div>
    <ReferenceSelect :label="t('plants.containers.choose')" :fetch-page="fetchPots" :disabled="disabled || loading || !canConsume" @select="choosePot" />
    <div v-if="model.container" class="stock-item" data-testid="repot-container">
     <header>
      <strong>{{ model.container.product.name }} · {{ Number(model.container.product.capacityLiters) }} L</strong>
      <ListActionButton type="button" icon="trash" severity="danger" :label="t('plants.containers.remove')" :disabled="disabled" @click="model.container = undefined" />
     </header>
     <div class="stock-inputs" :class="{ 'with-previous': model.currentContainer }">
      <div class="field">
       <label :for="`${id}-pot-source`">{{ t('plants.fertilizers.source') }}</label>
       <select :id="`${id}-pot-source`" v-model="model.container.balanceId" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled || !canConsume">
        <option disabled value="">{{ t('plants.fertilizers.chooseSource') }}</option>
        <option v-for="balance in model.container.product.balances" :key="balance.id" :value="balance.id">{{ inventoryLocationLabel(balance.location) }} · {{ Number(balance.quantity) }}</option>
       </select>
      </div>
      <div v-if="model.currentContainer" class="field">
       <label :for="`${id}-pot-previous`">{{ t('plants.containers.previous') }}</label>
       <select :id="`${id}-pot-previous`" v-model="model.container.previous" v-app-select="{ filter: false }" :disabled="disabled">
        <option disabled value="">{{ t('selectControl.choose') }}</option>
        <option value="recover">{{ t('plants.containers.recover') }}</option>
        <option value="discard">{{ t('plants.containers.discard') }}</option>
       </select>
       <small v-if="model.container.previous === 'recover'">{{ t('plants.containers.returnHelp') }}</small>
      </div>
     </div>
     <small>{{ t('plants.containers.deduction') }}</small>
    </div>
   </section>
  </template>
  <Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message>
 </section>
</template>

<style scoped>
.stock-fields, .stock-section { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.stock-heading { display: grid; gap: var(--space-1); }
.stock-heading h4 { display: flex; align-items: center; gap: var(--ui-icon-gap); margin: 0; font-size: .85rem; }
.stock-heading svg { width: 1rem; height: 1rem; }
p { margin: 0; }
.field { display: grid; gap: var(--space-1-5); margin: 0; min-width: 0; }
input, select { width: 100%; min-width: 0; min-height: var(--app-control-height); }
.stock-item { display: grid; gap: var(--ui-control-gap); padding: var(--ui-compact-inset); background: var(--theme-subtle); border-radius: .65rem; }
.stock-item header { display: flex; justify-content: space-between; align-items: center; gap: var(--ui-control-gap); }
.stock-item strong { min-width: 0; overflow-wrap: anywhere; font-size: .8rem; }
.stock-inputs { display: grid; align-items: start; gap: var(--ui-grid-gap); }
.fertilizer-row .stock-inputs { grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); }
.stock-inputs.with-previous { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.fertilizer-total { color: var(--theme-muted); font-variant-numeric: tabular-nums; }
@media (max-width: 500px) {
 .fertilizer-row .stock-inputs, .stock-inputs.with-previous { grid-template-columns: minmax(0, 1fr); }
}
</style>
