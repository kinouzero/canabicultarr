<script setup lang="ts">
import AppSwitch from '../../components/AppSwitch.vue';
import AppTabs from '../../components/AppTabs.vue';
import EmailChangeNotice from '../../components/EmailChangeNotice.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import PermissionOverrides from '../../components/PermissionOverrides.vue';
import { NTabPane } from 'naive-ui';




import { NTag as Tag } from 'naive-ui';
import { oidcProviderName } from '../../lib/branding';
import PageTitle from '../../components/PageTitle.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import FormDialog from '../../components/FormDialog.vue';
import UserAvatar from '../../components/UserAvatar.vue';
import { useId, computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import { NInput as Password } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError, type AuthUser, type LocationGrant } from '../../api/auth';
import { delegatedPermissions, permissionLabel, type DelegatedPermission } from '../../api/permissions';
import { usersApi, type ManagedUser, type UserInput } from '../../api/users';

import { accessProfilesApi, accessProfileName, type AccessProfile } from '../../api/accessProfiles';
import enProfiles from '../../locales/en/access-profiles.json';
import frProfiles from '../../locales/fr/access-profiles.json';
import RemovableTag from '../../components/RemovableTag.vue';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
import LocationSelect from '../../components/LocationSelect.vue';
import StorageLocationSelect from '../../components/StorageLocationSelect.vue';
import type { StorageLocation } from '../../api/inventory';
const pageSize = useCollectionPageSize('users');
const sort = useCollectionSort('users', sortOptions.users);
const allStockLocations = ref(true);
const stockGrants = ref<StorageLocation[]>([]);
const stockCandidate = ref<StorageLocation | null>(null);
watch(stockCandidate, value => { if (value && !stockGrants.value.some(row => row.id === value.id)) stockGrants.value.push(value); stockCandidate.value = null; });
const allLocations = ref(true);
const locationGrants = ref<LocationGrant[]>([]);
const selectedProfiles = new Map<string, Pick<AccessProfile, 'id' | 'name' | 'permissions'>>();
const props = defineProps<{ embedded?: boolean; currentUser: AuthUser }>();
const emit = defineEmits<{
  close: [];
  updated: [user: ManagedUser];
  sessionRevoked: [email: string];
  accessLost: [error: ApiError];
}>();
const { t, te, locale, mergeLocaleMessage } = useI18n();
mergeLocaleMessage('en', { accessProfiles: enProfiles }); mergeLocaleMessage('fr', { accessProfiles: frProfiles });
const users = ref<ManagedUser[]>([]);
const search = ref('');
const administratorsOnly = ref(false); const statusFilter = ref(''); const profileFilter = ref('');
const summary = ref<import('../../api/users').UserPage['summary']>();
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const editing = ref<ManagedUser | null>(null);
const formOpen = ref(false);
const editorTab = ref('identity');
const error = ref('');
const success = ref('');
const form = reactive({ name: '', email: '', isActive: true, password: '', confirmation: '', grants: [] as DelegatedPermission[], denials: [] as DelegatedPermission[], profiles: [] as { id: string; name: string; permissions?: DelegatedPermission[] }[] });
const inheritedPermissions = computed(() => form.profiles.flatMap(profile => profile.permissions ?? []));
const effectivePermissions = computed(() => [...new Set([...inheritedPermissions.value, ...form.grants])].filter(permission => !form.denials.includes(permission)));
const isAdministrator = computed(() => effectivePermissions.value.includes('administration.view'));

