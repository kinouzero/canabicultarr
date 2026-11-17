<script setup lang="ts">
import AppSwitch from './AppSwitch.vue';
import AppTabs from './AppTabs.vue';
import ReferenceSelect from './ReferenceSelect.vue';
import { inventoryApi } from '../api/inventory';
import InventoryRecipeEditor from './InventoryRecipeEditor.vue';
import LifecycleLocationSelect from './LifecycleLocationSelect.vue';
import StageFertilizerRecipe from './StageFertilizerRecipe.vue';
import { validFertilizerPeriods } from '../lib/fertilizer-periods';
import { validDose } from '../lib/plant-inventory';
import { useFieldErrors } from '../lib/field-errors';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { isSettingsTabChange } from '../lib/settings-navigation';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import PageTitle from './PageTitle.vue';
import { computed, onMounted, onUnmounted, ref, toRaw, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NTabPane } from 'naive-ui';




import { NCard as Card } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import FormDialog from './FormDialog.vue';
import DurationInput from './DurationInput.vue';
import { ApiError } from '../api/auth';
import { locationsApi } from '../api/locations';
import type { LocationContext } from '../api/types';
import { strainsApi, type LifecycleSettings, type Durations, type WateringDefaults } from '../api/strains';
import { applyLifecycle, phaseLabel, actionLabel, type LifecycleStage } from '../lib/lifecycle';
import { locationIcons, locationLabel } from '../lib/location';
import en from '../locales/en/catalog.json';
import fr from '../locales/fr/catalog.json';
import plantsEn from '../locales/en/plants.json';
import plantsFr from '../locales/fr/plants.json';
const props = defineProps<{ hideHeading?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { catalog: en, plants: plantsEn }); mergeLocaleMessage('fr', { catalog: fr, plants: plantsFr });
const settings = ref<LifecycleSettings | null>(null);
const stages = ref<LifecycleStage[]>([]); const durations = ref<Durations>({});
const automaticRelocation = ref(false);
const watering = ref<WateringDefaults>({ alertDays: 3, moistureThreshold: 35 });
const loading = ref(false); const saving = ref(false); const error = ref(''); const notice = ref(false);
const editing = ref<{ index: number; stage: LifecycleStage; days: number | ''; indefinite: boolean; added?: boolean } | null>(null);
let active = true;
const destinationContexts = ref(new Map<string, LocationContext | null | undefined>());
const destinationKey = (reference: NonNullable<LifecycleStage['target_location']>) => `${reference.type}:${reference.id}`;
function resolveDestinations() {
 const contexts = destinationContexts.value;
 for (const { target_location: reference } of stages.value) {
  if (!reference) continue;
  const key = destinationKey(reference);
  if (contexts.has(key)) continue;
  contexts.set(key, undefined);
  void locationsApi.context(reference.type, reference.id)
   .then(result => { if (active) contexts.set(key, result.context); })
   .catch(() => { if (active) contexts.set(key, null); });
 }
}
function destinationText(reference: NonNullable<LifecycleStage['target_location']>) {
 const context = destinationContexts.value.get(destinationKey(reference));
 if (context === undefined) return t('catalog.workflow.loadingDestination');
 return locationLabel(context) || t('catalog.workflow.unavailableDestination');
}
const failedSnapshot = ref<string | null>(null);
const editorTab = ref('stage');
const actionPreset = ref('custom');
const actionPresets = [
 { id: 'custom', label: 'catalog.workflow.customAction', icon: 'scissors', forward: false },
 { id: 'sow', label: 'plants.actions.sow', icon: 'seed', forward: true },
 { id: 'plant', label: 'plants.actions.plant', icon: 'sprout', forward: true },
 { id: 'repot_growth', label: 'plants.actions.repot_growth', icon: 'pot', forward: true },
 { id: 'repot_flowering', label: 'plants.actions.repot_flowering', icon: 'pot', forward: true },
 { id: 'harvest', label: 'plants.actions.harvest', icon: 'harvest', forward: true },
 { id: 'transfer_to_rack', label: 'plants.actions.transfer_to_rack', icon: 'rack', forward: true },
 { id: 'transfer_to_containers', label: 'plants.actions.transfer_to_containers', icon: 'jar', forward: true },
 { id: 'start_maturation', label: 'plants.actions.start_maturation', icon: 'hourglass', forward: true },
 { id: 'archive', label: 'plants.actions.archive', icon: 'archive', forward: true },
 { id: 'topping', label: 'plants.maintenance.types.topping', icon: 'scissors', forward: false },
 { id: 'pruning', label: 'plants.maintenance.types.pruning', icon: 'scissors', forward: false },
];
const availablePresets = computed(() => actionPresets.filter(preset => editing.value?.stage.id !== 'planned' || preset.forward || preset.id === 'custom'));
const boundary = (id: string) => ['planned', 'archived'].includes(id);
const stageBaseline = ref('');
function stageSnapshot() { const draft = editing.value; return draft ? JSON.stringify([draft.stage, draft.days, draft.indefinite]) : ''; }
const stageDirty = computed(() => !!editing.value && stageSnapshot() !== stageBaseline.value);
const draftSnapshot = computed(() => JSON.stringify([stages.value, durations.value, watering.value, automaticRelocation.value]));
const changed = computed(() => !!settings.value && draftSnapshot.value !== JSON.stringify([settings.value.stages, settings.value.durations, settings.value.wateringDefaults ?? { alertDays: 3, moistureThreshold: 35 }, settings.value.automaticRelocation ?? false]));
const dirty = computed(() => !!settings.value?.canManage && (changed.value || stageDirty.value));
const unsaved = useUnsavedChanges(dirty, saving, { preserveDraft: (to, from) => !!props.hideHeading && !editing.value && isSettingsTabChange(to, from), flush: async () => {
 if (canSave.value && draftSnapshot.value !== failedSnapshot.value) await save();
} });
const validation = useFieldErrors(() => ({
 stages: { id: 'cycle-stages-validation', message: 'catalog.validation.workflow' },
 ...Object.fromEntries(stages.value.filter(stage => !boundary(stage.id)).map(stage => [`durations.${stage.id}`, { id: `cycle-stage-${stage.id}`, message: 'catalog.validation.duration' }])),
 'durations.flush': { id: 'cycle-flush', message: 'catalog.validation.duration' },
 watering_alert_days: { id: 'cycle-watering-days', message: 'catalog.validation.wateringDays' },
 watering_moisture_threshold: { id: 'cycle-moisture', message: 'catalog.validation.moisture' },
}), () => ({ stages: JSON.stringify(stages.value), ...Object.fromEntries(Object.entries(durations.value).map(([key, value]) => [`durations.${key}`, value])),
 watering_alert_days: watering.value.alertDays, watering_moisture_threshold: watering.value.moistureThreshold }));
