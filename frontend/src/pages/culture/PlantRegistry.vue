<script setup lang="ts">
import { seedStockEmpty, type PlantStrainChoice } from '../../lib/strain-stock';
import LocationTrail from '../../components/LocationTrail.vue';
import { useFeedback } from '../../lib/feedback';
import AppSwitch from '../../components/AppSwitch.vue';
import StrainReferenceTag from '../../components/StrainReferenceTag.vue';
import RecordQrButton from '../../components/RecordQrButton.vue';
import AppTabs from '../../components/AppTabs.vue';
import { NTabPane } from 'naive-ui';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import CollectionLayout from '../../components/CollectionLayout.vue';
import { registerModuleMessages } from '../../lib/locale-messages';
import PlantHardwareIdentity from '../../components/PlantHardwareIdentity.vue';
import PumpControl from '../../components/PumpControl.vue';
import PlantInventoryFields from '../../components/PlantInventoryFields.vue';
import PlantActionTabs from '../../components/PlantActionTabs.vue';
import { emptyInventoryDraft, stockCommentContext, fertilizerPayload, substratePayload, containerPayload } from '../../lib/plant-inventory';
import PlantBatchActions from '../../components/PlantBatchActions.vue';
import { useFieldErrors } from '../../lib/field-errors';
import { hasCareDraft, plantActionTab, usePlantCareValidation } from '../../lib/plant-care-form';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import ConfirmRecordDeletion from '../../components/ConfirmRecordDeletion.vue';
import { photosApi } from '../../api/photos';
import { useRouter } from 'vue-router';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import PageTitle from '../../components/PageTitle.vue';
import { phaseKeys as plantPhases, phaseLabel, phaseDefinition, actionLabel, actionDefinition } from '../../lib/lifecycle';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import { usePlantAge } from '../../lib/plant-age';
import { locationLabel } from '../../lib/location';
import NotesEditor from '../../components/NotesEditor.vue';
import RichNotes from '../../components/RichNotes.vue';
import { builtInMaintenanceTypes, canRecordMaintenance, maintenanceLabel } from '../../lib/plant-maintenance';
import PlantPhotoCard from '../../components/PlantPhotoCard.vue';
import PlantTable from '../../components/PlantTable.vue';
import PlantPhaseIcon from '../../components/PlantPhaseIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { plantActionIcon } from '../../lib/plant-action-icon';
import StrainGeneticsTag from '../../components/StrainGeneticsTag.vue';
import { formatDateTime } from '../../lib/date-time';
import PlantExpectedActions from '../../components/PlantExpectedActions.vue';
import PlantActionFields from '../../components/PlantActionFields.vue';
import CommentField from '../../components/CommentField.vue';
import FormDialog from '../../components/FormDialog.vue';
import DetailDialog from '../../components/DetailDialog.vue';
import AppIcon from '../../components/AppIcon.vue';
import PhotoGallery from '../../components/PhotoGallery.vue';
import RecordPhotoActions from '../../components/RecordPhotoActions.vue';
import PlantChannelSelection from '../../components/PlantChannelSelection.vue';
import PlantReadings from '../../components/PlantReadings.vue';
import PlantStrainSummary from '../../components/PlantStrainSummary.vue';
import PlantTimeline from '../../components/PlantTimeline.vue';
import '../../styles/record-details.css';
import { watch, useId, computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import PlantActionAttachments from '../../components/PlantActionAttachments.vue';
import PlantFormSelectors from '../../components/PlantFormSelectors.vue';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
import { documentsApi } from '../../api/documents';
import documentEn from '../../locales/en/documents.json';
import documentFr from '../../locales/fr/documents.json';
import { ApiError } from '../../api/auth';
import { queryString, useRecordQuery } from '../../lib/route-query';
import { locationsApi } from '../../api/locations';
import type { LocationContext } from '../../api/types';
import { assetsApi, type Asset } from '../../api/assets';
import type { Strain } from '../../api/strains';
import { plantsApi, type Plant, type PlantEvent, type PlantInput, type PlantAttachment, type MaintenanceType } from '../../api/plants';
import en from '../../locales/en/plants.json';
import fr from '../../locales/fr/plants.json';
import catalogEn from '../../locales/en/catalog.json';
import catalogFr from '../../locales/fr/catalog.json';
const pageSize = useCollectionPageSize('plants');
const vRememberDisclosure = useRememberDisclosure('PlantRegistry');
const sort = useCollectionSort('plants', sortOptions.plants);
const props = defineProps<{ embedded?: boolean; canManage: boolean; detailOnly?: boolean; initialPlantId?: string | null; initialAction?: 'maintenance' | 'edit' | 'create' | null; initialLocation?: LocationContext | null; initialStrain?: Pick<Strain, 'id' | 'name'> | null }>();
const emit = defineEmits<{ close: []; changed: []; created: [plant: Plant]; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
const feedback = useFeedback();
registerModuleMessages(mergeLocaleMessage, { en: { plants: en, catalog: catalogEn, documents: documentEn }, fr: { plants: fr, catalog: catalogFr, documents: documentFr } });
const batchSelection = ref<string[]>([]);
function toggleBatch(id: string, checked: boolean) { batchSelection.value = checked ? [...new Set([...batchSelection.value, id])] : batchSelection.value.filter(value => value !== id); }
const actionOnly = computed(() => props.detailOnly && !!props.initialAction);
async function closeEdit() { if (!await unsaved.confirmDiscard()) return; formOpen.value = false; if (actionOnly.value) emit('close'); }
async function closeMaintenance() { if (!await unsaved.confirmDiscard()) return; closeEntry(); if (actionOnly.value) emit('close'); }
async function loadQuickAction() {
 if (props.initialAction === 'create') {
  if (props.canManage) edit(); else error.value = 'plants.errors.actionUnavailable';
  return;
 }
 if (!props.initialPlantId || !props.initialAction) return;
 const current = ++sequence; loading.value = true; error.value = '';
 formOpen.value = false; maintenanceFor.value = null;
 try {
  const plant = await plantsApi.get(props.initialPlantId);
  if (!alive || current !== sequence) return;
  loading.value = false;
  if (!plant.canManage || (props.initialAction === 'maintenance' && !canRecordMaintenance(plant.phase))) { error.value = 'plants.errors.actionUnavailable'; return; }
  if (props.initialAction === 'edit') edit(plant); else openMaintenance(plant);
 } catch (cause) { if (alive && current === sequence) failure(cause); }
 finally { if (current === sequence) loading.value = false; }
}
const recordRoute = useRecordQuery('plant'); const router = useRouter();
const listLocation = ref<LocationContext | null>(null);
const rows = ref<Plant[]>([]); const search = ref(''); const phase = ref('');
const status = ref('active');
const page = ref(1); const lastPage = ref(1); const total = ref(0); const loading = ref(false); const saving = ref(false);
const error = ref(''); const notice = ref(''); const formOpen = ref(false); const editing = ref<Plant | null>(null);
const detailOpen = ref(false); const detailId = ref('');
const deleting = ref(false);
const selected = ref<Plant | null>(null); const events = ref<PlantEvent[]>([]); const historyPage = ref(1); const historyLast = ref(1);
const hardwareReadingCounts = computed(() => {
 const counts = new Map<string, number>();
 for (const reading of selected.value?.measurements ?? []) {
  if (reading.value !== null) counts.set(reading.device.id, (counts.get(reading.device.id) ?? 0) + 1);
 }
 return counts;
});
const selectedDurations = computed(() => {
 const order = new Map(plantPhases.value.map((key, index) => [key, index]));
 order.set('flush', order.size + 1);
 return Object.entries(selected.value?.durationSnapshot.durations ?? {})
  .sort(([a], [b]) => (order.get(a) ?? plantPhases.value.length) - (order.get(b) ?? plantPhases.value.length));
});
const { label: selectedAge } = usePlantAge(() => selected.value);
const location = ref<LocationContext | null>(null); const strain = ref<PlantStrainChoice | null>(null);
const summarySensorIds = ref<string[] | null>(null);
const linkedDevices = ref<Plant['devices']>([]); const form = reactive({ name: '', notes: '', strainChangeReason: '', startedAt: '', planned: false });
const actionTab = ref('action');
const actionFor = ref<Plant | null>(null); const actionForm = reactive({ comment: '', occurredAt: '', harvestWeight: '' as string | number });
const wateringFor = ref<Plant | null>(null); const wateringForm = reactive({ choice: '', comment: '', occurredAt: '' });
const actionInventoryDraft = ref(emptyInventoryDraft());
const actionInventoryFields = ref<InstanceType<typeof PlantInventoryFields> | null>(null);
let wateringId = '';
const fertilizerDraft = ref(emptyInventoryDraft());
const fertilizerFields = ref<InstanceType<typeof PlantInventoryFields> | null>(null);
const maintenanceFor = ref<Plant | null>(null); const maintenanceForm = reactive({ type: '' as MaintenanceType | '', customType: '', comment: '', occurredAt: '' }); let maintenanceId = '';
const attachmentFiles = ref<PlantAttachment[]>([]); const attachmentBusy = ref(false); const attachmentPending = ref(false);
const busy = computed(() => saving.value || attachmentBusy.value);
const editorTab = ref('general');
const recordValidation = useFieldErrors(() => ({
 name: { id: 'plant-name', message: 'plants.validation.name' },
 strain_id: { id: 'plant-strain-select', message: 'plants.validation.strain' },
 strain_change_reason: { id: 'plant-strain-change-reason', message: 'plants.validation.strainChangeReason' },
 location: { id: 'plant-location-field', message: 'plants.validation.location' },
 device_ids: { id: 'plant-devices-field', message: 'plants.errors.deviceUnavailable' },
 summary_sensor_ids: { id: 'plant-summary-channels', message: 'plants.validation.channels' },
 started_at: { id: 'plant-started', message: 'plants.errors.timeInvalid' },
 notes: { id: 'plant-notes', message: 'plants.validation.notes' },
}), () => ({ name: form.name, strain_id: strain.value?.id, strain_change_reason: form.strainChangeReason, location: location.value ? `${location.value.type}:${location.value.id}` : null,
 device_ids: linkedDevices.value.map(row => row.id), summary_sensor_ids: summarySensorIds.value,
 started_at: form.startedAt, notes: form.notes }));
const strainChanged = computed(() => !!editing.value && !!strain.value && strain.value.id !== editing.value.strain.id);
const editBaseline = ref('');
function editSnapshot() {
 return JSON.stringify([form, strain.value?.id ?? null, location.value ? [location.value.type, location.value.id] : null, linkedDevices.value.map(device => device.id).sort(), summarySensorIds.value?.slice().sort() ?? null]);
}
const editDirty = computed(() => formOpen.value && editSnapshot() !== editBaseline.value);
const activeCareForm = computed(() => actionFor.value ? actionForm : wateringFor.value ? wateringForm : maintenanceFor.value ? { ...maintenanceForm, customType: maintenanceForm.type === '__new__' ? maintenanceForm.customType : '' } : null);
const inventoryChanged = ref(false);
const careDirty = computed(() => !!activeCareForm.value && (hasCareDraft(activeCareForm.value, attachmentFiles.value) || inventoryChanged.value || busy.value || attachmentPending.value));
const dirty = computed(() => editDirty.value || careDirty.value);
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { formOpen.value = false; actionFor.value = null; wateringFor.value = null; maintenanceFor.value = null; if (props.detailOnly && props.initialAction) emit('close'); } });
function clearAttachments() { attachmentFiles.value = []; attachmentPending.value = false; }
const savedMaintenanceTypes = ref<string[]>([]);
const chosenMaintenanceType = computed(() => (maintenanceForm.type === '__new__' ? maintenanceForm.customType : maintenanceForm.type.startsWith('saved:') ? maintenanceForm.type.slice(6) : maintenanceForm.type).trim());
const careValidation = usePlantCareValidation(() => ({
 ...(actionFor.value ? actionForm : wateringFor.value ? wateringForm : { ...maintenanceForm, type: chosenMaintenanceType.value }),
 documentIds: attachmentFiles.value.map(row => row.id),
}), () => ({
 occurred_at: `plant-${actionFor.value ? 'action' : wateringFor.value ? 'watering' : 'maintenance'}-date`,
 ...(actionFor.value ? { harvest_weight_grams: 'plant-harvest-weight' } : wateringFor.value ? { with_products: 'plant-watering-choice' } : { type: maintenanceForm.type === '__new__' ? 'plant-maintenance-custom-type' : 'plant-maintenance-type' }),
 comment: `plant-${actionFor.value ? 'action' : wateringFor.value ? 'watering' : 'maintenance'}-comment`, document_ids: 'plant-care-attachments',
}), () => !!actionFor.value?.nextAction && !!actionDefinition(actionFor.value.nextAction)?.requires_harvest_weight, field => { actionTab.value = plantActionTab(field); });
async function closeCare() { if (await unsaved.confirmDiscard()) closeEntry(); }
function closeEntry() { if (busy.value) return; actionFor.value = null; wateringFor.value = null; maintenanceFor.value = null; clearAttachments(); }
let alive = true;
let requestId = ''; let actionId = ''; let sequence = 0; let interval: ReturnType<typeof setInterval> | undefined;
const deviceOptions = new Map<string, Asset>();
const uploadedPhoto = ref(false);
function locationText(value: LocationContext | null) { return locationLabel(value) || t('plants.notSelected'); }
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('plants.indefinite'); }
function revealRecordErrors() {
 const tabs = Object.keys(recordValidation.fields.value).map(field => field === 'notes' ? 'notes' : ['device_ids', 'summary_sensor_ids'].includes(field) ? 'hardware' : 'general');
 const first = (['general', 'hardware', 'notes'] as const).find(tab => tabs.includes(tab));
 if (first) editorTab.value = first;
}
function failure(cause: unknown) {
 if (formOpen.value) {
  recordValidation.capture(cause); revealRecordErrors();
  if (cause instanceof ApiError) {
   const field = { 'plants.errors.timeInvalid': 'started_at', 'plants.errors.deviceUnavailable': 'device_ids', 'plants.errors.strainUnavailable': 'strain_id' }[cause.code];
   if (field) { recordValidation.set(field); revealRecordErrors(); void recordValidation.focus(); }
  }
 }
 if (activeCareForm.value) careValidation.capture(cause);
 const code = cause instanceof ApiError ? Object.values(cause.fields).flat()[0] ?? cause.code : '';
 error.value = /^(plants|auth|documents|inventory)\.(errors|validation|stock|containers)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && cause.status === 422 && cause.code === 'auth.errors.validationFailed') { const code = cause.fields.file?.[0]; error.value = code && /^documents\.(errors|validation|stock|containers)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'plants.validation.invalid'; }
 if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) { rows.value = []; detailOpen.value = false; selected.value = null; events.value = []; formOpen.value = false; actionFor.value = null; wateringFor.value = null; maintenanceFor.value = null; clearAttachments(); emit('accessLost', cause); }
}
async function load(target = 1, preserveRows = false) {
 if (props.detailOnly) return;
 if (busy.value || formOpen.value || activeCareForm.value) return;
 const current = ++sequence; closeEntry(); loading.value = true; error.value = ''; if (!preserveRows) rows.value = [];
 try { let result = await plantsApi.list(search.value.trim(), target, status.value, phase.value, sort.value, pageSize.value, listLocation.value); if (current !== sequence) return; if (target > result.meta.last_page) result = await plantsApi.list(search.value.trim(), Math.max(1, result.meta.last_page), status.value, phase.value, sort.value, pageSize.value, listLocation.value); if (current === sequence) { rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total; } }
 catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) loading.value = false; }
}
async function reload() { if (!await unsaved.confirmDiscard()) return; if (actionOnly.value) { await loadQuickAction(); return; } const target = selected.value?.id ?? props.initialPlantId; detailOpen.value = false; maintenanceFor.value = null; wateringFor.value = null; selected.value = null; events.value = []; actionFor.value = null; formOpen.value = false; if (props.detailOnly && target) await detail({ id: target }); else await load(page.value); }
async function edit(plant: Plant | null = null) {
 if (busy.value || loading.value || !await unsaved.confirmDiscard()) return;
 closeEntry(); editing.value = plant; strain.value = plant?.strain ?? props.initialStrain ?? null; location.value = plant?.location ?? props.initialLocation ?? listLocation.value ?? null; linkedDevices.value = [...(plant?.devices ?? [])]; summarySensorIds.value = plant?.summarySensorIds ? [...plant.summarySensorIds] : null;
 Object.assign(form, { name: plant?.givenName ?? '', notes: plant?.notes ?? '', strainChangeReason: '', startedAt: '', planned: false });
 recordValidation.clear(); editorTab.value = 'general'; editBaseline.value = editSnapshot(); requestId = crypto.randomUUID(); formOpen.value = true; actionFor.value = null; if (!plant) { selected.value = null; detailOpen.value = false; } error.value = ''; notice.value = '';
}
async function fetchDevices(query: string, target: number) {
 const result = await assetsApi.list(query, '', '', target); for (const row of result.data) deviceOptions.set(row.id, row);
 return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: row.locations.map(locationText).join(' · '), disabled: row.status === 'retired' })) };
}
function chooseDevice(id: string) {
 const row = deviceOptions.get(id);
 if (row && !linkedDevices.value.some(device => device.id === id)) {
  linkedDevices.value.push({ id, name: row.name, category: row.category, status: row.status });
 }
}
function iso(value: string) { return value ? new Date(value).toISOString() : null; }
async function save() {
 if (busy.value || loading.value) return;
 error.value = ''; notice.value = '';
 recordValidation.clear();
 if (form.name.length > 120) recordValidation.set('name');
 if (!strain.value) recordValidation.set('strain_id');
 if (!editing.value && seedStockEmpty(strain.value)) recordValidation.set('strain_id', 'plants.errors.seedStockEmpty');
 if (strainChanged.value && (!form.strainChangeReason.trim() || form.strainChangeReason.length > 2000)) recordValidation.set('strain_change_reason');
 if (!location.value) recordValidation.set('location');
 if (form.notes.length > 2000) recordValidation.set('notes');
 if (!editing.value && !form.planned && form.startedAt) { const date = new Date(form.startedAt); if (!Number.isFinite(date.getTime()) || date.getUTCFullYear() < 2000 || date.getTime() > Date.now()) recordValidation.set('started_at'); }
 if (Object.keys(recordValidation.fields.value).length) { error.value = 'plants.validation.invalid'; revealRecordErrors(); void recordValidation.focus(); return; }
 if (!location.value || !strain.value) return;
 saving.value = true; const current = sequence;
 try {
  const input: PlantInput = { name: form.name.trim() || null, notes: form.notes || null, location: { type: location.value.type, id: location.value.id }, device_ids: linkedDevices.value.map(device => device.id), summary_sensor_ids: summarySensorIds.value };
  if (editing.value) { input.version = editing.value.version; if (strainChanged.value) { input.strain_id = strain.value.id; input.strain_change_reason = form.strainChangeReason.trim(); } } else { input.request_id = requestId; input.strain_id = strain.value.id; input.planned = form.planned; input.started_at = form.planned ? null : iso(form.startedAt); }
  const result = editing.value ? await plantsApi.update(editing.value.id, input) : await plantsApi.create(input);
  if (current !== sequence) return;
  if (actionOnly.value) { if (editing.value) feedback.success(t('plants.updated')); if (!editing.value) emit('created', result); emit('changed'); emit('close'); return; }
  formOpen.value = false; saving.value = false;
  if (!editing.value) { status.value = result.phase === 'planned' ? 'planned' : 'active'; phase.value = ''; }
  notice.value = editing.value ? 'plants.updated' : 'plants.created'; await load(editing.value ? page.value : 1);
  if (detailOpen.value) await detail(result);
  emit('changed');
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { saving.value = false; }
}
async function detail(plant: Pick<Plant, 'id'>, target = historyPage.value, syncRoute = true) {
 if (busy.value) return;
 detailId.value = plant.id;
 if (syncRoute && !props.detailOnly) void recordRoute.open(plant.id);
 const current = ++sequence; closeEntry(); loading.value = true; detailOpen.value = true; error.value = ''; if (selected.value?.id !== plant.id) { events.value = []; selected.value = null; } formOpen.value = false;
 try {
  const [record, history] = await Promise.all([plantsApi.get(plant.id), plantsApi.events(plant.id, target)]);
  if (current !== sequence) return;
  selected.value = record; events.value = history.data; historyPage.value = history.meta.current_page; historyLast.value = history.meta.last_page;
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) loading.value = false; }
}
function closeDetail(syncRoute = true) {
 if (busy.value) return;
 deleting.value = false;
 const id = selected.value?.id;
 formOpen.value = false; detailId.value = ''; sequence++; loading.value = false; detailOpen.value = false; selected.value = null; events.value = []; error.value = ''; notice.value = ''; closeEntry();
 if (syncRoute && !props.detailOnly) void recordRoute.close();
 if (props.detailOnly) emit('close');
 else if (id) void nextTick(() => document.querySelector<HTMLButtonElement>(`[data-testid="plant-row-${id}"] button`)?.focus());
}
async function openAction(plant: Plant, action = plant.nextAction) {
 if (!await unsaved.confirmDiscard()) return; careValidation.clear();
 closeEntry(); clearAttachments(); actionTab.value = 'action'; actionFor.value = { ...plant, nextAction: action }; actionId = crypto.randomUUID(); inventoryChanged.value = false; actionInventoryDraft.value = emptyInventoryDraft(); Object.assign(actionForm, { comment: '', occurredAt: '', harvestWeight: '' }); error.value = ''; notice.value = ''; formOpen.value = false;
}
async function recordAction() {
 if (attachmentPending.value) return;
 if (actionInventoryFields.value && !actionInventoryFields.value.validate()) { actionTab.value = 'inventory'; return; }
 const plant = actionFor.value; if (!plant?.nextAction || busy.value || loading.value) return;

 if (!careValidation.validate()) { error.value = 'plants.validation.invalid'; return; }
 const current = sequence; saving.value = true; error.value = '';
 try {
  const result = await plantsApi.action(plant, plant.nextAction, actionId, actionForm.comment || null, iso(actionForm.occurredAt), attachmentFiles.value.map(row => row.id), actionDefinition(plant.nextAction)?.requires_harvest_weight ? actionForm.harvestWeight : undefined, substratePayload(actionInventoryDraft.value), containerPayload(actionInventoryDraft.value));
  if (current !== sequence) return;
  actionFor.value = null; saving.value = false; notice.value = 'plants.actionSaved';
  const started = plant.phase === 'planned' && status.value === 'planned';
  if (started) { status.value = 'active'; phase.value = ''; }
  await load(started ? 1 : page.value); if (detailOpen.value) await detail(result); emit('changed');
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { saving.value = false; }
}
async function openWatering(plant: Plant) {
 if (!await unsaved.confirmDiscard()) return; careValidation.clear();
 closeEntry(); clearAttachments(); actionTab.value = 'action'; wateringFor.value = { ...plant }; wateringId = crypto.randomUUID(); actionFor.value = null;
 fertilizerDraft.value = emptyInventoryDraft();
 Object.assign(wateringForm, { choice: '', comment: '', occurredAt: '' }); error.value = ''; notice.value = '';
}
async function recordWatering() {
 if (attachmentPending.value) return;
 const plant = wateringFor.value; if (!plant || busy.value || loading.value) return;
 if (!fertilizerFields.value?.validate()) { actionTab.value = 'action'; return; }
 if (!careValidation.validate()) { error.value = 'plants.validation.invalid'; return; }
 const current = sequence; saving.value = true; error.value = '';
 try {
  const result = await plantsApi.water(plant, wateringForm.choice === 'with', wateringId, wateringForm.comment || null, iso(wateringForm.occurredAt), attachmentFiles.value.map(row => row.id), fertilizerPayload(fertilizerDraft.value, wateringForm.choice === 'with'));
  if (current !== sequence) return;
  wateringFor.value = null; saving.value = false; notice.value = 'plants.watering.saved';
  await load(page.value); if (detailOpen.value) await detail(result); emit('changed');
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { saving.value = false; }
}
async function openMaintenance(plant: Plant) {
 if (!plant.canManage || !canRecordMaintenance(plant.phase) || !await unsaved.confirmDiscard()) return; careValidation.clear();
 closeEntry(); clearAttachments(); actionTab.value = 'action'; maintenanceFor.value = { ...plant }; maintenanceId = crypto.randomUUID();
 Object.assign(maintenanceForm, { type: '', customType: '', comment: '', occurredAt: '' }); error.value = ''; notice.value = '';
 const target = maintenanceFor.value;
 try { const result = await plantsApi.maintenanceTypes(); if (maintenanceFor.value === target) savedMaintenanceTypes.value = result; }
 catch (cause) { if (maintenanceFor.value === target) failure(cause); }
}
async function recordMaintenance() {
 if (attachmentPending.value) return;
 const plant = maintenanceFor.value; if (!plant || busy.value || loading.value) return;
 if (!careValidation.validate()) { error.value = 'plants.validation.invalid'; return; }
 const current = sequence; saving.value = true; error.value = '';
 try {
  const result = await plantsApi.maintain(plant, chosenMaintenanceType.value, maintenanceId, maintenanceForm.comment || null, iso(maintenanceForm.occurredAt), attachmentFiles.value.map(row => row.id));
  if (current !== sequence) return;
  if (actionOnly.value) { feedback.success(t('plants.maintenance.saved')); maintenanceFor.value = null; clearAttachments(); saving.value = false; emit('changed'); emit('close'); return; }
  maintenanceFor.value = null; saving.value = false; clearAttachments(); notice.value = 'plants.maintenance.saved';
  await load(page.value); if (detailOpen.value) await detail(result); emit('changed');
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { saving.value = false; }
}
async function downloadAttachment(document: PlantAttachment) {
 try { await documentsApi.download(document); } catch (cause) { failure(cause); }
}
async function setPhoto(id: string | null) {
 if (!selected.value || busy.value || loading.value) return;
 const target = selected.value; const current = sequence; saving.value = true; error.value = ''; notice.value = '';
 try {
  const result = await plantsApi.photo(target, id); if (current !== sequence) return;
  rows.value = rows.value.map(row => row.id === result.id ? result : row);
  saving.value = false; uploadedPhoto.value = false; notice.value = 'plants.photoSaved'; if (detailOpen.value) await detail(result); emit('changed');
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { saving.value = false; }
}
async function uploadPhoto(file: File, disk?: string): Promise<boolean> {
 if (!file || !selected.value || busy.value || loading.value) return false;
 const target = selected.value; const current = sequence; saving.value = true; error.value = ''; notice.value = ''; uploadedPhoto.value = false;
 try {
  const document = await photosApi.upload(file, target.name, target.location, disk); if (current !== sequence) return false;
  uploadedPhoto.value = true;
  const result = await plantsApi.photo(target, document.id); if (current !== sequence) return false;
  rows.value = rows.value.map(row => row.id === result.id ? result : row);
  saving.value = false; uploadedPhoto.value = false; notice.value = 'plants.photoSaved'; if (detailOpen.value) await detail(result); emit('changed'); return true;
 } catch (cause) { if (current === sequence) failure(cause); return false; }
 finally { saving.value = false; }
}
async function deleteRecord() {
 if (!selected.value || busy.value) return;
 const target = selected.value; saving.value = true; error.value = '';
 try {
  await plantsApi.remove(target);
  if (!alive) return;
  feedback.success(t('plants.deleted'));
  saving.value = false; deleting.value = false; closeDetail(); emit('changed'); if (!props.detailOnly) await load(rows.value.length === 1 ? Math.max(1, page.value - 1) : page.value);
  notice.value = '';
 } catch (cause) { if (alive) failure(cause); }
 finally { saving.value = false; }
}
onMounted(() => { void (async () => {
 if (actionOnly.value) { await loadQuickAction(); return; }
 if (!props.detailOnly) {
  const id = queryString(recordRoute.route.query.location); const type = queryString(recordRoute.route.query.locationType);
  if (id || type) {
   try { listLocation.value = (await locationsApi.context(type, id)).context; }
   catch (cause) { failure(cause); return; }
   if (!alive) return;
   if (!listLocation.value) { error.value = 'plants.validation.invalid'; return; }
  }
  await load(page.value);
 }
 const initialId = props.initialPlantId || (!props.detailOnly && queryString(recordRoute.route.query.plant));
 if (alive && initialId) await detail({ id: initialId }, historyPage.value, false);
})(); interval = setInterval(() => { if (!document.hidden && !loading.value && !busy.value && !maintenanceFor.value && !formOpen.value && !actionFor.value && !wateringFor.value) { if (deleting.value) return; if (selected.value) void detail(selected.value, historyPage.value); else if (!props.detailOnly) void load(page.value); } }, 60_000); });
watch(() => recordRoute.route.query.plant, value => {
 if (props.detailOnly) return;
 const id = queryString(value);
 if (id && (detailId.value !== id || !detailOpen.value)) void detail({ id }, historyPage.value, false);
 else if (!id && detailOpen.value) closeDetail(false);
});
onUnmounted(() => { alive = false; sequence++; if (interval) clearInterval(interval); });
const dialogId = useId();
function resetFilters() {
 search.value = ''; status.value = 'active'; phase.value = ''; sort.value = 'name_asc'; listLocation.value = null;
 void router.replace({ path: recordRoute.route.path, query: { ...recordRoute.route.query, q: '', phase: '', sort: 'name_asc', status: 'active', page: '1', location: undefined, locationType: undefined } });
}
const collectionQuery = useCollectionQuery({ paths: ['/plants'], enabled: () => !props.detailOnly, fields: { q: queryField.text(search), status: queryField.choice(status, ['active', 'planned', 'archived', 'all']), phase: queryField.text(phase, 64), sort: queryField.choice(sort, sortOptions.plants) }, page, pageSize, load: target => { selected.value = null; return load(target); } });
const applyFilters = collectionQuery.apply;
const historyQuery = useCollectionQuery({ prefix: 'plantHistory.', fields: {}, page: historyPage, enabled: () => !props.initialAction, load: target => selected.value && detail(selected.value, target, false) });


const selectionHeader = ref<HTMLElement | null>(null);
</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <ConfirmRecordDeletion v-if="deleting && selected" :name="selected.name" :message="t('plants.deleteHelp')" :busy="busy" :error="error ? t(error) : ''" @cancel="deleting = false; error = ''" @confirm="deleteRecord" />
 <Card class="plant-card" :class="{ 'detail-only': detailOnly }"><template v-if="!detailOnly" #header><div class="card-heading-block"><div class="page-heading"><div class="plant-heading"><PageTitle icon="leaf">{{ t('plants.title') }}</PageTitle></div><div v-if="!detailOnly" ref="selectionHeader" class="page-actions"><Button v-if="!embedded" :aria-label="t('plants.back')" type="default" :disabled="busy" @click="emit('close')" :title="t('plants.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" v-if="!formOpen && !actionFor && !wateringFor && !maintenanceFor" :label="t('plants.reload')" severity="secondary" :disabled="busy || loading" @click="reload" /><CollectionViewToggle preference-key="plants" :disabled="busy || loading" /><Button v-if="props.canManage" :aria-label="t('plants.add')" :disabled="busy || loading" @click="edit()" data-action-icon="plus" :title="t('plants.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('plants.description') }}</div></div></template>

  <template #default><Message v-if="error && !actionOnly && !detailOpen && !(formOpen || actionFor || wateringFor || maintenanceFor)" closable type="error" role="alert">{{ t(error) }}</Message><Message v-if="uploadedPhoto && !detailOpen" closable type="info">{{ t('plants.photoUploadRetained') }}</Message><Message v-if="notice && !detailOpen" closable type="success" role="status">{{ t(notice) }}</Message>

   <p v-if="!detailOnly && listLocation" class="plant-location-scope"><AppIcon name="location" /><span>{{ [listLocation.site.name, listLocation.room?.name, listLocation.zone?.name].filter(Boolean).join(' / ') }}</span></p>
   <form v-if="!detailOnly" class="plant-toolbar search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="field filter-field"><label for="plant-search">{{ t('plants.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="busy" :input-props="{ 'id': 'plant-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.plants" /></div>
    <div class="filter-secondary"><div class="field filter-field"><label for="plant-status">{{ t('plants.status') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="plant-status" v-model="status" :disabled="busy"><option value="active">{{ t('plants.active') }}</option><option value="planned">{{ t('plants.phases.planned') }}</option><option value="archived">{{ t('plants.archived') }}</option><option value="all">{{ t('plants.all') }}</option></select></div>
     <div class="field filter-field"><label for="plant-phase">{{ t('plants.phase') }}</label><div class="phase-select"><PlantPhaseIcon v-if="phase" :phase="phase" /><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="plant-phase" v-model="phase" :disabled="busy"><option value="">{{ t('plants.allPhases') }}</option><option v-for="value in plantPhases" :key="value" :value="value">{{ phaseLabel(value, t) }}</option></select></div></div></div>
    <ResetFilters @reset="resetFilters" /></form>
   <FormDialog v-if="actionOnly && !formOpen && !maintenanceFor" size="compact" :icon="initialAction === 'create' ? 'leaf' : initialAction === 'edit' ? 'edit' : 'scissors'" :visible="true" :title="t(initialAction === 'create' ? 'plants.createTitle' : initialAction === 'edit' ? 'plants.editTitle' : 'plants.maintenance.record')" :close-label="t('plants.cancel')" :error="error ? t(error) : ''" @update:visible="!$event && emit('close')"><p v-if="loading" role="status">{{ t('plants.loading') }}</p><Button v-else-if="error" :aria-label="t('plants.reload')" @click="loadQuickAction" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></FormDialog>
   <FormDialog icon="leaf" v-if="formOpen" :visible="true" :title="t(editing ? 'plants.editTitle' : 'plants.createTitle')" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeEdit()"><template #feedback><Button v-if="error === 'plants.errors.conflict'" :aria-label="t('plants.reload')" type="default" :disabled="busy || loading" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form plant-panel" @submit.prevent="save" novalidate>
 <AppTabs v-model:value="editorTab" class="plant-editor-tabs" :aria-label="t('plants.editorTabs.label')">
  <NTabPane name="general" display-directive="show"><template #tab><AppIcon name="leaf" />{{ t('plants.editorTabs.general') }}</template><div class="plant-editor-panel">
<fieldset class="dialog-section"><legend>{{ t('modalSections.identity') }}</legend>
<div class="dialog-form-grid"><div class="field dialog-wide"><label for="plant-name">{{ t('plants.name') }}</label><InputText v-bind="recordValidation.attrs('name')" :status="!!recordValidation.fields.value.name ? 'error' : undefined" v-model:value="form.name" maxlength="120" :disabled="busy" :placeholder="t('plants.namePlaceholder')" :input-props="{ 'id': 'plant-name' }"></InputText><small>{{ t('plants.nameHelp') }}</small><small v-if="recordValidation.fields.value.name" id="plant-name-error" class="field-error" role="alert">{{ t(recordValidation.fields.value.name) }}</small></div>
<PlantFormSelectors :creating="!editing" :strain-error="recordValidation.fields.value.strain_id" :location-error="recordValidation.fields.value.location" v-model:strain="strain" v-model:location="location" :disabled="busy" @access-lost="failure" />
<small v-if="!editing && seedStockEmpty(strain)" class="field-error dialog-wide" role="alert">{{ t('plants.errors.seedStockEmpty') }}</small><div v-if="strainChanged" class="field dialog-wide"><label for="plant-strain-change-reason">{{ t('plants.strainChangeReason') }}</label><textarea id="plant-strain-change-reason" v-bind="recordValidation.attrs('strain_change_reason')" v-model="form.strainChangeReason" rows="3" maxlength="2000" :disabled="busy" required /><small>{{ t('plants.strainChangeHelp') }}</small><small v-if="recordValidation.fields.value.strain_change_reason" id="plant-strain-change-reason-error" class="field-error" role="alert">{{ t(recordValidation.fields.value.strain_change_reason) }}</small></div></div></fieldset>
<div v-if="!editing" class="dialog-section"><div v-if="!editing" class="field dialog-wide"><AppSwitch v-model="form.planned" :disabled="busy">{{ t('plants.planSowing') }}</AppSwitch></div><div v-if="!editing && !form.planned" class="field"><label for="plant-started">{{ t('plants.startedAt') }}</label><input id="plant-started" v-bind="recordValidation.attrs('started_at')" v-model="form.startedAt" type="datetime-local" :disabled="busy" /><small>{{ t('plants.nowHelp') }}</small><small v-if="recordValidation.fields.value.started_at" id="plant-started-error" class="field-error" role="alert">{{ t(recordValidation.fields.value.started_at) }}</small></div></div>


  </div></NTabPane>
  <NTabPane name="hardware" display-directive="show"><template #tab><AppIcon name="device" />{{ t('plants.editorTabs.hardware') }}</template><div class="plant-editor-panel">
   <fieldset id="plant-devices-field" tabindex="-1" v-bind="recordValidation.attrs('device_ids')" class="dialog-section plant-hardware-editor">
    <legend><AppIcon name="device" />{{ t('plants.devices') }}</legend>
    <small v-if="recordValidation.fields.value.device_ids" id="plant-devices-field-error" class="field-error" role="alert">{{ t(recordValidation.fields.value.device_ids) }}</small>
    <ul v-if="linkedDevices.length" class="plant-hardware-list">
     <li v-for="device in linkedDevices" :key="device.id" class="plant-hardware-row">
      <PlantHardwareIdentity :device="device" />
      <ListActionButton icon="unlink" :label="t('plants.removeDevice', { name: device.name })" severity="secondary" :disabled="busy" @click="linkedDevices = linkedDevices.filter(row => row.id !== device.id)" />
     </li>
    </ul>
    <p v-else class="plant-hardware-empty">{{ t('plants.noDevices') }}</p>
    <ReferenceSelect id="plant-device-picker" :label="t('plants.addDevice')" :fetch-page="fetchDevices" :disabled="busy || linkedDevices.length >= 100" :exclude-ids="linkedDevices.map(row => row.id)" @select="chooseDevice" @access-lost="failure" />
   </fieldset>
<PlantChannelSelection :validation-error="recordValidation.fields.value.summary_sensor_ids" v-model="summarySensorIds" :devices="linkedDevices" :readings="editing?.measurements" :disabled="busy" />
  </div></NTabPane>
  <NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="message" />{{ t('plants.editorTabs.notes') }}</template><div class="plant-editor-panel">
<div class="field dialog-wide"><label for="plant-notes">{{ t('plants.notes') }}</label><NotesEditor :invalid="!!recordValidation.fields.value.notes" :described-by="recordValidation.fields.value.notes ? 'plant-notes-error' : undefined" id="plant-notes" v-model="form.notes" :label="t('plants.notes')" :disabled="busy" /><small v-if="recordValidation.fields.value.notes" id="plant-notes-error" class="field-error" role="alert">{{ t(recordValidation.fields.value.notes) }}</small></div>
  </div></NTabPane>
 </AppTabs>
</form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('plants.cancel')" type="default" :disabled="busy" @click="closeEdit"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('plants.save') : t('actions.create')" :loading="busy" :disabled="!editing && seedStockEmpty(strain)" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
   <FormDialog size="compact" :icon="plantActionIcon(actionFor?.nextAction)" v-if="actionFor?.nextAction" :visible="true" :title="t('plants.actionTitle', { action: actionLabel(actionFor.nextAction, t) })" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeCare()"><template #feedback><Button v-if="error === 'plants.errors.conflict'" :aria-label="t('plants.reload')" type="default" :disabled="busy || loading" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-1`" data-testid="lifecycle-form" class="dialog-form plant-panel" @submit.prevent="recordAction" novalidate><p>{{ actionFor.name }}</p>
    <PlantActionTabs v-model="actionTab">
     <template #action>
      <PlantActionFields v-model:date="actionForm.occurredAt" v-model:weight="actionForm.harvestWeight"
       :requires-weight="actionDefinition(actionFor.nextAction)?.requires_harvest_weight" :disabled="busy"
       :date-error="careValidation.fields.value.occurred_at" :weight-error="careValidation.fields.value.harvest_weight_grams" />
     </template>
     <template v-if="actionFor.nextAction.startsWith('repot') || actionDefinition(actionFor.nextAction)?.substrates?.length || actionDefinition(actionFor.nextAction)?.container_inventory_item_id" #inventory>
        <PlantInventoryFields ref="actionInventoryFields" @dirty="inventoryChanged = $event" v-model="actionInventoryDraft" :plant="actionFor" :action="actionFor.nextAction" :enabled="true" :disabled="busy" @failure="failure" />
     </template>
     <template #comment>
      <div class="field"><label for="plant-action-comment">{{ t('plants.comment') }}</label><CommentField :validation-error="careValidation.fields.value.comment" id="plant-action-comment" v-model="actionForm.comment" :plant="actionFor" :action="{ ...actionForm, id: actionFor.nextAction ?? '', inventory: stockCommentContext(actionInventoryDraft, false) }" :attachments="attachmentFiles" :disabled="busy" @failure="failure" /></div>
     </template>
     <template #attachments>
      <PlantActionAttachments :validation-error="careValidation.fields.value.document_ids" v-model="attachmentFiles" :key="actionFor.id" :location="actionFor.location" :name="actionFor.name" :disabled="busy" @busy="attachmentBusy = $event" @pending="attachmentPending = $event" @failure="failure" />
     </template>
    </PlantActionTabs>
   </form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('actions.cancel')" type="default" :disabled="busy" @click="closeCare"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-1`" attr-type="submit" :aria-label="t('plants.confirmAction')" :disabled="attachmentPending || busy" :loading="busy" type="primary"><template #icon><AppIcon name="check" /></template><span>{{ t('actions.record') }}</span></Button></div></template></FormDialog>
   <FormDialog size="compact" icon="water" v-if="wateringFor" :visible="true" :title="t('plants.watering.forPlant', { name: wateringFor.name })" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeCare()"><template #feedback><Button v-if="error === 'plants.errors.conflict'" :aria-label="t('plants.reload')" type="default" :disabled="busy || loading" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-2`" data-testid="watering-form" class="dialog-form plant-panel" @submit.prevent="recordWatering" novalidate>
    <PlantActionTabs v-model="actionTab">
     <template #action>
      <div class="dialog-form">
      <Message v-if="wateringFor.watering.status === 'flush'" type="warning" closable>{{ t('plants.watering.flushHelp') }}</Message>
    <div class="dialog-form-grid"><div class="field"><label for="plant-watering-choice">{{ t('plants.watering.choice') }}</label><select id="plant-watering-choice" v-bind="careValidation.attrs('with_products')" v-model="wateringForm.choice" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="busy" required><option value="" disabled>{{ t('plants.watering.choose') }}</option><option value="with">{{ t('plants.watering.with') }}</option><option value="without">{{ t('plants.watering.without') }}</option></select><small v-if="careValidation.fields.value.with_products" id="plant-watering-choice-error" class="field-error" role="alert">{{ t(careValidation.fields.value.with_products) }}</small></div>
<div class="field"><label for="plant-watering-date">{{ t('plants.watering.occurredAt') }}</label><input id="plant-watering-date" v-bind="careValidation.attrs('occurred_at')" v-model="wateringForm.occurredAt" type="datetime-local" :disabled="busy" /><small>{{ t('plants.nowHelp') }}</small><small v-if="careValidation.fields.value.occurred_at" id="plant-watering-date-error" class="field-error" role="alert">{{ t(careValidation.fields.value.occurred_at) }}</small></div></div>
<PlantInventoryFields ref="fertilizerFields" v-model="fertilizerDraft" :plant="wateringFor" :at="wateringForm.occurredAt" :enabled="!!wateringForm.choice" :with-fertilizer="wateringForm.choice === 'with'" :disabled="busy" @failure="failure" />
      </div>
     </template>
     <template #comment>
      <div class="field"><label for="plant-watering-comment">{{ t('plants.comment') }}</label><CommentField :validation-error="careValidation.fields.value.comment" id="plant-watering-comment" v-model="wateringForm.comment" :plant="wateringFor" :action="{ ...wateringForm, kind: 'watered', inventory: stockCommentContext(fertilizerDraft, true, wateringForm.choice === 'with') }" :attachments="attachmentFiles" :disabled="busy" @failure="failure" /></div>
     </template>
     <template #attachments>
      <PlantActionAttachments :validation-error="careValidation.fields.value.document_ids" v-model="attachmentFiles" :key="wateringFor.id" :location="wateringFor.location" :name="wateringFor.name" :disabled="busy" @busy="attachmentBusy = $event" @pending="attachmentPending = $event" @failure="failure" />
     </template>
    </PlantActionTabs>
   </form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('plants.watering.cancel')" type="default" :disabled="busy" @click="closeCare"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-2`" attr-type="submit" :aria-label="t('plants.watering.confirm')" :loading="busy" :disabled="!wateringForm.choice || busy || loading || attachmentPending" type="primary"><template #icon><AppIcon name="water" /></template><span>{{ t('actions.record') }}</span></Button></div></template></FormDialog>
   <FormDialog size="compact" icon="scissors" v-if="maintenanceFor" :visible="true" :title="t('plants.maintenance.forPlant', { name: maintenanceFor.name })" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeMaintenance()"><template #feedback><Button v-if="error === 'plants.errors.conflict'" :aria-label="t('plants.reload')" type="default" :disabled="busy || loading" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-3`" data-testid="maintenance-form" class="dialog-form plant-panel" @submit.prevent="recordMaintenance" novalidate>
    <PlantActionTabs v-model="actionTab">
     <template #action>
      <div class="dialog-form-grid"><div class="field"><label for="plant-maintenance-type">{{ t('plants.maintenance.type') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="plant-maintenance-type" v-bind="maintenanceForm.type === '__new__' ? {} : careValidation.attrs('type')" v-model="maintenanceForm.type" :disabled="busy"><option value="">{{ t('plants.maintenance.choose') }}</option><option v-for="type in builtInMaintenanceTypes" :key="type" :value="type">{{ maintenanceLabel(type, t) }}</option><option v-for="type in savedMaintenanceTypes" :key="type" :value="`saved:${type}`">{{ type }}</option><option value="__new__">{{ t('plants.maintenance.newType') }}</option></select><small v-if="careValidation.fields.value.type && maintenanceForm.type !== '__new__'" id="plant-maintenance-type-error" class="field-error" role="alert">{{ t(careValidation.fields.value.type) }}</small></div>
    <div v-if="maintenanceForm.type === '__new__'" class="field"><label for="plant-maintenance-custom-type">{{ t('plants.maintenance.customType') }}</label><InputText placeholder="" :status="!!careValidation.fields.value.type ? 'error' : undefined" v-bind="careValidation.attrs('type')" v-model:value="maintenanceForm.customType" maxlength="80" :disabled="busy" :input-props="{ 'id': 'plant-maintenance-custom-type', 'required': true, 'aria-invalid': !!careValidation.fields.value.type, 'aria-describedby': careValidation.fields.value.type ? 'plant-maintenance-type-help plant-maintenance-custom-type-error' : 'plant-maintenance-type-help' }"></InputText><small id="plant-maintenance-type-help">{{ t('plants.maintenance.reuseHelp') }}</small><small v-if="careValidation.fields.value.type" id="plant-maintenance-custom-type-error" class="field-error" role="alert">{{ t(careValidation.fields.value.type) }}</small></div>
    <div class="field"><label for="plant-maintenance-date">{{ t('plants.occurredAt') }}</label><input id="plant-maintenance-date" v-bind="careValidation.attrs('occurred_at')" v-model="maintenanceForm.occurredAt" type="datetime-local" :disabled="busy" /><small>{{ t('plants.nowHelp') }}</small><small v-if="careValidation.fields.value.occurred_at" id="plant-maintenance-date-error" class="field-error" role="alert">{{ t(careValidation.fields.value.occurred_at) }}</small></div></div>
     </template>
     <template #comment>
      <div class="field"><label for="plant-maintenance-comment">{{ t('plants.comment') }}</label><CommentField :validation-error="careValidation.fields.value.comment" id="plant-maintenance-comment" v-model="maintenanceForm.comment" :plant="maintenanceFor" :action="{ ...maintenanceForm, kind: 'maintained', type: chosenMaintenanceType }" :attachments="attachmentFiles" :disabled="busy" @failure="failure" /></div>
     </template>
     <template #attachments>
      <PlantActionAttachments :validation-error="careValidation.fields.value.document_ids" v-model="attachmentFiles" :key="maintenanceFor.id" :location="maintenanceFor.location" :name="maintenanceFor.name" :disabled="busy" @busy="attachmentBusy = $event" @pending="attachmentPending = $event" @failure="failure" />
     </template>
    </PlantActionTabs>
   </form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('plants.cancel')" type="default" :disabled="busy" @click="closeMaintenance"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-3`" attr-type="submit" :aria-label="t('plants.maintenance.confirm')" :disabled="busy || !chosenMaintenanceType || attachmentPending" :loading="busy" type="primary"><template #icon><AppIcon name="scissors" /></template><span>{{ t('actions.record') }}</span></Button></div></template></FormDialog>
   <DetailDialog v-if="detailOpen && !formOpen && !actionFor && !wateringFor && !maintenanceFor" record :title="selected?.name ?? t('plants.title')" :close-label="t('plants.closeDetail')" :busy="busy" :error="error ? t(error) : ''" @close="closeDetail"><template #header><div class="record-title"><span class="record-title-symbol"><AppIcon name="leaf" /></span><div><span class="record-eyebrow">{{ t('recordDetails.plantRecord') }}</span><h3>{{ selected?.name ?? t('plants.title') }}</h3><span v-if="selected && selected.givenName !== null" class="record-reference card-reference" :title="selected.id">#{{ selected.id.slice(0, 8) }}</span></div></div></template>
    <Message v-if="uploadedPhoto" closable type="info">{{ t('plants.photoUploadRetained') }}</Message>
    <p v-if="loading" role="status">{{ t('plants.loading') }}</p>
    <Message class="plant-feedback" v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>
    <section v-if="selected" class="plant-detail record-detail" :aria-label="selected.name"><nav class="record-actions" :aria-label="t('recordDetails.actions')"><div v-if="selected.canManage" class="record-action-group record-care-actions"><Button v-if="selected.watering?.status !== 'unavailable'" :aria-label="t('plants.watering.record')" type="default" :disabled="busy || loading" @click="openWatering(selected)" data-action-icon="water"><AppIcon name="water" /><span>{{ t('recordDetails.water') }}</span></Button>
       <Button v-if="canRecordMaintenance(selected.phase)" :aria-label="t('plants.maintenance.record')" type="default" :disabled="busy || loading" @click="openMaintenance(selected)" data-action-icon="scissors"><AppIcon name="scissors" /><span>{{ t('recordDetails.maintenance') }}</span></Button>
       <Button :data-action-icon="plantActionIcon(available.id)" v-for="available in (phaseDefinition(selected.phase)?.actions ?? [])" :key="available.id" :aria-label="t('plants.actionFor', { action: actionLabel(available.id, t), name: selected.name })" :disabled="busy || loading" @click="openAction(selected, available.id)" type="primary"><AppIcon :name="plantActionIcon(available.id)" /><span>{{ actionLabel(available.id, t) }}</span></Button></div>
     <div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: 'plant', id: selected.id, name: selected.name }" :disabled="busy || loading" />

       <Button v-if="selected.canManage" :aria-label="t('plants.edit', { name: selected.name })" type="default" :disabled="busy || loading" @click="edit(selected)" data-action-icon="edit"><AppIcon name="edit" /><span>{{ t('recordDetails.edit') }}</span></Button><Button v-if="selected.canDelete" type="error" :aria-label="t('plants.delete', { name: selected.name })" :disabled="busy || loading" @click="error = ''; deleting = true" data-action-icon="trash"><AppIcon name="trash" /><span>{{ t('recordDetails.delete') }}</span></Button></div></nav>
     <div class="record-badges"><span class="record-badge"><PlantPhaseIcon :phase="selected.phase" />{{ phaseLabel(selected.phase, t) }}</span><StrainGeneticsTag :classification="selected.strain.classification" /><StrainReferenceTag class="record-badge muted" :strain="selected.strain" /><span v-if="selectedAge" class="record-badge record-age-badge" data-testid="plant-age-badge" :aria-label="`${t('recordDetails.phaseAge')} : ${selectedAge}`"><AppIcon name="clock" />{{ selectedAge }}</span></div>
     <dl v-if="selected.startedAt || selected.phaseStartedAt || selected.phaseDueAt && !['maturation', 'archived'].includes(selected.phase) || selected.container || selected.harvestWeightGrams" class="record-metrics plant-summary-metrics" :aria-label="t('recordDetails.details')">
      <div v-if="selected.startedAt" class="record-metric"><dt><AppIcon name="seed" />{{ t('plants.startedAt') }}</dt><dd>{{ date(selected.startedAt) }}</dd></div>
      <div v-if="selected.phaseStartedAt" class="record-metric"><dt><PlantPhaseIcon :phase="selected.phase" />{{ t('plants.phaseStarted') }}</dt><dd>{{ date(selected.phaseStartedAt) }}</dd></div>
      <div v-if="selected.phaseDueAt && !['maturation', 'archived'].includes(selected.phase)" class="record-metric"><dt><AppIcon name="clock" />{{ t('plants.due') }}</dt><dd>{{ date(selected.phaseDueAt) }}</dd></div>
      <div v-if="selected.container" class="record-metric"><dt><AppIcon name="pot" />{{ t('plants.containers.currentLabel') }}</dt><dd>{{ selected.container.name }} · {{ Number(selected.container.capacityLiters) }} L</dd></div>
      <div v-if="selected.harvestWeightGrams" class="record-metric"><dt><AppIcon name="harvest" />{{ t('recordDetails.harvestWeight') }}</dt><dd>{{ new Intl.NumberFormat(locale, { style: 'unit', unit: 'gram', maximumFractionDigits: 2 }).format(Number(selected.harvestWeightGrams)) }}</dd></div>
     </dl>
     <div class="record-overview"><PhotoGallery :key="`${selected.id}:${selected.version}`" kind="plant" :record-id="selected.id" :name="selected.name" :cover-url="selected.photoUrl" @failure="failure"><template #actions><RecordPhotoActions overlay v-if="selected.canManage" :disabled="busy || loading" :has-photo="selected.hasOwnPhoto" :fetch-photos="photosApi.library" @select="setPhoto" :upload-file="uploadPhoto" :upload-error="error ? t(error) : ''" @access-lost="failure" /></template></PhotoGallery>
      <div class="record-summary"><LocationTrail class="record-location" :location="selected.location" />
       <RichNotes v-if="selected.notes" class="plant-notes record-note" :value="selected.notes" />
       <PlantExpectedActions :plant="selected" />
       <section v-if="selected.watering && selected.watering.status !== 'unavailable'" class="plant-watering" :aria-label="t('plants.watering.title')"><h4><AppIcon name="water" />{{ t('plants.watering.title') }}</h4>
        <p data-testid="plant-next-watering"><strong>{{ t('plants.watering.next') }} : {{ selected.watering.nextWithProducts === null ? t('plants.watering.choose') : t(selected.watering.nextWithProducts ? 'plants.watering.with' : 'plants.watering.without') }}</strong><span v-if="selected.watering.status === 'flush'"> · {{ t('plants.watering.flush') }}</span></p>
        <p v-if="selected.watering.last" data-testid="plant-last-watering">{{ t('plants.watering.last') }} : {{ date(selected.watering.last.occurredAt) }} · {{ t(selected.watering.last.withProducts ? 'plants.watering.with' : 'plants.watering.without') }}</p><p v-else>{{ t('plants.watering.none') }}</p>
        <details v-remember-disclosure="`${selected.id}:watering`"><DisclosureSummary :level="3">{{ t('recordDetails.wateringExplanation') }}</DisclosureSummary><p v-if="selected.watering.flushStartsAt">{{ t('plants.watering.flushDates', { date: date(selected.watering.flushStartsAt), days: selected.watering.flushDays }) }}</p><p>{{ t('plants.watering.needHelp') }}</p></details></section></div></div>
     <PlantReadings show-empty :context-key="`record:${selected.id}`" @access-lost="failure" v-if="selected.measurements?.length" :readings="selected.measurements" />
     <details v-remember-disclosure="`${selected.id}:hardware`" v-if="selected.devices.length" class="plant-hardware-details plant-detail-disclosure"><DisclosureSummary><span><AppIcon name="device" />{{ t('plants.devices') }}<span class="plant-section-count">{{ selected.devices.length }}</span></span></DisclosureSummary>
      <ul class="plant-hardware-list plant-disclosure-content">
       <li v-for="device in selected.devices" :key="device.id" class="plant-hardware-row" :aria-label="device.name">
        <PlantHardwareIdentity :device="device" />
        <div v-if="hardwareReadingCounts.has(device.id) || device.canActivate && selected.canManage && selected.watering.status !== 'unavailable'" class="plant-hardware-footer">
         <span v-if="hardwareReadingCounts.has(device.id)" class="plant-hardware-measurements"><AppIcon name="chart" />{{ t('plants.hardwareReadings', { count: hardwareReadingCounts.get(device.id) }, hardwareReadingCounts.get(device.id)!) }}</span>
         <div v-if="device.canActivate && selected.canManage && selected.watering.status !== 'unavailable'" class="plant-hardware-control"><PumpControl :disabled="busy || loading" :asset-id="device.id" :name="device.name" :plant-id="selected.id" @saved="detail(selected)" @access-lost="failure" /></div>
        </div>
       </li>
      </ul></details>
     <details v-remember-disclosure="`${selected.id}:strain`" class="plant-strain-details"><DisclosureSummary><span><AppIcon name="strain" />{{ t('plants.strainAndDurations') }}</span></DisclosureSummary>
      <PlantStrainSummary :key="selected.id" :strain="selected.strain" @access-lost="failure" />
      <section class="record-section"><div class="record-section-heading"><h4><AppIcon name="clock" />{{ t('plants.snapshot') }}</h4></div><p>{{ t('plants.snapshotHelp') }}</p><dl class="record-duration-grid"><div v-for="[key, days] in selectedDurations" :key="key"><dt><PlantPhaseIcon :phase="key" /> {{ phaseLabel(String(key), t) }}</dt><dd>{{ t('catalog.dayCount', { count: days }) }}</dd></div></dl></section></details>
     <details open v-remember-disclosure="`${selected.id}:history`" class="plant-history-details plant-detail-disclosure"><DisclosureSummary><span><AppIcon name="journal" />{{ t('plants.history') }}</span><small>{{ t('recordDetails.newestFirst') }}</small></DisclosureSummary>
      <div class="plant-disclosure-content"><PlantTimeline :events="events" :busy="busy" @download="downloadAttachment" @access-lost="failure" />
      <nav v-if="historyLast > 1" class="record-pagination" :aria-label="t('plants.historyPagination')"><Button type="default" :disabled="busy || loading || historyPage <= 1" @click="historyQuery.go(historyPage - 1)"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('plants.previous') }}</span></Button><span>{{ t('plants.page', { current: historyPage, total: historyLast }) }}</span><Button type="default" :disabled="busy || loading || historyPage >= historyLast" @click="historyQuery.go(historyPage + 1)"><template #icon><AppIcon name="arrow" /></template><span>{{ t('plants.next') }}</span></Button></nav></div></details></section></DetailDialog>
   <p v-if="!detailOnly && loading && !detailOpen" role="status">{{ t('plants.loading') }}</p><p v-else-if="!detailOnly && !rows.length && !error && !loading">{{ t('plants.empty') }}</p>
   <PlantBatchActions :selection-target="selectionHeader" v-if="!detailOnly" v-model="batchSelection" :plants="rows" :disabled="busy || loading" @changed="load(page, true); emit('changed')" @access-lost="failure" />
   <CollectionLayout preference-key="plants" grid-class="plant-grid photo-card-grid" v-if="!detailOnly" :inert="detailOpen" :aria-hidden="detailOpen ? true : undefined"><template #default><li v-for="row in rows" :key="row.id" :data-testid="`plant-row-${row.id}`"><PlantPhotoCard @changed="load(page); emit('changed')" @access-lost="failure" :plant="row" selectable :selected="batchSelection.includes(row.id)" @select="toggleBatch(row.id, $event)" registry :loading="busy || loading" @open="detail(row)" @water="openWatering(row)" @maintenance="openMaintenance(row)" @edit="edit(row)" @lifecycle="openAction(row)" /></li></template>
<template #table><PlantTable :rows="rows" selectable :selected-ids="batchSelection" :loading="busy || loading" @select="toggleBatch" @open="detail" @water="openWatering" @maintenance="openMaintenance" @edit="edit" @lifecycle="openAction" @changed="load(page); emit('changed')" @access-lost="failure" /></template></CollectionLayout>
   <CollectionPagination v-model:page-size="pageSize" v-if="!detailOnly" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('plants.pagination')" :previous-label="t('plants.previous')" :next-label="t('plants.next')" @page="collectionQuery.go($event)" /></template></Card></template>

<style scoped>
.plant-location-scope { display: flex; align-items: center; gap: var(--space-2); font-size: .8rem; color: var(--theme-muted); overflow-wrap: anywhere; }.plant-location-scope svg { width: 1rem; height: 1rem; flex-shrink: 0; }

.detail-only { padding: 0; background: transparent; box-shadow: none; }
.detail-only :deep(.n-card__content), .detail-only :deep(.n-card__content) { padding: 0; gap: 0; }
.plant-detail { min-width: 0; }

.plant-card { padding: var(--space-3); } h2, h3 { margin: 0; letter-spacing: -.025em; } p { font-size: .9rem; line-height: 1.6; }
.plant-heading, .plant-toolbar { display: flex; justify-content: space-between; flex-wrap: wrap; align-items: center; gap: var(--space-3); } .plant-toolbar { align-items: end; justify-content: flex-start; margin: var(--ui-content-gap) 0; } .plant-toolbar .field { margin: 0; }
.plant-panel { border: 1px solid var(--theme-border, #c1d3c8); border-radius: 1rem; padding: var(--space-6); margin: var(--ui-content-gap) 0; background: var(--theme-subtle, #f5faf7); }
fieldset { border: 1px solid var(--theme-border, #c1d3c8); border-radius: .75rem; padding: var(--space-4); margin: var(--ui-content-gap) 0; min-width: 0; } legend { font-weight: 600; font-size: .9rem; }
input, select, textarea { font: inherit; border: 1px solid var(--theme-border, #aebfb5); border-radius: .5rem; padding: var(--space-3); background: var(--theme-surface, white); color: inherit; max-width: 100%; } textarea { width: 100%; }
input:focus-visible, select:focus-visible, textarea:focus-visible{ outline: 2px solid var(--theme-focus, #08764b); outline-offset: 3px; }
 .plant-tile { min-width: 0; border: 1px solid var(--theme-border, #c1d3c8); border-radius: 1rem; padding: var(--space-6); }
p, h3, .plant-id, li { overflow-wrap: anywhere; } .plant-id { color: var(--theme-muted, #526c60); font-size: .75rem; } .plant-notes { white-space: pre-wrap; }
.phase-badge { padding: var(--space-1) var(--space-3); border-radius: 2rem; background: var(--theme-subtle, #dcf4e7); font-size: .8rem; font-weight: 600; } .overdue { color: var(--theme-warning, #9e510a); font-weight: 600; }
details { margin: var(--ui-content-gap) 0; } dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); } dt { font-size: .8rem; font-weight: 600; } dd { margin: var(--space-1) 0 0; }
.watering-help { color: var(--theme-muted, #526c60); font-size: .8rem; }
.plant-primary-photo img { display: block; width: min(100%, 26rem); max-height: 22rem; object-fit: cover; border-radius: .8rem; margin: var(--ui-content-gap) 0; }
@media (max-width: 700px) { dl { grid-template-columns: 1fr; } .plant-card { padding: 0; } .plant-panel, .plant-tile { padding: var(--space-4); } }
</style>

<style scoped>
.plant-editor-tabs { min-width: 0; width: 100%; }
.plant-editor-panel { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.plant-editor-panel > .field { margin-block: 0; }
.plant-hardware-editor .plant-hardware-list { margin: 0; grid-template-columns: minmax(0, 1fr); }
.plant-hardware-editor .plant-hardware-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
.plant-hardware-editor .reference-select { margin: 0; }
.plant-hardware-empty { margin: 0; color: var(--theme-muted); font-size: .8rem; }
</style>
