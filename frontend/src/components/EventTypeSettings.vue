<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import { NSelect as MultiSelect } from 'naive-ui';
import Message from './AppMessage.vue';
import { NTag as Tag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import FormDialog from './FormDialog.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import ListActionButton from './ListActionButton.vue';
import CollectionIdentity from './CollectionIdentity.vue';
import CollectionTable from './CollectionTable.vue';
import CollectionPagination from './CollectionPagination.vue';
import ResetFilters from './ResetFilters.vue';
import SortSelect from './SortSelect.vue';
import { useFieldErrors } from '../lib/field-errors';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { queryField, useCollectionQuery } from '../lib/collection-query';
import { useCollectionPageSize } from '../lib/collection-page-size';
import { useCollectionSort, sortOptions } from '../lib/collection-sorting';
import { registerModuleMessages } from '../lib/locale-messages';
import { eventTypesApi, systemEventTypes, type EventType, type EventContextKind } from '../api/event-types';
import { ApiError } from '../api/auth';
import en from '../locales/en/event-types.json';
import fr from '../locales/fr/event-types.json';
import eventEn from '../locales/en/events.json';
import eventFr from '../locales/fr/events.json';
defineProps<{ hideActions?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, mergeLocaleMessage } = useI18n({ useScope: 'global' });
registerModuleMessages(mergeLocaleMessage, { en: { eventTypes: en, events: eventEn }, fr: { eventTypes: fr, events: eventFr } });
const id = useId(); const pageSize = useCollectionPageSize('event-types'); const sort = useCollectionSort('event-types', sortOptions.common);
const rows = ref<EventType[]>([]); const icons = ref<string[]>([]); const contexts = ref<EventContextKind[]>([]); const canManage = ref(false);
const loading = ref(false); const saving = ref(false); const error = ref(''); const success = ref(false); const opened = ref(false); const editing = ref<EventType | null>(null); const conflict = ref(false);
const search = ref(''); const state = ref('all'); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const form = reactive({ name: '', icon: 'journal', contexts: [] as EventContextKind[], is_archived: false });
const baseline = ref(''); const dirty = computed(() => opened.value && JSON.stringify(form) !== baseline.value);
const guard = useUnsavedChanges(dirty, saving, { onNavigate: () => { opened.value = false; } }); const confirmLeave = guard.confirmDiscard;
const validation = useFieldErrors(() => ({ name: { id: `${id}-name`, message: 'eventTypes.validation.name' }, icon: { id: `${id}-icon`, message: 'eventTypes.validation.icon' }, contexts: { id: `${id}-contexts`, message: 'eventTypes.validation.contexts' } }), () => ({ ...form }));
let alive = true; let sequence = 0; onUnmounted(() => { alive = false; sequence++; });
function fail(cause: unknown) {
 if (!alive) return;
 error.value = cause instanceof ApiError && te(cause.code) ? cause.code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError) {
  if (cause.status === 422) { validation.capture(cause); void validation.focus(); }
  if (cause.status === 409) conflict.value = true;
  if ([401, 403].includes(cause.status)) { rows.value = []; opened.value = false; emit('accessLost', cause); }
 }
}
async function load(target = 1) {
 const current = ++sequence; loading.value = true; error.value = '';
 try {
  const result = await eventTypesApi.list(search.value.trim(), state.value, target, pageSize.value, sort.value);
  if (!alive || sequence !== current) return;
  if (target > result.meta.last_page && result.meta.total > 0) { await load(result.meta.last_page); return; }
  rows.value = result.data; icons.value = result.icons; contexts.value = result.contexts; canManage.value = result.canManage;
  page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (cause) { if (current === sequence) fail(cause); }
 finally { if (alive && current === sequence) loading.value = false; }
}
async function edit(row: EventType | null = null) {
 if (!canManage.value || saving.value || !await guard.confirmDiscard()) return;
 editing.value = row; Object.assign(form, { name: row?.name ?? '', icon: row?.icon ?? 'journal', contexts: [...(row?.contexts ?? contexts.value)], is_archived: row?.is_archived ?? false });
 baseline.value = JSON.stringify(form); conflict.value = false; validation.clear(); error.value = ''; success.value = false; opened.value = true;
}
async function close() { if (await guard.confirmDiscard()) { opened.value = false; error.value = ''; } }
async function reload() { if (await guard.confirmDiscard()) { opened.value = false; conflict.value = false; await load(page.value); } }
async function save() {
 if (saving.value || conflict.value) return;
 validation.clear(); error.value = '';
 if (!form.name.trim() || form.name.length > 80) validation.set('name');
 if (!icons.value.includes(form.icon)) validation.set('icon');
 if (!form.contexts.length) validation.set('contexts');
 if (Object.keys(validation.fields.value).length) { void validation.focus(); return; }
 saving.value = true;
 try {
  if (editing.value) await eventTypesApi.update(editing.value, { ...form }); else await eventTypesApi.create({ ...form });
  if (!alive) return;
  baseline.value = JSON.stringify(form); opened.value = false; success.value = true; await load(page.value);
 } catch (cause) { fail(cause); }
 finally { if (alive) saving.value = false; }
}
const collectionQuery = useCollectionQuery({ paths: ['/settings/catalogs'], prefix: 'eventTypes.', fields: { q: queryField.text(search), state: queryField.choice(state, ['all', 'active', 'archived']), sort: queryField.choice(sort, sortOptions.common) }, page, pageSize, load: target => load(target) });
onMounted(() => { void load(page.value); });
defineExpose({ loading, saving, canManage, create: () => edit(), reload, confirmLeave });
</script>
<template><section class="event-type-settings" data-testid="event-type-settings"><DiscardChangesDialog v-if="guard.pending.value" @decision="guard.finish" />
  <div v-if="!hideActions" class="page-heading"><h3><AppIcon name="journal" />{{ t('eventTypes.title') }}</h3><div class="page-actions"><ListActionButton icon="refresh" :label="t('collection.refresh')" :disabled="saving" @click="reload" /><ListActionButton v-if="canManage" icon="plus" :label="t('eventTypes.add')" :disabled="loading || saving" @click="edit()" /></div></div>
  <section class="system-type-summary" :aria-label="t('eventTypes.systemTitle')"><h3><AppIcon name="shield" />{{ t('eventTypes.systemTitle') }}</h3>
   <p class="record-note">{{ t('eventTypes.systemHelp') }}</p>
   <div class="system-event-types"><Tag v-for="(icon, key) in systemEventTypes" :key="key" type="default" :title="t('eventTypes.system')"><AppIcon :name="icon" /><span>{{ t(`events.categories.${key}`) }}</span></Tag></div></section>
  <Message v-if="error && !opened" type="error" closable>{{ t(error) }}</Message><Message v-if="success" type="success" closable>{{ t('eventTypes.saved') }}</Message>
  <div class="custom-types-heading"><h3><AppIcon name="journal" />{{ t('eventTypes.customTitle') }}</h3></div>
  <form class="search-filters" @submit.prevent><div class="filter-primary"><div class="filter-field"><label :for="`${id}-search`">{{ t('eventTypes.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="saving" :input-props="{ 'id': `${id}-search` }"></InputText></div><SortSelect v-model="sort" :options="sortOptions.common" :disabled="saving" /></div>
   <div class="filter-secondary"><div class="filter-field"><label :for="`${id}-state`">{{ t('eventTypes.state') }}</label><select :id="`${id}-state`" v-model="state" v-app-select="{ filter: false }" :disabled="saving"><option value="all">{{ t('eventTypes.all') }}</option><option value="active">{{ t('eventTypes.active') }}</option><option value="archived">{{ t('eventTypes.archived') }}</option></select></div></div>
   <ResetFilters :disabled="saving" @reset="search = ''; state = 'all'; sort = 'name_asc'" /></form>
  <p v-if="loading" role="status" class="record-note">{{ t('events.loading') }}</p>
  <CollectionTable :rows="rows" :columns="[{ key: 'name', label: t('eventTypes.name') }, { key: 'contexts', label: t('eventTypes.contexts') }, { key: 'state', label: t('eventTypes.state') }, { key: 'actions', label: t('collection.actions') }]"><template #name="{ row }"><CollectionIdentity :name="row.name" :icon="row.icon" :interactive="false" /></template><template #contexts="{ row }"><div class="type-contexts"><Tag v-for="context in row.contexts" :key="context" type="default">{{ t(`eventTypes.contextNames.${context}`) }}</Tag></div></template><template #state="{ row }"><Tag :type="row.is_archived ? 'default' : 'success'">{{ t(row.is_archived ? 'eventTypes.archived' : 'eventTypes.active') }}</Tag></template><template #actions="{ row }"><ListActionButton v-if="canManage" icon="edit" :label="t('eventTypes.edit')" :disabled="saving" @click="edit(row)" /></template></CollectionTable><p v-if="!loading && !rows.length" class="record-note">{{ t('eventTypes.empty') }}</p>
  <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="saving || loading" @page="collectionQuery.go($event)" />
  <FormDialog v-if="opened" :visible="true" :title="t(editing ? 'eventTypes.edit' : 'eventTypes.add')" :icon="form.icon" :close-label="t('actions.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && close()"><form :id="`${id}-form`" class="dialog-form" @submit.prevent="save" novalidate><div class="field"><label :for="`${id}-name`">{{ t('eventTypes.name') }}</label><InputText placeholder="" v-model:value="form.name" maxlength="80" :disabled="saving || conflict" v-bind="validation.attrs('name')" :input-props="{ 'id': `${id}-name` }"></InputText><small v-if="validation.fields.value.name" :id="`${id}-name-error`" class="field-error" role="alert">{{ t(validation.fields.value.name) }}</small></div>
    <div class="field"><label :for="`${id}-icon`">{{ t('eventTypes.icon') }}</label><div class="icon-select type-icon-input"><AppIcon :name="form.icon" /><select :id="`${id}-icon`" v-model="form.icon" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="saving || conflict" v-bind="validation.attrs('icon')"><option v-for="icon in icons" :key="icon" :value="icon">{{ t(`eventTypes.icons.${icon}`) }}</option></select></div></div>
    <div class="field dialog-wide"><label :for="`${id}-contexts`">{{ t('eventTypes.contexts') }}</label><MultiSelect :input-props="{ id: `${id}-contexts` }" v-model:value="form.contexts" :options="contexts.map(value => ({ label: t(`eventTypes.contextNames.${value}`), value }))" label-field="label" value-field="value" :disabled="saving || conflict" :status="validation.fields.value.contexts ? 'error' : undefined" v-bind="validation.attrs('contexts')" multiple></MultiSelect><small>{{ t('eventTypes.contextHelp') }}</small><small v-if="validation.fields.value.contexts" :id="`${id}-contexts-error`" class="field-error" role="alert">{{ t(validation.fields.value.contexts) }}</small></div>
    <div class="field"><label :for="`${id}-archived`">{{ t('eventTypes.state') }}</label><select :id="`${id}-archived`" :value="String(form.is_archived)" v-app-select="{ filter: false }" :disabled="saving || conflict" @change="form.is_archived = ($event.target as HTMLSelectElement).value === 'true'"><option value="false">{{ t('eventTypes.active') }}</option><option value="true">{{ t('eventTypes.archived') }}</option></select></div></form>
   <template #footer><div class="dialog-actions"><Button type="default" :disabled="saving" @click="close"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button v-if="conflict" type="default" :disabled="saving" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('collection.refresh') }}</span></Button><Button :form="`${id}-form`" attr-type="submit" :loading="saving" :disabled="conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog></section></template>
<style scoped>
.event-type-settings { grid-template-columns: minmax(0,1fr); display: grid; gap: var(--ui-content-gap); min-width: 0; }.system-type-summary { display: grid; gap: var(--space-3); padding: var(--space-4); border-radius: .7rem; background: var(--theme-subtle); min-width: 0; }.event-type-settings h3 { display: flex; align-items: center; gap: var(--ui-icon-gap); margin: 0; font-size: .9rem; }.system-event-types,.type-contexts { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }.event-type-settings h3 svg,.system-event-types svg,.type-icon-input > svg { width: 1rem; height: 1rem; flex-shrink: 0; }.system-event-types :deep(.n-tag),.type-contexts :deep(.n-tag) { gap: var(--space-1-5); font-size: .7rem; font-weight: 500; }.system-event-types :deep(.n-tag) { background: var(--theme-surface); }.record-note { margin: 0; font-size: .78rem; color: var(--theme-muted); line-height: 1.5; }.custom-types-heading { margin-top: var(--space-2); }.type-contexts { min-width: 0; }.type-contexts :deep(.n-tag) { white-space: normal; }.type-icon-input select { flex: 1; min-width: 0; }
.event-type-settings > .collection-table { min-width: 0; max-width: 100%; }
</style>
