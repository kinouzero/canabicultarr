<script setup lang="ts">
import { inventoryTabs, isTab, tabAtPath } from '../../tab-paths';
import { useFormTabs } from '../../lib/form-tabs';
import AppSwitch from '../../components/AppSwitch.vue';
import AppTabs from '../../components/AppTabs.vue';
import RecordQrButton from '../../components/RecordQrButton.vue';
import { watch } from 'vue';
import { useResolvedRecordQuery } from '../../lib/route-query';
import { toRef } from 'vue';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import RecordBatchAction from '../../components/RecordBatchAction.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionIdentity from '../../components/CollectionIdentity.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import PageTitle from '../../components/PageTitle.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import { inventoryLocationLabel } from '../../lib/inventory-location';
import type { StorageLocation, StockLocationSnapshot } from '../../api/inventory';
import StorageLocationsPanel from '../../components/StorageLocationsPanel.vue';
import { NTabPane } from 'naive-ui';




import { useRoute, useRouter } from 'vue-router';
import NotesEditor from '../../components/NotesEditor.vue';
import RichNotes from '../../components/RichNotes.vue';
import DetailDialog from '../../components/DetailDialog.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import { computed, useId, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import { NInput as Textarea } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import StorageLocationSelect from '../../components/StorageLocationSelect.vue';
import en from '../../locales/en/inventory.json';
import fr from '../../locales/fr/inventory.json';
import { ApiError } from '../../api/auth';
import { inventoryApi, inventoryItemTypes, type InventoryItemType, type InventoryItem, type StockBalance, type StockMovement, type StockUnit, type MovementKind, type MovementInput, type ItemInput } from '../../api/inventory';
const pageSize = useCollectionPageSize('inventory');
const vRememberDisclosure = useRememberDisclosure('InventoryRegistry');
const sort = useCollectionSort('inventory', sortOptions.inventory);
const props = defineProps<{ embedded?: boolean; canManage: boolean }>();
const route = useRoute(); const router = useRouter();
const inventoryTab = computed(() => tabAtPath(inventoryTabs, route.path) ?? 'items');
const storagePanel = ref<InstanceType<typeof StorageLocationsPanel> | null>(null);
function selectTab(value: string | number) { if (isTab(inventoryTabs, value)) void router.push({ path: inventoryTabs[value], query: route.query }); }
const canEditCatalog = ref(false);
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { inventory: en }); mergeLocaleMessage('fr', { inventory: fr });
const typeIcons: Record<InventoryItemType, string> = { seeds: 'seed', fertilizer: 'ph', substrate: 'substrate', container: 'pot', other: 'box' };
const units: StockUnit[] = ['piece', 'g', 'kg', 'ml', 'l', 'm']; const kinds: MovementKind[] = ['receipt', 'issue', 'transfer', 'count'];
const entries = ref<InventoryItem[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const filters = reactive({ search: '', type: '', state: 'active', low: false }); const loading = ref(false); const saving = ref(false); const detailLoading = ref(false);
const error = ref(''); const success = ref('');
const formOpen = ref(false); const editing = ref<InventoryItem | null>(null);
const form = reactive({ name: '', sku: '', description: '', type: 'other' as InventoryItemType, unit: 'piece' as StockUnit, decimals: 0, dose: '', capacity: '', threshold: '', archived: false });
const stockRoute = useResolvedRecordQuery('stock', inventoryApi.show, showError); const detail = stockRoute.record; const balances = ref<StockBalance[]>([]); const history = ref<StockMovement[]>([]);
const balancePage = ref(1); const balanceLast = ref(1); const historyPage = ref(1); const historyLast = ref(1);
const movementOpen = ref(false); const movement = reactive({ kind: 'receipt' as MovementKind, quantity: '', reason: '' });
const source = ref<StorageLocation | null>(null); const destination = ref<StorageLocation | null>(null);
const pending = ref<MovementInput | null>(null);
let listSequence = 0; let detailSequence = 0; let active = true;
const itemBaseline = ref(''); const movementBaseline = ref('');
function itemSnapshot() { return JSON.stringify(form); }
function movementSnapshot() { return JSON.stringify([movement, source.value ? [source.value.id] : null, destination.value ? [destination.value.id] : null]); }
const blocked = computed(() => saving.value || !!pending.value);
const dirty = computed(() => blocked.value || formOpen.value && itemSnapshot() !== itemBaseline.value || movementOpen.value && movementSnapshot() !== movementBaseline.value);
const unsaved = useUnsavedChanges(dirty, blocked, { onNavigate: () => { formOpen.value = false; movementOpen.value = false; } });
const editorTabs = useFormTabs(formOpen, {"unit": "stock", "decimal_places": "stock", "threshold": "stock", "default_dose_per_liter": "stock", "capacity_liters": "stock", "description": "notes"});
const editorTab = editorTabs.tab;
const itemValidation = useFieldErrors(() => Object.fromEntries([
 ['type', 'inventory-type', 'typeInvalid'], ['name', 'inventory-name', 'nameInvalid'], ['sku', 'inventory-sku', 'skuInvalid'], ['unit', 'inventory-unit', 'unitInvalid'],
 ['default_dose_per_liter', 'inventory-dose', 'doseInvalid'], ['capacity_liters', 'inventory-capacity', 'capacityInvalid'], ['decimal_places', 'inventory-decimals', 'decimalsInvalid'], ['threshold', 'inventory-threshold', 'quantityInvalid'],
 ['description', 'inventory-description', 'descriptionInvalid'], ['is_archived', 'inventory-archived', 'stateInvalid'],
].map(([field, id, message]) => [field, { id, message: `inventory.validation.${message}` }])),
 () => ({ ...form, threshold: JSON.stringify([form.threshold, form.unit]), decimal_places: form.decimals, default_dose_per_liter: form.dose, capacity_liters: form.capacity, is_archived: form.archived }), editorTabs.reveal);
const movementValidation = useFieldErrors(() => Object.fromEntries([
 ['kind', 'movement-kind', 'kindInvalid'], ['quantity', 'movement-quantity', 'quantityInvalid'],
 ['source', 'inventory-source', 'locationInvalid'], ['destination', 'inventory-destination', 'locationInvalid'], ['reason', 'movement-reason', 'reasonInvalid'],
].map(([field, id, message]) => [field, { id, message: `inventory.validation.${message}` }])),
 () => ({ ...movement, quantity: JSON.stringify([movement.quantity, movement.kind, detail.value?.unit]), source: source.value ? source.value.id : null, destination: JSON.stringify([destination.value?.id, source.value?.id]) }));
const fields = computed(() => formOpen.value ? itemValidation.fields.value : movementValidation.fields.value);

function clearFields() { itemValidation.clear(); movementValidation.clear(); }
async function closeForm() { if (await unsaved.confirmDiscard()) { formOpen.value = movementOpen.value = false; error.value = ''; clearFields(); } }
async function leave() { if (await unsaved.confirmDiscard()) emit('close'); }
function quantityError(value: string, unit: StockUnit, allowZero = true) {
 if (!/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/.test(value)) return 'inventory.validation.quantityInvalid';
 if (unit === 'piece' && Number(value) % 1 !== 0) return 'inventory.validation.wholePieces';
 return !allowZero && Number(value) === 0 ? 'inventory.validation.quantityPositive' : undefined;
}
function key(value: string) { return /^(auth|inventory)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function showError(cause: unknown) {
 if (!active) return;
 error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (cause instanceof ApiError) {
  const validation = formOpen.value ? itemValidation : movementOpen.value ? movementValidation : null;
  validation?.capture(cause);
  if (cause.status === 422) error.value = 'inventory.validation.invalid';
  const field = formOpen.value ? ({ 'inventory.errors.skuTaken': 'sku', 'inventory.errors.unitLocked': 'unit', 'inventory.errors.stockRemaining': 'is_archived' } as Record<string, string>)[cause.code]
   : movementOpen.value ? ({ 'inventory.errors.insufficientStock': 'quantity', 'inventory.errors.quantityLimit': 'quantity' } as Record<string, string>)[cause.code] : undefined;
  if (field && validation) { validation.set(field, cause.code); void validation.focus(); }
  // Only known inventory advice can specialize a field; never display arbitrary server text.
  if (validation) for (const [field, messages] of Object.entries(cause.fields)) {
   const advice = messages[0];
   if (['inventory.validation.wholePieces', 'inventory.validation.quantityPositive', 'inventory.validation.differentLocations', 'inventory.validation.seedUnit', 'inventory.validation.linkedSeeds'].includes(advice ?? '')) validation.set(field.split('.')[0], advice);
  }
  if ([401, 403].includes(cause.status)) { entries.value = []; detail.value = null; formOpen.value = movementOpen.value = false; pending.value = null; emit('accessLost', cause); }
 }
}
function context(location: StockLocationSnapshot | null) { return inventoryLocationLabel(location) || t('inventory.noLocation'); }
function stockNumber(value: string, decimals: number) { return new Intl.NumberFormat(locale.value, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value)); }
function quantity(value: string | null, unit: StockUnit, decimals = detail.value?.decimalPlaces ?? (unit === 'piece' ? 0 : 3)) {
 return value === null ? '—' : `${stockNumber(value, decimals)} ${t(`inventory.units.${unit}`)}`;
}
function date(iso: string) { return formatDateTime(iso, locale.value); }
async function load(target = 1) {
 const current = ++listSequence; loading.value = true;
 try { const query = { per_page: String(pageSize.value), sort: sort.value, search: filters.search.trim(), type: filters.type, state: filters.state, low_stock: filters.low ? '1' : '0' }; let result = await inventoryApi.list(query, target); if (current !== listSequence) return; if (target > result.meta.last_page) result = await inventoryApi.list(query, result.meta.last_page); if (current !== listSequence) return; entries.value = result.data; canEditCatalog.value = result.canEditCatalog ?? false; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total; }
 catch (cause) { if (current === listSequence) showError(cause); } finally { if (current === listSequence) loading.value = false; }
}
async function openItem(entry: InventoryItem | null = null) {
 if (await unsaved.confirmDiscard()) replaceItem(entry);
}
function replaceItem(entry: InventoryItem | null = null) {
 editing.value = entry; Object.assign(form, { name: entry?.name ?? '', sku: entry?.sku ?? '', description: entry?.description ?? '', type: entry?.type ?? 'other', unit: entry?.unit ?? 'piece', decimals: entry?.decimalPlaces ?? (entry?.unit && entry.unit !== 'piece' ? 3 : 0), dose: entry?.defaultDosePerLiter ?? '', capacity: entry?.capacityLiters ?? '', threshold: entry?.threshold ?? '', archived: entry?.isArchived ?? false });
 itemBaseline.value = itemSnapshot(); formOpen.value = true; movementOpen.value = false; error.value = success.value = ''; clearFields();
}
async function saveItem() {
 if (blocked.value) return; clearFields(); error.value = success.value = '';
 if (!form.name.trim() || form.name.length > 120) itemValidation.set('name');
 if (form.sku.length > 80) itemValidation.set('sku');
 if (form.description.length > 2000) itemValidation.set('description');
 if (!inventoryItemTypes.includes(form.type)) itemValidation.set('type');
 if (form.type === 'seeds' && form.unit !== 'piece') itemValidation.set('unit', 'inventory.validation.seedUnit');
 if (!units.includes(form.unit)) itemValidation.set('unit');
 if (form.type === 'container' && form.capacity && (!/^\d{1,9}(?:\.\d{1,3})?$/.test(form.capacity) || Number(form.capacity) <= 0 || Number(form.capacity) > 1000000 || form.unit !== 'piece')) itemValidation.set('capacity_liters');
 if (form.type === 'fertilizer' && form.dose && (!/^\d{1,9}(?:\.\d{1,3})?$/.test(form.dose) || Number(form.dose) <= 0 || Number(form.dose) > 1000000 || !['ml', 'l', 'g', 'kg'].includes(form.unit))) itemValidation.set('default_dose_per_liter');
 if (!Number.isInteger(form.decimals) || form.decimals < 0 || form.decimals > 3) itemValidation.set('decimal_places');
 const thresholdError = form.threshold ? quantityError(form.threshold, form.unit) : undefined;
 if (thresholdError) itemValidation.set('threshold', thresholdError);
 if (Object.keys(itemValidation.fields.value).length) { error.value = 'inventory.validation.invalid'; void itemValidation.focus(); return; }
 saving.value = true;
 try { const input: ItemInput = { name: form.name, sku: form.sku || null, description: form.description || null, type: form.type, unit: form.unit, decimal_places: form.decimals, default_dose_per_liter: form.type === 'fertilizer' ? form.dose || null : null, capacity_liters: form.type === 'container' ? form.capacity || null : null, threshold: form.threshold || null, is_archived: form.archived };
  if (editing.value) { input.version = editing.value.version; await inventoryApi.update(editing.value.id, input); } else await inventoryApi.create(input);
  if (!active) return; formOpen.value = false; success.value = 'inventory.itemSaved'; await load(editing.value ? page.value : 1); if (detail.value && editing.value?.id === detail.value.id) await showDetails(detail.value);
 } catch (cause) { showError(cause); } finally { saving.value = false; }
}
async function showDetails(entry: InventoryItem, targetBalance = 1, targetHistory = 1) {
 if (detail.value?.id !== entry.id) { if (await unsaved.confirmDiscard()) void stockRoute.open(entry.id); return; }
 return loadStock(entry, targetBalance, targetHistory, true);
}
async function loadStock(entry: InventoryItem, targetBalance = 1, targetHistory = 1, refresh = false) {
 if ((formOpen.value || movementOpen.value) && !await unsaved.confirmDiscard()) return;
 const current = ++detailSequence; detail.value = entry; detailLoading.value = true; formOpen.value = false; movementOpen.value = false;
 try {
  const [record, stock, movements] = await Promise.all([refresh ? inventoryApi.show(entry.id) : Promise.resolve(entry), inventoryApi.balances(entry.id, targetBalance), inventoryApi.movements(entry.id, targetHistory)]);
  if (current !== detailSequence) return; detail.value = record; balances.value = stock.data; history.value = movements.data; balancePage.value = stock.meta.current_page; balanceLast.value = stock.meta.last_page; historyPage.value = movements.meta.current_page; historyLast.value = movements.meta.last_page;
 } catch (cause) { if (current === detailSequence) showError(cause); } finally { if (current === detailSequence) detailLoading.value = false; }
}
async function closeDetails() { if (!await unsaved.confirmDiscard()) return; movementOpen.value = false; void stockRoute.close(); }
async function openMovement() { if (!await unsaved.confirmDiscard()) return; movement.kind = 'receipt'; movement.quantity = movement.reason = ''; source.value = destination.value = null; pending.value = null; clearFields(); error.value = success.value = ''; movementBaseline.value = movementSnapshot(); movementOpen.value = true; formOpen.value = false; }
async function record() {
 if (saving.value || !detail.value) return;
 clearFields(); error.value = success.value = '';
 if (!pending.value) {
  const needsSource = ['issue', 'transfer'].includes(movement.kind); const needsDestination = ['receipt', 'transfer', 'count'].includes(movement.kind);
  if (!kinds.includes(movement.kind)) movementValidation.set('kind');
  const invalidQuantity = quantityError(movement.quantity, detail.value.unit, movement.kind === 'count');
  if (invalidQuantity) movementValidation.set('quantity', invalidQuantity);
  if (needsSource && !source.value) movementValidation.set('source');
  if (needsDestination && !destination.value) movementValidation.set('destination');
  if (movement.kind === 'transfer' && source.value && destination.value && source.value.id === destination.value.id) movementValidation.set('destination', 'inventory.validation.differentLocations');
  if (!movement.reason.trim() || movement.reason.length > 500) movementValidation.set('reason');
  if (Object.keys(movementValidation.fields.value).length) { error.value = 'inventory.validation.invalid'; void movementValidation.focus(); return; }
  pending.value = { kind: movement.kind, quantity: movement.quantity, reason: movement.reason, version: detail.value.version, request_id: crypto.randomUUID(), ...(needsSource ? { source: { id: source.value!.id } } : {}), ...(needsDestination ? { destination: { id: destination.value!.id } } : {}) };
 }
 saving.value = true;
 try { await inventoryApi.record(detail.value.id, pending.value); if (!active) return; pending.value = null; movementOpen.value = false; success.value = 'inventory.movementSaved'; await showDetails(detail.value); await load(page.value); }
 catch (cause) { showError(cause); if (cause instanceof ApiError && cause.status < 500) pending.value = null; else error.value = 'inventory.errors.uncertainResult'; }
 finally { saving.value = false; }
}
async function reloadCurrent() {
 if (!await unsaved.confirmDiscard()) return; saving.value = true; error.value = ''; clearFields();
 try { if (formOpen.value && editing.value) replaceItem(await inventoryApi.show(editing.value.id)); else if (detail.value) { movementOpen.value = false; await showDetails(detail.value); await load(page.value); } } catch (cause) { showError(cause); } finally { saving.value = false; }
}
onMounted(() => load(page.value)); onUnmounted(() => { active = false; listSequence++; detailSequence++; });
const dialogId = useId();
watch(() => detail.value?.id, id => {
 detailSequence++; history.value = []; balances.value = []; detailLoading.value = false;
 if (!id) { formOpen.value = false; movementOpen.value = false; }
 if (id && detail.value) { formOpen.value = false; movementOpen.value = false; void loadStock(detail.value, balancePage.value, historyPage.value); }
});
const collectionQuery = useCollectionQuery({ paths: [inventoryTabs.items], fields: { q: queryField.text(toRef(filters, 'search'), 160), type: queryField.choice(toRef(filters, 'type'), ['', 'seeds', 'fertilizer', 'substrate', 'container', 'other']), state: queryField.choice(toRef(filters, 'state'), ['active', 'archived', 'all']), low: queryField.boolean(toRef(filters, 'low')), sort: queryField.choice(sort, sortOptions.inventory) }, page, pageSize, load: target => (error.value = '', load(target)) });
const applyFilters = collectionQuery.apply;
const balanceQuery = useCollectionQuery({ paths: ['/inventory'], prefix: 'stockBalances.', fields: {}, page: balancePage, load: target => detail.value && loadStock(detail.value, target, historyPage.value) });
const movementQuery = useCollectionQuery({ paths: ['/inventory'], prefix: 'stockHistory.', fields: {}, page: historyPage, load: target => detail.value && loadStock(detail.value, balancePage.value, target) });


const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <Card class="inventory-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="inventory">{{ t('inventory.title') }}</PageTitle></div><div ref="selectionHeader" class="page-actions"><template v-if="inventoryTab === 'storage'"><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="storagePanel?.loading" :disabled="storagePanel?.saving" @click="storagePanel?.reload()" /><ListActionButton v-if="storagePanel?.canManage" icon="plus" :label="t('storageLocations.add')" :disabled="storagePanel.loading || storagePanel.saving" @click="storagePanel.create()" /></template><template v-else><Button v-if="!embedded" :aria-label="t('inventory.back')" type="default" :disabled="saving || !!pending" @click="leave" :title="t('inventory.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="loading" :disabled="saving || !!pending" @click="load(page)" /><CollectionViewToggle preference-key="inventory" :disabled="saving || loading || !!pending" /><Button v-if="canManage && canEditCatalog" :aria-label="t('inventory.add')" :disabled="saving || !!pending" @click="openItem()" data-action-icon="plus" :title="t('inventory.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></template></div></div><div class="card-subtitle">{{ t(canManage ? 'inventory.description' : 'inventory.readDescription') }}</div></div></template>

  <template #default><AppTabs :value="inventoryTab" @update:value="selectTab" animated><NTabPane name="items" display-directive="show:lazy"><template #tab><AppIcon name="inventory" />{{ t('inventory.title') }}</template><Message v-if="error && !(formOpen || movementOpen)" type="error" closable role="alert">{{ t(error) }}</Message>
   <Button v-if="!(formOpen || movementOpen) && (error === 'inventory.errors.conflict')" :aria-label="t('inventory.reloadCurrent')" type="default" :loading="saving" @click="reloadCurrent"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button>
   <Message v-if="success" type="success" closable role="status">{{ t(success) }}</Message>

   <form class="filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="filter-field"><label for="inventory-search">{{ t('inventory.search') }}</label><InputText placeholder="" v-model:value="filters.search" maxlength="160" :disabled="saving || !!pending" :input-props="{ 'id': 'inventory-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.inventory" /></div>
    <div class="filter-secondary"><div class="filter-field"><label for="inventory-type-filter">{{ t('inventory.type') }}</label><select id="inventory-type-filter" v-model="filters.type" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :disabled="saving || !!pending"><option value="">{{ t('inventory.allTypes') }}</option><option v-for="type in inventoryItemTypes" :key="type" :value="type">{{ t(`inventory.types.${type}`) }}</option></select></div>
     <div class="filter-field"><label for="inventory-state">{{ t('inventory.state') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="inventory-state" v-model="filters.state" :disabled="saving || !!pending"><option v-for="state in ['active','archived','all']" :key="state" :value="state">{{ t(`inventory.states.${state}`) }}</option></select></div>
     <AppSwitch class="filter-checkbox" id="inventory-low" v-model="filters.low" :disabled="saving || !!pending">{{ t('inventory.lowOnly') }}</AppSwitch></div>
    <ResetFilters @reset="Object.assign(filters, { search: '', type: '', state: 'active', low: false }); sort = 'name_asc'" /></form>
   <FormDialog icon="folder" v-if="formOpen" :visible="true" :title="t(editing ? 'inventory.editTitle' : 'inventory.createTitle')" :close-label="t('inventory.cancel')" :busy="saving || !!pending" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><template #feedback><Button v-if="error === 'inventory.errors.conflict'" :aria-label="t('inventory.reloadCurrent')" type="default" :loading="saving" @click="reloadCurrent"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form panel" @submit.prevent="saveItem" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><fieldset class="dialog-section"><legend>{{ t('modalSections.identity') }}</legend>
<div class="dialog-form-grid"><div class="field dialog-wide"><label for="inventory-name">{{ t('inventory.name') }}</label><InputText placeholder="" v-bind="itemValidation.attrs('name')" v-model:value="form.name" maxlength="120" :disabled="saving" :status="!!fields.name ? 'error' : undefined" :input-props="{ 'id': 'inventory-name' }"></InputText><small v-if="fields.name" id="inventory-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>
<div class="field"><label for="inventory-type">{{ t('inventory.type') }}</label><select id="inventory-type" v-bind="itemValidation.attrs('type')" v-model="form.type" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :disabled="saving" @change="form.type === 'seeds' && !editing?.unitLocked && (form.unit = 'piece', form.decimals = 0)"><option v-for="type in inventoryItemTypes" :key="type" :value="type">{{ t(`inventory.types.${type}`) }}</option></select><small v-if="fields.type" id="inventory-type-error" role="alert" class="field-error">{{ t(fields.type) }}</small></div>
<div class="field"><label for="inventory-sku">{{ t('inventory.sku') }}</label><InputText placeholder="" v-bind="itemValidation.attrs('sku')" v-model:value="form.sku" maxlength="80" :disabled="saving" :status="!!fields.sku ? 'error' : undefined" :input-props="{ 'id': 'inventory-sku' }"></InputText><small v-if="fields.sku" id="inventory-sku-error" role="alert" class="field-error">{{ t(fields.sku) }}</small></div></div></fieldset>
<div v-if="editing" class="field"><AppSwitch id="inventory-archived" v-bind="itemValidation.attrs('is_archived')" v-model="form.archived" :disabled="saving">{{ t('inventory.archived') }}</AppSwitch><small>{{ t('inventory.archiveHelp') }}</small><small v-if="fields.is_archived" id="inventory-archived-error" class="field-error" role="alert">{{ t(fields.is_archived) }}</small></div></div></NTabPane><NTabPane name="stock" display-directive="show"><template #tab><AppIcon name="inventory" />{{ t('editorTabs.stock') }}</template><div class="dialog-form"><fieldset class="dialog-section"><legend>{{ t('modalSections.stock') }}</legend>
<div class="dialog-form-grid"><div class="field"><label for="inventory-unit">{{ t('inventory.unit') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="inventory-unit" v-bind="itemValidation.attrs('unit')" v-model="form.unit" @change="!editing && (form.decimals = form.unit === 'piece' ? 0 : 3)" :disabled="saving || !!editing?.unitLocked || form.type === 'seeds'"><option v-for="unit in units" :key="unit" :value="unit">{{ t(`inventory.units.${unit}`) }}</option></select><small>{{ t('inventory.unitHelp') }}</small><small v-if="fields.unit" id="inventory-unit-error" role="alert" class="field-error">{{ t(fields.unit) }}</small></div>
<div class="field"><label for="inventory-decimals">{{ t('inventory.decimals') }}</label><select id="inventory-decimals" v-bind="itemValidation.attrs('decimal_places')" v-model="form.decimals" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :disabled="saving" :aria-invalid="!!fields.decimal_places"><option v-for="digits in [0, 1, 2, 3]" :key="digits" :value="digits">{{ digits }}</option></select><small>{{ t('inventory.decimalsHelp') }}</small><small v-if="fields.decimal_places" id="inventory-decimals-error" role="alert" class="field-error">{{ t(fields.decimal_places) }}</small></div>
<div class="field"><label for="inventory-threshold">{{ t('inventory.threshold') }}</label><InputText placeholder="" v-bind="itemValidation.attrs('threshold')" v-model:value="form.threshold" maxlength="13" :disabled="saving" :status="!!fields.threshold ? 'error' : undefined" :input-props="{ 'id': 'inventory-threshold', 'inputmode': 'decimal' }"></InputText><small>{{ t('inventory.quantityHelp') }}</small><small v-if="fields.threshold" id="inventory-threshold-error" role="alert" class="field-error">{{ t(fields.threshold) }}</small></div>
<div v-if="form.type === 'fertilizer'" class="field"><label for="inventory-dose">{{ t('inventory.defaultDose', { unit: form.unit }) }}</label><input id="inventory-dose" v-bind="itemValidation.attrs('default_dose_per_liter')" v-model="form.dose" type="text" inputmode="decimal" :disabled="saving" /><small>{{ t('inventory.doseHelp') }}</small><small v-if="fields.default_dose_per_liter" id="inventory-dose-error" role="alert" class="field-error">{{ t(fields.default_dose_per_liter) }}</small></div>
<div v-if="form.type === 'container'" class="field"><label for="inventory-capacity">{{ t('inventory.capacity') }}</label><input id="inventory-capacity" v-bind="itemValidation.attrs('capacity_liters')" v-model="form.capacity" type="text" inputmode="decimal" :disabled="saving" /><small v-if="fields.capacity_liters" id="inventory-capacity-error" role="alert" class="field-error">{{ t(fields.capacity_liters) }}</small></div></div></fieldset>
    </div></NTabPane><NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="file" />{{ t('editorTabs.notes') }}</template><div class="dialog-form"><div class="field dialog-wide"><label for="inventory-description">{{ t('inventory.notes') }}</label><NotesEditor id="inventory-description" :invalid="!!fields.description" :described-by="fields.description ? 'inventory-description-error' : undefined" v-model="form.description" :label="t('inventory.notes')" :disabled="saving" /><small v-if="fields.description" id="inventory-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div>
    </div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('inventory.cancel')" type="default" :disabled="saving" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('inventory.saveItem') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
   <DetailDialog v-if="detail" module :title="detail.name" :close-label="t('inventory.closeDetails')" :busy="saving || !!pending" :error="error ? t(error) : ''" icon="folder" @close="closeDetails"><section class="modal-panel"><p v-if="detailLoading" role="status">{{ t('inventory.loading') }}</p>
    <template v-else><nav class="record-actions" :aria-label="t('recordDetails.actions')"><div v-if="detail.canManage && !detail.isArchived" class="record-action-group record-care-actions"><Button :disabled="saving || !!pending" data-action-icon="box" @click="openMovement" type="primary"><template #icon><AppIcon name="box" /></template><span>{{ t('inventory.record') }}</span></Button></div><div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: 'stock', id: detail.id, name: detail.name }" :disabled="saving || !!pending" /><Button v-if="detail.canManage && detail.canEditCatalog !== false" :aria-label="t('inventory.editNamed', { name: detail.name })" type="default" :disabled="saving || !!pending" data-action-icon="edit" @click="openItem(detail)"><template #icon><AppIcon name="edit" /></template><span>{{ t('recordDetails.edit') }}</span></Button><Button :aria-label="t('inventory.refresh')" type="default" :disabled="saving || !!pending" data-action-icon="refresh" @click="showDetails(detail, balancePage, historyPage)"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></div></nav>
     <div class="stock-record-meta"><Tag type="default">{{ t(`inventory.types.${detail.type}`) }}</Tag><Tag :type="detail.isLowStock ? 'warning' : 'default'">{{ t(detail.isArchived ? 'inventory.archived' : detail.isLowStock ? 'inventory.lowStock' : 'inventory.states.active') }}</Tag><p v-if="detail.sku">{{ t('inventory.sku') }}: <span class="card-reference" :title="detail.sku">{{ detail.sku }}</span></p><p v-if="detail.threshold !== null">{{ t('inventory.threshold') }}: {{ quantity(detail.threshold, detail.unit) }}</p><RichNotes v-if="detail.description" :value="detail.description" /></div>
     <p class="stock-total">{{ t('inventory.totalStock') }}: {{ quantity(detail.totalQuantity, detail.unit) }}</p>
     <p v-if="detail.isLowStock">{{ t('inventory.lowStock') }}</p>

     <FormDialog icon="folder" v-if="movementOpen" :visible="true" :title="t('inventory.record')" :close-label="t('inventory.cancel')" :busy="saving || !!pending" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><template #feedback><Button v-if="error === 'inventory.errors.conflict'" :aria-label="t('inventory.reloadCurrent')" type="default" :loading="saving" @click="reloadCurrent"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-1`" class="dialog-form movement-form" @submit.prevent="record" novalidate><div class="grid"><div class="field"><label for="movement-kind">{{ t('inventory.kind') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="movement-kind" v-bind="movementValidation.attrs('kind')" v-model="movement.kind" :disabled="saving || !!pending" @change="source = destination = null"><option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`inventory.kinds.${kind}`) }}</option></select></div>
       <div class="field"><label for="movement-quantity">{{ t(movement.kind === 'count' ? 'inventory.countQuantity' : 'inventory.quantity') }} ({{ t(`inventory.units.${detail.unit}`) }})</label><InputText placeholder="" v-bind="movementValidation.attrs('quantity')" v-model:value="movement.quantity" maxlength="13" :disabled="saving || !!pending" :status="!!fields.quantity ? 'error' : undefined" :input-props="{ 'id': 'movement-quantity', 'inputmode': 'decimal', 'aria-describedby': fields.quantity ? 'movement-quantity-help movement-quantity-error' : 'movement-quantity-help' }"></InputText><small id="movement-quantity-help">{{ t('inventory.quantityHelp') }}</small><small v-if="fields.quantity" id="movement-quantity-error" role="alert" class="field-error">{{ t(fields.quantity) }}</small></div></div>
      <p v-if="movement.kind === 'count'">{{ t('inventory.countHelp') }}</p>
      <div v-if="['issue','transfer'].includes(movement.kind)" class="location"><StorageLocationSelect id="inventory-source" :invalid="!!fields.source" :described-by="fields.source ? 'inventory-source-error' : undefined" v-model="source" :label="t('inventory.source')" :placeholder="t('inventory.chooseSource')" :disabled="saving || !!pending" @access-lost="showError" /><small v-if="fields.source" id="inventory-source-error" role="alert" class="field-error">{{ t(fields.source) }}</small></div>
      <div v-if="['receipt','transfer','count'].includes(movement.kind)" class="location"><StorageLocationSelect id="inventory-destination" :invalid="!!fields.destination" :described-by="fields.destination ? 'inventory-destination-error' : undefined" v-model="destination" :label="t('inventory.destination')" :placeholder="t('inventory.chooseDestination')" :disabled="saving || !!pending" @access-lost="showError" /><small v-if="fields.destination" id="inventory-destination-error" role="alert" class="field-error">{{ t(fields.destination) }}</small></div>
      <div class="field"><label for="movement-reason">{{ t('inventory.reason') }}</label><Textarea placeholder="" v-bind="movementValidation.attrs('reason')" v-model:value="movement.reason" rows="3" maxlength="500" :disabled="saving || !!pending" :status="!!fields.reason ? 'error' : undefined" type="textarea" :input-props="{ 'id': 'movement-reason' }"></Textarea><small v-if="fields.reason" id="movement-reason-error" role="alert" class="field-error">{{ t(fields.reason) }}</small></div></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('inventory.cancel')" type="default" :disabled="saving || !!pending" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-1`" attr-type="submit" :loading="saving" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t(pending ? 'inventory.retry' : 'inventory.saveMovement') }}</span></Button></div></template></FormDialog>
     <h4>{{ t('inventory.balances') }}</h4><p v-if="!balances.length">{{ t('inventory.noBalances') }}</p>
     <ul class="balances"><li v-for="balance in balances" :key="balance.id"><span>{{ context(balance.location) }}</span><strong>{{ quantity(balance.quantity, detail.unit) }}</strong></li></ul>
     <nav v-if="balanceLast > 1" class="heading" :aria-label="t('inventory.balancePagination')"><Button :disabled="saving || !!pending || balancePage <= 1" @click="balanceQuery.go(balancePage - 1)" type="primary"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('inventory.previous') }}</span></Button><span>{{ t('inventory.page', { current: balancePage, total: balanceLast }) }}</span><Button :disabled="saving || !!pending || balancePage >= balanceLast" @click="balanceQuery.go(balancePage + 1)" type="primary"><template #icon><AppIcon name="arrow" /></template><span>{{ t('inventory.next') }}</span></Button></nav>
     <h4>{{ t('inventory.history') }}</h4><p v-if="!history.length">{{ t('inventory.noMovements') }}</p>
     <ol class="history"><li v-for="entry in history" :key="entry.id"><details v-remember-disclosure="entry.id"><DisclosureSummary :level="3" icon="inventory">{{ t(`inventory.kinds.${entry.kind}`) }} · {{ quantity(entry.quantity, entry.unit) }} · {{ date(entry.recordedAt) }}</DisclosureSummary><p>{{ entry.itemName }} · {{ entry.actor.name ?? t('inventory.actorUnavailable') }}</p><p v-if="entry.source">{{ t('inventory.source') }}: {{ context(entry.source) }} · {{ quantity(entry.sourceBefore, entry.unit) }} → {{ quantity(entry.sourceAfter, entry.unit) }}</p><p v-if="entry.destination">{{ t('inventory.destination') }}: {{ context(entry.destination) }} · {{ quantity(entry.destinationBefore, entry.unit) }} → {{ quantity(entry.destinationAfter, entry.unit) }}</p><p v-if="entry.totalAfter !== null">{{ t('inventory.totalStock') }}: {{ quantity(entry.totalAfter, entry.unit) }}</p><p class="notes">{{ entry.reason }}</p></details></li></ol>
     <nav v-if="historyLast > 1" class="heading" :aria-label="t('inventory.historyPagination')"><Button :disabled="saving || !!pending || historyPage <= 1" @click="movementQuery.go(historyPage - 1)" type="primary"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('inventory.previous') }}</span></Button><span>{{ t('inventory.page', { current: historyPage, total: historyLast }) }}</span><Button :disabled="saving || !!pending || historyPage >= historyLast" @click="movementQuery.go(historyPage + 1)" type="primary"><template #icon><AppIcon name="arrow" /></template><span>{{ t('inventory.next') }}</span></Button></nav></template></section></DetailDialog>

   <p v-if="loading" role="status">{{ t('inventory.loading') }}</p><div v-else-if="!entries.length" class="resource-empty"><AppIcon name="box" /><span>{{ t('inventory.empty') }}</span></div>
   <CollectionTable :selection-target="inventoryTab === 'items' ? selectionHeader : null" preference-key="inventory" grid-class="item-list resource-grid" v-if="entries.length" :aria-busy="loading" :rows="entries" :can-select="entry => entry.canManage && entry.canEditCatalog !== false && !entry.isArchived" :row-name="entry => entry.name" :loading="loading" :locked="saving" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'type', label: t('inventory.type') }, { key: 'stock', label: t('inventory.totalStock') }, { key: 'unit', label: t('inventory.unit') }, { key: 'actions', label: t('collection.actions') }]">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(entry)" v-for="entry in entries" :key="entry.id" :icon="typeIcons[entry.type]" :eyebrow="t(`inventory.types.${entry.type}`)" :tone="entry.isLowStock ? 'warning' : undefined"><template #title><button type="button" :aria-label="t('inventory.viewNamed', { name: entry.name })" :disabled="saving || !!pending || detailLoading" @click="showDetails(entry)">{{ entry.name }}</button></template>
     <template #badge><Tag :type="entry.isArchived || Number(entry.totalQuantity) === 0 && !entry.isLowStock ? 'default' : entry.isLowStock ? 'warning' : 'success'">{{ t(entry.isArchived ? 'inventory.archived' : entry.isLowStock ? 'inventory.lowStock' : Number(entry.totalQuantity) === 0 ? 'collection.emptyStock' : 'collection.stockAvailable') }}</Tag></template>
     <div class="resource-metric" :aria-label="t('inventory.totalStock')"><strong>{{ stockNumber(entry.totalQuantity, entry.decimalPlaces ?? (entry.unit === 'piece' ? 0 : 3)) }}</strong><span>{{ t(`inventory.units.${entry.unit}`) }}</span></div>
     <dl class="resource-facts"><div v-if="entry.defaultDosePerLiter" class="resource-wide"><dt>{{ t('inventory.defaultDose', { unit: entry.unit }) }}</dt><dd>{{ Number(entry.defaultDosePerLiter) }} {{ entry.unit }}/L</dd></div><div v-if="entry.capacityLiters" class="resource-wide"><dt>{{ t('inventory.capacity') }}</dt><dd>{{ Number(entry.capacityLiters) }} L</dd></div><div v-if="entry.sku" class="resource-wide"><dt>{{ t('inventory.sku') }}</dt><dd class="card-reference" :title="entry.sku">{{ entry.sku }}</dd></div><div class="resource-wide"><dt><AppIcon name="bell" />{{ t('inventory.threshold') }}</dt><dd>{{ entry.threshold === null ? t('collection.noThreshold') : quantity(entry.threshold, entry.unit, entry.decimalPlaces) }}</dd></div></dl>
     <RichNotes v-if="entry.description" class="resource-note" :value="entry.description" />
     <template #management><ListActionButton icon="edit" v-if="entry.canManage && entry.canEditCatalog !== false" :label="t('inventory.editNamed', { name: entry.name })" :disabled="saving || !!pending" @click="openItem(entry)" /></template></ResourceCard></template><template #batch-actions="{ rows: selectedItems, clear }">
 <RecordBatchAction :rows="selectedItems" :action="entry => inventoryApi.update(entry.id, { version: entry.version, is_archived: true })" :name="entry => entry.name" :label="t('collection.batch.archive')" icon="archive" @applied="clear($event); load(page)" @access-lost="showError" />
