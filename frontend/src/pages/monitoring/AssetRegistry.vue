<script setup lang="ts">
import { hardwareTabs } from '../../tab-paths';
import RecordBatchAction from '../../components/RecordBatchAction.vue';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
import { plantsApi } from '../../api/plants';
import LocationSelect from '../../components/LocationSelect.vue';
import RecordStatusTag from '../../components/RecordStatusTag.vue';
import { useFormTabs } from '../../lib/form-tabs';
import AppTabs from '../../components/AppTabs.vue';
import RecordQrButton from '../../components/RecordQrButton.vue';
import { useResolvedRecordQuery, usePathChoice } from '../../lib/route-query';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionIdentity from '../../components/CollectionIdentity.vue';
import { categoryIcons } from '../../lib/asset-category';
import AssetIntegrationTags from '../../components/AssetIntegrationTags.vue';
import AssetPowerSettings from '../../components/AssetPowerSettings.vue';
import AssetPowerControl from '../../components/AssetPowerControl.vue';
import type { PowerSettings } from '../../api/assets';
import PumpSettings from '../../components/PumpSettings.vue';
import PumpControl from '../../components/PumpControl.vue';
import type { PumpSettings as PumpConfiguration } from '../../api/assets';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useGuardedTabs } from '../../lib/guarded-tabs';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';

import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import { NTabPane } from 'naive-ui';




import PageTitle from '../../components/PageTitle.vue';
import DeviceAssignment from '../../components/DeviceAssignment.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import NotesEditor from '../../components/NotesEditor.vue';
import RichNotes from '../../components/RichNotes.vue';
import DetailDialog from '../../components/DetailDialog.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import { computed, useId, defineAsyncComponent, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import LocationAssignments from '../../components/LocationAssignments.vue';
import { ApiError } from '../../api/auth';
import type { LocationContext } from '../../api/types';
import { assetsApi, assetCategories, type AssetCategory, type Asset, type AssetInput, type AssetStatus, type Assignment } from '../../api/assets';
const pageSize = useCollectionPageSize('hardware');
const sort = useCollectionSort('hardware', sortOptions.common);

const props = defineProps<{ embedded?: boolean; canManage: boolean; canManageSensors?: boolean; canConfigurePower?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [error: ApiError] }>();
const { t, te, locale } = useI18n();
const SensorConfiguration = defineAsyncComponent(() => import('../../components/SensorConfiguration.vue'));
const view = ref('devices');
usePathChoice(view, hardwareTabs);
const channelsBoard = ref<{ reload: () => void; create: () => void; loading: boolean; saving: boolean; confirmLeave: () => Promise<boolean> } | null>(null);
const deviceRoute = useResolvedRecordQuery('asset', assetsApi.show, showError);
const selectedDevice = deviceRoute.record;
async function showDevice(entry: Asset) {
 // Commit pending search criteria before the record creates its own history entry.
 if ((deviceRoute.route.query.q ?? '') !== search.value) await collectionQuery.apply();
 void deviceRoute.open(entry.id);
}
const contextualChannels = ref<{ confirmLeave: () => Promise<boolean> } | null>(null);
async function closeDevice() { if (await contextualChannels.value?.confirmLeave() ?? true) void deviceRoute.close(); }
const guardedTabs = useGuardedTabs(view, async () => {
 if (!await unsaved.confirmDiscard() || channelsBoard.value && !await channelsBoard.value.confirmLeave()) return false;
 formOpen.value = false; return true;
});
async function leave() { if (await unsaved.confirmDiscard() && (await channelsBoard.value?.confirmLeave() ?? true) && (await contextualChannels.value?.confirmLeave() ?? true)) emit('close'); }
const statuses: AssetStatus[] = ['active', 'maintenance', 'retired'];
const statusOptions = computed(() => statuses.map(value => ({ value, label: t(`assets.statuses.${value}`), type: value === 'active' ? 'success' as const : value === 'maintenance' ? 'warning' as const : 'default' as const })));
const records = ref<Asset[]>([]);
const search = ref('');
const filterCategory = ref<AssetCategory | ''>('');
const filterStatus = ref('');
const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const saving = ref(false);
const error = ref(''); const success = ref('');
const formOpen = ref(false); const editing = ref<Asset | null>(null);
const locations = ref<LocationContext[]>([]);
const batchLocation = ref<LocationContext | null>(null);
const batchPlant = ref('');
async function listBatchPlants(search: string, page: number) {
 const result = await plantsApi.list(search, page, 'all');
 return { ...result, data: result.data.filter(row => row.canManage).map(row => ({ id: row.id, name: row.name, detail: '' })) };
}
async function assignBatchPlant(entries: Asset[]) {
 const plant = await plantsApi.get(batchPlant.value);
 const ids = [...new Set([...plant.devices.map(device => device.id), ...entries.map(entry => entry.id)])];
 if (ids.length !== plant.devices.length) await plantsApi.update(plant.id, { version: plant.version, device_ids: ids });
}
async function assignBatchLocation(entry: Asset) {
 const location = batchLocation.value;
 if (!location) return;
 await assetsApi.addLocation(entry.id, { type: location.type, id: location.id });
}
const form = reactive({ name: '', category: 'other' as AssetCategory, status: 'active' as AssetStatus, tag: '', manufacturer: '', model: '', serial: '', description: '', pumpControl: null as PumpConfiguration | null, powerControl: null as PowerSettings | null });
const supportsPowerControl = computed(() => ['light', 'socket', 'fan'].includes(form.category));
const historyRoute = useResolvedRecordQuery('assetHistory', assetsApi.show, showError);
const historyFor = historyRoute.record; const history = ref<Assignment[]>([]);
const historyPage = ref(1); const historyLast = ref(1); const historyLoading = ref(false);
let listSequence = 0; let historySequence = 0; let active = true;
const baseline = ref('');
function snapshot() { return JSON.stringify([form, locations.value.map(location => [location.type, location.id])]); }
const dirty = computed(() => saving.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, saving, { onNavigate: () => { formOpen.value = false; } });
const editorTabs = useFormTabs(formOpen, {"manufacturer": "technical", "model": "technical", "asset_tag": "technical", "serial_number": "technical", "description": "notes", "power_control": "control", "pump_control": "control"});
const editorTab = editorTabs.tab;
const validation = useFieldErrors(() => Object.fromEntries([
 ['name', 'asset-name', 'nameInvalid'], ['category', 'asset-category', 'categoryInvalid'], ['status', 'asset-status', 'statusInvalid'], ['asset_tag', 'asset-tag', 'tagInvalid'],
 ['manufacturer', 'asset-manufacturer', 'metadataInvalid'], ['model', 'asset-model', 'metadataInvalid'], ['serial_number', 'asset-serial', 'metadataInvalid'], ['description', 'asset-description', 'descriptionInvalid'], ['locations', 'asset-location', 'locationInvalid'], ['power_control', 'asset-power-settings', 'powerInvalid'],
].map(([field, id, message]) => [field, { id, message: `assets.validation.${message}` }])), () => ({ ...form, asset_tag: form.tag, serial_number: form.serial, power_control: JSON.stringify(form.powerControl), locations: JSON.stringify(locations.value.map(location => [location.type, location.id])) }), editorTabs.reveal);
const fields = validation.fields;