let alive = true;
const baseline = ref('');
function snapshot() { return JSON.stringify({ name: form.name, email: form.email, isActive: form.isActive, profiles: form.profiles.map(profile => profile.id).sort(), grants: [...form.grants].sort(), denials: [...form.denials].sort(), allStockLocations: allStockLocations.value, stock: stockGrants.value.map(row => row.id).sort(), allLocations: allLocations.value, locations: locationGrants.value.map(({ type, id }) => `${type}:${id}`).sort() }); }
// Passwords stay only in their inputs; no password snapshot or persistence is needed.
const dirty = computed(() => saving.value || formOpen.value && (snapshot() !== baseline.value || !!form.password || !!form.confirmation));
const unsaved = useUnsavedChanges(dirty, saving, { onNavigate: () => { formOpen.value = false; } });
const validation = useFieldErrors(() => ({
 name: { id: 'user-name', message: 'users.validation.nameInvalid' },
 email: { id: 'user-email', message: 'users.validation.emailInvalid' },
 is_active: { id: 'user-active', message: 'users.validation.activeInvalid' },
 permission_role_ids: { id: 'user-permission-profile', message: 'accessProfiles.errors.unavailable' },
 permission_denials: { id: 'user-permission-grants', message: 'users.validation.permissionsInvalid' },
 permission_grants: { id: 'user-permission-grants', message: 'users.validation.permissionsInvalid' },
 inventory_location_access: { id: 'user-stock-grants', message: 'storageLocations.invalid' },
 location_access: { id: 'user-location-grants', message: 'locationAccess.errors.invalidSelection' },
 password: { id: 'user-password', message: 'auth.validation.passwordLength' },
 password_confirmation: { id: 'user-confirmation', message: 'auth.validation.passwordConfirmation' },
}), () => ({ inventory_location_access: JSON.stringify([allStockLocations.value, stockGrants.value.map(row => row.id).sort()]), name: form.name, email: form.email, is_active: form.isActive, permission_role_ids: JSON.stringify(form.profiles.map(profile => profile.id).sort()), permission_grants: JSON.stringify([...form.grants].sort()), permission_denials: JSON.stringify([...form.denials].sort()), location_access: JSON.stringify([isAdministrator.value, allLocations.value, locationGrants.value.map(({ type, id }) => `${type}:${id}`).sort()]), password: form.password, password_confirmation: JSON.stringify([form.password, form.confirmation]) }));
const fields = validation.fields;
watch(fields, value => { const field = Object.keys(value)[0]; if (field) editorTab.value = field.startsWith('password') ? 'security' : ['location_access', 'inventory_location_access'].includes(field) ? 'locations' : field.startsWith('permission_') ? 'access' : 'identity'; }, { deep: true, flush: 'sync' });
function resetForm() { formOpen.value = false; form.password = form.confirmation = ''; validation.clear(); }
async function leave() { if (await closeForm()) emit('close'); }

function key(value: string): string {
  if (value === 'accountActivation.unavailable' || /^emailChange\.[A-Za-z]+$/.test(value) && te(value)) return value;
  return /^(auth|users|accessProfiles|locationAccess)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed';
}

function showError(cause: unknown) {
  if (!alive) return;
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError) {
    if (cause.status === 401 || cause.status === 403) {
      users.value = []; summary.value = undefined; resetForm();
      emit('accessLost', cause);
    }
    if (formOpen.value) {
      validation.capture(cause);
      if (cause.fields.password?.includes('auth.validation.passwordConfirmation')) { delete fields.value.password; validation.set('password_confirmation'); void validation.focus(); }
      if (cause.code === 'users.errors.emailTaken') { validation.set('email', cause.code); void validation.focus(); }
      if (cause.code === 'users.errors.lastAdministrator') { validation.set('permission_role_ids', cause.code); void validation.focus(); }
    }
    if (cause.status === 422) error.value = 'auth.errors.validationFailed';
  }
}

let listSequence = 0;
async function load(target = 1) {
  const current = ++listSequence;
  loading.value = true;
  error.value = '';
  try {
    const result = await usersApi.list(search.value.trim(), target, { per_page: String(pageSize.value), sort: sort.value, administrators: administratorsOnly.value ? '1' : '', status: statusFilter.value, permission_role_id: profileFilter.value });
    if (current !== listSequence) return;
    users.value = result.data;
    summary.value = result.summary;
    page.value = result.meta.current_page;
    lastPage.value = result.meta.last_page;
    total.value = result.meta.total;
  } catch (cause) { if (current === listSequence) showError(cause); }
  finally { if (current === listSequence) loading.value = false; }
}