</template><template #name="{ row: entry }"><div class="collection-table-name"><CollectionIdentity :name="entry.name" :icon="typeIcons[entry.type]" @open="showDetails(entry)" /><div class="collection-table-tags"><Tag :type="entry.isArchived || Number(entry.totalQuantity) === 0 && !entry.isLowStock ? 'default' : entry.isLowStock ? 'warning' : 'success'">{{ t(entry.isArchived ? 'inventory.archived' : entry.isLowStock ? 'inventory.lowStock' : Number(entry.totalQuantity) === 0 ? 'collection.emptyStock' : 'collection.stockAvailable') }}</Tag></div></div></template>
<template #type="{ row: entry }">{{ t(`inventory.types.${entry.type}`) }}</template>
<template #stock="{ row: entry }">{{ stockNumber(entry.totalQuantity, entry.decimalPlaces ?? (entry.unit === 'piece' ? 0 : 3)) }}</template>
<template #unit="{ row: entry }">{{ t(`inventory.units.${entry.unit}`) }}</template>
<template #actions="{ row: entry }"><div class="collection-table-actions"><ListActionButton icon="eye" :label="t('inventory.viewNamed', { name: entry.name })" :disabled="saving || !!pending || detailLoading" @click="showDetails(entry)" /><ListActionButton icon="edit" v-if="entry.canManage && entry.canEditCatalog !== false" :label="t('inventory.editNamed', { name: entry.name })" :disabled="saving || !!pending" @click="openItem(entry)" /></div></template></CollectionTable>
   <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="loading || saving || !!pending" :label="t('inventory.pagination')" :previous-label="t('inventory.previous')" :next-label="t('inventory.next')" @page="collectionQuery.go($event)" /></NTabPane><NTabPane name="storage" display-directive="show:lazy"><template #tab><AppIcon name="box" />{{ t('storageLocations.title') }}</template><StorageLocationsPanel v-if="inventoryTab === 'storage'" ref="storagePanel" @access-lost="showError" /></NTabPane></AppTabs></template></Card></template>
