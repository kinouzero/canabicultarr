<script setup lang="ts">
import PlantBatchAssignments from './PlantBatchAssignments.vue';
import PlantActionTabs from './PlantActionTabs.vue';
import { useFeedback } from '../lib/feedback';
import PlantInventoryFields from './PlantInventoryFields.vue';
import { emptyInventoryDraft, fertilizerPayload, substratePayload, containerPayload, type PlantInventoryDraft } from '../lib/plant-inventory';
import { computed, onUnmounted, reactive, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import CollectionSelectAllButton from './CollectionSelectAllButton.vue';
import FormDialog from './FormDialog.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { plantActionTab, usePlantCareValidation } from '../lib/plant-care-form';
import { actionLabel, phaseLabel, phaseDefinition, type LifecycleAction } from '../lib/lifecycle';
import { builtInMaintenanceTypes, canRecordMaintenance, maintenanceLabel } from '../lib/plant-maintenance';
import { validHarvestWeight } from '../lib/harvest-weight';
import { plantsApi, type Plant } from '../api/plants';
import { ApiError } from '../api/auth';

type Kind = 'water' | 'maintenance' | 'lifecycle';
type Result = 'ready' | 'saving' | 'done' | 'failed';
interface Row { id: string; name: string; plant: Plant | null; error: string; requestId: string; state: Result; weight: string | number }
const props = defineProps<{ plants: { id: string; name: string; canManage: boolean; canDelete?: boolean }[]; disabled?: boolean; selectionTarget?: HTMLElement | null }>();
const selected = defineModel<string[]>({ required: true });
const emit = defineEmits<{ changed: []; opened: [value: boolean]; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const feedback = useFeedback();
const id = useId();
const actionTab = ref('action');
const mode = ref<Kind | null>(null); const loading = ref(false); const saving = ref(false); const attempted = ref(false);
watch(mode, value => emit('opened', !!value));
const occurredAt = ref<string | null>(null);
const fertilizerDrafts = ref<Record<string, PlantInventoryDraft>>({});
const fertilizerFields = new Map<string, InstanceType<typeof PlantInventoryFields>>();
const rows = ref<Row[]>([]); const error = ref(''); const savedTypes = ref<string[]>([]);
const form = reactive({ choice: '', type: '', customType: '', action: '', comment: '', occurredAt: '' });
const maintenanceType = computed(() => form.type === '__new__' ? form.customType.trim() : form.type.startsWith('saved:') ? form.type.slice(6) : form.type);
const busy = computed(() => loading.value || saving.value);
const editable = computed(() => !busy.value && !attempted.value);
const available = computed(() => props.plants.filter(plant => plant.canManage || plant.canDelete));
const allSelected = computed(() => !!available.value.length && selected.value.length === available.value.length);
watch([() => props.plants, () => props.disabled], () => { if (props.disabled) return; const ids = new Set(available.value.map(row => row.id)); const next = selected.value.filter(value => ids.has(value)); if (next.length !== selected.value.length) selected.value = next; });
const selectedPlants = computed(() => available.value.filter(row => selected.value.includes(row.id)));
function onApplied(ids: string[]) {
 selected.value = selected.value.filter(id => !ids.includes(id));
 emit('changed');
}
const actions = computed(() => {
 const result = new Map<string, LifecycleAction>();
 for (const row of rows.value) if (row.plant?.canManage) for (const action of phaseDefinition(row.plant.phase)?.actions ?? []) result.set(action.id, action);
 return [...result.values()];
});
function definition(row: Row) { return phaseDefinition(row.plant?.phase ?? '')?.actions.find(action => action.id === form.action); }
function eligible(row: Row) {
 if (!row.plant?.canManage) return false;
 return mode.value === 'water' ? row.plant.watering.status !== 'unavailable' : mode.value === 'maintenance' ? canRecordMaintenance(row.plant.phase) : !!definition(row);
}
watch(() => form.action, () => { if (!attempted.value) for (const row of rows.value) if (row.plant) row.error = ''; });
const targets = computed(() => rows.value.filter(eligible));
function stockGroupKey(plant: Plant) { return mode.value === 'water' ? plant.phase : `${plant.phase}:${plant.container?.inventoryItemId ?? ''}`; }
const stockGroups = computed(() => [...new Map(targets.value.filter(row => row.plant && (mode.value === 'water' || mode.value === 'lifecycle' && (form.action.startsWith('repot') || definition(row)?.substrates?.length || definition(row)?.container_inventory_item_id))).map(row => [stockGroupKey(row.plant!), { key: stockGroupKey(row.plant!), plant: row.plant! }])).values()]);
watch(stockGroups, groups => { for (const group of groups) if (!fertilizerDrafts.value[group.key]) fertilizerDrafts.value[group.key] = emptyInventoryDraft(); }, { flush: 'sync' });
const visibleRows = computed(() => rows.value.filter(row => mode.value !== 'lifecycle' || eligible(row) || row.error || row.state !== 'ready'));
const remaining = computed(() => rows.value.filter(row => row.state === 'failed'));
const done = computed(() => rows.value.filter(row => row.state === 'done').length);
const complete = computed(() => attempted.value && !remaining.value.length && !busy.value);
const unsaved = useUnsavedChanges(computed(() => !!mode.value && !complete.value && (attempted.value || !!form.choice || !!form.type || !!form.action || !!form.comment || !!form.occurredAt || rows.value.some(row => !!row.weight))), busy);
const validation = usePlantCareValidation(() => ({ comment: form.comment, occurredAt: form.occurredAt, ...(mode.value === 'water' ? { choice: form.choice } : mode.value === 'maintenance' ? { type: maintenanceType.value } : {}), documentIds: [] }), () => ({ occurred_at: `${id}-date`, comment: `${id}-comment`, with_products: `${id}-choice`, type: form.type === '__new__' ? `${id}-custom-type` : `${id}-type` }), undefined, field => { actionTab.value = plantActionTab(field); });
let active = true;
onUnmounted(() => { active = false; });
function message(cause: unknown) {
 const code = cause instanceof ApiError ? Object.values(cause.fields).flat().find(value => /^(plants|auth)\.(errors|validation|stock|containers)\.[A-Za-z]+$/.test(value) && te(value)) ?? cause.code : '';
 return /^(plants|auth)\.(errors|validation|stock|containers)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
}
function accessLost(cause: unknown) {
 if (!(cause instanceof ApiError) || ![401, 403].includes(cause.status)) return false;
 emit('accessLost', cause); return true;
}
async function read(row: Row) {
 try { const plant = await plantsApi.get(row.id); if (active) { row.plant = plant; row.name = plant.name; row.error = ''; } }
 catch (cause) { if (active) { row.error = message(cause); accessLost(cause); } }
}
async function open(kind: Kind) {
 if (props.disabled || !selected.value.length || busy.value) return;
 actionTab.value = 'action'; fertilizerDrafts.value = {}; fertilizerFields.clear();
 mode.value = kind; attempted.value = false; occurredAt.value = null; error.value = ''; validation.clear();
 Object.assign(form, { choice: '', type: '', customType: '', action: '', comment: '', occurredAt: '' });
 rows.value = available.value.filter(row => selected.value.includes(row.id)).map(row => ({ id: row.id, name: row.name, plant: null, error: '', requestId: crypto.randomUUID(), state: 'ready', weight: '' }));
 loading.value = true;
 // Bounded sequential reads keep large visible selections from flooding the API.
 for (const row of rows.value) { if (!active) return; await read(row); }
 if (kind === 'maintenance' && active) {
  try { savedTypes.value = await plantsApi.maintenanceTypes(); }
  catch (cause) { if (active) { error.value = message(cause); accessLost(cause); } }
 }
 if (active) {  loading.value = false; }
}
async function close() { if (await unsaved.confirmDiscard()) { mode.value = null; rows.value = []; } }
async function retryReads() {
 if (busy.value) return;
 loading.value = true;
 for (const row of rows.value.filter(row => !row.plant)) { if (!active) return; await read(row); }
 if (active) loading.value = false;
}
async function save() {
 if (busy.value || complete.value || !attempted.value && rows.value.some(row => !row.plant)) return;
 error.value = '';
 if (!attempted.value) {
  if ((mode.value === 'water' && !!form.choice || mode.value === 'lifecycle') && [...fertilizerFields.values()].some(fields => !fields.validate())) { actionTab.value = 'action'; return; }
  if (!validation.validate()) { error.value = 'plants.validation.invalid'; return; }
  if (!targets.value.length) return;
  const invalid = targets.value.find(row => definition(row)?.requires_harvest_weight && mode.value === 'lifecycle' && !validHarvestWeight(row.weight));
  if (invalid) { invalid.error = 'plants.validation.harvestWeight'; document.getElementById(`${id}-weight-${invalid.id}`)?.focus(); return; }
 }
 const completedBefore = done.value;
 const retrying = attempted.value;
 if (!retrying) occurredAt.value = form.occurredAt ? new Date(form.occurredAt).toISOString() : new Date().toISOString();
 const pending = retrying ? [...remaining.value] : [...targets.value];
 attempted.value = true; saving.value = true;
 // Freeze inputs after the first attempt. Retried requests retain their keys and payloads,
 // including after an ambiguous network failure; the server deduplicates each plant action.
 for (const row of pending) {
  if (!active) return;
  row.state = 'saving'; row.error = '';
  try {
   const plant = retrying ? await plantsApi.get(row.id) : row.plant!;
   if (!active) return;
   const time = occurredAt.value;
   if (mode.value === 'water') await plantsApi.water(plant, form.choice === 'with', row.requestId, form.comment || null, time, [], fertilizerPayload(fertilizerDrafts.value[stockGroupKey(row.plant!)] ?? emptyInventoryDraft(), form.choice === 'with'));
   else if (mode.value === 'maintenance') await plantsApi.maintain(plant, maintenanceType.value, row.requestId, form.comment || null, time, []);
   else await plantsApi.action(plant, form.action, row.requestId, form.comment || null, time, [], definition(row)?.requires_harvest_weight ? row.weight : undefined, substratePayload(fertilizerDrafts.value[stockGroupKey(row.plant!)] ?? emptyInventoryDraft()), containerPayload(fertilizerDrafts.value[stockGroupKey(row.plant!)] ?? emptyInventoryDraft()));
   if (!active) return; row.state = 'done';
  } catch (cause) {
   if (!active) return; row.state = 'failed'; row.error = message(cause);
   if (accessLost(cause)) break;
   if (cause instanceof ApiError && cause.status === 429) {
    for (const next of pending) if (next.state === 'ready') { next.state = 'failed'; next.error = row.error; }
    break;
   }
  }
 }
 if (active) { saving.value = false; if (done.value > completedBefore) feedback.success(t('feedback.batch', { count: done.value - completedBefore })); if (done.value) { selected.value = selected.value.filter(value => !rows.value.some(row => row.id === value && row.state === 'done')); emit('changed'); } }
}
</script>
<template>
 <Teleport v-if="available.length" :to="selectionTarget ?? 'body'" :disabled="!selectionTarget">
  <CollectionSelectAllButton :label="t('plants.batch.selectAll')" :pressed="allSelected || (!!selected.length && 'mixed')" :disabled="disabled || busy" @toggle="selected = allSelected ? [] : available.map(row => row.id)" />
 </Teleport>
 <div v-if="available.length && selected.length" class="batch-toolbar-container">
  <section class="batch-toolbar" :class="{ 'has-selection': selected.length }" :aria-label="t('plants.batch.title')">
   <div class="batch-selection-row">
    <div class="batch-overview">
     <span class="batch-symbol"><AppIcon name="check" /></span>
     <div class="batch-selection">
      <strong role="status">{{ t('plants.batch.selected', { count: selected.length }) }}</strong>
      <small :title="t('plants.batch.visibleOnly')">{{ t('plants.batch.scope') }}</small>
     </div>
    </div>
    <div class="batch-selection-tools">
     <ListActionButton v-if="selected.length" class="batch-clear" icon="close" :label="t('plants.batch.clear')" :disabled="disabled || busy" @click="selected = []" />
    </div>
   </div>
   <div v-if="selected.length" class="batch-action-row">
    <div v-if="selectedPlants.some(row => row.canManage)" class="batch-action-group" role="group" :aria-label="t('plants.batch.careActions')">
     <Button :aria-label="t('recordDetails.water')" :title="t('recordDetails.water')" :disabled="disabled || busy" type="primary" @click="open('water')">
      <template #icon><AppIcon name="water" /></template>{{ t('recordDetails.water') }}
     </Button>
     <Button type="default" :aria-label="t('recordDetails.maintenance')" :title="t('recordDetails.maintenance')" :disabled="disabled || busy" @click="open('maintenance')">
      <template #icon><AppIcon name="scissors" /></template>{{ t('recordDetails.maintenance') }}
     </Button>
     <Button type="default" :aria-label="t('plants.batch.lifecycle')" :title="t('plants.batch.lifecycle')" :disabled="disabled || busy" @click="open('lifecycle')">
      <template #icon><AppIcon name="refresh" /></template>{{ t('plants.batch.lifecycleShort') }}
     </Button>
    </div>
    <div class="batch-action-group batch-management" role="group" :aria-label="t('plants.batch.managementActions')">
     <PlantBatchAssignments :rows="selectedPlants" :disabled="disabled || busy" @applied="onApplied" @access-lost="emit('accessLost', $event)" />
    </div>
   </div>
  </section>
 </div>
 <DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <FormDialog v-if="mode" :visible="true" :icon="mode === 'water' ? 'water' : mode === 'maintenance' ? 'scissors' : 'refresh'" :title="t(`plants.batch.${mode}`)" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && close()"><p v-if="loading" role="status">{{ t('plants.loading') }}</p>
  <template v-else><Message v-if="attempted" :type="remaining.length ? 'warning' : 'success'" closable role="status">{{ t('plants.batch.result', { done, failed: remaining.length }) }}</Message>
   <p v-else>{{ t('plants.batch.review', { count: targets.length, total: rows.length }) }}</p>
   <form :id="`${id}-form`" class="dialog-form" @submit.prevent="save" novalidate>
    <PlantActionTabs v-model="actionTab">
     <template #action>
      <div class="dialog-form">
       <div class="dialog-form-grid"><div v-if="mode === 'water'" class="field"><label :for="`${id}-choice`">{{ t('plants.watering.choice') }}</label><select :id="`${id}-choice`" v-model="form.choice" v-bind="validation.attrs('with_products')" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="!editable"><option value="" disabled>{{ t('plants.watering.choose') }}</option><option value="with">{{ t('plants.watering.with') }}</option><option value="without">{{ t('plants.watering.without') }}</option></select><small v-if="validation.fields.value.with_products" :id="`${id}-choice-error`" role="alert" class="field-error">{{ t(validation.fields.value.with_products) }}</small><Message v-if="targets.some(row => row.plant?.watering.status === 'flush')" type="warning" closable>{{ t('plants.watering.flushHelp') }}</Message></div>
    <template v-if="mode === 'maintenance'"><div class="field"><label :for="`${id}-type`">{{ t('plants.maintenance.type') }}</label><select :id="`${id}-type`" v-model="form.type" v-bind="form.type === '__new__' ? {} : validation.attrs('type')" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="!editable"><option disabled value="">{{ t('plants.maintenance.choose') }}</option><option v-for="type in builtInMaintenanceTypes" :key="type" :value="type">{{ maintenanceLabel(type, t) }}</option><option v-for="type in savedTypes" :key="`saved:${type}`" :value="`saved:${type}`">{{ type }}</option><option value="__new__">{{ t('plants.maintenance.newType') }}</option></select><input v-if="form.type === '__new__'" :id="`${id}-custom-type`" v-bind="validation.attrs('type')" v-model="form.customType" :aria-label="t('plants.maintenance.customType')" maxlength="80" :disabled="!editable" /><small v-if="validation.fields.value.type" :id="form.type === '__new__' ? `${id}-custom-type-error` : `${id}-type-error`" role="alert" class="field-error">{{ t(validation.fields.value.type) }}</small></div></template>
    <div v-if="mode === 'lifecycle'" class="field"><label :for="`${id}-action`">{{ t('plants.batch.lifecycle') }}</label><select :id="`${id}-action`" v-model="form.action" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="!editable"><option value="" disabled>{{ t('plants.batch.chooseAction') }}</option><option v-for="action in actions" :key="action.id" :value="action.id">{{ actionLabel(action.id, t) }}</option></select></div>
    <div class="field"><label :for="`${id}-date`">{{ t('plants.occurredAt') }}</label><input :id="`${id}-date`" v-model="form.occurredAt" type="datetime-local" v-bind="validation.attrs('occurred_at')" :disabled="!editable" /><small>{{ t('plants.nowHelp') }}</small><small v-if="validation.fields.value.occurred_at" :id="`${id}-date-error`" role="alert" class="field-error">{{ t(validation.fields.value.occurred_at) }}</small></div></div>
    <template v-if="mode === 'water' || mode === 'lifecycle'"><div v-for="group in stockGroups" :key="group.key" class="dialog-wide"><h4 v-if="stockGroups.length > 1">{{ phaseLabel(group.plant.phase, t) }}<span v-if="mode === 'lifecycle' && group.plant.container"> · {{ group.plant.container.name }}</span></h4><PlantInventoryFields v-if="fertilizerDrafts[group.key]" :ref="value => { if (value) fertilizerFields.set(group.key, value as InstanceType<typeof PlantInventoryFields>); else fertilizerFields.delete(group.key); }" v-model="fertilizerDrafts[group.key]" :plant="group.plant" :action="mode === 'lifecycle' ? form.action : undefined" :enabled="mode === 'lifecycle' || !!form.choice" :with-fertilizer="mode === 'lifecycle' || form.choice === 'with'" :disabled="!editable" @failure="error = message($event)" /></div></template>
      </div>
     </template>
     <template #comment>
      <div class="field dialog-wide"><label :for="`${id}-comment`">{{ t('plants.comment') }}</label><textarea :id="`${id}-comment`" v-model="form.comment" rows="3" maxlength="2000" v-bind="validation.attrs('comment')" :disabled="!editable" /><small v-if="validation.fields.value.comment" :id="`${id}-comment-error`" role="alert" class="field-error">{{ t(validation.fields.value.comment) }}</small></div>
     </template>
    </PlantActionTabs>
   </form>
   <ul class="batch-results"><li v-for="row in visibleRows" :key="row.id" :data-state="row.state"><div class="batch-plant"><strong>{{ row.name }}</strong><small v-if="row.state === 'done'"><AppIcon name="check" />{{ t('plants.batch.done') }}</small><small v-else-if="row.state === 'saving'">{{ t('plants.batch.saving') }}</small><small v-else-if="!row.error && !eligible(row)">{{ t('plants.batch.skipped') }}</small><small v-else-if="mode === 'water' && row.plant">{{ t(row.plant.watering.nextWithProducts === null ? 'plants.watering.noChoice' : row.plant.watering.nextWithProducts ? 'plants.watering.nextWith' : 'plants.watering.nextWithout') }}<span v-if="row.plant.watering.status === 'flush'"> · {{ t('plants.watering.flush') }}</span></small></div><div v-if="mode === 'lifecycle' && eligible(row) && definition(row)?.requires_harvest_weight" class="field"><label :for="`${id}-weight-${row.id}`">{{ t('plants.batch.weight') }}</label><input :id="`${id}-weight-${row.id}`" v-model="row.weight" type="number" min="0.01" step="0.01" :disabled="!editable" :aria-invalid="row.error === 'plants.validation.harvestWeight'" :aria-describedby="row.error ? `${id}-error-${row.id}` : undefined" @input="row.error = ''" /></div><small v-if="row.error" :id="`${id}-error-${row.id}`" role="alert" class="field-error">{{ t(row.error) }}</small></li></ul>
   <Button v-if="rows.some(row => !row.plant) && !attempted" type="default" :aria-label="t('plants.batch.reload')" :disabled="busy" @click="retryReads"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button>
   <p v-if="attempted && remaining.length" class="batch-help">{{ t('plants.batch.retryHelp') }}</p></template>
  <template #footer><div class="dialog-actions"><Button type="default" :disabled="busy" @click="close"><template #icon><AppIcon name="close" /></template><span>{{ t(complete ? 'plants.batch.close' : 'plants.cancel') }}</span></Button><Button v-if="!complete" :form="`${id}-form`" attr-type="submit" :loading="saving" :disabled="busy || (!attempted && (!targets.length || rows.some(row => !row.plant) || mode === 'lifecycle' && !form.action))" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t(attempted ? 'plants.batch.retry' : 'plants.batch.confirm', { count: targets.length }) }}</span></Button></div></template></FormDialog></template>
<style scoped>
.batch-toolbar-container { container-type: inline-size; min-width: 0; }
.batch-toolbar {
 --batch-control-height: 2.15rem;
 display: grid;
 gap: var(--ui-content-gap);
 margin: var(--space-3) 0;
 padding: var(--space-2) 0;
 color: var(--theme-text);
}
.batch-selection-row, .batch-action-row {
 display: flex;
 justify-content: space-between;
 align-items: center;
 gap: var(--ui-content-gap);
}
.batch-overview, .batch-selection-tools, .batch-action-group {
 display: flex;
 align-items: center;
 gap: var(--ui-control-gap);
}
.batch-overview { min-width: 0; overflow-wrap: anywhere; }
.batch-symbol {
 display: grid;
 place-items: center;
 width: 2rem;
 height: 2rem;
 flex-shrink: 0;
 border-radius: .65rem;
 background: var(--theme-subtle);
 color: var(--theme-muted);
}
.batch-symbol svg { width: 1rem; height: 1rem; }
.has-selection .batch-symbol { background: var(--app-accent); color: var(--theme-accent-text, white); }
.batch-selection { display: grid; gap: var(--space-0-5); font-size: .8rem; }
.batch-selection small, .batch-help { color: var(--theme-muted); font-size: .7rem; }
.batch-selection-tools { flex-shrink: 0; }
.batch-action-row { flex-wrap: wrap; }
.batch-management { margin-inline-start: auto; }
.batch-toolbar :deep(.n-button) {
 height: var(--batch-control-height);
 min-height: var(--batch-control-height);
 padding-inline: var(--space-3);
 font-size: .75rem;
}
.batch-toolbar :deep(.n-button.batch-clear) {
 width: var(--batch-control-height);
 min-width: var(--batch-control-height);
 padding: 0;
}
.batch-toolbar :deep(.n-button__content) { white-space: nowrap; }
.batch-toolbar :deep(.n-button__icon svg) { width: .95rem; height: .95rem; flex-shrink: 0; }
@container (max-width: 42rem) {
 .batch-toolbar { --batch-control-height: var(--app-control-height, 2.5rem); }
 .batch-symbol { display: none; }
 .batch-action-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--ui-control-gap);
 }
 .batch-action-group {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(0, 1fr);
  margin-inline-start: 0;
 }
 .batch-toolbar :deep(.n-button) { min-width: 0; padding-inline: var(--space-1); font-size: .72rem; }
 .batch-action-group :deep(.n-button__content) { display: block; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
 .batch-action-group :deep(.n-button__icon) { margin-inline-end: var(--space-1); }
}
@container (max-width: 17.5rem) {
 .batch-action-group :deep(.n-button__content) { display: none; }
 .batch-action-group :deep(.n-button__icon) { margin: 0; }
}
.batch-results { list-style: none; padding: 0; display: grid; gap: var(--space-2); }.batch-results li { padding: var(--space-3) var(--space-3); border: 1px solid var(--theme-border); border-radius: .55rem; }.batch-plant { display: flex; justify-content: space-between; align-items: center; gap: var(--space-2); flex-wrap: wrap; font-size: .85rem; }.batch-plant strong { overflow-wrap: anywhere; }.batch-plant small { display: inline-flex; align-items: center; gap: var(--space-1); color: var(--theme-muted); }.batch-results [data-state="done"] { border-color: var(--theme-success); }.field { display: grid; gap: var(--space-1-5); }.field input, textarea { width: 100%; font: inherit; padding: var(--space-3); border: 1px solid var(--theme-border); border-radius: .5rem; background: var(--theme-surface); color: var(--theme-text); }
</style>
