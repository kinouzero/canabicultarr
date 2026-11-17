<script setup lang="ts">
import { inventoryTabs } from '../tab-paths';
import AppSwitch from './AppSwitch.vue';
import { computed, onMounted, onUnmounted, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import FormDialog from './FormDialog.vue';
import ListActionButton from './ListActionButton.vue';
import CollectionPagination from './CollectionPagination.vue';
import Message from './AppMessage.vue';
import { NTag as Tag } from 'naive-ui';
import ResetFilters from './ResetFilters.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useCollectionPageSize } from '../lib/collection-page-size';
import { queryField, useCollectionQuery } from '../lib/collection-query';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { ApiError } from '../api/auth';
import { storageLocationsApi, type StorageLocation } from '../api/inventory';
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const id = useId();
const search = ref('');
const pageSize = useCollectionPageSize('storage-locations');
let sequence = 0;
const rows = ref<StorageLocation[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const saving = ref(false); const editing = ref(false); const target = ref<string>(); const canManage = ref(false); const error = ref('');
const form = reactive({ name: '', description: '', is_archived: false });
const baseline = ref('');
const dirty = computed(() => editing.value && JSON.stringify(form) !== baseline.value);
const unsaved = useUnsavedChanges(dirty, saving, { onNavigate: () => { editing.value = false; } });
function failure(cause: unknown) { error.value = cause instanceof ApiError && cause.code === 'inventory.errors.stockRemaining' ? t('storageLocations.stockRemaining') : t('auth.errors.requestFailed'); if (cause instanceof ApiError && [401,403].includes(cause.status)) emit('accessLost', cause); }
async function load(targetPage = 1) {
 const current = ++sequence; loading.value = true; error.value = '';
 try { const result = await storageLocationsApi.list(search.value, targetPage, 'all', pageSize.value); if (current !== sequence) return; rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total; canManage.value = result.canManage; }
 catch (cause) { if (current === sequence) failure(cause); } finally { if (current === sequence) loading.value = false; }
}
function edit(row?: StorageLocation) { target.value = row?.id; Object.assign(form, { name: row?.name ?? '', description: row?.description ?? '', is_archived: row?.isArchived ?? false }); baseline.value = JSON.stringify(form); editing.value = true; error.value = ''; }
async function cancel() { if (saving.value || !await unsaved.confirmDiscard()) return; if (editing.value) { editing.value = false; error.value = ''; }  }
async function save() {
 if (saving.value || !form.name.trim()) return;
 saving.value = true; error.value = '';
 try { await storageLocationsApi.save({ ...form, name: form.name.trim(), description: form.description.trim() || null }, target.value); baseline.value = JSON.stringify(form); editing.value = false; await load(page.value); }
 catch (cause) { failure(cause); } finally { saving.value = false; }
}
const collectionQuery = useCollectionQuery({ paths: [inventoryTabs.storage], prefix: 'storageLocations.', fields: { q: queryField.text(search) }, page, pageSize, load: target => load(target) });
defineExpose({ loading, saving, canManage, create: () => edit(), reload: () => load(page.value) });
onMounted(() => load(page.value));
onUnmounted(() => { sequence++; });
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <section class="storage-location-panel"><Message v-if="error && !editing" type="error" closable role="alert">{{ error }}</Message>
  <p class="record-note">{{ t('storageLocations.help') }}</p>
  <form class="search-filters" @submit.prevent="collectionQuery.apply"><div class="field filter-field"><label :for="`${id}-search`">{{ t('storageLocations.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :input-props="{ 'id': `${id}-search`, type: 'search' }"></InputText></div><ResetFilters v-if="search" @reset="search = ''" /></form>
  <p v-if="loading" role="status">{{ t('inventory.loading') }}</p>
  <p v-else-if="!rows.length" class="record-note">{{ t('storageLocations.empty') }}</p>
  <ul class="storage-locations"><li v-for="row in rows" :key="row.id"><span class="storage-location-icon"><AppIcon name="box" /></span><div class="storage-location-info"><strong>{{ row.name }}</strong><small v-if="row.description">{{ row.description }}</small><Tag v-if="row.isArchived" type="default">{{ t('storageLocations.archived') }}</Tag></div><ListActionButton v-if="canManage" icon="edit" :label="t('storageLocations.editNamed', { name: row.name })" @click="edit(row)" /></li></ul>
  <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="loading || saving" @page="collectionQuery.go($event)" /></section>
 <FormDialog v-if="editing" :visible="true" :title="t(target ? 'storageLocations.edit' : 'storageLocations.add')" :close-label="t('actions.cancel')" icon="box" :busy="saving" :error="error" @update:visible="!$event && cancel()"><form :id="id" class="storage-location-form" @submit.prevent="save"><div class="field"><label :for="`${id}-name`">{{ t('storageLocations.name') }}</label><InputText placeholder="" v-model:value="form.name" maxlength="240" :disabled="saving" autofocus :input-props="{ 'id': `${id}-name`, 'required': true }"></InputText></div>
   <div class="field"><label :for="`${id}-description`">{{ t('storageLocations.description') }}</label><textarea :id="`${id}-description`" v-model="form.description" maxlength="2000" rows="4" :disabled="saving" /></div>
   <AppSwitch v-model="form.is_archived" :disabled="saving">{{ t('storageLocations.archived') }}</AppSwitch></form>
  <template #footer><Button type="default" :disabled="saving" @click="cancel"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button attr-type="submit" :form="id" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t('actions.save') }}</span></Button></template></FormDialog></template>
<style scoped>
.storage-location-panel { display: grid; gap: var(--ui-content-gap); min-width: 0; }.storage-location-panel p { margin: 0; }.storage-location-form { display: grid; gap: var(--space-3); }.storage-locations { margin: 0; padding: 0; list-style: none; display: grid; gap: var(--space-2); }.storage-locations li { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border-radius: .65rem; background: var(--theme-subtle); min-width: 0; }.storage-location-icon { display: grid; place-items: center; flex: 0 0 2.25rem; height: 2.25rem; border-radius: .65rem; color: var(--theme-muted); background: var(--theme-surface); }.storage-location-icon svg { width: 1.1rem; height: 1.1rem; }.storage-location-info { flex: 1; min-width: 0; display: grid; gap: var(--space-1-5); overflow-wrap: anywhere; }.storage-locations small { display: block; color: var(--theme-muted); }.storage-location-form textarea { width: 100%; box-sizing: border-box; }.storage-location-form .field { display: grid; gap: var(--space-2); }
</style>
