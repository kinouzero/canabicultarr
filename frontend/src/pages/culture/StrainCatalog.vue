<script setup lang="ts">
import { seedStockEmpty } from '../../lib/strain-stock';
import RecordStatusTag from '../../components/RecordStatusTag.vue';
import { useFeedback } from '../../lib/feedback';
import AppSwitch from '../../components/AppSwitch.vue';
import RecordQrButton from '../../components/RecordQrButton.vue';
import AppTabs from '../../components/AppTabs.vue';
import { NTabPane } from 'naive-ui';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionIdentity from '../../components/CollectionIdentity.vue';
import { useFieldErrors } from '../../lib/field-errors';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import ConfirmRecordDeletion from '../../components/ConfirmRecordDeletion.vue';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort } from '../../lib/collection-sorting';
import PageTitle from '../../components/PageTitle.vue';
import { applyLifecycle, phaseLabel } from '../../lib/lifecycle';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import NotesEditor from '../../components/NotesEditor.vue';
import RichNotes from '../../components/RichNotes.vue';
import StrainPhotoCard from '../../components/StrainPhotoCard.vue';
import StrainRecordActions from '../../components/StrainRecordActions.vue';
import PlantPhaseIcon from '../../components/PlantPhaseIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import StrainGeneticsTag from '../../components/StrainGeneticsTag.vue';
import { inventoryApi, type InventoryItem } from '../../api/inventory';
import FormDialog from '../../components/FormDialog.vue';
import DetailDialog from '../../components/DetailDialog.vue';
import AppIcon from '../../components/AppIcon.vue';
import RecordPhotoActions from '../../components/RecordPhotoActions.vue';
import { photosApi } from '../../api/photos';
import PhotoGallery from '../../components/PhotoGallery.vue';
import '../../styles/record-details.css';
import { watch, defineAsyncComponent, useId, computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import DurationInput from '../../components/DurationInput.vue';
import { ApiError } from '../../api/auth';
import { queryString, useRecordQuery } from '../../lib/route-query';
import { strainsApi, strainSorts, type Strain, type StrainInput, type LifecycleSettings, type DurationKey } from '../../api/strains';
import en from '../../locales/en/catalog.json';
import fr from '../../locales/fr/catalog.json';
import plantEn from '../../locales/en/plants.json';
import plantFr from '../../locales/fr/plants.json';
const pageSize = useCollectionPageSize('strains');
const vRememberDisclosure = useRememberDisclosure('StrainCatalog');
const props = defineProps<{ embedded?: boolean; detailOnly?: boolean; initialStrainId?: string; canManageStrains?: boolean; canManagePlants?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, n, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
const feedback = useFeedback();
mergeLocaleMessage('en', { catalog: en, plants: plantEn }); mergeLocaleMessage('fr', { catalog: fr, plants: plantFr });
const PlantRegistry = defineAsyncComponent(() => import('./PlantRegistry.vue'));
const creatingPlant = ref(false); const plantCreated = ref(false);
function createPlant(strain: Strain) {
 if (!props.canManagePlants || strain.isArchived || seedStockEmpty(strain) || saving.value || loading.value || detailLoading.value) return;
 selected.value = strain; plantCreated.value = false; notice.value = ''; creatingPlant.value = true;
}
function confirmPlantCreated() { if (detailOpen.value) plantCreated.value = true; else notice.value = 'plants.created'; }

const recordRoute = useRecordQuery('strain');
const detailId = ref(''); let alive = true;
const deleting = ref<Strain | null>(null);
const lineageExpanded = ref(false); let lineageTarget: HTMLElement | null = null;
function showLineage(event: MouseEvent) { lineageTarget = event.currentTarget as HTMLElement; lineageExpanded.value = !lineageExpanded.value; }
function restoreLineageFocus() { if (detailOpen.value && !open.value && lineageTarget?.isConnected && !lineageTarget.closest('[inert]') && lineageTarget.getClientRects().length) lineageTarget.focus(); }
const selected = ref<Strain | null>(null); const detailOpen = ref(false); const detailLoading = ref(false); let detailSequence = 0;
const rows = ref<Strain[]>([]); const settings = ref<LifecycleSettings | null>(null);
const durationKeys = computed(() => [...(settings.value?.stages ?? []).map(stage => stage.id).filter(id => !['planned', 'archived'].includes(id)), 'flush']);
const sort = useCollectionSort('strains', strainSorts);
const search = ref(''); const status = ref('active'); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const saving = ref(false); const editing = ref<Strain | null>(null);
const open = ref(false); const error = ref(''); const notice = ref('');
const seedStock = ref<Strain['seedStock']>(null);
const stockRows = ref<InventoryItem[]>([]); const stockLoading = ref(false); const stockFailed = ref(false); let stockSequence = 0;
const stockOptions = computed(() => {
 const options = stockRows.value.filter(item => item.type === 'seeds' && item.unit === 'piece').map(item => ({ id: item.id, name: item.name, quantity: item.totalQuantity, isArchived: item.isArchived, scoped: item.canEditCatalog === false, disabled: item.unit !== 'piece' }));
 return seedStock.value && !options.some(item => item.id === seedStock.value!.id) ? [{ ...seedStock.value, disabled: false }, ...options] : options;
});
const stockId = computed({ get: () => seedStock.value?.id ?? '', set: (id: string) => {
 const option = stockOptions.value.find(item => item.id === id);
 seedStock.value = option && !option.disabled ? option : null;
} });
async function loadStock() {
 const current = ++stockSequence; stockLoading.value = true; stockFailed.value = false; stockRows.value = [];
 try {
  const items: InventoryItem[] = []; let target = 1, last = 1;
  do {
   const result = await inventoryApi.list({ state: 'active', type: 'seeds', per_page: '100' }, target);
   if (current !== stockSequence) return;
   items.push(...result.data); last = result.meta.last_page; target++;
  } while (target <= last);
  stockRows.value = items;
 } catch (cause) { if (current === stockSequence) { stockFailed.value = true; if (cause instanceof ApiError && [401, 403].includes(cause.status)) failure(cause); } }
 finally { if (current === stockSequence) stockLoading.value = false; }
}
const textFields = ['name', 'sourceUrl', 'lineage', 'thc', 'cbd', 'aroma', 'reportedEffects', 'notes'] as const;
type TextField = typeof textFields[number];
const form = reactive({ ...Object.fromEntries(textFields.map(k => [k, ''])) as Record<TextField, string>, classification: '', isArchived: false });
const theoreticalYield = ref<string | number>('');
const overrides = reactive({} as Record<DurationKey, number | ''>);
function apiField(field: string) { return field === 'sourceUrl' ? 'source_url' : field === 'reportedEffects' ? 'reported_effects' : field; }
const validation = useFieldErrors(() => ({
 name: { id: 'strain-name', message: 'catalog.validation.name' },
 classification: { id: 'strain-genetics', message: 'catalog.validation.classification' },
 theoretical_yield: { id: 'strain-yield', message: 'catalog.validation.yield' },
 ...Object.fromEntries(['sourceUrl', 'lineage', 'thc', 'cbd', 'aroma', 'reportedEffects'].map(field => [apiField(field), { id: `strain-${field}`, message: field === 'sourceUrl' ? 'catalog.validation.url' : ['thc', 'cbd'].includes(field) ? 'catalog.validation.shortText' : 'catalog.validation.text' }])),
 seed_inventory_item_id: { id: 'strain-stock', message: 'catalog.validation.stock' },
 ...Object.fromEntries(durationKeys.value.map(stage => [`duration_overrides.${stage}`, { id: `override-${stage}`, message: 'catalog.validation.duration' }])),
 notes: { id: 'strain-notes', message: 'catalog.validation.text' },
}), () => ({ ...Object.fromEntries(textFields.map(field => [apiField(field), form[field]])), classification: form.classification, theoretical_yield: theoreticalYield.value,
 seed_inventory_item_id: seedStock.value?.id, ...Object.fromEntries(durationKeys.value.map(stage => [`duration_overrides.${stage}`, overrides[stage]])) }));
const fields = validation.fields;
const editorTab = ref('general');
function revealFieldErrors() {
 const field = Object.keys(fields.value)[0];
 if (field) editorTab.value = field.startsWith('duration_overrides.') ? 'cycle' : field === 'seed_inventory_item_id' ? 'stock' : field === 'notes' ? 'notes' : 'general';
}
const editBaseline = ref('');
function editSnapshot() { return JSON.stringify([form, theoreticalYield.value === '' ? null : Number(theoreticalYield.value), seedStock.value?.id ?? null, overrides]); }
const editDirty = computed(() => open.value && editSnapshot() !== editBaseline.value);
const unsaved = useUnsavedChanges(editDirty, saving);
async function closeEdit() { if (await unsaved.confirmDiscard()) open.value = false; }
async function reloadEdit() {
 if (!await unsaved.confirmDiscard()) return;
 open.value = false;
 if (props.detailOnly) await detail({ id: detailId.value }, false);
 else await load(page.value);
}
let sequence = 0;
function key(value: string) { return /^(catalog|auth)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function failure(cause: unknown) {
 error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (cause instanceof ApiError) {
  if (open.value) { validation.capture(cause); revealFieldErrors(); }
  if (cause.status === 422) error.value = 'catalog.validation.invalid';
  if (cause.code === 'catalog.errors.nameTaken') { error.value = cause.code; if (open.value) { validation.set('name', cause.code); revealFieldErrors(); void validation.focus(); } }
  if (cause.status === 401 || cause.status === 403) { creatingPlant.value = false; detailOpen.value = false; selected.value = null; rows.value = []; settings.value = null; open.value = false; emit('accessLost', cause); }
 }
}
async function load(target = 1) {
 if (saving.value || open.value) return;
 if (props.detailOnly) return;
 const current = ++sequence; loading.value = true; error.value = ''; fields.value = {}; open.value = false;
 try {
  const [list, global] = await Promise.all([strainsApi.list(search.value.trim(), target, status.value, sort.value, pageSize.value), strainsApi.settings()]);
  if (current !== sequence) return;
  rows.value = list.data; page.value = list.meta.current_page; lastPage.value = list.meta.last_page; total.value = list.meta.total; settings.value = global; applyLifecycle(global.stages, global.icons);
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) loading.value = false; }
}
async function detail(row: Pick<Strain, 'id'>, syncRoute = true) {
 lineageExpanded.value = false; notice.value = '';
 detailId.value = row.id;
 if (syncRoute && !props.detailOnly) void recordRoute.open(row.id);
 plantCreated.value = false;
 const current = ++detailSequence; detailOpen.value = true; detailLoading.value = true; selected.value = rows.value.find(item => item.id === row.id) ?? null; error.value = '';
 try { const record = await strainsApi.get(row.id); if (current === detailSequence) selected.value = record; }
 catch (cause) { if (current === detailSequence) failure(cause); }
 finally { if (current === detailSequence) detailLoading.value = false; }
}
function closeDetail(syncRoute = true) {
 if (saving.value) return;
 if (syncRoute && !props.detailOnly) void recordRoute.close();
 deleting.value = null;
 open.value = false; const id = selected.value?.id; detailId.value = ''; detailSequence++; detailOpen.value = false; detailLoading.value = false; selected.value = null; error.value = ''; notice.value = '';
 if (props.detailOnly) emit('close');
 if (id && !props.detailOnly) void nextTick(() => document.querySelector<HTMLButtonElement>(`[data-testid="strain-row-${id}"] .open-plant`)?.focus());
 plantCreated.value = false;
}
async function edit(row: Strain | null = null) {
 if (saving.value || loading.value || !props.canManageStrains || !await unsaved.confirmDiscard()) return;
 theoreticalYield.value = row?.theoreticalYield ?? '';
 seedStock.value = row?.seedStock ?? null;
 editing.value = row ? { ...row, durationOverrides: { ...row.durationOverrides } } : null;
 for (const field of textFields) form[field] = row?.[field] ?? '';
 form.classification = row?.classification ?? ''; form.isArchived = row?.isArchived ?? false;
 for (const stage of durationKeys.value) overrides[stage] = row?.durationOverrides[stage] ?? '';
 editBaseline.value = editSnapshot(); editorTab.value = 'general'; open.value = true; error.value = ''; notice.value = ''; fields.value = {}; void loadStock();
}
function seedQuantity(value: string) { return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 3 }).format(Number(value)); }
function effective(stage: DurationKey) { return overrides[stage] === '' ? settings.value?.durations[stage] : overrides[stage]; }
function durationLabel(stage: DurationKey, days: number | undefined) {
 return days == null ? t('catalog.workflow.indefinite') : stage === 'flowering' ? t('catalog.weekCount', { count: n(days / 7, { maximumFractionDigits: 2 }) }) : t('catalog.dayCount', { count: days });
}
async function toggleStatus(row: Strain) {
 if (!row.canManage || saving.value || loading.value) return;
 saving.value = true; error.value = ''; notice.value = '';
 try {
  const result = await strainsApi.setArchived(row, !row.isArchived);
  if (!alive) return;
  if (selected.value?.id === result.id) selected.value = result;
  rows.value = rows.value.map(entry => entry.id === result.id ? result : entry);
  notice.value = 'catalog.updated';
  await load(page.value);
 } catch (cause) { if (alive) failure(cause); }
 finally { saving.value = false; }
}
async function save() {
 if (saving.value || loading.value || !props.canManageStrains) return;
 fields.value = {}; error.value = ''; notice.value = '';
 if (!form.name.trim() || form.name.length > 120) validation.set('name');
 if (form.classification && !['indica', 'sativa', 'hybrid'].includes(form.classification)) validation.set('classification');
 if (theoreticalYield.value !== '' && (!/^\d+(?:\.\d{1,2})?$/.test(String(theoreticalYield.value)) || Number(theoreticalYield.value) > 99999999.99)) validation.set('theoretical_yield');
 if (form.sourceUrl) { try { const url = new URL(form.sourceUrl); if (!['http:', 'https:'].includes(url.protocol) || form.sourceUrl.length > 2000) validation.set('source_url'); } catch { validation.set('source_url'); } }
 for (const field of textFields) if (form[field].length > (['name', 'thc', 'cbd'].includes(field) ? 120 : 2000)) validation.set(apiField(field));
 const values = overrides;
 for (const stage of durationKeys.value) {
  const value = values[stage];
  if ((value !== '' && (!Number.isInteger(value) || value < (stage === 'flush' ? 0 : 1) || value > 3650))) {
   fields.value[`duration_overrides.${stage}`] = 'catalog.validation.duration';
  }
 }
 if (Object.keys(fields.value).length) { error.value = 'catalog.validation.invalid'; revealFieldErrors(); void validation.focus(); return; }
 const current = sequence; saving.value = true;
 try {
   const input: StrainInput = {
    theoretical_yield: String(theoreticalYield.value).trim() || null, seed_inventory_item_id: seedStock.value?.id ?? null, name: form.name, source_url: form.sourceUrl || null, classification: form.classification || null,
    genetics: editing.value?.genetics ?? null, lineage: form.lineage || null, thc: form.thc || null, cbd: form.cbd || null,
    aroma: form.aroma || null, reported_effects: form.reportedEffects || null, notes: form.notes || null,
    duration_overrides: Object.fromEntries(durationKeys.value.map(k => [k, overrides[k] === '' ? null : overrides[k]])), is_archived: form.isArchived,
   };
   if (editing.value) input.version = editing.value.version;
   const result = editing.value ? await strainsApi.update(editing.value.id, input) : await strainsApi.create(input);
   if (detailOpen.value && selected.value?.id === result.id) selected.value = result;
   if (current !== sequence) return;
   notice.value = editing.value ? 'catalog.updated' : 'catalog.created';
   open.value = false; saving.value = false; await load(page.value);
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { saving.value = false; }
}
async function addRecordPhoto(target: Strain, id: string | null, file?: File, disk?: string) {
 if (!target.canManage || saving.value || detailLoading.value || (!id && !file)) return false; saving.value = true; error.value = ''; notice.value = '';
 try {
  const documentId = file ? (await photosApi.upload(file, target.name, { type: 'strain', id: target.id }, disk)).id : id!;
  if (!alive) return false;
  const result = await strainsApi.photo(target, documentId);
  if (!alive) return false;
  if (selected.value?.id === result.id) selected.value = result;
  rows.value = rows.value.map(row => row.id === result.id ? result : row);
  notice.value = 'plants.photoSaved'; return true;
 } catch (cause) { if (alive) failure(cause); return false; }
 finally { saving.value = false; }
}
function addPhoto(id: string | null, file?: File, disk?: string): Promise<boolean> { return selected.value ? addRecordPhoto(selected.value, id, file, disk) : Promise.resolve(false); }
function uploadPhoto(file: File, disk?: string): Promise<boolean> { return addPhoto(null, file, disk); }
function confirmDeletion(row: Strain) { if (!saving.value && row.canManage) { error.value = ''; deleting.value = row; } }
async function deleteRecord() {
 if (!deleting.value?.canManage || saving.value) return;
 const target = deleting.value; saving.value = true; error.value = '';
 try {
  await strainsApi.remove(target);
  if (!alive) return;
  feedback.success(t('catalog.deleted'));
  saving.value = false; deleting.value = null; if (selected.value?.id === target.id) closeDetail(); await load(rows.value.length === 1 ? Math.max(1, page.value - 1) : page.value);
  notice.value = '';
 } catch (cause) { if (alive) failure(cause); }
 finally { saving.value = false; }
}
onMounted(async () => {
 if (props.detailOnly) {
  await Promise.all([detail({ id: props.initialStrainId! }, false), strainsApi.settings().then(global => { if (alive) { settings.value = global; applyLifecycle(global.stages, global.icons); } }).catch(cause => { if (alive) failure(cause); })]);
  return;
 }
 await load(page.value); const id = queryString(recordRoute.route.query.strain); if (alive && id) await detail({ id }, false); });
watch(() => recordRoute.route.query.strain, value => {
 if (props.detailOnly) return;
 const id = queryString(value);
 if (id && (detailId.value !== id || !detailOpen.value)) void detail({ id }, false);
 else if (!id && detailOpen.value) closeDetail(false);
}); onUnmounted(() => { alive = false; sequence++; detailSequence++; stockSequence++; });
const dialogId = useId();
const collectionQuery = useCollectionQuery({ enabled: () => !props.detailOnly, paths: ['/strains'], fields: { q: queryField.text(search), status: queryField.choice(status, ['active', 'archived', 'all']), sort: queryField.choice(sort, strainSorts) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;


const selectionHeader = ref<HTMLElement | null>(null);
</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <ConfirmRecordDeletion v-if="deleting" :name="deleting.name" :message="t('catalog.deleteHelp')" :busy="saving" :error="error ? t(error) : ''" @cancel="deleting = null; error = ''" @confirm="deleteRecord" />
 <PlantRegistry v-if="creatingPlant && selected" detail-only initial-action="create" :can-manage="!!canManagePlants" :initial-strain="selected" @close="creatingPlant = false" @created="confirmPlantCreated" @access-lost="failure" />
   <FormDialog size="wide" icon="strain" v-if="open && canManageStrains && settings" :visible="true" :title="t(editing ? 'catalog.editTitle' : 'catalog.createTitle')" :close-label="t('catalog.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && closeEdit()"><template #feedback><Button v-if="error === 'catalog.errors.conflict'" :aria-label="t('catalog.reload')" type="default" :disabled="saving || loading" @click="reloadEdit"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form catalog-panel" @submit.prevent="save()" novalidate>
 <AppTabs v-model:value="editorTab" class="strain-editor-tabs" :aria-label="t('catalog.editorTabs.label')">
  <NTabPane name="general" display-directive="show"><template #tab><AppIcon name="strain" />{{ t('catalog.editorTabs.general') }}</template><div class="strain-editor-panel">
<div class="field dialog-wide"><label for="strain-name">{{ t('catalog.name') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="form.name" maxlength="120" :disabled="saving" :status="!!fields.name ? 'error' : undefined" :input-props="{ 'id': 'strain-name' }"></InputText><small v-if="fields.name" id="strain-name-error" class="field-error" role="alert">{{ t(fields.name) }}</small></div>
<fieldset class="dialog-section"><legend>{{ t('modalSections.characteristics') }}</legend>
<div class="dialog-form-grid"><div class="field"><label for="strain-genetics">{{ t('catalog.genetics') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="strain-genetics" v-bind="validation.attrs('classification')" v-model="form.classification" :disabled="saving"><option value="">{{ t('catalog.unspecified') }}</option><option v-for="value in ['indica', 'sativa', 'hybrid']" :key="value" :value="value">{{ t(`catalog.classificationValues.${value}`) }}</option></select><small v-if="fields.classification" id="strain-genetics-error" class="field-error" role="alert">{{ t(fields.classification) }}</small></div>
<div class="field"><label for="strain-yield">{{ t('catalog.theoreticalYield') }} (g/m²)</label><input id="strain-yield" v-bind="validation.attrs('theoretical_yield')" v-model="theoreticalYield" type="number" min="0" max="99999999.99" step="0.01" :disabled="saving" /><small v-if="fields.theoretical_yield" id="strain-yield-error" role="alert" class="field-error">{{ t(fields.theoretical_yield) }}</small></div>
     <div v-for="field in (['thc', 'cbd', 'lineage', 'aroma', 'reportedEffects'] as const)" :key="field" class="field" :class="{ 'dialog-wide': field === 'lineage' }"><label :for="`strain-${field}`">{{ t(`catalog.${field}`) }}</label>
      <InputText placeholder="" v-if="['thc', 'cbd'].includes(field)" v-bind="validation.attrs(apiField(field))" v-model:value="form[field]" maxlength="120" :disabled="saving" :status="!!fields[field] ? 'error' : undefined" :input-props="{ 'id': `strain-${field}` }"></InputText>
      <textarea v-else :id="`strain-${field}`" v-bind="validation.attrs(apiField(field))" v-model="form[field]" maxlength="2000" rows="3" :disabled="saving" />
      <small v-if="fields[apiField(field)]" :id="`strain-${field}-error`" class="field-error" role="alert">{{ t(fields[apiField(field)]) }}</small></div></div></fieldset>
<div class="field dialog-wide"><label for="strain-sourceUrl">{{ t('catalog.sourceUrl') }}</label><InputText placeholder="" v-bind="validation.attrs('source_url')" v-model:value="form.sourceUrl" maxlength="2000" :disabled="saving" :status="!!fields.source_url ? 'error' : undefined" :input-props="{ 'id': 'strain-sourceUrl' }"></InputText><small v-if="fields.source_url" id="strain-sourceUrl-error" class="field-error" role="alert">{{ t(fields.source_url) }}</small></div>

    <AppSwitch class="archive-check" v-model="form.isArchived" :disabled="saving">{{ t('catalog.archive') }}</AppSwitch><p>{{ t('catalog.archiveHelp') }}</p>
  </div></NTabPane>
  <NTabPane name="cycle" display-directive="show"><template #tab><AppIcon name="clock" />{{ t('catalog.editorTabs.cycle') }}</template><div class="strain-editor-panel">
<fieldset class="dialog-section"><legend>{{ t('catalog.overridesTitle') }}</legend><p>{{ t('catalog.overrideHelp') }}</p>
    <div class="duration-grid"><div v-for="stage in durationKeys" :key="stage" class="field"><DurationInput :phase="stage" :id="`override-${stage}`" v-model="overrides[stage]" :label="t('catalog.durationInput', { stage: phaseLabel(stage, t) })" :minimum="stage === 'flush' ? 0 : 1" :disabled="saving" :inherited-days="settings.durations[stage]" :validation-error="fields[`duration_overrides.${stage}`]" />
     <small :data-testid="`effective-${stage}`">{{ t(overrides[stage] === '' ? 'catalog.inherited' : 'catalog.customized') }} · {{ durationLabel(stage, effective(stage) as number | undefined) }}</small></div></div></fieldset>

  </div></NTabPane>
  <NTabPane name="stock" display-directive="show"><template #tab><AppIcon name="archive" />{{ t('catalog.editorTabs.stock') }}</template><div class="strain-editor-panel">
<div class="field seed-stock-field"><label for="strain-stock">{{ t('catalog.seedStock') }}</label><select id="strain-stock" v-model="stockId" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :disabled="saving || stockLoading || stockFailed" :aria-invalid="!!fields.seed_inventory_item_id" :aria-describedby="fields.seed_inventory_item_id ? 'strain-stock-help strain-stock-error' : 'strain-stock-help'"><option value="">{{ t('catalog.noSeedStock') }}</option><option v-for="item in stockOptions" :key="item.id" :value="item.id" :disabled="item.disabled">{{ item.name }} · {{ t('catalog.seedQuantity', { count: seedQuantity(item.quantity) }) }}</option></select><small id="strain-stock-help">{{ t('catalog.stockHelp') }}</small><small v-if="seedStock?.scoped">{{ t('catalog.scopedStock') }}</small><small v-if="fields.seed_inventory_item_id" id="strain-stock-error" role="alert" class="field-error">{{ t(fields.seed_inventory_item_id) }}</small><Message v-if="stockFailed" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }} <Button attr-type="button" :aria-label="t('collection.refresh')" type="default" :disabled="saving || stockLoading" @click="loadStock"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></Message></div>

  </div></NTabPane>
  <NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="message" />{{ t('catalog.editorTabs.notes') }}</template><div class="strain-editor-panel">
    <div class="field"><label for="strain-notes">{{ t('catalog.notes') }}</label><NotesEditor id="strain-notes" v-model="form.notes" :label="t('catalog.notes')" :disabled="saving" :invalid="!!fields.notes" :described-by="fields.notes ? 'strain-notes-error' : undefined" /><small v-if="fields.notes" id="strain-notes-error" class="field-error" role="alert">{{ t(fields.notes) }}</small></div>

  </div></NTabPane>
 </AppTabs>
</form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('catalog.cancel')" type="default" :disabled="saving" @click="closeEdit"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('catalog.save') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
   <DetailDialog v-if="detailOpen && !open" record :title="selected?.name ?? t('catalog.title')" :close-label="t('catalog.closeDetail')" :busy="saving" :error="error ? t(error) : ''" @close="closeDetail"><template #header><div class="record-title"><span class="record-title-symbol"><AppIcon name="strain" /></span><div><span class="record-eyebrow">{{ t('recordDetails.strainRecord') }}</span><h3>{{ selected?.name ?? t('catalog.title') }}</h3></div></div></template>
    <p v-if="detailLoading" role="status">{{ t('catalog.loading') }}</p>
    <section v-if="selected" class="strain-detail record-detail" :aria-label="selected.name"><Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>
     <Message v-if="plantCreated" closable type="success" role="status">{{ t('plants.created') }}</Message>
     <nav class="record-actions" :aria-label="t('recordDetails.actions')"><div v-if="canManagePlants && !selected.isArchived" class="record-action-group record-care-actions"><Button :disabled="saving || detailLoading || seedStockEmpty(selected)" :title="seedStockEmpty(selected) ? t('plants.errors.seedStockEmpty') : t('plants.add')" @click="createPlant(selected)" data-action-icon="leaf" type="primary"><AppIcon name="leaf" /><span>{{ t('plants.add') }}</span></Button></div><div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: 'strain', id: selected.id, name: selected.name }" :disabled="saving || detailLoading" /><Button v-if="selected.canManage" :aria-label="t('catalog.edit', { name: selected.name })" type="default" :disabled="saving || detailLoading" @click="edit(selected)" data-action-icon="edit"><AppIcon name="edit" /><span>{{ t('recordDetails.edit') }}</span></Button><Button v-if="selected.canManage" type="error" :aria-label="t('catalog.delete', { name: selected.name })" :disabled="saving || detailLoading" @click="confirmDeletion(selected)" data-action-icon="trash"><AppIcon name="trash" /><span>{{ t('recordDetails.delete') }}</span></Button></div></nav>
     <div class="record-badges"><RecordStatusTag :value="selected.isArchived ? 'archived' : 'active'" :options="[{ value: 'active', label: t('catalog.activeTag'), type: 'success' }, { value: 'archived', label: t('catalog.archivedTag'), type: 'default' }]" :interactive="selected.canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: selected.name })" @update:value="toggleStatus(selected)" /><StrainGeneticsTag :classification="selected.classification" /><span class="record-badge muted card-reference" :title="selected.id">#{{ selected.id.slice(0, 8) }}</span><a v-if="selected.sourceUrl" class="record-web-link" :aria-label="t('catalog.sourceUrl')" :title="t('catalog.sourceUrl')" :href="selected.sourceUrl" target="_blank" rel="noopener noreferrer"><AppIcon name="web" /></a></div>
     <div class="record-overview"><div><PhotoGallery :key="`${selected.id}:${selected.version}`" kind="strain" :record-id="selected.id" :name="selected.name" @failure="failure"><template #actions><RecordPhotoActions overlay v-if="selected.canManage" :disabled="saving || detailLoading" :has-photo="false" :fetch-photos="photosApi.library" @select="addPhoto" :upload-file="uploadPhoto" :upload-error="error ? t(error) : ''" @access-lost="failure" /></template></PhotoGallery></div>
      <div class="record-summary"><div class="record-metrics"><div class="record-metric seed-stock"><small>{{ t('catalog.seedStock') }}</small><strong>{{ selected.seedStock ? t('catalog.seedQuantity', { count: seedQuantity(selected.seedStock.quantity) }) : t('catalog.noSeedStock') }}</strong><small v-if="selected.seedStock?.scoped">{{ t('catalog.scopedStock') }}</small></div>
        <div class="record-metric"><small>{{ t('catalog.theoreticalYield') }}</small><strong>{{ selected.theoreticalYield != null ? t('catalog.yieldValue', { count: n(Number(selected.theoreticalYield), { maximumFractionDigits: 2 }) }) : t('catalog.unspecified') }}</strong></div>
        <div v-for="field in (['thc', 'cbd'] as const).filter(key => selected?.[key])" :key="field" class="record-metric"><small>{{ t(`catalog.${field}`) }}</small><strong>{{ selected[field] }}</strong></div></div>
       <dl class="record-facts"><div v-for="field in (['lineage', 'aroma', 'reportedEffects'] as const).filter(key => selected?.[key])" :key="field"><dt>{{ t(`catalog.${field}`) }}</dt><dd v-if="field === 'lineage'"><button type="button" class="lineage-value" :title="selected.lineage ?? undefined" :aria-label="t('catalog.readLineage')" aria-haspopup="dialog" :aria-expanded="lineageExpanded" @click="showLineage"><span>{{ selected.lineage }}</span><AppIcon name="expand" /></button></dd><dd v-else>{{ selected[field] }}</dd></div></dl>
       <FormDialog :visible="lineageExpanded" :title="t('catalog.lineage')" :close-label="t('actions.close')" size="compact" @update:visible="lineageExpanded = $event; if (!$event) restoreLineageFocus()"><p class="lineage-full">{{ selected.lineage }}</p></FormDialog>
       <RichNotes v-if="selected.notes" class="record-note" :value="selected.notes" /></div></div>
     <details v-remember-disclosure="`${selected.id}:durations`" v-if="settings"><DisclosureSummary><span><AppIcon name="clock" />{{ t('catalog.durationsTitle') }}</span></DisclosureSummary><dl class="record-duration-grid"><div v-for="stage in durationKeys" :key="stage"><dt><PlantPhaseIcon :phase="stage" /> {{ phaseLabel(stage, t) }}</dt><dd>{{ durationLabel(stage, selected.durationOverrides[stage] ?? settings.durations[stage]) }}<small>{{ t(selected.durationOverrides[stage] == null ? 'catalog.inherited' : 'catalog.customized') }}</small></dd></div></dl></details></section></DetailDialog>
 <Card v-if="!detailOnly" class="catalog-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="catalog-heading"><PageTitle icon="strain">{{ t('catalog.title') }}</PageTitle></div><div ref="selectionHeader" class="page-actions"><Button v-if="!embedded" :aria-label="t('catalog.back')" type="default" :disabled="saving" @click="emit('close')" :title="t('catalog.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" v-if="!open" :label="t('catalog.reload')" severity="secondary" :disabled="saving || loading" @click="load(page)" /><CollectionViewToggle preference-key="strains" :disabled="saving || loading" /><Button v-if="canManageStrains" :aria-label="t('catalog.add')" :disabled="saving || loading" @click="edit()" data-action-icon="plus" :title="t('catalog.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('catalog.description') }}</div></div></template>

  <template #default><Message v-if="error && !open && !detailOpen" closable type="error" role="alert">{{ t(error) }}</Message>
   <Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>

   <form class="catalog-toolbar search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="field filter-field"><label for="strain-search">{{ t('catalog.search') }}</label><InputText placeholder="" v-model:value="search" :disabled="saving" maxlength="120" :input-props="{ 'id': 'strain-search' }"></InputText></div>
     <div class="field filter-field"><label for="strain-sort">{{ t('catalog.sort') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="strain-sort" v-model="sort" :disabled="saving"><option v-for="value in strainSorts" :key="value" :value="value">{{ t(`catalog.sorts.${value}`) }}</option></select></div></div>
    <div class="filter-secondary"><div class="field filter-field"><label for="strain-status">{{ t('catalog.status') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="strain-status" v-model="status" :disabled="saving"><option value="active">{{ t('catalog.active') }}</option><option value="archived">{{ t('catalog.archived') }}</option><option value="all">{{ t('catalog.all') }}</option></select></div></div>
    <ResetFilters @reset="search = ''; status = 'active'; sort = 'name_asc'" /></form>

   <p v-if="loading" role="status">{{ t('catalog.loading') }}</p><p v-else-if="!rows.length && !error">{{ t('catalog.empty') }}</p>
   <CollectionTable :selection-target="selectionHeader" preference-key="strains" grid-class="strain-grid photo-card-grid" :inert="detailOpen" :aria-hidden="detailOpen ? true : undefined" :rows="rows" :can-select="row => row.canManage" :row-name="row => row.name" :remove="strainsApi.remove" :loading="loading || saving" @removed="load(page)" @access-lost="failure" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'stock', label: t('catalog.seedStock') }, { key: 'yield', label: t('catalog.theoreticalYield') }, { key: 'actions', label: t('collection.actions') }]">
<template #cards="{ selectionFor }"><li v-for="row in rows" :key="row.id" :data-testid="`strain-row-${row.id}`"><StrainPhotoCard :selection="selectionFor(row)" :strain="row" :can-create-plant="!!canManagePlants" @create-plant="createPlant(row)" :flowering-days="row.durationOverrides.flowering ?? settings?.durations.flowering ?? 56" :loading="saving || loading" :error="error ? t(error) : ''" :upload-file="(file, disk) => addRecordPhoto(row, null, file, disk)" @open="detail(row)" @status="toggleStatus(row)" @edit="edit(row)" @remove="confirmDeletion(row)" @photo="addRecordPhoto(row, $event)" @failure="failure" /></li></template><template #name="{ row }"><div class="collection-table-name"><CollectionIdentity :name="row.name" :reference="`#${row.id.slice(0, 8)}`" :strain-id="row.id" :label="t('catalog.open', { name: row.name })" @open="detail(row)" @failure="failure" /><div class="collection-table-tags"><RecordStatusTag :value="row.isArchived ? 'archived' : 'active'" :options="[{ value: 'active', label: t('catalog.activeTag'), type: 'success' }, { value: 'archived', label: t('catalog.archivedTag'), type: 'default' }]" :interactive="row.canManage" :busy="saving || loading" :label="t('collection.changeStatus', { name: row.name })" @update:value="toggleStatus(row)" /><span class="collection-record-tag"><PlantPhaseIcon phase="flowering" />{{ t('catalog.weekCount', { count: n((row.durationOverrides.flowering ?? settings?.durations.flowering ?? 56) / 7, { maximumFractionDigits: 2 }) }) }}</span><StrainGeneticsTag :classification="row.classification" /></div></div></template>
<template #stock="{ row }">{{ row.seedStock ? seedQuantity(row.seedStock.quantity) : '—' }}</template>
<template #yield="{ row }">{{ row.theoreticalYield == null ? '—' : t('catalog.yieldValue', { count: n(Number(row.theoreticalYield), { maximumFractionDigits: 2 }) }) }}</template>
<template #actions="{ row }"><div class="collection-table-actions"><StrainRecordActions :strain="row" :disabled="saving || loading" :error="error ? t(error) : ''" :upload-file="(file, disk) => addRecordPhoto(row, null, file, disk)" @photo="addRecordPhoto(row, $event)" @edit="edit(row)" @remove="confirmDeletion(row)" @failure="failure" /></div></template></CollectionTable>

   <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="saving || loading" :label="t('catalog.pagination')" :previous-label="t('catalog.previous')" :next-label="t('catalog.next')" @page="collectionQuery.go($event)" /></template></Card></template>

<style scoped>
.strain-detail { min-width: 0; overflow-wrap: anywhere; }

.catalog-card { padding: var(--space-3); } h2, h3 { margin: 0; letter-spacing: -.025em; } p { line-height: 1.6; font-size: .9rem; }
.catalog-heading, .catalog-toolbar, .catalog-actions { display: flex; flex-wrap: wrap; gap: var(--ui-grid-gap); align-items: center; justify-content: space-between; }
.catalog-toolbar { align-items: end; justify-content: flex-start; margin: var(--ui-content-gap) 0; } .catalog-toolbar .field { margin: 0; }
.catalog-panel { padding: var(--space-6); background: var(--theme-subtle, #f5faf7); border: 1px solid var(--theme-border, #c1d3c8); border-radius: 1rem; margin: var(--ui-content-gap) 0; }
.metadata-grid, .duration-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ui-grid-gap) var(--ui-grid-gap); }
.field { min-width: 0; margin: var(--space-2) 0; } input, textarea, select { width: 100%; font: inherit; border: 1px solid var(--theme-border, #aebfb5); border-radius: .5rem; padding: var(--space-3); background: var(--theme-surface, white); color: inherit; }
input:focus-visible, textarea:focus-visible, select:focus-visible, a:focus-visible { outline: 2px solid var(--theme-focus, #08764b); outline-offset: 3px; }
input[aria-invalid="true"] { border-color: var(--theme-danger, #b42318); } .archive-check { display: flex; align-items: center; gap: var(--space-3); margin-top: var(--ui-content-gap); }
 .strain-tile { min-width: 0; padding: var(--space-6); border: 1px solid var(--theme-border, #c1d3c8); border-radius: 1rem; }
dt { font-weight: 600; font-size: .8rem; } dd { margin: var(--space-1) 0 var(--ui-content-gap); font-size: .9rem; line-height: 1.6; white-space: pre-wrap; } h3, dd, a, label { overflow-wrap: anywhere; }
a { color: var(--theme-success, #08764b); } details { margin: var(--ui-content-gap) 0; } .catalog-badge { background: var(--theme-subtle, #edf1ee); padding: var(--space-1) var(--space-3); border-radius: 2rem; font-size: .8rem; }
@media (max-width: 700px) { .metadata-grid, .duration-grid { grid-template-columns: 1fr; } .catalog-card { padding: 0; } .catalog-panel, .strain-tile { padding: var(--space-4); } }
.lineage-value { display: flex; align-items: center; gap: var(--space-1-5); width: 100%; min-width: 0; padding: 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.lineage-value > span { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lineage-value > svg { width: .9rem; height: .9rem; flex: 0 0 auto; color: var(--theme-muted); }
.lineage-value:hover { color: var(--theme-accent); }
.lineage-value:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 3px; border-radius: .2rem; }
.lineage-full:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 3px; border-radius: .2rem; }
.lineage-full { margin: 0; max-width: min(28rem, calc(100vw - 4rem)); max-height: min(60dvh, 26rem); overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-size: .85rem; line-height: 1.6; }
</style>

<style scoped>
.strain-editor-tabs { min-width: 0; width: 100%; }
.strain-editor-panel { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.strain-editor-panel > .field { margin-block: 0; }
.strain-editor-panel > .archive-check { margin: 0; }
.strain-editor-panel > p { margin: 0; color: var(--theme-muted); font-size: .75rem; }
</style>