const stageValidation = useFieldErrors(() => ({
 target_location: { id: 'stage-target-location', message: 'catalog.validation.targetLocation' },
 name: { id: 'stage-name', message: 'catalog.validation.stageName' }, icon: { id: 'stage-icon', message: 'catalog.validation.icon' },
 days: { id: 'stage-duration', message: 'catalog.validation.duration' }, flush: { id: 'stage-flush', message: 'catalog.validation.flush' },
 ...Object.fromEntries((editing.value?.stage.actions ?? []).flatMap((_, index) => ['name', 'icon', 'target'].map(field => [`actions.${index}.${field}`, { id: `action-${field}-${index}`, message: `catalog.validation.${field === 'name' ? 'stageName' : field}` }]))),
}), () => ({ target_location: JSON.stringify(editing.value?.stage.target_location), name: editing.value?.stage.name, icon: editing.value?.stage.icon, days: editing.value?.days,
 flush: JSON.stringify([editing.value?.stage.flush, editing.value?.stage.watering, editing.value?.indefinite]),
 ...Object.fromEntries((editing.value?.stage.actions ?? []).flatMap((action, index) => ['name', 'icon', 'target'].map(field => [`actions.${index}.${field}`, action[field as 'name' | 'icon' | 'target']]))),
}));
function failure(cause: unknown) {
 if (cause instanceof ApiError && cause.status === 422) {
  const keys = Object.keys(cause.fields);
  const nested = keys.map(key => /^stages\.(\d+)\./.exec(key)).find(Boolean);
  const duration = keys.find(key => key.startsWith('durations.') && key !== 'durations.flush');
  const index = nested ? Number(nested[1]) : duration ? stages.value.findIndex(stage => stage.id === duration.slice('durations.'.length)) : -1;
  if (index >= 0 && stages.value[index] && !editing.value) edit(index);
  validation.capture(cause, key => nested && key.startsWith(`stages.${index}.`) ? '__stage_field__' : key);
  if (cause.fields.stages?.includes('catalog.validation.stageInUse')) validation.set('stages', 'catalog.validation.stageInUse');
  if (editing.value) {
   const prefix = `stages.${editing.value.index}.`;
   editorTab.value = keys.some(key => key.startsWith(`${prefix}actions.`)) ? 'actions' : keys.some(key => key.startsWith(`${prefix}fertilizer_periods`) || key === `${prefix}water_liters`) ? 'watering' : 'stage';
   stageValidation.capture(cause, key => key.startsWith(`stages.${editing.value!.index}.`) ? key.slice(`stages.${editing.value!.index}.`.length) : key === `durations.${editing.value!.stage.id}` ? 'days' : key);
  }
  const fieldMessage = Object.values(cause.fields).flat().find(code => (code.startsWith('plants.stock.') || code.startsWith('plants.fertilizerSchedule.')) && te(code));
  error.value = fieldMessage ?? 'catalog.validation.invalid';
 } else {
  const key = cause instanceof ApiError ? cause.code : '';
  error.value = /^(catalog|auth)\.(errors|validation)\.[A-Za-z]+$/.test(key) && te(key) ? key : 'auth.errors.requestFailed';
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { settings.value = null; editing.value = null; emit('accessLost', cause); }
}

function apply(result: LifecycleSettings) {
 failedSnapshot.value = null;
 automaticRelocation.value = result.automaticRelocation ?? false;
 validation.clear(); stageValidation.clear(); applyLifecycle(result.stages, result.icons); settings.value = result; stages.value = structuredClone(result.stages); durations.value = { ...result.durations };
 watering.value = { ...(result.wateringDefaults ?? { alertDays: 3, moistureThreshold: 35 }) };
 resolveDestinations();
}
async function load() {
 if (!await unsaved.confirmDiscard()) return;
 loading.value = true; error.value = ''; notice.value = false; editing.value = null;
 try {
  const result = await strainsApi.settings();
  if (active) { destinationContexts.value = new Map(); apply(result); }
 }
 catch (cause) { if (active) failure(cause); }
 finally { if (active) loading.value = false; }
}
async function save() {
 if (!settings.value?.canManage || !canSave.value || unsaved.pending.value) return;
 notice.value = false;
 validation.clear();
 if (!Number.isInteger(durations.value.flush) || durations.value.flush < 0 || durations.value.flush > 3650) validation.set('durations.flush');
 if (!Number.isInteger(watering.value.alertDays) || watering.value.alertDays < 1 || watering.value.alertDays > 3650) validation.set('watering_alert_days');
 if (!/^\d+(?:\.\d{1,2})?$/.test(String(watering.value.moistureThreshold)) || watering.value.moistureThreshold < 0 || watering.value.moistureThreshold > 100) validation.set('watering_moisture_threshold');
 if (Object.keys(validation.fields.value).length) { failedSnapshot.value = draftSnapshot.value; error.value = 'catalog.validation.invalid'; void validation.focus(); return; }
 saving.value = true; error.value = ''; failedSnapshot.value = null;
 try { const result = await strainsApi.saveSettings(settings.value.version, durations.value, watering.value, stages.value, automaticRelocation.value); if (active) { apply(result); notice.value = true; } }
 catch (cause) { if (active) { failedSnapshot.value = draftSnapshot.value; failure(cause); } }
 finally { if (active) saving.value = false; }
}
const containerOptions = new Map<string, { id: string; name: string; detail: string }>();
async function fetchContainers(search: string, page: number) { const result = await inventoryApi.list({ type: 'container', state: 'active', search, per_page: '100' }, page); for (const item of result.data) containerOptions.set(item.id, { id: item.id, name: item.name, detail: item.capacityLiters ? `${Number(item.capacityLiters)} L` : '' }); return { ...result, data: result.data.map(item => ({ id: item.id, name: item.name, detail: item.capacityLiters ? `${Number(item.capacityLiters)} L` : t('plants.containers.noCapacity'), disabled: item.unit !== 'piece' || !item.capacityLiters })) }; }
function id(prefix: string) { return `${prefix}_${crypto.randomUUID().replaceAll('-', '').slice(0, 18)}`; }
function edit(index: number) {
 editorTab.value = 'stage';
 const stage = stages.value[index]; stageValidation.clear(); error.value = ''; actionPreset.value = 'custom';
 editing.value = { index, stage: { ...structuredClone(toRaw(stage)), actions: stage.actions.map(action => ({ ...action, substrates: structuredClone(toRaw(action.substrates ?? [])) })) }, days: durations.value[stage.id] ?? '', indefinite: durations.value[stage.id] === undefined };
 stageBaseline.value = stageSnapshot();
}
function addStage() {
 const stageId = id('s'); const index = stages.value.length - 1;
 stages.value.splice(index, 0, { id: stageId, name: t('catalog.workflow.newStage'), icon: 'leaf', watering: false, maintenance: false, flush: false, actions: [{ id: id('a'), name: t('catalog.workflow.advance'), icon: 'arrow', target: 'archived', requires_harvest_weight: false }] });
 durations.value[stageId] = 7; edit(index); if (editing.value) editing.value.added = true;
}
async function cancelEdit() { if (!await unsaved.confirmDiscard(stageDirty.value)) return; if (editing.value?.added) remove(editing.value.index); editing.value = null; error.value = ''; }
function remove(index: number) {
 const replacement = stages.value[index + 1]?.id; const [removed] = stages.value.splice(index, 1);
 delete durations.value[removed.id];
 for (const stage of stages.value) for (const action of stage.actions) if (action.target === removed.id && replacement) action.target = replacement;
}
function move(index: number, offset: number) { const target = index + offset; [stages.value[index], stages.value[target]] = [stages.value[target], stages.value[index]]; }
function addAction() {
 if (!editing.value) return;
 const preset = availablePresets.value.find(value => value.id === actionPreset.value); if (!preset) return;
 const target = preset.forward || editing.value.stage.id === 'planned' ? stages.value[editing.value.index + 1]?.id : editing.value.stage.id;
 if (!target) return;
 editing.value.stage.actions.push({ substrates: [], id: id('a'), name: t(preset.id === 'custom' ? 'catalog.workflow.newAction' : preset.label), icon: preset.icon, target, requires_harvest_weight: preset.id === 'start_maturation' });
}
function applyStage() {
 const draft = editing.value; if (!draft) return;
 if (!draft.stage.watering) { draft.stage.fertilizer_periods = []; draft.stage.water_liters = null; }
 if (draft.stage.water_liters === '') draft.stage.water_liters = null;
 stageValidation.clear();
 if (draft.stage.water_liters && !validDose(draft.stage.water_liters) || !validFertilizerPeriods(draft.stage.fertilizer_periods ?? [], draft.indefinite ? null : Number(draft.days) || null)) { editorTab.value = 'watering'; error.value = 'plants.stock.invalidRecipe'; return; }
 if (!draft.indefinite && !boundary(draft.stage.id) && (!Number.isInteger(draft.days) || Number(draft.days) < 1 || Number(draft.days) > 3650)) stageValidation.set('days');
 if (draft.stage.flush && (draft.indefinite || !draft.stage.watering)) stageValidation.set('flush');
 if ((draft.stage.name?.length ?? 0) > 80 || (!draft.stage.name?.trim() && !te(`plants.phases.${draft.stage.id}`))) stageValidation.set('name');
 if (!settings.value?.icons.includes(draft.stage.icon)) stageValidation.set('icon');
 draft.stage.actions.forEach((action, index) => {
  if (action.substrates?.some(row => !validDose(row.quantity))) stageValidation.set(`actions.${index}.name`, 'plants.stock.invalidRecipe');
  if ((action.name?.length ?? 0) > 80 || (!action.name?.trim() && !te(`plants.actions.${action.id}`))) stageValidation.set(`actions.${index}.name`);
  if (!settings.value?.icons.includes(action.icon)) stageValidation.set(`actions.${index}.icon`);
  if (!stages.value.some(stage => stage.id === action.target)) stageValidation.set(`actions.${index}.target`);
 });
 if (Object.keys(stageValidation.fields.value).length) { editorTab.value = Object.keys(stageValidation.fields.value)[0]?.startsWith('actions.') ? 'actions' : 'stage'; error.value = 'catalog.validation.invalid'; void stageValidation.focus(); return; }
 stages.value[draft.index] = structuredClone(toRaw(draft.stage));
 resolveDestinations();
 if (draft.indefinite || boundary(draft.stage.id)) delete durations.value[draft.stage.id]; else durations.value[draft.stage.id] = Number(draft.days);
 editing.value = null; error.value = ''; notice.value = false;
}
const canManage = computed(() => settings.value?.canManage === true);
const canCreate = computed(() => canManage.value && !loading.value && !saving.value && !editing.value && stages.value.length < 32);
const canSave = computed(() => canManage.value && changed.value && !loading.value && !saving.value && !editing.value);
watch([draftSnapshot, editing, canSave, unsaved.pending], (_, __, onCleanup) => {
 if (!canSave.value || unsaved.pending.value || draftSnapshot.value === failedSnapshot.value) return;
 const timer = setTimeout(() => void save(), 350);
 onCleanup(() => clearTimeout(timer));
});
defineExpose({ loading, saving, canManage, canCreate, create: addStage, reload: load });
onMounted(load); onUnmounted(() => { active = false; });
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <Card class="lifecycle-settings"><template v-if="!hideHeading" #header><div class="card-heading-block"><div class="page-heading"><PageTitle icon="refresh">{{ t('catalog.globalTitle') }}</PageTitle><div class="page-actions"><ListActionButton icon="refresh" :label="t('catalog.reloadSettings')" :disabled="saving || loading" severity="secondary" @click="load" /><Button v-if="settings?.canManage" :disabled="saving || loading || !!editing || stages.length >= 32" @click="addStage" data-action-icon="plus" :aria-label="t('catalog.workflow.addStage')" :title="t('catalog.workflow.addStage')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('catalog.workflow.help') }}</div></div></template>

  <template #default><Message v-if="error && !editing" type="error" closable role="alert">{{ t(error) }} <Button v-if="canSave && failedSnapshot === draftSnapshot" text @click="save">{{ t('catalog.retrySettings') }}</Button></Message>
   <Message v-if="notice" type="success" closable role="status">{{ t('catalog.settingsSaved') }}</Message>
   <p v-if="saving" role="status">{{ t('catalog.savingSettings') }}</p>
   <p v-if="loading" role="status">{{ t('catalog.loading') }}</p>
   <template v-if="settings"><p class="record-note">{{ t('catalog.workflow.scheduleHelp') }}</p>
    <AppSwitch class="cycle-check" id="cycle-auto-relocation" v-model="automaticRelocation" :disabled="loading || saving || !settings.canManage">{{ t('catalog.workflow.automaticRelocation') }}</AppSwitch>
    <p class="record-note">{{ t('catalog.workflow.automaticRelocationHelp') }}</p>
    <div id="cycle-stages-validation" tabindex="-1" v-bind="validation.attrs('stages')"><small v-if="validation.fields.value.stages" id="cycle-stages-validation-error" class="field-error" role="alert">{{ t(validation.fields.value.stages) }}</small><ol class="cycle-stages" :inert="loading || saving || !settings.canManage"><li v-for="(stage, index) in stages" :key="stage.id" class="cycle-stage" :data-stage="stage.id" :id="`cycle-stage-${stage.id}`" tabindex="-1"><span class="cycle-symbol"><AppIcon :name="stage.icon" /></span>
      <div class="cycle-body"><small v-if="validation.fields.value[`durations.${stage.id}`]" :id="`cycle-stage-${stage.id}-error`" class="field-error" role="alert">{{ t(validation.fields.value[`durations.${stage.id}`]) }}</small><h3>{{ phaseLabel(stage.id, t, stage) }}</h3><div class="cycle-meta"><span><AppIcon name="clock" />{{ durations[stage.id] === undefined ? t('catalog.workflow.indefinite') : t('catalog.dayCount', { count: durations[stage.id] }) }}</span><span v-if="stage.watering"><AppIcon name="water" />{{ t('catalog.workflow.watering') }}</span><span v-if="stage.maintenance"><AppIcon name="scissors" />{{ t('catalog.workflow.maintenance') }}</span><span v-if="stage.flush"><AppIcon name="humidity" />{{ t('catalog.stages.flush') }}</span></div>
       <p v-if="stage.target_location" class="cycle-destination"><AppIcon :name="locationIcons[stage.target_location.type]" /><span><strong>{{ t('catalog.workflow.targetLocation') }}</strong><span>{{ destinationText(stage.target_location) }}</span></span></p>
       <ul class="cycle-actions"><li v-for="action in stage.actions" :key="action.id"><AppIcon :name="action.icon" />{{ actionLabel(action.id, t, action) }}<AppIcon name="arrow" /><span>{{ phaseLabel(action.target, t, stages.find(value => value.id === action.target)) }}</span><AppIcon v-if="action.requires_harvest_weight" name="box" :title="t('catalog.workflow.weight')" /></li></ul></div>
      <div class="cycle-tools"><ListActionButton v-if="index > 1 && !boundary(stage.id)" icon="chevron" class="cycle-up" :label="t('catalog.workflow.moveUp')" @click="move(index, -1)" /><ListActionButton v-if="index < stages.length - 2 && !boundary(stage.id)" icon="chevron" :label="t('catalog.workflow.moveDown')" @click="move(index, 1)" /><ListActionButton v-if="!boundary(stage.id)" icon="trash" severity="danger" :label="t('catalog.workflow.removeStage')" @click="remove(index)" /><ListActionButton icon="edit" :label="t('catalog.workflow.editStage')" @click="edit(index)" /></div></li></ol></div>
    <form novalidate class="cycle-defaults" :inert="saving || loading || !settings.canManage" @submit.prevent="save"><h3>{{ t('catalog.workflow.defaults') }}</h3>
     <div class="cycle-fields"><DurationInput id="cycle-flush" :validation-error="validation.fields.value['durations.flush']" v-model="durations.flush" :label="t('catalog.stages.flush')" :minimum="0" /><div class="field"><label for="cycle-watering-days">{{ t('catalog.wateringDays') }}</label><input id="cycle-watering-days" v-bind="validation.attrs('watering_alert_days')" v-model.number="watering.alertDays" type="number" min="1" max="3650" /><small v-if="validation.fields.value.watering_alert_days" id="cycle-watering-days-error" class="field-error" role="alert">{{ t(validation.fields.value.watering_alert_days) }}</small></div><div class="field"><label for="cycle-moisture">{{ t('catalog.wateringMoisture') }}</label><input id="cycle-moisture" v-bind="validation.attrs('watering_moisture_threshold')" v-model.number="watering.moistureThreshold" type="number" min="0" max="100" step="0.01" /><small v-if="validation.fields.value.watering_moisture_threshold" id="cycle-moisture-error" class="field-error" role="alert">{{ t(validation.fields.value.watering_moisture_threshold) }}</small></div></div></form></template>
   <FormDialog v-if="editing" :visible="true" size="standard" :icon="editing.stage.icon" :title="t('catalog.workflow.editStage')" :close-label="t('catalog.cancel')" :error="error ? t(error) : ''" @update:visible="!$event && cancelEdit()"><form id="cycle-stage-form" novalidate class="dialog-form stage-editor-form" @submit.prevent="applyStage"><AppTabs type="bar" class="button-tabs stage-editor-tabs" :value="editorTab" @update:value="editorTab = String($event)" animated><NTabPane name="stage" display-directive="show:lazy"><template #tab><AppIcon :name="editing.stage.icon" />{{ t('catalog.workflow.stageTab') }}</template><div class="stage-editor-section"><div class="cycle-fields"><div class="field"><label for="stage-name">{{ t('catalog.workflow.name') }}</label><input id="stage-name" v-bind="stageValidation.attrs('name')" v-model="editing.stage.name" :placeholder="phaseLabel(editing.stage.id, t)" maxlength="80" /><small v-if="stageValidation.fields.value.name" id="stage-name-error" class="field-error" role="alert">{{ t(stageValidation.fields.value.name) }}</small></div><div class="field"><label for="stage-icon">{{ t('catalog.workflow.icon') }}</label><div class="icon-select"><AppIcon :name="editing.stage.icon" /><select id="stage-icon" v-bind="stageValidation.attrs('icon')" v-model="editing.stage.icon" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }"><option v-for="icon in settings?.icons" :key="icon" :value="icon">{{ t(`catalog.workflow.icons.${icon}`) }}</option></select></div><small v-if="stageValidation.fields.value.icon" id="stage-icon-error" class="field-error" role="alert">{{ t(stageValidation.fields.value.icon) }}</small></div></div>
     <template v-if="!boundary(editing.stage.id)"><div class="stage-duration-row"><DurationInput v-if="!editing.indefinite" :key="editing.stage.id" id="stage-duration" :validation-error="stageValidation.fields.value.days" v-model="editing.days" :label="t('catalog.workflow.duration')" :minimum="1" />
       <AppSwitch class="cycle-check" v-model="editing.indefinite">{{ t('catalog.workflow.indefinite') }}</AppSwitch></div>
      <div class="cycle-checks"><AppSwitch class="cycle-check" v-model="editing.stage.watering">{{ t('catalog.workflow.watering') }}</AppSwitch><AppSwitch class="cycle-check" v-model="editing.stage.maintenance">{{ t('catalog.workflow.maintenance') }}</AppSwitch><AppSwitch class="cycle-check" id="stage-flush" v-bind="stageValidation.attrs('flush')" v-model="editing.stage.flush">{{ t('catalog.workflow.flush') }}</AppSwitch><small v-if="stageValidation.fields.value.flush" id="stage-flush-error" class="field-error" role="alert">{{ t(stageValidation.fields.value.flush) }}</small></div></template>
     <LifecycleLocationSelect :key="editing.stage.id" id="stage-target-location" v-model="editing.stage.target_location" :invalid="!!stageValidation.fields.value.target_location" :described-by="stageValidation.fields.value.target_location ? 'stage-target-location-error' : undefined" @access-lost="failure" />
     <small v-if="stageValidation.fields.value.target_location" id="stage-target-location-error" class="field-error" role="alert">{{ t(stageValidation.fields.value.target_location) }}</small></div></NTabPane><NTabPane v-if="editing.stage.watering" name="watering" display-directive="show:lazy"><template #tab><AppIcon name="water" />{{ t('catalog.workflow.watering') }}</template><div class="stage-editor-section"><StageFertilizerRecipe v-model="editing.stage" :duration-days="editing.indefinite ? null : Number(editing.days) || null" :flush-days="Number(durations.flush ?? 0)" @failure="failure" /></div></NTabPane><NTabPane v-if="editing.stage.id !== 'archived'" name="actions" display-directive="show:lazy"><template #tab><AppIcon name="refresh" />{{ t('catalog.workflow.actionsTab') }}<span class="stage-tab-count">{{ editing.stage.actions.length }}</span></template><div class="stage-editor-section"><div v-if="editing.stage.id !== 'archived'" class="cycle-actions-heading"><div class="cycle-action-add"><div class="field"><label for="action-preset">{{ t('catalog.workflow.actionPreset') }}</label><select id="action-preset" v-model="actionPreset" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }"><option v-for="preset in availablePresets" :key="preset.id" :value="preset.id">{{ t(preset.label) }}</option></select></div><Button attr-type="button" type="default" :disabled="editing.stage.actions.length >= 12" @click="addAction"><template #icon><AppIcon name="plus" /></template><span>{{ t('catalog.workflow.addAction') }}</span></Button></div></div>
     <p v-if="editing.stage.id !== 'archived'" class="record-note">{{ t('catalog.workflow.actionHelp') }}</p>
     <fieldset v-for="(action, index) in editing.stage.actions" :key="action.id" class="cycle-action-editor"><legend><AppIcon :name="action.icon" />{{ actionLabel(action.id, t, action) }}</legend><div class="cycle-fields"><div class="field"><label :for="`action-name-${index}`">{{ t('catalog.workflow.name') }}</label><input :id="`action-name-${index}`" v-bind="stageValidation.attrs(`actions.${index}.name`)" v-model="action.name" :placeholder="actionLabel(action.id, t)" maxlength="80" /><small v-if="stageValidation.fields.value[`actions.${index}.name`]" :id="`action-name-${index}-error`" class="field-error" role="alert">{{ t(stageValidation.fields.value[`actions.${index}.name`]) }}</small></div><div class="field"><label :for="`action-icon-${index}`">{{ t('catalog.workflow.icon') }}</label><div class="icon-select"><AppIcon :name="action.icon" /><select :id="`action-icon-${index}`" v-bind="stageValidation.attrs(`actions.${index}.icon`)" v-model="action.icon" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }"><option v-for="icon in settings?.icons" :key="icon" :value="icon">{{ t(`catalog.workflow.icons.${icon}`) }}</option></select></div><small v-if="stageValidation.fields.value[`actions.${index}.icon`]" :id="`action-icon-${index}-error`" class="field-error" role="alert">{{ t(stageValidation.fields.value[`actions.${index}.icon`]) }}</small></div><div class="field action-target-field"><label :for="`action-target-${index}`">{{ t('catalog.workflow.target') }}</label><select :id="`action-target-${index}`" v-bind="stageValidation.attrs(`actions.${index}.target`)" v-model="action.target" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }"><option :value="editing.stage.id">{{ t('catalog.workflow.stay') }}</option><option v-for="target in stages.filter(stage => stage.id !== editing?.stage.id)" :key="target.id" :value="target.id">{{ phaseLabel(target.id, t, target) }}</option></select><small v-if="stageValidation.fields.value[`actions.${index}.target`]" :id="`action-target-${index}-error`" class="field-error" role="alert">{{ t(stageValidation.fields.value[`actions.${index}.target`]) }}</small></div><ReferenceSelect class="action-container-field" :label="t('plants.containers.default')" :model-value="action.container_inventory_item_id ?? ''" :selected="containerOptions.get(action.container_inventory_item_id ?? '')" :fetch-page="fetchContainers" @update:model-value="action.container_inventory_item_id = $event || null" /></div><InventoryRecipeEditor :model-value="action.substrates ?? []" @update:model-value="action.substrates = $event" kind="substrate" /><div class="cycle-action-footer"><AppSwitch class="cycle-check" v-model="action.requires_harvest_weight">{{ t('catalog.workflow.weight') }}</AppSwitch><ListActionButton type="button" icon="trash" severity="danger" :label="t('catalog.workflow.removeAction')" @click="editing.stage.actions.splice(index, 1)" /></div></fieldset></div></NTabPane></AppTabs></form>
    <template #footer><div class="dialog-actions"><Button type="default" :aria-label="t('catalog.cancel')" @click="cancelEdit"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button attr-type="submit" form="cycle-stage-form" :aria-label="t('catalog.workflow.apply')" type="primary"><template #icon><AppIcon name="check" /></template><span>{{ t('actions.apply') }}</span></Button></div></template></FormDialog></template></Card></template>
