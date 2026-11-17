<script setup lang="ts">
import { queryField, useCollectionQuery } from '../lib/collection-query';
import CollectionViewToggle from './CollectionViewToggle.vue';
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
import { computed, watch, onMounted, onUnmounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import PageTitle from './PageTitle.vue';
import FormDialog from './FormDialog.vue';
import ResourceCard from './ResourceCard.vue';
import ListActionButton from './ListActionButton.vue';
import { ApiError } from '../api/auth';
import { maintenanceTypesApi, type MaintenanceTypeRecord } from '../api/maintenanceTypes';
const pageSize = useCollectionPageSize('maintenance-types');
defineProps<{ selectionTarget?: HTMLElement | null; hideActions?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, locale } = useI18n();
const sort = useCollectionSort('maintenance-types', sortOptions.maintenance); const formId = useId();
const rows = ref<MaintenanceTypeRecord[]>([]); const search = ref(''); const loading = ref(false); const saving = ref(false); const error = ref(''); const saved = ref(false);
const mode = ref<'create' | 'edit' | 'remove' | null>(null); const selected = ref<MaintenanceTypeRecord | null>(null); const name = ref(''); let alive = true;
const baseline = ref(''); const conflict = ref(false);
const blocked = computed(() => saving.value || !!mode.value && loading.value);
const dirty = computed(() => blocked.value || !!mode.value && mode.value !== 'remove' && name.value !== baseline.value);
const unsaved = useUnsavedChanges(dirty, blocked, { onNavigate: () => { mode.value = null; } });
const validation = useFieldErrors(() => ({ name: { id: `${formId}-name`, message: 'plants.maintenance.nameInvalid' } }), () => ({ name: name.value }));
const fields = validation.fields;
async function confirmLeave() {
 if (!await unsaved.confirmDiscard()) return false;
 mode.value = null; error.value = ''; validation.clear(); return true;
}
async function reload() {
 if (blocked.value || !await unsaved.confirmDiscard()) return;
 if (await load()) { mode.value = null; conflict.value = false; validation.clear(); }
}
const filtered = computed(() => rows.value.filter(row => row.name.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())).sort((a, b) => new Intl.Collator(locale.value, { numeric: true, sensitivity: 'base' }).compare(a.name, b.name) * (sort.value === 'name_desc' ? -1 : 1)));
function failure(cause: unknown) {
 if (!alive) return;
 const code = cause instanceof ApiError ? cause.code : '';
 error.value = cause instanceof ApiError && cause.status === 409 ? 'plants.maintenance.conflict' : /^(plants|auth)\.(maintenance|errors)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && cause.status === 409) conflict.value = true;
 if (cause instanceof ApiError && cause.status === 422 && mode.value !== 'remove') {
  error.value = 'auth.errors.validationFailed'; validation.capture(cause);
  if (cause.code === 'plants.maintenance.nameTaken') { validation.set('name', cause.code); void validation.focus(); }
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { rows.value = []; mode.value = null; emit('accessLost', cause); }
}
async function load() {
 loading.value = true; error.value = '';
 try { const result = await maintenanceTypesApi.list(); if (alive) { rows.value = result; return true; } }
 catch (cause) { if (alive) failure(cause); }
 finally { if (alive) loading.value = false; }
}
async function open(action: 'create' | 'edit' | 'remove', row: MaintenanceTypeRecord | null = null) {
 if (loading.value || !await unsaved.confirmDiscard()) return;
 mode.value = action; selected.value = row; name.value = row?.name ?? ''; error.value = ''; saved.value = false; baseline.value = name.value; conflict.value = false; validation.clear();
}
async function save() {
 if (blocked.value || conflict.value || !mode.value) return;
 validation.clear(); error.value = '';
 if (mode.value !== 'remove' && (!name.value.trim() || name.value.length > 80)) validation.set('name');
 if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
 saving.value = true;
 try {
  if (mode.value === 'remove' && selected.value) await maintenanceTypesApi.remove(selected.value);
  else if (mode.value === 'edit' && selected.value) await maintenanceTypesApi.update(selected.value, name.value.trim());
  else await maintenanceTypesApi.create(name.value.trim());
  if (!alive) return;
  mode.value = null; saved.value = true; await load();
 } catch (cause) { if (alive) failure(cause); }
 finally { if (alive) saving.value = false; }
}
defineExpose({ reload, create: () => open('create'), loading, saving: blocked, confirmLeave });
onMounted(load); onUnmounted(() => { alive = false; });
const page = ref(1);
const lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize.value)));
const visibleRows = computed(() => filtered.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value));
const collectionQuery = useCollectionQuery({ paths: ['/settings/catalogs'], prefix: 'maintenance.', fields: { q: queryField.text(search), sort: queryField.choice(sort, sortOptions.maintenance) }, page, pageSize, load: target => { page.value = Math.min(target, lastPage.value); } });
watch(lastPage, value => { if (page.value > value) page.value = value; });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><section class="maintenance-type-settings"><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <div v-if="!hideActions" class="page-heading"><PageTitle icon="scissors">{{ t('plants.maintenance.catalog') }}</PageTitle><div v-if="!hideActions" ref="selectionHeader" class="page-actions"><ListActionButton icon="refresh" :label="t('plants.reload')" :disabled="loading || saving" @click="reload" /><CollectionViewToggle preference-key="maintenance-types" :disabled="saving || loading" /><Button :disabled="saving" @click="open('create')" data-action-icon="plus" :aria-label="t('plants.maintenance.addType')" :title="t('plants.maintenance.addType')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div>
  <p>{{ t('plants.maintenance.catalogHelp') }}</p>
  <Message v-if="error && !mode" type="error" closable>{{ t(error) }}</Message><Message v-if="saved" type="success" closable>{{ t('plants.maintenance.catalogSaved') }}</Message>
  <div class="search-filters"><div class="filter-primary"><div class="filter-field"><label for="maintenance-type-search">{{ t('plants.maintenance.searchTypes') }}</label><InputText placeholder="" v-model:value="search" :input-props="{ 'id': 'maintenance-type-search', type: 'search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.maintenance" /></div>
    <ResetFilters @reset="search = ''; sort = 'name_asc'" /></div>
  <p v-if="loading" role="status">{{ t('plants.loading') }}</p>
  <p v-else-if="!filtered.length" class="resource-empty">{{ t('plants.maintenance.noTypes') }}</p>
  <CollectionTable preference-key="maintenance-types" :selection-target="selectionTarget ?? selectionHeader" grid-class="resource-grid" v-if="visibleRows.length" :rows="visibleRows" :can-select="() => true" :locked="saving" :row-name="row => row.name" :remove="maintenanceTypesApi.remove" @removed="load" @access-lost="failure" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'actions', label: t('collection.actions') }]">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(row)" v-for="row in visibleRows" :key="row.id" icon="scissors" :eyebrow="t('plants.maintenance.customType')"><template #title>{{ row.name }}</template><template #management><ListActionButton icon="edit" :label="t('plants.maintenance.editNamed', { name: row.name })" :disabled="saving" @click="open('edit', row)" /><ListActionButton icon="trash" :label="t('plants.maintenance.removeNamed', { name: row.name })" :disabled="saving" @click="open('remove', row)" /></template></ResourceCard></template><template #name="{ row }"><CollectionIdentity :name="row.name" icon="scissors" :interactive="false" /></template>
