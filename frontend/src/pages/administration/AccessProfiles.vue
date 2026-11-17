<script setup lang="ts">
import { useFormTabs } from '../../lib/form-tabs';
import AppTabs from '../../components/AppTabs.vue';
import { NTabPane } from 'naive-ui';
import AppSwitch from '../../components/AppSwitch.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import CollectionLayout from '../../components/CollectionLayout.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionIdentity from '../../components/CollectionIdentity.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import PermissionFields from '../../components/PermissionFields.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import PageTitle from '../../components/PageTitle.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import NotesEditor from '../../components/NotesEditor.vue';
import RichNotes from '../../components/RichNotes.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import FormDialog from '../../components/FormDialog.vue';
import { computed, useId, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { accessProfilesApi, accessProfileName, type AccessProfile, type AccessProfileInput } from '../../api/accessProfiles';
import { delegatedPermissions, permissionLabel, permissionIcon, type DelegatedPermission } from '../../api/permissions';
import en from '../../locales/en/access-profiles.json';
import fr from '../../locales/fr/access-profiles.json';
const pageSize = useCollectionPageSize('profiles');
const sort = useCollectionSort('access-profiles', sortOptions.profiles);
defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { accessProfiles: en }); mergeLocaleMessage('fr', { accessProfiles: fr });
const rows = ref<AccessProfile[]>([]); const search = ref(''); const status = ref<'active' | 'archived' | 'all'>('active');
const page = ref(1); const lastPage = ref(1); const total = ref(0); const loading = ref(false); const saving = ref(false); const open = ref(false);
const editing = ref<AccessProfile | null>(null); const error = ref(''); const notice = ref('');
const form = reactive({ name: '', description: '', permissions: [] as DelegatedPermission[], isArchived: false });
let sequence = 0; let alive = true;
const baseline = ref(''); const reloading = ref(false); const conflict = ref(false);
function snapshot() { return JSON.stringify({ ...form, permissions: [...form.permissions].sort() }); }
const busy = computed(() => saving.value || reloading.value);
const dirty = computed(() => busy.value || open.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { open.value = false; } });
const editorTabs = useFormTabs(open, {"description": "notes", "permissions": "permissions"});
const editorTab = editorTabs.tab;
const validation = useFieldErrors(() => ({
 name: { id: 'profile-name', message: 'accessProfiles.validation.nameInvalid' },
 description: { id: 'profile-description', message: 'accessProfiles.validation.descriptionInvalid' },
 permissions: { id: 'profile-permissions', message: 'users.validation.permissionsInvalid' },
 is_archived: { id: 'profile-archived', message: 'accessProfiles.validation.stateInvalid' },
 version: { id: 'profile-version', message: 'accessProfiles.validation.versionInvalid' },
}), () => ({ name: form.name, description: form.description, permissions: [...form.permissions].sort(), is_archived: form.isArchived, version: editing.value?.version }), editorTabs.reveal);
const fields = validation.fields;