async function open(user: ManagedUser | null = null) {
  if (loading.value || !await unsaved.confirmDiscard()) return;
  editing.value = user ? { ...user } : null;
  allStockLocations.value = user?.inventoryLocationAccess == null;
  stockGrants.value = [...(user?.inventoryLocationAccess ?? [])];
  allLocations.value = user?.locationAccess == null;
  locationGrants.value = [...(user?.locationAccess ?? [])];
  Object.assign(form, { name: user?.name ?? '', email: user?.email ?? '', isActive: user?.isActive ?? true, password: '', confirmation: '', grants: [...(user?.permissionGrants ?? [])], denials: [...(user?.permissionDenials ?? [])], profiles: (user?.permissionRoles ?? []).map(profile => ({ ...profile })) });
  validation.clear();
  error.value = '';
  success.value = '';
  baseline.value = snapshot(); editorTab.value = 'identity'; formOpen.value = true;
}

async function closeForm() {
  if (!await unsaved.confirmDiscard()) return false;
  resetForm(); error.value = ''; return true;
}

async function save() {
  if (saving.value) return;
  validation.clear();
  error.value = '';
  success.value = '';
  if (!form.name.trim() || form.name.length > 120) validation.set('name');
  if (!form.email.trim() || form.email.trim().length > 254 || !/^[^\s@]+@[^\s@]+$/.test(form.email.trim())) validation.set('email');
  if (!form.profiles.length) validation.set('permission_role_ids');
  if ((new Set(form.grants).size !== form.grants.length || form.grants.some(value => !delegatedPermissions.includes(value)))) validation.set('permission_grants');
  if (form.denials.some(value => !delegatedPermissions.includes(value) || form.grants.includes(value))) validation.set('permission_denials');
  if (!editing.value || form.password !== '') {
    if (!/\S/.test(form.password)) validation.set('password', 'auth.validation.passwordRequired');
    else if (form.password.length < 12 || form.password.length > 128) validation.set('password');
    if (form.password !== form.confirmation) validation.set('password_confirmation');
  } else if (form.confirmation) validation.set('password_confirmation');
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
  saving.value = true;
  const input: UserInput = { inventory_location_access: isAdministrator.value || allStockLocations.value ? null : stockGrants.value.map(row => row.id), locale: locale.value === 'fr' ? 'fr' : 'en', location_access: isAdministrator.value || allLocations.value ? null : locationGrants.value.map(({ type, id }) => ({ type, id })), name: form.name, email: form.email.trim().toLowerCase(), is_active: form.isActive, permission_role_ids: form.profiles.map(profile => profile.id), permission_grants: [...form.grants], permission_denials: [...form.denials] };
  if (!editing.value || form.password !== '') {
    input.password = form.password;
    input.password_confirmation = form.confirmation;
  }
  const previous = editing.value;
  const revokesCurrent = previous?.id === props.currentUser.id && (
    input.is_active !== previous.isActive || !!input.password || JSON.stringify([...input.permission_role_ids].sort()) !== JSON.stringify((previous.permissionRoles ?? []).map(profile => profile.id).sort()) || JSON.stringify([...input.permission_grants!].sort()) !== JSON.stringify([...(previous.permissionGrants ?? [])].sort()) || JSON.stringify([...input.permission_denials!].sort()) !== JSON.stringify([...(previous.permissionDenials ?? [])].sort())
  );
  try {
    const saved = previous ? await usersApi.update(previous.id, input) : await usersApi.create(input);
    if (!alive) return;
    resetForm();
    if (revokesCurrent) { emit('sessionRevoked', saved.email); return; }
    emit('updated', saved);
    success.value = previous ? 'users.updated' : saved.emailActivationRequired && saved.isActive ? 'users.createdPending' : 'users.created';
    await load(previous ? page.value : 1);
  } catch (cause) { showError(cause); }
  finally { saving.value = false; }
}