<style scoped>
.stage-editor-section { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.stage-editor-tabs :deep(.n-tabs-pane-wrapper) { padding: var(--ui-content-gap) 0 0; }
.stage-editor-tabs :deep(.n-tabs-tab) { display: inline-flex; align-items: center; gap: var(--ui-icon-gap); font-size: .78rem; }
.stage-editor-tabs :deep(.n-tabs-tab svg) { width: 1rem; height: 1rem; flex-shrink: 0; }
.stage-tab-count { display: inline-grid; place-items: center; min-width: 1.2rem; padding: .05rem var(--space-1); border-radius: 1rem; background: color-mix(in srgb, currentColor 12%, transparent); font-size: .65rem; }
.stage-duration-row { display: flex; flex-wrap: wrap; align-items: end; gap: var(--ui-content-gap); }
.stage-duration-row > .duration-input { flex: 1 1 14rem; }
.stage-duration-row > .cycle-check { padding-bottom: var(--space-3); }
.stage-editor-section > .cycle-actions-heading { justify-content: flex-start; }
.stage-editor-section .cycle-action-add { width: 100%; }

h2,h3,h4 { margin: 0; letter-spacing: -.025em; } h3 { font-size: .95rem; } .cycle-stages { list-style: none; margin: var(--ui-content-gap) 0; padding: 0; display: grid; gap: var(--space-3); }
.cycle-stage { display: flex; gap: var(--space-3); align-items: flex-start; padding: var(--space-4); border: 1px solid var(--theme-border, #e6ebdf); background: var(--theme-surface, #fff); border-radius: .85rem; }
.cycle-symbol { display: grid; place-items: center; flex-shrink: 0; width: 2.5rem; height: 2.5rem; border-radius: .75rem; background: var(--theme-subtle, #f5f7f1); color: var(--theme-success, #577740); font-size: 1.3rem; }.cycle-body { flex: 1; min-width: 0; overflow-wrap: anywhere; }.cycle-meta { display: flex; flex-wrap: wrap; gap: var(--space-2) var(--ui-grid-gap); font-size: .75rem; color: var(--theme-muted, #66765b); margin: var(--space-1-5) 0; }.cycle-meta span { display: inline-flex; gap: var(--space-1); align-items: center; }
.cycle-destination { display: flex; align-items: flex-start; gap: var(--ui-icon-gap); margin: var(--space-2) 0; font-size: .75rem; color: var(--theme-muted); }
.cycle-destination > svg { flex: 0 0 1rem; width: 1rem; height: 1rem; }
.cycle-destination > span { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-2); min-width: 0; }
.cycle-destination strong { color: var(--theme-text); }
.cycle-actions { list-style: none; padding: 0; display: grid; gap: var(--space-1); font-size: .8rem; }.cycle-actions li { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-1-5); }.cycle-actions span { color: var(--theme-muted, #66765b); }.cycle-tools { display: flex; gap: var(--space-1); flex-wrap: wrap; justify-content: flex-end; }.cycle-up { transform: rotate(180deg); }
.cycle-defaults { padding: var(--space-4); border: 1px solid var(--theme-border, #e6ebdf); border-radius: .85rem; }.cycle-defaults h3 { margin-bottom: var(--space-3); }.cycle-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); align-items: end; gap: var(--space-3); }.field { margin: 0; display: grid; align-content: start; gap: var(--space-1); min-width: 0; }
input:not([type=checkbox]), select { min-width: 0; width: 100%; padding: var(--space-3); font: inherit; color: inherit; background: var(--theme-surface, #fff); border: 1px solid var(--theme-border, #e6ebdf); border-radius: .5rem; }.cycle-check { display: inline-flex; align-items: center; gap: var(--space-2); font-size: .85rem; }.cycle-actions-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2) var(--space-3); }.cycle-actions-heading h4 { flex: 1; min-width: 0; align-self: flex-end; margin: 0; padding-bottom: var(--space-3); }.cycle-actions-heading > .n-button { flex: 0 0 auto; width: auto; white-space: nowrap; }
.cycle-action-add { display: flex; align-items: flex-end; flex-wrap: wrap; gap: var(--space-2); min-width: 0; }.cycle-action-add > .field { flex: 1 1 12rem; margin: 0; min-width: 0; }.cycle-action-add > .n-button { flex: 0 0 auto; }.cycle-fields :is(input, select), .cycle-action-add select { height: 2.5rem; }.cycle-action-add > .n-button { min-height: 2.5rem; }.cycle-actions li > svg, .cycle-meta svg { flex-shrink: 0; width: 1em; height: 1em; }.cycle-checks { display: flex; flex-wrap: wrap; gap: var(--ui-grid-gap); }.cycle-action-editor { min-width: 0; padding: var(--space-3); border: 1px solid var(--theme-border, #e6ebdf); border-radius: .75rem; }.cycle-action-editor legend { display: inline-flex; align-items: center; gap: var(--space-1-5); padding: 0 var(--space-1); font-size: .85rem; }.cycle-action-editor legend > svg { flex-shrink: 0; width: 1rem; height: 1rem; }.cycle-action-footer { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); margin-top: var(--space-3); }
@media(max-width:700px) { .cycle-stage { padding: var(--space-3); gap: var(--space-2); flex-wrap: wrap; }.cycle-body { flex-basis: calc(100% - 3.1rem); }.cycle-tools { width: 100%; }.cycle-fields { grid-template-columns: 1fr; } }
.cycle-actions-heading { display: block; }.cycle-action-add { width: 100%; align-items: end; gap: var(--space-3); }.cycle-action-editor { padding: 0; border: 0; border-radius: 0; margin: 0; display: grid; gap: var(--ui-grid-gap); }.cycle-action-editor + .cycle-action-editor { padding-top: var(--space-4); border-top: 1px solid var(--theme-border); }.cycle-action-editor legend { padding: 0 0 var(--space-3); font-weight: 600; font-size: .9rem; }.cycle-action-editor .cycle-fields { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; }.action-target-field { grid-column: 1 / -1; }.cycle-action-footer { margin-top: 0; flex-wrap: wrap; }.cycle-action-editor .cycle-check { flex: 1; min-width: min(100%, 15rem); }.cycle-action-editor :deep(.stage-fertilizers) { padding-top: var(--space-2); }@media(max-width:500px) { .cycle-action-editor .cycle-fields { grid-template-columns: 1fr; } }
</style>