<style scoped>
.resource-metric { justify-content: flex-end; text-align: right; }
.inventory-card { padding: var(--space-3); } .heading, .actions { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap; } .actions { justify-content: flex-start; }
h2 { margin: 0; font-size: 1.6rem; } .filters { display: flex; align-items: flex-end; flex-wrap: wrap; gap: var(--space-3); margin: var(--ui-content-gap) 0; } .filters > div { display: grid; gap: var(--space-2); min-width: 0; } .filters input { min-width: 0; max-width: 100%; }
label { font-size: .875rem; font-weight: 600; } select { font: inherit; padding: var(--space-2); border: 1px solid var(--theme-border,var(--theme-border, #c1d3c8)); border-radius: .4rem; background: var(--theme-surface, white); color: inherit; min-width: 0; }
.panel { padding: var(--space-5); border: 1px solid var(--theme-border,var(--theme-border, #dbe7df)); border-radius: .75rem; margin: var(--ui-content-gap) 0; } .panel > h3 { margin-top: 0; } .grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 0 var(--ui-grid-gap); } .movement-form { margin: var(--ui-content-gap) 0; border-top: 1px solid var(--theme-border,var(--theme-border, #dbe7df)); }
.location { margin: var(--ui-content-gap) 0; } .location small { display: block; }
p, h3, strong, span, small { overflow-wrap: anywhere; } .notes { white-space: pre-wrap; line-height: 1.6; } .low-stock { color: var(--theme-warning, #705408); background: var(--theme-warning-soft, #fff3d0); padding: var(--space-1) var(--space-2); border-radius: .4rem; width: fit-content; } .balances { padding: 0; list-style: none; } .balances li { display: flex; justify-content: space-between; gap: var(--ui-grid-gap); padding: var(--space-3) 0; border-bottom: 1px solid var(--theme-border,var(--theme-border, #dbe7df)); } .balances strong { flex-shrink: 0; } .history { padding-left: var(--space-5); } .history li { padding: var(--space-2) 0; } .pagination { margin-top: var(--ui-content-gap); font-size: .875rem; }
@media (max-width: 600px) { .grid { grid-template-columns: 1fr; } .filters > div { flex: 1 1 100%; } .inventory-card { padding: 0; } .panel { padding: var(--space-3); } }
</style>