async function closeForm() { if (await unsaved.confirmDiscard()) { formOpen.value = false; error.value = ''; validation.clear(); } }

function key(value: string): string {
  return /^(auth|assets)\.(errors|validation|pump|power)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed';
}
function showError(cause: unknown) {
  if (!active) return;
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError) {
    if (formOpen.value) { validation.capture(cause); if (cause.code === 'assets.errors.tagTaken') { validation.set('asset_tag', cause.code); void validation.focus(); } }
    if (cause.status === 422) error.value = 'auth.errors.validationFailed';
    if (cause.status === 401 || cause.status === 403) { records.value = []; history.value = []; formOpen.value = false; selectedDevice.value = null; historyFor.value = null; emit('accessLost', cause); }
  }
}
async function changeStatus(entry: Asset, status: string) {
 if (!props.canManage || saving.value || loading.value || !statuses.includes(status as AssetStatus)) return;
 saving.value = true; error.value = ''; success.value = '';
 try {
  const result = await assetsApi.setStatus(entry.id, status as AssetStatus);
  if (selectedDevice.value?.id === result.id) selectedDevice.value = result;
  success.value = 'assets.updated'; await load(page.value);
 } catch (cause) { showError(cause); }
 finally { saving.value = false; }
}
async function load(target = 1) {
  const sequence = ++listSequence;
  loading.value = true; error.value = '';
  try {
    let result = await assetsApi.list(search.value.trim(), filterCategory.value, filterStatus.value, target, sort.value, pageSize.value);
    if (sequence !== listSequence) return;
    if (target > result.meta.last_page && result.meta.total > 0) result = await assetsApi.list(search.value.trim(), filterCategory.value, filterStatus.value, result.meta.last_page, sort.value, pageSize.value);
    if (sequence !== listSequence) return;
    records.value = result.data;
    if (selectedDevice.value) selectedDevice.value = result.data.find(entry => entry.id === selectedDevice.value?.id) ?? selectedDevice.value;
    page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
  } catch (cause) { if (sequence === listSequence) showError(cause); }
  finally { if (sequence === listSequence) loading.value = false; }
}
async function open(entry: Asset | null = null) {
  if (!await unsaved.confirmDiscard()) return;
  editing.value = entry; locations.value = [...(entry?.locations ?? [])];
  Object.assign(form, { name: entry?.name ?? '', category: entry?.category ?? 'other', status: entry?.status ?? 'active', tag: entry?.assetTag ?? '', manufacturer: entry?.manufacturer ?? '', model: entry?.model ?? '', serial: entry?.serialNumber ?? '', description: entry?.description ?? '', pumpControl: entry?.pumpControl ? { ...entry.pumpControl } : null, powerControl: entry?.powerControl ? { ...entry.powerControl } : null });
  validation.clear(); error.value = success.value = ''; baseline.value = snapshot(); formOpen.value = true;
  historyFor.value = null; historySequence++;
}
async function save() {
  if (saving.value) return;
  validation.clear(); error.value = success.value = '';
  if (!form.name.trim() || form.name.length > 120) validation.set('name');
  if (!assetCategories.includes(form.category)) validation.set('category');
  if (!statuses.includes(form.status)) validation.set('status');
  for (const [field, value, limit] of [['asset_tag', form.tag, 80], ['manufacturer', form.manufacturer, 120], ['model', form.model, 120], ['serial_number', form.serial, 120], ['description', form.description, 2000]] as const) if (value.length > limit) validation.set(field);
  if (form.category === 'pump' && form.pumpControl && (!form.pumpControl.entity || form.pumpControl.mode === 'timer' && (!Number.isInteger(form.pumpControl.seconds) || Number(form.pumpControl.seconds) < 1 || Number(form.pumpControl.seconds) > 86400))) { error.value = 'assets.pump.invalid'; return; }
  if (supportsPowerControl.value && form.powerControl && (!form.powerControl.entity || form.powerControl.mode === 'auto' && (!form.powerControl.on_at || !form.powerControl.off_at || form.powerControl.on_at === form.powerControl.off_at || !form.powerControl.timezone))) { error.value = 'assets.power.invalid'; return; }
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
  saving.value = true;
  const nullable = (value: string) => value.trim() || null;
  const input: AssetInput = { name: form.name, category: form.category, status: form.status, asset_tag: nullable(form.tag), manufacturer: nullable(form.manufacturer), model: nullable(form.model), serial_number: nullable(form.serial), description: nullable(form.description), pump_control: form.category === 'pump' ? form.pumpControl : null, power_control: supportsPowerControl.value ? form.powerControl : null, locations: locations.value.map(({ type, id }) => ({ type, id })) };
  try {
    const result = editing.value ? await assetsApi.update(editing.value.id, input) : await assetsApi.create(input);
    if (!active) return;
    if (selectedDevice.value?.id === result.id) selectedDevice.value = result;
    formOpen.value = false; success.value = editing.value ? 'assets.updated' : 'assets.created';
    await load(editing.value ? page.value : 1);
  } catch (cause) { showError(cause); }
  finally { saving.value = false; }
}
async function loadHistory(entry: Asset, target = 1) {
  if (historyFor.value?.id !== entry.id) { if (await unsaved.confirmDiscard()) void historyRoute.open(entry.id); return; }
  if (!await unsaved.confirmDiscard()) return;
  const sequence = ++historySequence;
  historyFor.value = entry; historyLoading.value = true; history.value = []; error.value = ''; formOpen.value = false;
  try {
    const result = await assetsApi.history(entry.id, target);
    if (sequence !== historySequence) return;
    history.value = result.data; historyPage.value = result.meta.current_page; historyLast.value = result.meta.last_page;
  } catch (cause) { if (sequence === historySequence) showError(cause); }
  finally { if (sequence === historySequence) historyLoading.value = false; }
}
function closeHistory() { void historyRoute.close(); }
function date(value: string) { return formatDateTime(value, locale.value); }
onMounted(() => load(page.value)); onUnmounted(() => { active = false; listSequence++; historySequence++; });
const dialogId = useId();
const collectionQuery = useCollectionQuery({ paths: Object.values(hardwareTabs), enabled: () => view.value === 'devices', fields: { q: queryField.text(search), category: queryField.choice(filterCategory, ['', ...assetCategories]), status: queryField.choice(filterStatus, ['', ...statuses]), sort: queryField.choice(sort, sortOptions.common) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;
const assignmentQuery = useCollectionQuery({ paths: Object.values(hardwareTabs), prefix: 'assetHistory.', fields: {}, page: historyPage, load: target => historyFor.value && loadHistory(historyFor.value, target) });
watch(() => historyFor.value?.id, id => { historySequence++; history.value = []; historyLoading.value = false; if (id && historyFor.value) void loadHistory(historyFor.value, historyPage.value); });


const selectionHeader = ref<HTMLElement | null>(null);
</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <section class="hardware-workspace module-workspace" data-testid="hardware-workspace"><div class="page-heading"><PageTitle icon="device">{{ t('assets.title') }}</PageTitle><div ref="selectionHeader" class="page-actions"><Button v-if="!embedded" :aria-label="t('assets.back')" type="default" @click="leave" :title="t('assets.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="view === 'devices' ? loading : channelsBoard?.loading" :disabled="view === 'devices' ? saving : !channelsBoard || channelsBoard.saving" @click="view === 'devices' ? load(page) : channelsBoard?.reload()" /><CollectionViewToggle :preference-key="view === 'channels' ? 'channels' : 'equipment'" :disabled="view === 'devices' ? saving || loading : !!channelsBoard?.saving || !!channelsBoard?.loading" /><Button v-if="view === 'devices' ? canManage : (canManageSensors ?? canManage)" attr-type="button" :disabled="view === 'devices' ? saving : !channelsBoard || channelsBoard.saving" @click="view === 'devices' ? open() : channelsBoard?.create()" data-action-icon="plus" :aria-label="t(view === 'devices' ? 'assets.add' : 'sensors.add')" :title="t(view === 'devices' ? 'assets.add' : 'sensors.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div>
   <p class="hardware-intro">{{ t('assets.description') }}</p>
   <AppTabs :value="guardedTabs.value.value" @update:value="guardedTabs.change" animated><NTabPane name="devices" display-directive="show:lazy"><template #tab><AppIcon name="device" />{{ t('assets.devicesView') }}</template><Card class="assets-card"><template #default><p v-if="!canManage">{{ t('assets.readOnly') }}</p>
      <Message v-if="error && !(formOpen)" closable type="error" role="alert">{{ t(error) }}</Message>
      <Message v-if="success" closable type="success" role="status">{{ t(success) }}</Message>

   <form class="assets-filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="filter-field"><label for="assets-search">{{ t('assets.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="saving" :input-props="{ 'id': 'assets-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.common" /></div>
    <div class="filter-secondary"><div class="filter-field"><label for="assets-category-filter">{{ t('assets.category') }}</label><div class="icon-select"><AppIcon :name="filterCategory ? categoryIcons[filterCategory] : 'equipment'" /><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="assets-category-filter" v-model="filterCategory" :disabled="saving"><option value="">{{ t('assets.allCategories') }}</option><option v-for="value in assetCategories" :key="value" :value="value">{{ t(`assets.categories.${value}`) }}</option></select></div></div>
     <div class="filter-field"><label for="assets-status-filter">{{ t('assets.status') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="assets-status-filter" v-model="filterStatus" :disabled="saving"><option value="">{{ t('assets.allStatuses') }}</option><option v-for="value in statuses" :key="value" :value="value">{{ t(`assets.statuses.${value}`) }}</option></select></div></div>
    <ResetFilters @reset="search = ''; filterCategory = ''; filterStatus = ''; sort = 'name_asc'" /></form>

      <p v-if="loading" role="status">{{ t('assets.loading') }}</p>
      <div v-else-if="!records.length" class="resource-empty"><AppIcon name="device" /><span>{{ t('assets.empty') }}</span></div>
      <CollectionTable :selection-target="view === 'devices' ? selectionHeader : null" preference-key="equipment" grid-class="asset-grid resource-grid" v-if="records.length" :aria-busy="loading" :rows="records" :can-select="canManage ? () => true : undefined" :loading="loading || saving" :row-name="row => row.name" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'category', label: t('assets.category') }, { key: 'location', label: t('assets.locations') }, { key: 'channels', label: t('assets.channels') }, { key: 'actions', label: t('collection.actions') }]">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(entry)" v-for="entry in records" :key="entry.id" class="asset-node" :icon="categoryIcons[entry.category]" :eyebrow="t(`assets.categories.${entry.category}`)" :tone="entry.status === 'maintenance' ? 'warning' : undefined"><template #title><button class="device-name" type="button" :aria-label="t('assets.openNamed', { name: entry.name })" :disabled="saving" @click="showDevice(entry)">{{ entry.name }}</button></template>
          <template #badge><RecordStatusTag :value="entry.status" :options="statusOptions" :interactive="canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: entry.name })" @update:value="changeStatus(entry, $event)" /></template>
          <div v-if="entry.integrations?.length" class="device-integration-tags"><AssetIntegrationTags :integrations="entry.integrations" /></div>
          <DeviceAssignment class="resource-path" :device="entry" />
          <dl class="resource-facts"><div v-if="entry.assetTag"><dt>{{ t('collection.tag') }}</dt><dd class="card-reference" :title="entry.assetTag">{{ entry.assetTag }}</dd></div><div v-if="entry.manufacturer || entry.model"><dt>{{ t('collection.model') }}</dt><dd>{{ [entry.manufacturer, entry.model].filter(Boolean).join(' · ') }}</dd></div><div v-if="entry.serialNumber" class="resource-wide"><dt>{{ t('collection.serial') }}</dt><dd class="card-reference" :title="entry.serialNumber">{{ entry.serialNumber }}</dd></div></dl>
          <button class="device-channels-summary" type="button" :aria-label="t('assets.channelsNamed', { name: entry.name })" @click="showDevice(entry)"><AppIcon name="sensor" /><span>{{ t('assets.channelCount', { count: entry.channelsCount ?? 0 }) }}<small>{{ entry.channelsCount ? t('assets.enabledChannelCount', { count: entry.enabledChannelsCount ?? 0 }) : t('assets.optionalChannels') }}</small></span><AppIcon name="arrow" /></button>
          <RichNotes v-if="entry.description" class="resource-note" :value="entry.description" />
          <template #actions><AssetPowerControl v-if="entry.canControlPower && entry.powerControl" :asset-id="entry.id" :name="entry.name" :category="entry.category" :settings="entry.powerControl" /><PumpControl v-if="entry.canActivate" :asset-id="entry.id" :name="entry.name" @access-lost="showError" /><ListActionButton icon="clock" :label="t('assets.historyNamed', { name: entry.name })" :disabled="saving" @click="loadHistory(entry)" /></template><template #management><span class="card-management-actions"><ListActionButton icon="edit" v-if="canManage" :label="t('assets.editNamed', { name: entry.name })" :disabled="saving" @click="open(entry)" /></span></template></ResourceCard></template><template #batch-actions="{ rows: selectedAssets, clear }">
 <RecordBatchAction :rows="selectedAssets" :action="assignBatchLocation" :name="entry => entry.name" :label="t('assets.addLocation')" icon="location" :disabled="loading || saving" :incomplete="!batchLocation" @applied="clear($event); load(page)" @access-lost="showError">
  <template #default="{ busy }"><LocationSelect id="asset-batch-location" v-model="batchLocation" :disabled="busy" :label="t('assets.locations')" :empty-label="t('selectControl.empty')" @access-lost="showError" /></template>
 </RecordBatchAction>
 <RecordBatchAction :rows="selectedAssets.filter(entry => entry.status !== 'retired')" :group-action="assignBatchPlant" :name="entry => entry.name" :label="t('collection.batch.assignPlant')" icon="leaf" :disabled="loading || saving" :incomplete="!batchPlant" @applied="clear($event); load(page)" @access-lost="showError">
  <template #default="{ busy }"><ReferenceSelect v-model="batchPlant" :label="t('collection.batch.assignPlant')" :disabled="busy" :fetch-page="listBatchPlants" @access-lost="showError" /></template>
 </RecordBatchAction>
 <RecordBatchAction :rows="selectedAssets.filter(entry => entry.status !== 'retired')" :action="entry => assetsApi.setStatus(entry.id, 'retired')" :name="entry => entry.name" :label="t('assets.statuses.retired')" icon="archive" :confirmation="t('collection.batch.retireWarning')" :disabled="loading || saving" @applied="clear($event); load(page)" @access-lost="showError" />
</template><template #name="{ row: entry }"><div class="collection-table-name"><CollectionIdentity :name="entry.name" :reference="entry.assetTag || undefined" :icon="categoryIcons[entry.category]" @open="showDevice(entry)" /><div class="collection-table-tags"><AssetIntegrationTags :integrations="entry.integrations" /><RecordStatusTag :value="entry.status" :options="statusOptions" :interactive="canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: entry.name })" @update:value="changeStatus(entry, $event)" /></div></div></template>
<template #category="{ row: entry }">{{ t(`assets.categories.${entry.category}`) }}</template>
<template #location="{ row: entry }"><DeviceAssignment :device="entry" /></template>
<template #channels="{ row: entry }">{{ entry.channelsCount ?? 0 }}</template>
<template #actions="{ row: entry }"><div class="collection-table-actions"><AssetPowerControl v-if="entry.canControlPower && entry.powerControl" :asset-id="entry.id" :name="entry.name" :category="entry.category" :settings="entry.powerControl" /><PumpControl v-if="entry.canActivate" :asset-id="entry.id" :name="entry.name" @access-lost="showError" /><ListActionButton icon="eye" :label="t('assets.openNamed', { name: entry.name })" :disabled="saving" @click="showDevice(entry)" /><ListActionButton icon="clock" :label="t('assets.historyNamed', { name: entry.name })" :disabled="saving" @click="loadHistory(entry)" /><span class="card-management-actions"><ListActionButton icon="edit" v-if="canManage" :label="t('assets.editNamed', { name: entry.name })" :disabled="saving" @click="open(entry)" /></span></div></template></CollectionTable>
      <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="saving || loading" :label="t('assets.pagination')" :previous-label="t('assets.previous')" :next-label="t('assets.next')" @page="collectionQuery.go($event)" /></template></Card></NTabPane><NTabPane name="channels" id="workspace-section-sensors" data-testid="workspace-section-sensors" display-directive="show:lazy"><template #tab><AppIcon name="sensor" />{{ t('sensors.title') }}</template><SensorConfiguration v-if="view === 'channels'" ref="channelsBoard" :selection-target="selectionHeader" embedded hide-actions :can-manage="canManageSensors ?? canManage" @changed="load(page)" @access-lost="emit('accessLost', $event)" /></NTabPane></AppTabs></section>
  <DetailDialog v-if="selectedDevice" module :title="selectedDevice.name" :close-label="t('assets.closeDevice')" :icon="categoryIcons[selectedDevice.category]" @close="closeDevice"><nav class="record-actions" :aria-label="t('recordDetails.actions')"><div class="record-action-group record-care-actions"><AssetPowerControl v-if="selectedDevice.canControlPower && selectedDevice.powerControl" :asset-id="selectedDevice.id" :name="selectedDevice.name" :category="selectedDevice.category" :settings="selectedDevice.powerControl" labeled /><PumpControl v-if="selectedDevice.canActivate" labeled :asset-id="selectedDevice.id" :name="selectedDevice.name" @access-lost="showError" /><Button :aria-label="t('assets.historyNamed', { name: selectedDevice.name })" type="default" data-action-icon="clock" @click="loadHistory(selectedDevice)"><template #icon><AppIcon name="clock" /></template><span>{{ t('assets.history') }}</span></Button></div><div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: 'asset', id: selectedDevice.id, name: selectedDevice.name }" :disabled="saving" /><Button v-if="canManage" :aria-label="t('assets.editNamed', { name: selectedDevice.name })" type="default" data-action-icon="edit" @click="open(selectedDevice)"><template #icon><AppIcon name="edit" /></template><span>{{ t('recordDetails.edit') }}</span></Button></div></nav>
   <section class="device-detail-summary"><div class="device-detail-tags"><Tag type="default">{{ t(`assets.categories.${selectedDevice.category}`) }}</Tag><AssetIntegrationTags :integrations="selectedDevice.integrations" /><RecordStatusTag :value="selectedDevice.status" :options="statusOptions" :interactive="canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: selectedDevice.name })" @update:value="changeStatus(selectedDevice, $event)" /></div><DeviceAssignment class="device-location" :device="selectedDevice" />
    <dl class="device-details"><div v-if="selectedDevice.manufacturer || selectedDevice.model"><dt>{{ t('collection.model') }}</dt><dd>{{ [selectedDevice.manufacturer, selectedDevice.model].filter(Boolean).join(' · ') }}</dd></div><div v-if="selectedDevice.assetTag"><dt>{{ t('collection.tag') }}</dt><dd class="card-reference" :title="selectedDevice.assetTag">{{ selectedDevice.assetTag }}</dd></div><div v-if="selectedDevice.serialNumber"><dt>{{ t('collection.serial') }}</dt><dd class="card-reference" :title="selectedDevice.serialNumber">{{ selectedDevice.serialNumber }}</dd></div></dl><RichNotes v-if="selectedDevice.description" :value="selectedDevice.description" /></section>
   <h4 class="device-channel-heading"><AppIcon name="sensor" />{{ t('sensors.title') }}</h4><p>{{ t('assets.channelsHelp') }}</p>
   <SensorConfiguration ref="contextualChannels" embedded :device="selectedDevice" :can-manage="canManageSensors ?? canManage" @changed="load(page)" @access-lost="emit('accessLost', $event)" /></DetailDialog>
      <FormDialog :icon="categoryIcons[form.category]" v-if="formOpen && canManage" :visible="true" :title="t(editing ? 'assets.editTitle' : 'assets.createTitle')" :close-label="t('assets.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><form :id="`${dialogId}-0`" @submit.prevent="save" class="dialog-form asset-form" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><fieldset class="dialog-section"><legend>{{ t('modalSections.identity') }}</legend>
<div class="dialog-form-grid"><div class="field dialog-wide"><label for="asset-name">{{ t('assets.name') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="form.name" maxlength="120" :status="!!fields.name ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'asset-name' }"></InputText><small v-if="fields.name" id="asset-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>
<div class="field"><label for="asset-category">{{ t('assets.category') }}</label><div class="icon-select"><AppIcon :name="categoryIcons[form.category]" /><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="asset-category" v-bind="validation.attrs('category')" v-model="form.category" :disabled="saving"><option v-for="value in assetCategories" :key="value" :value="value">{{ t(`assets.categories.${value}`) }}</option></select></div><small>{{ t('assets.categoryHelp') }}</small><small v-if="fields.category" id="asset-category-error" role="alert" class="field-error">{{ t(fields.category) }}</small></div>
<div class="field"><label for="asset-status">{{ t('assets.status') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="asset-status" v-bind="validation.attrs('status')" v-model="form.status" :disabled="saving"><option v-for="value in statuses" :key="value" :value="value">{{ t(`assets.statuses.${value}`) }}</option></select><small>{{ t('assets.statusHelp') }}</small><small v-if="fields.status" id="asset-status-error" role="alert" class="field-error">{{ t(fields.status) }}</small></div></div></fieldset>
<div class="field dialog-wide"><label for="asset-location">{{ t('assets.locations') }}</label><LocationAssignments id="asset-location" :described-by="fields.locations ? 'asset-location-error' : undefined" v-model="locations" :label="t('assets.locations')"  :disabled="saving" :invalid="!!fields.locations" @access-lost="showError" /><small v-if="fields.locations" id="asset-location-error" role="alert" class="field-error">{{ t(fields.locations) }}</small></div>
</div></NTabPane><NTabPane name="technical" display-directive="show"><template #tab><AppIcon name="device" />{{ t('editorTabs.technical') }}</template><div class="dialog-form"><fieldset class="dialog-section"><legend>{{ t('modalSections.technical') }}</legend>
<div class="dialog-form-grid"><div class="field"><label for="asset-manufacturer">{{ t('assets.manufacturer') }}</label><InputText placeholder="" v-bind="validation.attrs('manufacturer')" v-model:value="form.manufacturer" maxlength="120" :status="!!fields.manufacturer ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'asset-manufacturer' }"></InputText><small v-if="fields.manufacturer" id="asset-manufacturer-error" role="alert" class="field-error">{{ t(fields.manufacturer) }}</small></div>
<div class="field"><label for="asset-model">{{ t('assets.model') }}</label><InputText placeholder="" v-bind="validation.attrs('model')" v-model:value="form.model" maxlength="120" :status="!!fields.model ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'asset-model' }"></InputText><small v-if="fields.model" id="asset-model-error" role="alert" class="field-error">{{ t(fields.model) }}</small></div>
<div class="field"><label for="asset-tag">{{ t('assets.tag') }}</label><InputText placeholder="" v-bind="validation.attrs('asset_tag')" v-model:value="form.tag" maxlength="80" :status="!!fields.asset_tag ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'asset-tag' }"></InputText><small v-if="fields.asset_tag" id="asset-tag-error" role="alert" class="field-error">{{ t(fields.asset_tag) }}</small></div>
<div class="field"><label for="asset-serial">{{ t('assets.serial') }}</label><InputText placeholder="" v-bind="validation.attrs('serial_number')" v-model:value="form.serial" maxlength="120" :status="!!fields.serial_number ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'asset-serial' }"></InputText><small v-if="fields.serial_number" id="asset-serial-error" role="alert" class="field-error">{{ t(fields.serial_number) }}</small></div></div></fieldset>
</div></NTabPane><NTabPane v-if="supportsPowerControl || form.category === 'pump'" name="control" display-directive="show"><template #tab><AppIcon name="power" />{{ t('editorTabs.control') }}</template><div class="dialog-form"><AssetPowerSettings :asset-id="editing?.id" :fixed-provider="editing?.importedControlProvider ?? undefined" id="asset-power-settings" tabindex="-1" v-bind="validation.attrs('power_control')" v-if="supportsPowerControl" v-model="form.powerControl" :allow-binding="editing?.canConfigurePower ?? canConfigurePower ?? false" :disabled="saving" /><small v-if="fields.power_control" id="asset-power-settings-error" role="alert" class="field-error">{{ t(fields.power_control) }}</small>
<PumpSettings :asset-id="editing?.id" :fixed-provider="editing?.importedControlProvider ?? undefined" v-if="form.category === 'pump'" v-model="form.pumpControl" :disabled="saving" />
</div></NTabPane><NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="file" />{{ t('editorTabs.notes') }}</template><div class="dialog-form"><div class="field dialog-wide"><label for="asset-description">{{ t('assets.notes') }}</label><NotesEditor id="asset-description" :described-by="fields.description ? 'asset-description-error' : undefined" v-model="form.description" :label="t('assets.notes')" :disabled="saving" :invalid="!!fields.description" /><small v-if="fields.description" id="asset-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div></div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button attr-type="button" :aria-label="t('assets.cancel')" type="default" :disabled="saving" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('assets.save') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
      <DetailDialog v-if="historyFor" module :title="t('assets.historyFor', { name: historyFor.name })" :close-label="t('assets.closeHistory')" :busy="saving" :error="error ? t(error) : ''" icon="clock" @close="closeHistory"><section class="modal-panel"><p v-if="historyLoading" role="status">{{ t('assets.loading') }}</p>
        <p v-else-if="!history.length">{{ t('assets.noHistory') }}</p>
        <ol v-else class="assignment-history-list"><li v-for="event in history" :key="event.id"><div class="assignment-history-date"><AppIcon name="clock" /><time :datetime="event.assignedAt">{{ date(event.assignedAt) }}</time></div><DeviceAssignment :device="{ locations: event.locations, plants: [] }" /></li></ol>
        <nav v-if="historyLast > 1" class="assets-pagination" :aria-label="t('assets.historyPagination')"><Button :disabled="historyLoading || historyPage <= 1" @click="assignmentQuery.go(historyPage - 1)" type="primary"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('assets.previous') }}</span></Button><span>{{ t('assets.page', { current: historyPage, total: historyLast }) }}</span><Button :disabled="historyLoading || historyPage >= historyLast" @click="assignmentQuery.go(historyPage + 1)" type="primary"><template #icon><AppIcon name="arrow" /></template><span>{{ t('assets.next') }}</span></Button></nav></section></DetailDialog></template>

<style scoped>
.device-integration-tags { display: flex; align-items: center; flex-wrap: wrap; gap: var(--ui-control-gap); }
.hardware-intro { color: var(--theme-muted); font-size: .8rem; }.hardware-workspace .page-actions { justify-content: flex-end; }.device-channels-summary { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-3); border: 1px solid var(--theme-border); border-radius: .6rem; background: var(--theme-subtle); color: var(--theme-text); font: inherit; text-align: left; cursor: pointer; }.device-channels-summary > span { min-width: 0; flex: 1; overflow-wrap: anywhere; }.device-channels-summary > svg { flex-shrink: 0; }.device-channels-summary > svg:last-child { margin-left: auto; }.device-channels-summary small { display: block; color: var(--theme-muted); margin-top: var(--space-0-5); }.device-channels-summary:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }.device-detail-summary { display: grid; gap: var(--space-3); padding-bottom: var(--space-4); border-bottom: 1px solid var(--theme-border); }.device-detail-tags, .device-channel-heading { display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; }.device-location { margin: 0; color: var(--theme-muted); }.device-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: var(--space-3); }.device-details dd { margin: 0; font-size: .82rem; }.device-location { font-size: .82rem; }.device-channel-heading + p { color: var(--theme-muted); font-size: .8rem; }.device-channel-heading { margin: var(--ui-content-gap) 0 var(--space-1-5); font-size: .9rem; }

.assets-card { padding: var(--space-3); }
.assets-heading { display: flex; justify-content: space-between; align-items: center; gap: var(--ui-grid-gap); flex-wrap: wrap; }
h2 { margin: 0; font-size: 1.6rem; letter-spacing: -0.035em; }
.assets-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); margin: var(--ui-content-gap) 0; }
.assets-filters > div { display: grid; gap: var(--space-2); min-width: 0; }
.assets-filters label { font-size: 0.875rem; font-weight: 600; }
.assets-filters input { min-width: 0; width: 100%; }
select { font: inherit; padding: var(--space-2); border: 1px solid var(--theme-border, var(--theme-border, #c1d3c8)); border-radius: 0.4rem; background: var(--theme-surface, var(--theme-surface, white)); color: var(--theme-text, inherit); min-width: 0; max-width: 100%; }
.asset-form, .asset-history { border: 1px solid var(--theme-border, var(--theme-border, #dbe7df)); border-radius: 0.75rem; padding: var(--space-5); margin: var(--ui-content-gap) 0; }
.asset-form h3 { margin-top: 0; }
.asset-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 var(--ui-grid-gap); }
.asset-form-actions, .asset-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }
dl { display: grid; gap: var(--space-1); margin: 0; }
dt { font-size: 0.75rem; opacity: 0.7; }
dd { margin: 0 0 var(--space-2); overflow-wrap: anywhere; }
.asset-history li { padding: var(--space-2) 0; overflow-wrap: anywhere; }
.asset-history time { font-size: 0.85rem; opacity: 0.75; }
.assets-pagination { display: flex; justify-content: space-between; align-items: center; gap: var(--space-3); flex-wrap: wrap; margin-top: var(--ui-content-gap); font-size: 0.875rem; }
@media (max-width: 600px) { .asset-form-grid { grid-template-columns: 1fr; } .assets-filters > div { flex: 1 1 100%; } .asset-form, .asset-history { padding: var(--space-3); } .assets-card { padding: 0; } }
.assignment-history-list { display: grid; gap: var(--ui-content-gap); padding: 0; margin: 0; list-style: none; min-width: 0; }
.assignment-history-list > li { display: grid; gap: var(--ui-content-gap); padding: var(--ui-card-inset); min-width: 0; border-radius: .65rem; background: var(--theme-subtle); }
.assignment-history-date { display: flex; align-items: center; gap: var(--ui-icon-gap); color: var(--theme-muted); font-size: .78rem; line-height: 1.5; }
.assignment-history-date > svg { width: 1rem; height: 1rem; flex: 0 0 auto; }
</style>