async function manageEmail(resend: boolean) {
 if (saving.value || !editing.value) return;
 saving.value = true; error.value = '';
 try {
  const user = await (resend ? usersApi.resendEmail(editing.value.id, locale.value === 'fr' ? 'fr' : 'en') : usersApi.cancelEmail(editing.value.id));
  if (alive) { editing.value = user; emit('updated', user); await load(page.value); }
 } catch (cause) { showError(cause); }
 finally { if (alive) saving.value = false; }
}
async function profilePage(search: string, page: number) {
 const result = await accessProfilesApi.list(search, page);
 return { ...result, data: result.data.map(profile => { selectedProfiles.set(profile.id, profile); return { id: profile.id, name: accessProfileName(profile.name, t), detail: profile.permissions.map(permission => t(permissionLabel(permission))).join(', ') }; }) };
}
function chooseProfile(id: string) { const profile = selectedProfiles.get(id); if (profile && !form.profiles.some(selected => selected.id === id)) form.profiles.push(profile); }
function chooseLocation(value: import('../../api/types').LocationContext) { if (!locationGrants.value.some(grant => grant.type === value.type && grant.id === value.id)) locationGrants.value.push({ type: value.type, id: value.id, name: (value.type === 'zone' ? value.zone : value.type === 'room' ? value.room : value.site)!.name }); }
onMounted(() => load(page.value));
onUnmounted(() => { alive = false; listSequence++; form.password = form.confirmation = ''; });
const dialogId = useId();
const collectionQuery = useCollectionQuery({ paths: ['/users'], fields: { q: queryField.text(search), administrators: queryField.boolean(administratorsOnly), status: queryField.choice(statusFilter, ['', 'active', 'inactive']), profile: queryField.identifier(profileFilter), sort: queryField.choice(sort, sortOptions.users) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;
function filterUsers(kind: string) { search.value = ''; profileFilter.value = ''; administratorsOnly.value = kind === 'administrators'; statusFilter.value = ['active', 'inactive'].includes(kind) ? kind : ''; }



</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <Card class="users-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="users-heading"><PageTitle icon="users">{{ t('users.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('users.back')" type="default" @click="leave" :title="t('users.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div class="page-actions"><ListActionButton icon="refresh" :label="t('users.reload')" :disabled="saving || loading" @click="load(page)" /><Button :aria-label="t('users.add')" @click="open()" :disabled="saving || loading" data-action-icon="plus" :title="t('users.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('users.description') }}</div></div></template>

    <template #default><slot name="navigation" />
      <Message v-if="error && !(formOpen)" closable type="error" role="alert">{{ t(error) }}</Message>
      <Message v-if="success" closable type="success" role="status">{{ t(success) }}</Message>

      <div v-if="summary" class="user-summary" :aria-label="t('users.summary')"><button v-for="kind in ['total', 'active', 'inactive', 'administrators'] as const" :key="kind" type="button" @click="filterUsers(kind)"><AppIcon :name="kind === 'administrators' ? 'shield' : kind === 'active' ? 'check' : 'users'" /><span>{{ t(`users.summaryLabels.${kind}`) }}</span><strong>{{ summary[kind] }}</strong></button></div>
      <form @submit.prevent="applyFilters" class="search-filters"><div class="filter-primary"><div class="filter-field"><label for="users-search">{{ t('users.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :input-props="{ 'id': 'users-search', type: 'search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.users" /></div>
    <div class="filter-secondary"><div class="filter-field"><label for="users-status-filter">{{ t('users.status') }}</label><select id="users-status-filter" v-model="statusFilter" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="">{{ t('users.allStatuses') }}</option><option value="active">{{ t('users.active') }}</option><option value="inactive">{{ t('users.inactive') }}</option></select></div>
     <ReferenceSelect id="users-profile-filter" v-model="profileFilter" :label="t('users.permissionRoles')" :placeholder="t('users.allProfiles')" :fetch-page="profilePage" @access-lost="showError" /></div>
    <ResetFilters @reset="search = ''; administratorsOnly = false; statusFilter = ''; profileFilter = ''; sort = 'name_asc'" /></form>

      <FormDialog icon="users" v-if="formOpen" :visible="true" :title="t(editing ? 'users.editTitle' : 'users.createTitle')" :close-label="t('users.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><EmailChangeNotice v-if="editing?.pendingEmail" :email="editing.pendingEmail" :busy="saving" @resend="manageEmail(true)" @cancel="manageEmail(false)" />
<form :id="`${dialogId}-0`" @submit.prevent="save" class="dialog-form user-form" novalidate><p v-if="!editing || editing.emailActivationRequired">{{ t('users.activationHelp') }}</p>

        <Message v-if="editing" closable type="info">{{ t('users.sensitiveHelp') }}</Message>
        <AppTabs class="user-editor-tabs" v-model:value="editorTab" animated><NTabPane name="identity" display-directive="show:lazy"><template #tab><AppIcon name="user" />{{ t('users.editorTabs.identity') }}</template><div class="user-form-grid"><div class="field"><label for="user-name">{{ t('auth.name') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="form.name" maxlength="120" :disabled="saving" :status="!!fields.name ? 'error' : undefined" :input-props="{ 'id': 'user-name' }"></InputText><small v-if="fields.name" id="user-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>
          <div class="field"><label for="user-email">{{ t('auth.email') }}</label><InputText placeholder="" v-bind="validation.attrs('email')" v-model:value="form.email" :disabled="saving" :status="!!fields.email ? 'error' : undefined" :input-props="{ 'id': 'user-email', type: 'email', 'autocomplete': 'off' }"></InputText><small v-if="fields.email" id="user-email-error" role="alert" class="field-error">{{ t(fields.email) }}</small></div>

          <div class="field"><AppSwitch id="user-active" v-model="form.isActive" :disabled="saving || editing?.id === currentUser.id" aria-describedby="user-active-help">{{ t('users.active') }}</AppSwitch><small v-if="fields.is_active" id="user-active-error" role="alert" class="field-error">{{ t(fields.is_active) }}</small><small id="user-active-help">{{ t(editing?.id === currentUser.id ? 'users.selfActiveHelp' : 'users.activeHelp') }}</small></div></div></NTabPane><NTabPane name="access" display-directive="show:lazy"><template #tab><AppIcon name="shield" />{{ t('users.editorTabs.access') }}</template><div class="user-form-grid"><section class="user-access-section field" :aria-label="t('users.permissionRoles')"><ReferenceSelect id="user-permission-profile" :invalid="!!fields.permission_role_ids" :described-by="fields.permission_role_ids ? 'user-permission-profile-error' : undefined" :exclude-ids="form.profiles.map(profile => profile.id)" :label="t('users.chooseProfile')" :placeholder="t('users.chooseProfile')" :fetch-page="profilePage" :disabled="saving" @select="chooseProfile" @access-lost="showError" />
          <div class="user-profile-chips"><RemovableTag v-for="profile in form.profiles" :key="profile.id" :label="t('users.removeProfile', { name: accessProfileName(profile.name, t) })" :disabled="saving" @remove="form.profiles = form.profiles.filter(selected => selected.id !== profile.id)"><template #icon><AppIcon name="shield" /></template>{{ accessProfileName(profile.name, t) }}</RemovableTag></div>
          <small>{{ t('users.profileHelp') }}</small>
          <small v-if="fields.permission_role_ids" id="user-permission-profile-error" role="alert" class="field-error">{{ t(fields.permission_role_ids) }}</small></section>
          <fieldset class="permission-grants" id="user-permission-grants" tabindex="-1" v-bind="validation.attrs('permission_grants')"><legend>{{ t('users.effectivePermissions', { count: effectivePermissions.length }) }}</legend>
           <p>{{ t('users.permissionsHelp') }}</p>
           <PermissionOverrides v-model:grants="form.grants" v-model:denials="form.denials" :inherited="inheritedPermissions" :disabled="saving" />
           <p class="permission-integration-help">{{ t('users.integrationPermissionHelp') }}</p>
           <small v-if="fields.permission_grants || fields.permission_denials" id="user-permission-grants-error" role="alert" class="field-error">{{ t(fields.permission_grants || fields.permission_denials) }}</small></fieldset></div></NTabPane><NTabPane name="locations" display-directive="show:lazy"><template #tab><AppIcon name="location" />{{ t('users.editorTabs.locations') }}</template><div class="user-form-grid"><fieldset v-if="!isAdministrator" id="user-stock-grants" class="location-access" tabindex="-1" v-bind="validation.attrs('inventory_location_access')"><legend>{{ t('storageLocations.access') }}</legend><AppSwitch class="location-all" v-model="allStockLocations" :disabled="saving">{{ t('storageLocations.all') }}</AppSwitch><p>{{ t('storageLocations.help') }}</p><template v-if="!allStockLocations"><p v-if="!stockGrants.length">{{ t('storageLocations.none') }}</p><ul><li v-for="grant in stockGrants" :key="grant.id">{{ grant.name }} <ListActionButton icon="unlink" :label="t('storageLocations.remove', { name: grant.name })" :disabled="saving" @click="stockGrants = stockGrants.filter(row => row.id !== grant.id)" /></li></ul><StorageLocationSelect include-archived id="user-stock-location" v-model="stockCandidate" :label="t('storageLocations.choose')" :disabled="saving" @access-lost="showError" /></template><small v-if="fields.inventory_location_access" role="alert" class="field-error">{{ t(fields.inventory_location_access) }}</small></fieldset>
          <fieldset v-if="!isAdministrator" class="location-access" id="user-location-grants" tabindex="-1" v-bind="validation.attrs('location_access')"><legend>{{ t('locationAccess.title') }}</legend>
       <AppSwitch class="location-all" v-model="allLocations" :disabled="saving">{{ t('locationAccess.all') }}</AppSwitch><p>{{ t('locationAccess.help') }}</p>
       <template v-if="!allLocations"><p v-if="!locationGrants.length">{{ t('locationAccess.none') }}</p><ul><li v-for="grant in locationGrants" :key="`${grant.type}:${grant.id}`">{{ grant.name }} <ListActionButton icon="unlink" :label="t('locationAccess.remove', { name: grant.name })" severity="secondary" :disabled="saving" @click="locationGrants = locationGrants.filter(value => value !== grant)" /></li></ul><LocationSelect id="user-location-access" :model-value="null" reset-after-selection :label="t('locationAccess.add')" :empty-label="t('locationAccess.add')" :disabled="saving" @update:model-value="$event && chooseLocation($event)" @access-lost="showError" /></template>
       <small v-if="fields.location_access" id="user-location-grants-error" role="alert" class="field-error">{{ t(fields.location_access) }}</small></fieldset></div></NTabPane><NTabPane name="security" display-directive="show:lazy"><template #tab><AppIcon name="key" />{{ t('users.editorTabs.security') }}</template><div class="user-form-grid"><div class="field"><label for="user-password">{{ t(editing ? 'users.newPassword' : 'auth.password') }}</label><Password placeholder="" v-model:value="form.password" :disabled="saving" :status="!!fields.password ? 'error' : undefined" type="password" :input-props="{ 'id': 'user-password', ...(validation.attrs('password')), 'autocomplete': 'new-password', 'aria-describedby': fields.password ? 'user-password-help user-password-error' : 'user-password-help' }"></Password><small id="user-password-help">{{ t(editing ? 'users.passwordHelp' : 'auth.passwordHelp') }}</small><small v-if="fields.password" id="user-password-error" role="alert" class="field-error">{{ t(fields.password) }}</small></div>
          <div class="field"><label for="user-confirmation">{{ t('auth.passwordConfirmation') }}</label><Password placeholder="" v-model:value="form.confirmation" :disabled="saving" :status="!!fields.password_confirmation ? 'error' : undefined" type="password" :input-props="{ 'id': 'user-confirmation', ...(validation.attrs('password_confirmation')), 'autocomplete': 'new-password' }"></Password><small v-if="fields.password_confirmation" id="user-confirmation-error" role="alert" class="field-error">{{ t(fields.password_confirmation) }}</small></div></div></NTabPane></AppTabs>
        <p v-if="isAdministrator && ['access', 'locations'].includes(editorTab)">{{ t('users.adminPermissionsHelp') }}</p></form>
<template #footer><div class="dialog-actions"><Button attr-type="button" :aria-label="t('users.cancel')" type="default" :disabled="saving" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('users.save') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>


      <div class="users-table-container" :aria-busy="loading"><table class="users-table" role="table"><caption class="sr-only">{{ t('users.title') }}</caption>
          <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">{{ t('users.account') }}</th><th role="columnheader" scope="col">{{ t('users.permissionRoles') }}</th><th role="columnheader" scope="col">{{ t('users.status') }}</th><th role="columnheader" scope="col">{{ t('users.actions') }}</th></tr></thead>
          <tbody role="rowgroup"><tr role="row" v-for="entry in users" :key="entry.id"><td role="cell"><div class="user-identity"><UserAvatar :name="entry.name" :url="entry.avatarUrl" /><div><strong>{{ entry.name }}</strong><span v-if="entry.id === currentUser.id" class="self-label">{{ t('users.you') }}</span><span class="user-email">{{ entry.email }}</span><small v-if="entry.pendingEmail">{{ t('emailChange.pending', { email: entry.pendingEmail }) }}</small><Tag v-if="entry.oidcLinked" type="default">{{ oidcProviderName }}</Tag></div></div></td>
              <td role="cell" :data-label="t('users.permissionRoles')"><div class="user-profile-chips"><Tag v-for="profile in entry.permissionRoles" :key="profile.id" type="default"><template #icon><AppIcon name="shield" /></template>{{ accessProfileName(profile.name, t) }}</Tag></div><small :title="entry.permissions?.map(permission => t(permissionLabel(permission))).join(', ')">{{ t('users.effectivePermissions', { count: entry.permissions?.length ?? entry.permissionGrants?.length ?? 0 }) }}</small><small>{{ entry.locationAccess == null ? t('users.allLocations') : t('users.locationCount', { count: entry.locationAccess.length }) }}</small></td>
              <td role="cell" :data-label="t('users.status')"><div class="user-profile-chips"><span class="user-status" :data-active="entry.isActive">{{ t(entry.isActive ? 'users.active' : 'users.inactive') }}</span><Tag v-if="entry.emailActivationRequired" type="warning">{{ t('users.activationPending') }}</Tag></div></td>
              <td role="cell" :data-label="t('users.actions')"><ListActionButton icon="edit" :label="t('users.editUser', { name: entry.name })"  severity="secondary" size="small" @click="open(entry)" :disabled="saving" /></td></tr>
            <tr role="row" v-if="!users.length && !loading"><td role="cell" colspan="4">{{ t('users.empty') }}</td></tr></tbody></table></div>
      <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="loading" :label="t('users.pagination')" :previous-label="t('users.previous')" :next-label="t('users.next')" @page="collectionQuery.go($event)" /></template></Card></template>

<style scoped>
.location-access { min-width: 0; grid-column: 1 / -1; border: 1px solid var(--theme-border); border-radius: 0.5rem; padding: var(--space-4); overflow-wrap: anywhere; } .location-access li { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); margin: var(--space-2) 0; } .location-all { display: flex; gap: var(--space-2); align-items: center; }
.permission-grants { grid-column: 1 / -1; margin: var(--space-1-5) 0; padding: var(--space-4); border: 1px solid var(--theme-border); border-radius: 0.5rem; }
.permission-grants legend { font-weight: 600; padding: 0 var(--space-1-5); }
.permission-grants p { font-size: 0.875rem; line-height: 1.6; }
.permission-option { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-2) 0; cursor: pointer; }
.permission-option input { width: 1.1rem; height: 1.1rem; flex-shrink: 0; accent-color: var(--theme-accent); }
.users-table td small { display: block; margin-top: var(--space-1-5); }
.users-heading, .users-toolbar, .user-form-actions, .users-pagination { display: flex; align-items: center; justify-content: space-between; gap: var(--ui-grid-gap); flex-wrap: wrap; }
.users-heading h2 { margin: 0; }
.users-toolbar { margin: var(--ui-content-gap) 0; align-items: end; }
.users-search { display: grid; gap: var(--space-2); }
.users-search > div { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.users-search label { font-size: 0.875rem; font-weight: 600; }
.user-form { padding: 0; margin: 0; }
.user-form h3 { margin: 0 0 var(--ui-content-gap); }
.user-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3) var(--ui-grid-gap); }
.user-form-actions { justify-content: flex-start; }
.users-table-container { overflow-x: auto; }
.users-table { border-collapse: collapse; width: 100%; min-width: 35rem; text-align: left; }
.users-table th, .users-table td { padding: var(--space-3) var(--space-3); border-bottom: 1px solid var(--theme-border); }
.users-table th { font-size: 0.8rem; font-weight: 600; }
.user-identity { display: flex; align-items: center; gap: var(--space-3); }
.user-identity > div { min-width: 0; }
.user-email { display: block; margin-top: var(--space-1); font-size: 0.85rem; opacity: 0.75; }
.self-label { font-size: 0.75rem; margin-left: var(--space-2); opacity: 0.65; }
.user-status { padding: var(--space-1) var(--space-2); border-radius: 2rem; background: var(--theme-warning-soft, #fff1da); color: var(--theme-warning, #6f4206); font-size: 0.8rem; white-space: nowrap; }
.user-status[data-active="true"] { background: var(--theme-subtle, #e3f2e9); color: var(--theme-success, #1d5b3a); }
.users-pagination { margin-top: var(--ui-content-gap); font-size: 0.875rem; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
@media (max-width: 600px) {
  .user-form-grid { grid-template-columns: 1fr; }
  .user-form { padding: var(--space-4); }
  .users-table { display: block; min-width: 0; }
  .users-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
  .users-table tbody { display: grid; gap: var(--ui-grid-gap); }
  .users-table tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ui-grid-gap); padding: var(--space-4); border: 1px solid var(--theme-border); border-radius: 0.75rem; }
  .users-table td { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-1-5); padding: 0; border: 0; min-width: 0; overflow-wrap: anywhere; }
  .users-table td:first-child, .users-table td:last-child { grid-column: 1 / -1; }
  .users-table td:first-child { display: block; }
  .users-table td:last-child { align-items: flex-end; }
  .users-table td[data-label]::before { content: attr(data-label); font-size: 0.75rem; font-weight: 600; opacity: 0.75; }
  .user-email { overflow-wrap: anywhere; }
}
.form-section-heading { grid-column: 1 / -1; font-size: .78rem; margin: var(--space-1-5) 0; color: var(--theme-muted); display: flex; align-items: center; gap: var(--space-2); }.user-access-section { grid-column: 1 / -1; padding: var(--space-3); background: var(--theme-subtle); border-radius: .7rem; }.user-access-section h4 { margin: 0; font-size: .85rem; }.user-access-section p { font-size: .75rem; line-height: 1.5; }.user-summary { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: var(--space-3); margin-bottom: var(--ui-content-gap); }.user-summary button { display: grid; grid-template-columns: auto 1fr; align-items: center; gap: var(--space-1-5) var(--space-2); text-align: left; border: 1px solid var(--theme-border); border-radius: .7rem; padding: var(--space-3); background: var(--theme-subtle); color: var(--theme-text); cursor: pointer; font: inherit; }.user-summary svg { width: 1rem; height: 1rem; color: var(--theme-muted); }.user-summary span { font-size: .74rem; }.user-summary strong { grid-column: 2; font-size: 1.4rem; }.user-summary button:hover { border-color: var(--theme-accent); }.user-summary button:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }@media(max-width:600px){ .user-summary { grid-template-columns: repeat(2,minmax(0,1fr)); } }
.user-editor-tabs { min-width: 0; }.user-editor-tabs :deep(.n-tabs-pane-wrapper) { padding: var(--space-4) 0 0; }.user-editor-tabs :is(.permission-grants,.location-access) { padding: 0; border: 0; border-radius: 0; margin: 0; }.user-editor-tabs :is(.permission-grants,.location-access) legend { padding: 0; margin-bottom: var(--space-3); }.user-editor-tabs .user-form-grid { row-gap: var(--ui-grid-gap); }
</style>

<style scoped>
.user-profile-chips { display: flex; flex-wrap: wrap; gap: var(--space-1-5); margin-block: var(--space-1-5); }

</style>