<template #actions="{ row: row }"><div class="collection-table-actions"><ListActionButton icon="edit" :label="t('plants.maintenance.editNamed', { name: row.name })" :disabled="saving" @click="open('edit', row)" /><ListActionButton icon="trash" :label="t('plants.maintenance.removeNamed', { name: row.name })" :disabled="saving" @click="open('remove', row)" /></div></template></CollectionTable>
  <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" @page="collectionQuery.go($event)" :total="filtered.length" />
  <FormDialog v-if="mode" :visible="true" size="compact" icon="scissors" :title="t(`plants.maintenance.${mode}Type`)" :close-label="t('plants.cancel')" :busy="blocked" :error="error ? t(error) : ''" @update:visible="!$event && confirmLeave()"><form :id="formId" class="dialog-form" novalidate @submit.prevent="save"><p v-if="mode === 'remove'">{{ t('plants.maintenance.removeHelp', { name: selected?.name }) }}</p>
    <div v-else class="field"><label :for="`${formId}-name`">{{ t('plants.maintenance.customType') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="name" maxlength="80" :disabled="blocked" :input-props="{ 'id': `${formId}-name`, ...validation.attrs('name'), 'required': true }"></InputText><small v-if="fields.name" :id="`${formId}-name-error`" class="field-error" role="alert">{{ t(fields.name) }}</small></div></form>
   <template #footer><div class="dialog-actions"><Button v-if="conflict" attr-type="button" type="default" :aria-label="t('collection.refresh')" :disabled="blocked" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button><Button :aria-label="t('plants.cancel')" type="default" :disabled="blocked" @click="confirmLeave"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="formId" attr-type="submit" :type="mode === 'remove' ? 'error' : undefined" :loading="saving" :disabled="blocked || conflict"><template #icon><AppIcon :name="mode === 'remove' ? 'trash' : 'save'" /></template><span>{{ t(mode === 'remove' ? 'actions.delete' : mode === 'create' ? 'actions.create' : 'actions.save') }}</span></Button></div></template></FormDialog></section></template>
<style scoped>
.maintenance-type-settings > p { color: var(--app-muted); font-size: .85rem; }
</style>