function resetEditor() { open.value = false; editing.value = null; conflict.value = false; validation.clear(); }
async function closeForm() { if (await unsaved.confirmDiscard()) { resetEditor(); error.value = ''; } }
async function leave() { if (await unsaved.confirmDiscard()) emit('close'); }
function key(value: string) { return /^(auth|users|accessProfiles)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function failure(cause: unknown, editor = true) {
 if (!alive) return;
 error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (!(cause instanceof ApiError)) return;
 if (cause.status === 401 || cause.status === 403) { rows.value = []; resetEditor(); emit('accessLost', cause); return; }
 if (!editor || !open.value) return;
 validation.capture(cause);
 const field = ({ 'accessProfiles.errors.nameTaken': 'name', 'accessProfiles.errors.assigned': 'is_archived' } as Record<string, string>)[cause.code];
 if (field) { validation.set(field, cause.code); void validation.focus(); }
 if (cause.code === 'accessProfiles.errors.conflict' || cause.fields.version) conflict.value = true;
 if (cause.status === 422) error.value = 'auth.errors.validationFailed';
}
// List refreshes never replace or close the independently owned editor draft.
async function load(target = 1) {
 const current = ++sequence; loading.value = true;
 if (!open.value) error.value = '';
 try {
  const result = await accessProfilesApi.list(search.value.trim(), target, status.value, sort.value, pageSize.value);
  if (!alive || current !== sequence) return false;
  rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
  return true;
 } catch (cause) { if (current === sequence) failure(cause, false); return false; }
 finally { if (current === sequence) loading.value = false; }
}
async function reload() {
 if (!await unsaved.confirmDiscard()) return;
 reloading.value = true; error.value = '';
 try { if (await load(page.value)) { resetEditor(); error.value = ''; notice.value = ''; } }
 finally { reloading.value = false; }
}
function replaceEditor(profile: AccessProfile | null = null) {
 editing.value = profile ? { ...profile, permissions: [...profile.permissions] } : null;
 Object.assign(form, { name: profile?.name ?? '', description: profile?.description ?? '', permissions: [...(profile?.permissions ?? [])], isArchived: profile?.isArchived ?? false });
 baseline.value = snapshot(); error.value = ''; notice.value = ''; conflict.value = false; validation.clear(); open.value = true;
}
async function edit(profile: AccessProfile | null = null) {
 if (loading.value || !await unsaved.confirmDiscard()) return;
 replaceEditor(profile);
}
async function duplicate(profile: AccessProfile) {
 if (loading.value || !await unsaved.confirmDiscard()) return;
 replaceEditor();
 // The copied content is an unsaved creation; its archive state and version are not inherited.
 Object.assign(form, { name: t('accessProfiles.copyName', { name: accessProfileName(profile.name, t) }), description: profile.description ?? '', permissions: [...profile.permissions] });
}
async function save() {
 if (busy.value || conflict.value) return;
 error.value = ''; notice.value = ''; validation.clear();
 if (!form.name.trim() || form.name.trim().length > 120) validation.set('name');
 if (form.description.length > 2000) validation.set('description');
 if (new Set(form.permissions).size !== form.permissions.length || form.permissions.some(value => !delegatedPermissions.includes(value))) validation.set('permissions');
 if (editing.value && (!Number.isInteger(editing.value.version) || editing.value.version < 1 || editing.value.version > 2147483647)) { validation.set('version'); conflict.value = true; }
 if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
 saving.value = true;
 const previous = editing.value;
 const input: AccessProfileInput = { name: form.name, description: form.description || null, permissions: [...form.permissions], is_archived: form.isArchived };
 if (previous) input.version = previous.version;
 try {
  if (previous) await accessProfilesApi.update(previous.id, input); else await accessProfilesApi.create(input);
  if (!alive) return;
  resetEditor(); notice.value = previous ? 'accessProfiles.updated' : 'accessProfiles.created';
  await load(previous ? page.value : 1);
 } catch (cause) { failure(cause); }
 finally { saving.value = false; }
}
onMounted(() => load(page.value)); onUnmounted(() => { alive = false; sequence++; });
const dialogId = useId();
const collectionQuery = useCollectionQuery({ paths: ['/access-profiles'], fields: { q: queryField.text(search), status: queryField.choice(status, ['active', 'archived', 'all']), sort: queryField.choice(sort, sortOptions.profiles) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;


</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <Card class="profiles-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="profile-heading"><PageTitle icon="shield">{{ t('accessProfiles.title') }}</PageTitle></div><div class="page-actions"><Button v-if="!embedded" :aria-label="t('accessProfiles.back')" type="default" :disabled="busy" @click="leave" :title="t('accessProfiles.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" v-if="!open" :label="t('accessProfiles.reload')" severity="secondary" :disabled="busy || loading" @click="reload" /><CollectionViewToggle preference-key="access-profiles" :disabled="busy || loading" /><Button :aria-label="t('accessProfiles.add')" :disabled="busy || loading" @click="edit()" data-action-icon="plus" :title="t('accessProfiles.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('accessProfiles.description') }}</div></div></template>

  <template #default><slot name="navigation" />
   <Message v-if="error && !(open)" closable type="error" role="alert">{{ t(error) }}</Message>
   <Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>

   <form class="profile-toolbar search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="field filter-field"><label for="profile-search">{{ t('accessProfiles.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="busy" :input-props="{ 'id': 'profile-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.profiles" :disabled="busy" /></div>
    <div class="filter-secondary"><div class="field filter-field"><label for="profile-status">{{ t('accessProfiles.status') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="profile-status" v-model="status" :disabled="busy"><option value="active">{{ t('accessProfiles.active') }}</option><option value="archived">{{ t('accessProfiles.archived') }}</option><option value="all">{{ t('accessProfiles.all') }}</option></select></div></div>
    <ResetFilters :disabled="busy" @reset="search = ''; status = 'active'; sort = 'name_asc'" /></form>
   <FormDialog icon="shield" v-if="open" :visible="true" :title="t(editing ? 'accessProfiles.editTitle' : 'accessProfiles.createTitle')" :close-label="t('accessProfiles.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><template #feedback><div v-if="fields.version" id="profile-version" tabindex="-1" v-bind="validation.attrs('version')"><small id="profile-version-error" class="field-error" role="alert">{{ t(fields.version) }}</small></div><Button v-if="conflict" :aria-label="t('accessProfiles.reload')" type="default" :disabled="busy" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form profile-form" @submit.prevent="save" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><Message closable type="info" role="status">{{ t('accessProfiles.effects') }}</Message>
    <div class="field"><label for="profile-name">{{ t('accessProfiles.name') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="form.name" maxlength="120" :disabled="busy" :status="!!fields.name ? 'error' : undefined" :input-props="{ 'id': 'profile-name' }"></InputText><small v-if="fields.name" id="profile-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>
    <AppSwitch class="profile-check" id="profile-archived" v-bind="validation.attrs('is_archived')" v-model="form.isArchived" :disabled="busy"><span>{{ t('accessProfiles.state') }}</span></AppSwitch><p>{{ t('accessProfiles.archiveHelp') }}</p><small v-if="fields.is_archived" id="profile-archived-error" role="alert" class="field-error">{{ t(fields.is_archived) }}</small></div></NTabPane><NTabPane name="permissions" display-directive="show"><template #tab><AppIcon name="shield" />{{ t('editorTabs.permissions') }}</template><div class="dialog-form"><fieldset id="profile-permissions" tabindex="-1" v-bind="validation.attrs('permissions')" :disabled="busy"><legend>{{ t('accessProfiles.permissions') }}</legend><p class="permission-help">{{ t('accessProfiles.baseline') }}</p><PermissionFields v-model="form.permissions" :disabled="busy" /><small v-if="fields.permissions" id="profile-permissions-error" role="alert" class="field-error">{{ t(fields.permissions) }}</small></fieldset>
    </div></NTabPane><NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="file" />{{ t('editorTabs.notes') }}</template><div class="dialog-form"><div class="field dialog-wide"><label for="profile-description">{{ t('accessProfiles.notes') }}</label><NotesEditor id="profile-description" :invalid="!!fields.description" :described-by="fields.description ? 'profile-description-error' : undefined" v-model="form.description" :label="t('accessProfiles.notes')" :disabled="busy" /><small v-if="fields.description" id="profile-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div>
    </div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('accessProfiles.cancel')" type="default" :disabled="busy" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('accessProfiles.save') : t('actions.create')" :loading="saving" :disabled="reloading || conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
   <p v-if="loading" role="status">{{ t('accessProfiles.loading') }}</p><p v-else-if="!rows.length">{{ t('accessProfiles.empty') }}</p>
   <CollectionLayout preference-key="access-profiles" grid-class="profile-list resource-grid"><template #default><ResourceCard v-for="profile in rows" :key="profile.id" icon="shield" :eyebrow="t('users.permissionRole')" :tone="profile.isArchived ? 'muted' : undefined"><template #title>{{ accessProfileName(profile.name, t) }}</template>
     <template #badge><Tag type="default">{{ t(profile.isArchived ? 'accessProfiles.archived' : 'accessProfiles.active') }}</Tag></template>
     <dl class="resource-facts"><div><dt><AppIcon name="users" />{{ t('accessProfiles.accounts') }}</dt><dd>{{ t('accessProfiles.assigned', { count: profile.assignedUsers }) }}</dd></div><div><dt><AppIcon name="shield" />{{ t('accessProfiles.permissions') }}</dt><dd>{{ t('users.effectivePermissions', { count: profile.permissions.length }) }}</dd></div></dl>
     <RichNotes v-if="profile.description" class="resource-note" :value="profile.description" />
     <div v-if="profile.permissions.length" class="profile-capabilities"><span v-for="permission in profile.permissions" :key="permission"><AppIcon :name="permissionIcon(permission)" />{{ t(permissionLabel(permission)) }}</span></div><p v-else class="profile-baseline"><AppIcon name="eye" />{{ t('accessProfiles.baseline') }}</p>
     <template #management><ListActionButton icon="copy" :label="t('accessProfiles.duplicate', { name: accessProfileName(profile.name, t) })" :disabled="busy || loading" @click="duplicate(profile)" /><ListActionButton icon="edit" :label="t('accessProfiles.edit', { name: accessProfileName(profile.name, t) })" :disabled="busy || loading" @click="edit(profile)" /></template></ResourceCard></template>
<template #table><CollectionTable :rows="rows" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'accounts', label: t('accessProfiles.accounts') }, { key: 'permissions', label: t('accessProfiles.permissions') }, { key: 'actions', label: t('collection.actions') }]"><template #name="{ row: profile }"><div class="collection-table-name"><CollectionIdentity :name="accessProfileName(profile.name, t)" icon="shield" :interactive="false" /><div class="collection-table-tags"><Tag type="default">{{ t(profile.isArchived ? 'accessProfiles.archived' : 'accessProfiles.active') }}</Tag></div></div></template>
<template #accounts="{ row: profile }">{{ profile.assignedUsers }}</template>
<template #permissions="{ row: profile }">{{ profile.permissions.length }}</template>
<template #actions="{ row: profile }"><div class="collection-table-actions"><ListActionButton icon="copy" :label="t('accessProfiles.duplicate', { name: accessProfileName(profile.name, t) })" :disabled="busy || loading" @click="duplicate(profile)" /><ListActionButton icon="edit" :label="t('accessProfiles.edit', { name: accessProfileName(profile.name, t) })" :disabled="busy || loading" @click="edit(profile)" /></div></template></CollectionTable></template></CollectionLayout>
   <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('accessProfiles.pagination')" :previous-label="t('accessProfiles.previous')" :next-label="t('accessProfiles.next')" @page="collectionQuery.go($event)" /></template></Card></template>
<style scoped>
.profile-heading, .profile-pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-3); }.profile-toolbar .field { margin: 0; }.profile-form fieldset { min-width: 0; grid-column: 1 / -1; border: 1px solid var(--theme-border); border-radius: .7rem; padding: var(--space-3); margin: 0; }legend { font-size: .8rem; font-weight: 600; }.profile-form p, .permission-help { color: var(--theme-muted); font-size: .75rem; line-height: 1.5; }.profile-check { display: flex; align-items: center; gap: var(--space-2); font-size: .8rem; }.profile-capabilities { display: grid; gap: var(--space-1-5); }.profile-capabilities > span, .profile-baseline { display: flex; align-items: flex-start; gap: var(--space-1-5); font-size: .74rem; line-height: 1.5; }.profile-capabilities svg, .profile-baseline svg { width: .95rem; height: .95rem; flex-shrink: 0; margin-top: var(--space-0-5); color: var(--theme-muted); }.profile-baseline { color: var(--theme-muted); margin: 0; }.profile-pagination { margin-top: var(--ui-content-gap); }
</style>
