<script setup lang="ts">
import { catalogTabs, hardwareTabs } from '../tab-paths';
import RecordStatusTag from './RecordStatusTag.vue';
import { assetCategoryIcon } from '../lib/asset-category';
import { useFormTabs } from '../lib/form-tabs';
import AppTabs from '../components/AppTabs.vue';
import { NTabPane } from 'naive-ui';
import CollectionViewToggle from './CollectionViewToggle.vue';
import CollectionLayout from './CollectionLayout.vue';
import RecordBatchAction from './RecordBatchAction.vue';
import CollectionTable from './CollectionTable.vue';
import CollectionIdentity from './CollectionIdentity.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { useFieldErrors } from '../lib/field-errors';

import { useCollectionPageSize } from '../lib/collection-page-size';
import CollectionPagination from './CollectionPagination.vue';
import ResetFilters from './ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../lib/collection-sorting';
import SortSelect from './SortSelect.vue';
import { measurementNatureIcon } from '../lib/measurement-nature-icon';
import PageTitle from './PageTitle.vue';
import DeviceAssignment from './DeviceAssignment.vue';
import { queryField, useCollectionQuery } from '../lib/collection-query';
import AppIcon from './AppIcon.vue';
import ResourceCard from './ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import ListActionButton from './ListActionButton.vue';
import FormDialog from './FormDialog.vue';
import { useId, computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import NotesEditor from './NotesEditor.vue';
import RichNotes from './RichNotes.vue';
import AppSwitch from './AppSwitch.vue';
import Message from './AppMessage.vue';
import SensorFormSelectors from './SensorFormSelectors.vue';
import { ApiError } from '../api/auth';
import type { Page } from '../api/types';
import { measurementTypesApi, measurementNatures, sensorsApi, type MeasurementNature, type MeasurementType, type MeasurementTypeSummary, type Sensor, type SensorInput, type SensorDevice } from '../api/sensors';
const channelPageSize = useCollectionPageSize('channels');
const typePageSize = useCollectionPageSize('measurement-types');
const typeSort = useCollectionSort('measurement-types', sortOptions.measurementTypes);
const channelSort = useCollectionSort('channels', sortOptions.channels);

const props = defineProps<{ selectionTarget?: HTMLElement | null; embedded?: boolean; hideActions?: boolean; canManage: boolean; device?: SensorDevice; kind?: 'sensors' | 'types' }>();
const emit = defineEmits<{ close: []; changed: []; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const view = computed(() => props.kind ?? 'sensors');
const pageSize = computed({ get: () => view.value === 'types' ? typePageSize.value : channelPageSize.value, set: (value: number) => { if (view.value === 'types') typePageSize.value = value; else channelPageSize.value = value; } });
const sort = computed<string>({ get: () => view.value === 'types' ? typeSort.value : channelSort.value, set: value => { if (view.value === 'types') typeSort.value = value as typeof typeSort.value; else channelSort.value = value as typeof channelSort.value; } });
const sensors = ref<Sensor[]>([]); const types = ref<MeasurementType[]>([]);
const search = ref(''); const enabledFilter = ref('');
const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const saving = ref(false); const error = ref(''); const success = ref('');
const formOpen = ref(false);
const editingSensor = ref<Sensor | null>(null); const editingType = ref<MeasurementType | null>(null);
const selectedDevice = ref<SensorDevice | null>(null); const selectedType = ref<MeasurementTypeSummary | null>(null);
const sensorForm = reactive({ name: '', channel: '', enabled: true, interval: '', description: '' });
const typeForm = reactive({ name: '', code: '', unit: '', decimals: '2', description: '', nature: 'other' as MeasurementNature });
const semanticsLocked = computed(() => (editingType.value?.sensorsCount ?? 0) > 0);
let sequence = 0; let active = true;
const baseline = ref('');
function snapshot() { return view.value === 'types' ? JSON.stringify(typeForm) : JSON.stringify([sensorForm, selectedDevice.value?.id, selectedType.value?.id]); }
const dirty = computed(() => saving.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, saving, { onNavigate: () => { formOpen.value = false; } });
const editorTabs = useFormTabs(formOpen, {"is_enabled": "measurement", "expected_interval_seconds": "measurement", "nature": "measurement", "unit": "measurement", "decimal_places": "measurement", "description": "notes"});
const editorTab = editorTabs.tab;
const sensorValidation = useFieldErrors(() => Object.fromEntries([
 ['name', 'sensor-name', 'nameInvalid'], ['channel_key', 'sensor-channel', 'channelInvalid'], ['device_id', 'sensor-device', 'deviceInvalid'], ['measurement_type_id', 'sensor-type', 'typeInvalid'],
 ['is_enabled', 'sensor-enabled', 'enabledInvalid'], ['expected_interval_seconds', 'sensor-interval', 'intervalInvalid'], ['description', 'sensor-description', 'descriptionInvalid'],
].map(([field, id, message]) => [field, { id, message: `sensors.validation.${message}` }])), () => ({ ...sensorForm, channel_key: sensorForm.channel, device_id: selectedDevice.value?.id, measurement_type_id: selectedType.value?.id, is_enabled: sensorForm.enabled, expected_interval_seconds: sensorForm.interval }), editorTabs.reveal);
const typeValidation = useFieldErrors(() => Object.fromEntries([
 ['name', 'measurement-name', 'nameInvalid'], ['code', 'measurement-code', 'codeInvalid'], ['nature', 'measurement-nature', 'natureInvalid'], ['unit', 'measurement-unit', 'unitInvalid'], ['decimal_places', 'measurement-decimals', 'decimalsInvalid'], ['description', 'measurement-description', 'descriptionInvalid'],
].map(([field, id, message]) => [field, { id, message: `measurementTypes.validation.${message}` }])), () => ({ ...typeForm, unit: JSON.stringify([typeForm.unit, typeForm.nature]), decimal_places: typeForm.decimals }), editorTabs.reveal);
const validation = computed(() => view.value === 'types' ? typeValidation : sensorValidation);
const fields = computed(() => validation.value.fields.value);

async function confirmLeave() { if (!await unsaved.confirmDiscard()) return false; formOpen.value = false; error.value = ''; validation.value.clear(); return true; }
async function leave() { if (await confirmLeave()) emit('close'); }
function key(value: string): string { return /^(auth|sensors|measurementTypes)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function showError(cause: unknown) {
  if (!active) return;
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError) {
    if (formOpen.value) {
      validation.value.capture(cause);
      const field = ({ 'sensors.errors.channelTaken': 'channel_key', 'sensors.errors.deviceRetired': 'device_id', 'measurementTypes.errors.codeTaken': 'code' } as Record<string, string>)[cause.code];
      if (field) { validation.value.set(field, cause.code); void validation.value.focus(); }
      if (cause.fields.unit?.includes('measurementTypes.validation.moistureInvalid')) { typeValidation.set(semanticsLocked.value ? 'nature' : 'unit', 'measurementTypes.validation.moistureInvalid'); void typeValidation.focus(); }
    }
    if (cause.status === 422) error.value = 'auth.errors.validationFailed';
    if (cause.status === 401 || cause.status === 403) { sensors.value = []; types.value = []; formOpen.value = false; emit('accessLost', cause); }
  }
}
function pagination(meta: Page<Sensor>['meta']) { page.value = meta.current_page; lastPage.value = meta.last_page; total.value = meta.total; }
async function load(target = 1) {
  const current = ++sequence; loading.value = true; error.value = '';
  try {
    if (view.value === 'sensors') {
      let result = await sensorsApi.list(search.value.trim(), enabledFilter.value, target, props.device?.id, sort.value, pageSize.value);
      if (current !== sequence) return;
      if (target > result.meta.last_page && result.meta.total > 0) result = await sensorsApi.list(search.value.trim(), enabledFilter.value, result.meta.last_page, props.device?.id, sort.value, pageSize.value);
      if (current !== sequence) return;
      sensors.value = result.data; pagination(result.meta);
    } else {
      let result = await measurementTypesApi.list(search.value.trim(), target, sort.value, pageSize.value);
      if (current !== sequence) return;
      if (target > result.meta.last_page && result.meta.total > 0) result = await measurementTypesApi.list(search.value.trim(), result.meta.last_page, sort.value, pageSize.value);
      if (current !== sequence) return;
      types.value = result.data; pagination(result.meta);
    }
  } catch (cause) { if (current === sequence) showError(cause); }
  finally { if (current === sequence) loading.value = false; }
}
async function openSensor(entry: Sensor | null = null) {
  if (!await unsaved.confirmDiscard()) return;
  editingSensor.value = entry; selectedDevice.value = entry?.device ?? props.device ?? null; selectedType.value = entry?.measurementType ?? null;
  Object.assign(sensorForm, { name: entry?.name ?? '', channel: entry?.channelKey ?? '', enabled: entry?.isEnabled ?? true, interval: entry?.expectedIntervalSeconds == null ? '' : String(entry.expectedIntervalSeconds), description: entry?.description ?? '' });
  validation.value.clear(); error.value = success.value = ''; baseline.value = snapshot(); formOpen.value = true;
}
async function openType(entry: MeasurementType | null = null) {
  if (!await unsaved.confirmDiscard()) return;
  editingType.value = entry;
  Object.assign(typeForm, { name: entry?.name ?? '', code: entry?.code ?? '', unit: entry?.unit ?? '', decimals: String(entry?.decimalPlaces ?? 2), description: entry?.description ?? '', nature: entry?.nature ?? 'other' });
  validation.value.clear(); error.value = success.value = ''; baseline.value = snapshot(); formOpen.value = true;
}
async function saveSensor() {
  if (saving.value) return;
  validation.value.clear(); error.value = success.value = '';
  if (!sensorForm.name.trim() || sensorForm.name.length > 120) sensorValidation.set('name');
  if (!editingSensor.value && (!/^[a-z][a-z0-9_]*$/.test(sensorForm.channel.trim().toLowerCase()) || sensorForm.channel.trim().length > 64)) sensorValidation.set('channel_key');
  if (sensorForm.description.length > 2000) sensorValidation.set('description');
  if (!selectedDevice.value) sensorValidation.set('device_id');
  if (!selectedType.value) fields.value.measurement_type_id = 'sensors.validation.typeInvalid';
  const interval = sensorForm.interval.trim() === '' ? null : Number(sensorForm.interval);
  if (interval !== null && (!Number.isInteger(interval) || interval < 1 || interval > 86400)) fields.value.expected_interval_seconds = 'sensors.validation.intervalInvalid';
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.value.focus(); return; }
  const input: SensorInput = { name: sensorForm.name, is_enabled: sensorForm.enabled, expected_interval_seconds: interval, description: sensorForm.description.trim() || null };
  if (!editingSensor.value) { input.device_id = selectedDevice.value!.id; input.measurement_type_id = selectedType.value!.id; input.channel_key = sensorForm.channel.trim().toLowerCase(); }
  saving.value = true;
  try {
    if (editingSensor.value) await sensorsApi.update(editingSensor.value.id, input); else await sensorsApi.create(input);
    if (!active) return; formOpen.value = false; emit('changed'); success.value = editingSensor.value ? 'sensors.updated' : 'sensors.created'; await load(editingSensor.value ? page.value : 1);
  } catch (cause) { showError(cause); } finally { saving.value = false; }
}
async function toggleEnabled(entry: Sensor) {
 if (!props.canManage || saving.value || loading.value) return;
 saving.value = true; error.value = success.value = '';
 try {
  await sensorsApi.setEnabled(entry.id, !entry.isEnabled);
  if (!active) return;
  emit('changed'); success.value = 'sensors.updated'; await load(page.value);
 } catch (cause) { if (active) showError(cause); }
 finally { saving.value = false; }
}
async function saveType() {
  if (saving.value) return;
  validation.value.clear(); error.value = success.value = '';
  const decimals = Number(typeForm.decimals);
  if (!typeForm.name.trim() || typeForm.name.length > 120) typeValidation.set('name');
  if (!/^[a-z][a-z0-9_]*$/.test(typeForm.code.trim().toLowerCase()) || typeForm.code.trim().length > 50) typeValidation.set('code');
  if (!measurementNatures.includes(typeForm.nature)) typeValidation.set('nature');
  if (typeForm.unit.length > 24) typeValidation.set('unit');
  if (typeForm.description.length > 2000) typeValidation.set('description');
  if (typeForm.decimals.trim() === '' || !Number.isInteger(decimals) || decimals < 0 || decimals > 6) typeValidation.set('decimal_places');
  if (typeForm.nature === 'substrate_moisture' && typeForm.unit.trim() !== '%') typeValidation.set(semanticsLocked.value ? 'nature' : 'unit', 'measurementTypes.validation.moistureInvalid');
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void typeValidation.focus(); return; }
  const input = { nature: typeForm.nature, name: typeForm.name, code: typeForm.code.trim().toLowerCase(), unit: typeForm.unit.trim() || null, decimal_places: decimals, description: typeForm.description.trim() || null };
  saving.value = true;
  try {
    if (editingType.value) await measurementTypesApi.update(editingType.value.id, input); else await measurementTypesApi.create(input);
    if (!active) return; formOpen.value = false; success.value = editingType.value ? 'measurementTypes.updated' : 'measurementTypes.created'; await load(editingType.value ? page.value : 1);
  } catch (cause) { showError(cause); } finally { saving.value = false; }
}
onMounted(() => load(page.value)); onUnmounted(() => { active = false; sequence++; });
const dialogId = useId();
const collectionQuery = useCollectionQuery({ paths: [...Object.values(hardwareTabs), ...Object.values(catalogTabs)], prefix: props.kind === 'types' ? 'measurementTypes.' : props.device ? 'deviceChannels.' : 'channels.', fields: { q: queryField.text(search), enabled: queryField.choice(enabledFilter, ['', '1', '0']), sort: queryField.choice(sort, props.kind === 'types' ? sortOptions.measurementTypes : sortOptions.channels) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;
defineExpose({ reload: () => load(page.value), create: () => view.value === 'types' ? openType() : openSensor(), loading, saving, confirmLeave });


const selectionHeader = ref<HTMLElement | null>(null);
</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <Card class="sensors-card"><template v-if="!hideActions" #header><div class="card-heading-block"><div class="page-heading"><div v-if="!embedded" class="sensors-heading"><PageTitle :icon="view === 'types' ? 'chart' : 'sensor'">{{ t(view === 'types' ? 'measurementTypes.title' : 'sensors.title') }}</PageTitle></div><div ref="selectionHeader" class="page-actions"><Button v-if="!embedded" :aria-label="t('sensors.back')" type="default" :disabled="saving" @click="leave" :title="t('sensors.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="loading" :disabled="saving" @click="load(page)" /><CollectionViewToggle :preference-key="view === 'types' ? 'measurement-types' : 'channels'" :disabled="saving || loading" /><Button v-if="canManage" attr-type="button" :disabled="saving || (view === 'sensors' && device?.status === 'retired')" @click="view === 'sensors' ? openSensor() : openType()" data-action-icon="plus" :aria-label="t(view === 'sensors' ? 'sensors.add' : 'measurementTypes.add')" :title="t(view === 'sensors' ? 'sensors.add' : 'measurementTypes.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t(view === 'types' ? 'measurementTypes.description' : 'sensors.description') }}</div></div></template>

    <template #default><p v-if="!canManage">{{ t('sensors.readOnly') }}</p>
      <Message v-if="error && !(formOpen)" closable type="error" role="alert">{{ t(error) }}</Message>
      <Message v-if="success" closable type="success" role="status">{{ t(success) }}</Message>

   <form class="sensor-filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="filter-field"><label :for="device ? `${device.id}-sensors-search` : 'sensors-search'">{{ t('sensors.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="saving" :input-props="{ 'id': device ? `${device.id}-sensors-search` : 'sensors-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="view === 'types' ? sortOptions.measurementTypes : sortOptions.channels" /></div>
    <div class="filter-secondary"><div class="filter-field" v-if="view === 'sensors'"><label :for="device ? `${device.id}-sensors-enabled-filter` : 'sensors-enabled-filter'">{{ t('sensors.enabledState') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :id="device ? `${device.id}-sensors-enabled-filter` : 'sensors-enabled-filter'" v-model="enabledFilter" :disabled="saving"><option value="">{{ t('sensors.allStates') }}</option><option value="1">{{ t('sensors.enabled') }}</option><option value="0">{{ t('sensors.disabled') }}</option></select></div></div>
    <ResetFilters @reset="search = ''; enabledFilter = ''; sort = 'name_asc'" /></form>
      <FormDialog :icon="measurementNatureIcon(selectedType?.nature)" v-if="formOpen && view === 'sensors' && canManage" :visible="true" :title="t(editingSensor ? 'sensors.editTitle' : 'sensors.createTitle')" :close-label="t('sensors.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && confirmLeave()"><form :id="`${dialogId}-0`" class="dialog-form sensor-form" @submit.prevent="saveSensor" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><Message v-if="editingSensor" closable type="info">{{ t('sensors.identityHelp') }}</Message>
        <div class="sensor-form-grid"><div class="field"><label for="sensor-name">{{ t('sensors.name') }}</label><InputText placeholder="" v-bind="sensorValidation.attrs('name')" v-model:value="sensorForm.name" maxlength="120" :disabled="saving" :status="!!fields.name ? 'error' : undefined" :input-props="{ 'id': 'sensor-name' }"></InputText><small v-if="fields.name" id="sensor-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>
          <div class="field"><label for="sensor-channel">{{ t('sensors.channel') }}</label><InputText v-bind="sensorValidation.attrs('channel_key')" v-model:value="sensorForm.channel" maxlength="64" :placeholder="t('sensors.channelPlaceholder')" :disabled="saving || !!editingSensor" :status="!!fields.channel_key ? 'error' : undefined" :input-props="{ 'id': 'sensor-channel', 'aria-describedby': fields.channel_key ? 'sensor-channel-help sensor-channel-error' : 'sensor-channel-help' }"></InputText><small id="sensor-channel-help">{{ t('sensors.channelHelp') }}</small><small v-if="fields.channel_key" id="sensor-channel-error" role="alert" class="field-error">{{ t(fields.channel_key) }}</small></div>
          <SensorFormSelectors v-model:device="selectedDevice" v-model:type="selectedType" :disabled="saving" :lock-device="!!editingSensor || !!device" :lock-type="!!editingSensor" :errors="fields" @access-lost="emit('accessLost', $event)" />
          </div></div></NTabPane><NTabPane name="measurement" display-directive="show"><template #tab><AppIcon name="sensor" />{{ t('editorTabs.measurement') }}</template><div class="dialog-form"><div class="sensor-form-grid"><div class="field"><AppSwitch id="sensor-enabled" v-model="sensorForm.enabled" v-bind="sensorValidation.attrs('is_enabled')" :disabled="saving">{{ t('sensors.enabled') }}</AppSwitch><small v-if="fields.is_enabled" id="sensor-enabled-error" role="alert" class="field-error">{{ t(fields.is_enabled) }}</small></div>
          <div class="field"><label for="sensor-interval">{{ t('sensors.interval') }}</label><InputText placeholder="" v-bind="sensorValidation.attrs('expected_interval_seconds')" v-model:value="sensorForm.interval" min="1" max="86400" step="1" :disabled="saving" :status="!!fields.expected_interval_seconds ? 'error' : undefined" :input-props="{ 'id': 'sensor-interval', type: 'number', 'aria-describedby': fields.expected_interval_seconds ? 'sensor-interval-help sensor-interval-error' : 'sensor-interval-help' }"></InputText><small id="sensor-interval-help">{{ t('sensors.intervalHelp') }}</small><small v-if="fields.expected_interval_seconds" id="sensor-interval-error" role="alert" class="field-error">{{ t(fields.expected_interval_seconds) }}</small></div></div>
        </div></NTabPane><NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="file" />{{ t('editorTabs.notes') }}</template><div class="dialog-form"><div class="field dialog-wide"><label for="sensor-description">{{ t('sensors.notes') }}</label><NotesEditor id="sensor-description" :described-by="fields.description ? 'sensor-description-error' : undefined" v-model="sensorForm.description" :label="t('sensors.notes')" :disabled="saving" :invalid="!!fields.description" /><small v-if="fields.description" id="sensor-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div></div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('sensors.cancel')" type="default" :disabled="saving" @click="confirmLeave"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editingSensor ? t('sensors.save') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editingSensor ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
      <FormDialog :icon="measurementNatureIcon(typeForm.nature)" v-if="formOpen && view === 'types' && canManage" :visible="true" :title="t(editingType ? 'measurementTypes.editTitle' : 'measurementTypes.createTitle')" :close-label="t('sensors.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && confirmLeave()"><form :id="`${dialogId}-1`" class="dialog-form sensor-form" @submit.prevent="saveType" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><Message v-if="semanticsLocked" closable type="info">{{ t('measurementTypes.inUseHelp') }}</Message>
        <div class="sensor-form-grid"><div class="field"><label for="measurement-name">{{ t('measurementTypes.name') }}</label><InputText placeholder="" v-bind="typeValidation.attrs('name')" v-model:value="typeForm.name" maxlength="120" :disabled="saving" :status="!!fields.name ? 'error' : undefined" :input-props="{ 'id': 'measurement-name' }"></InputText><small v-if="fields.name" id="measurement-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>
          <div class="field"><label for="measurement-code">{{ t('measurementTypes.code') }}</label><InputText placeholder="" v-bind="typeValidation.attrs('code')" v-model:value="typeForm.code" maxlength="50" :disabled="saving || semanticsLocked" :status="!!fields.code ? 'error' : undefined" :input-props="{ 'id': 'measurement-code', 'aria-describedby': fields.code ? 'measurement-code-help measurement-code-error' : 'measurement-code-help' }"></InputText><small id="measurement-code-help">{{ t('measurementTypes.codeHelp') }}</small><small v-if="fields.code" id="measurement-code-error" role="alert" class="field-error">{{ t(fields.code) }}</small></div>
          </div></div></NTabPane><NTabPane name="measurement" display-directive="show"><template #tab><AppIcon name="sensor" />{{ t('editorTabs.measurement') }}</template><div class="dialog-form"><div class="sensor-form-grid"><div class="field dialog-wide"><label for="measurement-nature">{{ t('measurementTypes.nature') }}</label><div class="icon-select"><AppIcon :name="measurementNatureIcon(typeForm.nature)" /><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="measurement-nature" v-bind="typeValidation.attrs('nature')" v-model="typeForm.nature" :disabled="saving" :aria-invalid="!!fields.nature" :aria-describedby="fields.nature ? 'measurement-nature-help measurement-nature-error' : 'measurement-nature-help'"><option v-for="nature in measurementNatures" :key="nature" :value="nature">{{ t(`measurementTypes.natures.${nature}`) }}</option></select></div><small id="measurement-nature-help">{{ t(typeForm.nature === 'substrate_moisture' ? 'measurementTypes.substrateHelp' : 'measurementTypes.natureHelp') }}</small><small v-if="fields.nature" id="measurement-nature-error" role="alert" class="field-error">{{ t(fields.nature) }}</small></div>
          <div class="field"><label for="measurement-unit">{{ t('measurementTypes.unit') }}</label><InputText placeholder="" v-bind="typeValidation.attrs('unit')" v-model:value="typeForm.unit" maxlength="24" :disabled="saving || semanticsLocked" :status="!!fields.unit ? 'error' : undefined" :input-props="{ 'id': 'measurement-unit' }"></InputText><small v-if="fields.unit" id="measurement-unit-error" role="alert" class="field-error">{{ t(fields.unit) }}</small></div>
          <div class="field"><label for="measurement-decimals">{{ t('measurementTypes.decimals') }}</label><InputText placeholder="" v-bind="typeValidation.attrs('decimal_places')" v-model:value="typeForm.decimals" min="0" max="6" step="1" :disabled="saving || semanticsLocked" :status="!!fields.decimal_places ? 'error' : undefined" :input-props="{ 'id': 'measurement-decimals', type: 'number' }"></InputText><small v-if="fields.decimal_places" id="measurement-decimals-error" role="alert" class="field-error">{{ t(fields.decimal_places) }}</small></div></div>

        </div></NTabPane><NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="file" />{{ t('editorTabs.notes') }}</template><div class="dialog-form"><div class="field dialog-wide"><label for="measurement-description">{{ t('sensors.notes') }}</label><NotesEditor id="measurement-description" :described-by="fields.description ? 'measurement-description-error' : undefined" v-model="typeForm.description" :label="t('sensors.notes')" :disabled="saving" :invalid="!!fields.description" /><small v-if="fields.description" id="measurement-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div></div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('sensors.cancel')" type="default" :disabled="saving" @click="confirmLeave"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-1`" attr-type="submit" :aria-label="editingType ? t('measurementTypes.save') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editingType ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>

      <p v-if="loading" role="status">{{ t('sensors.loading') }}</p>
      <div v-else-if="total === 0" class="resource-empty"><AppIcon name="sensor" /><span>{{ t(view === 'sensors' ? 'sensors.empty' : 'measurementTypes.empty') }}</span></div>
      <CollectionTable preference-key="channels" :selection-target="selectionTarget ?? selectionHeader" grid-class="sensor-grid resource-grid" v-if="view === 'sensors' && (!loading || sensors.length)" :rows="sensors" :can-select="canManage ? entry => entry.isEnabled : undefined" :row-name="entry => entry.name" :loading="loading" :locked="saving" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'device', label: t('sensors.device') }, { key: 'channel', label: t('sensors.channel') }, { key: 'unit', label: t('collection.unit') }, { key: 'interval', label: t('collection.interval') }, { key: 'actions', label: t('collection.actions') }]">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(entry)" v-for="entry in sensors" :key="entry.id" :icon="measurementNatureIcon(entry.measurementType.nature)" :eyebrow="entry.measurementType.name"><template #title>{{ entry.name }}</template>
          <template #badge><RecordStatusTag :value="entry.isEnabled ? 'enabled' : 'disabled'" :options="[{ value: 'enabled', label: t('sensors.enabled'), type: 'success' }, { value: 'disabled', label: t('sensors.disabled'), type: 'default' }]" :interactive="canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: entry.name })" @update:value="toggleEnabled(entry)" /></template>
          <DeviceAssignment class="resource-path" :device="entry.device" />
          <dl class="resource-facts"><div class="resource-wide"><dt><AppIcon :name="assetCategoryIcon(entry.device.category)" />{{ t('sensors.device') }}</dt><dd>{{ entry.device.name }} · {{ t(`assets.statuses.${entry.device.status}`) }}</dd></div><div><dt>{{ t('sensors.channel') }}</dt><dd>{{ entry.channelKey }}</dd></div><div><dt>{{ t('collection.unit') }}</dt><dd>{{ entry.measurementType.unit ?? t('measurementTypes.noUnit') }}</dd></div><div class="resource-wide"><dt><AppIcon name="clock" />{{ t('collection.interval') }}</dt><dd>{{ entry.expectedIntervalSeconds == null ? t('sensors.noInterval') : t('sensors.seconds', { count: entry.expectedIntervalSeconds }) }}</dd></div></dl>
          <RichNotes v-if="entry.description" class="resource-note" :value="entry.description" />
          <template v-if="canManage" #management><ListActionButton icon="edit" :label="t('sensors.editNamed', { name: entry.name })" :disabled="saving" @click="openSensor(entry)" /></template></ResourceCard></template><template #batch-actions="{ rows: selectedChannels, clear }">
 <RecordBatchAction :rows="selectedChannels" :action="entry => sensorsApi.setEnabled(entry.id, false)" :name="entry => entry.name" :label="t('collection.batch.disable')" icon="archive" @applied="clear($event); emit('changed'); load(page)" @access-lost="showError" />
</template><template #name="{ row: entry }"><div class="collection-table-name"><CollectionIdentity :name="entry.name" :icon="measurementNatureIcon(entry.measurementType.nature)" :interactive="false" /><div class="collection-table-tags"><RecordStatusTag :value="entry.isEnabled ? 'enabled' : 'disabled'" :options="[{ value: 'enabled', label: t('sensors.enabled'), type: 'success' }, { value: 'disabled', label: t('sensors.disabled'), type: 'default' }]" :interactive="canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: entry.name })" @update:value="toggleEnabled(entry)" /></div></div></template>
<template #device="{ row: entry }">{{ entry.device.name }}</template>
<template #channel="{ row: entry }">{{ entry.channelKey }}</template>
<template #unit="{ row: entry }">{{ entry.measurementType.unit ?? t('measurementTypes.noUnit') }}</template>
<template #interval="{ row: entry }">{{ entry.expectedIntervalSeconds == null ? t('sensors.noInterval') : t('sensors.seconds', { count: entry.expectedIntervalSeconds }) }}</template>
<template #actions="{ row: entry }"><div v-if="canManage" class="collection-table-actions"><ListActionButton icon="edit" :label="t('sensors.editNamed', { name: entry.name })" :disabled="saving" @click="openSensor(entry)" /></div></template></CollectionTable>
      <CollectionLayout preference-key="measurement-types" grid-class="sensor-grid resource-grid" v-else-if="view === 'types' && !loading"><template #default><ResourceCard v-for="entry in types" :key="entry.id" :icon="measurementNatureIcon(entry.nature)" :eyebrow="entry.code"><template #title>{{ entry.name }}</template>
          <template #badge><Tag type="info">{{ t(`measurementTypes.natures.${entry.nature}`) }}</Tag></template>
          <dl class="resource-facts"><div><dt>{{ t('collection.unit') }}</dt><dd>{{ entry.unit ?? t('measurementTypes.noUnit') }}</dd></div><div><dt>{{ t('measurementTypes.decimals') }}</dt><dd>{{ entry.decimalPlaces }}</dd></div><div class="resource-wide"><dt><AppIcon name="sensor" />{{ t('sensors.sensorList') }}</dt><dd>{{ entry.sensorsCount }}</dd></div></dl>
          <RichNotes v-if="entry.description" class="resource-note" :value="entry.description" />
          <template v-if="canManage" #management><ListActionButton icon="edit" :label="t('sensors.editNamed', { name: entry.name })" :disabled="saving" @click="openType(entry)" /></template></ResourceCard></template>
<template #table><CollectionTable :rows="types" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'code', label: t('measurementTypes.code') }, { key: 'unit', label: t('collection.unit') }, { key: 'decimals', label: t('measurementTypes.decimals') }, { key: 'sensors', label: t('sensors.sensorList') }, { key: 'actions', label: t('collection.actions') }]"><template #name="{ row: entry }"><div class="collection-table-name"><CollectionIdentity :name="entry.name" :icon="measurementNatureIcon(entry.nature)" :interactive="false" /><div class="collection-table-tags"><Tag type="info">{{ t(`measurementTypes.natures.${entry.nature}`) }}</Tag></div></div></template>
<template #code="{ row: entry }">{{ entry.code }}</template>
<template #unit="{ row: entry }">{{ entry.unit ?? t('measurementTypes.noUnit') }}</template>
<template #decimals="{ row: entry }">{{ entry.decimalPlaces }}</template>
<template #sensors="{ row: entry }">{{ entry.sensorsCount }}</template>
<template #actions="{ row: entry }"><div v-if="canManage" class="collection-table-actions"><ListActionButton icon="edit" :label="t('sensors.editNamed', { name: entry.name })" :disabled="saving" @click="openType(entry)" /></div></template></CollectionTable></template></CollectionLayout>
      <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="saving || loading" :label="t('sensors.pagination')" :previous-label="t('sensors.previous')" :next-label="t('sensors.next')" @page="collectionQuery.go($event)" /></template></Card></template>
<style scoped>
:global(.n-card.app-dialog.module-dialog .sensors-card .n-card-header__main) { display: block; }
.sensors-card { padding: var(--space-3); }
.sensors-heading, .sensors-pagination { display: flex; justify-content: space-between; align-items: center; gap: var(--ui-grid-gap); flex-wrap: wrap; }
h2 { margin: 0; font-size: 1.6rem; letter-spacing: -0.035em; }
.sensor-views, .sensor-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.sensor-views { margin: var(--space-3) 0 var(--ui-content-gap); }
.sensor-filters { display: flex; align-items: flex-end; gap: var(--space-3); flex-wrap: wrap; margin: var(--ui-content-gap) 0; }
.sensor-filters > div { display: grid; gap: var(--space-2); min-width: 0; }
.sensor-filters label { font-size: 0.875rem; font-weight: 600; }
.sensor-filters input { min-width: 0; width: 100%; }
select { font: inherit; padding: var(--space-2); border: 1px solid var(--theme-border, var(--theme-border, #c1d3c8)); border-radius: 0.4rem; background: var(--theme-surface, var(--theme-surface, white)); color: var(--theme-text, inherit); }
.sensor-form { border: 1px solid var(--theme-border, var(--theme-border, #dbe7df)); padding: var(--space-5); border-radius: 0.75rem; margin: var(--ui-content-gap) 0; }
.sensor-form h3 { margin-top: 0; }
.sensor-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 var(--ui-grid-gap); }
dl { display: grid; gap: var(--space-1); margin: 0; }
dt { font-size: 0.75rem; opacity: 0.7; }
dd { margin: 0 0 var(--space-2); overflow-wrap: anywhere; }
.sensors-pagination { margin-top: var(--ui-content-gap); font-size: 0.875rem; }
@media (max-width: 600px) { .sensor-form-grid { grid-template-columns: 1fr; } .sensor-filters > div { flex: 1 1 100%; } .sensor-form { padding: var(--space-3); } .sensors-card { padding: 0; } }
</style>
