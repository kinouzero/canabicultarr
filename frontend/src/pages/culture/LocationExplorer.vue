<script setup lang="ts">
import LocationTrail from '../../components/LocationTrail.vue';
import { useFormTabs } from '../../lib/form-tabs';
import AppTabs from '../../components/AppTabs.vue';
import { NTabPane } from 'naive-ui';
import LocationBreadcrumbs from '../../components/LocationBreadcrumbs.vue';
import RecordQrButton from '../../components/RecordQrButton.vue';
import { useRouter } from 'vue-router';
import { useResolvedRecordQuery } from '../../lib/route-query';
import { locationIcons } from '../../lib/location';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionIdentity from '../../components/CollectionIdentity.vue';
import { photoThumbnailUrl } from '../../lib/photo-urls';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import PageTitle from '../../components/PageTitle.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import LocationPlants from '../../components/LocationPlants.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import RecordCardMedia from '../../components/RecordCardMedia.vue';
import { NTag as Tag } from 'naive-ui';
import NotesEditor from '../../components/NotesEditor.vue';
import RichNotes from '../../components/RichNotes.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import FormDialog from '../../components/FormDialog.vue';
import DetailDialog from '../../components/DetailDialog.vue';
import ConfirmRecordDeletion from '../../components/ConfirmRecordDeletion.vue';
import PhotoGallery from '../../components/PhotoGallery.vue';
import RecordPhotoActions from '../../components/RecordPhotoActions.vue';
import { photosApi } from '../../api/photos';
import { useId, computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { locationsApi, type LocationNode } from '../../api/locations';
const pageSize = useCollectionPageSize('locations');
const sort = useCollectionSort('locations', sortOptions.common);

const props = defineProps<{ embedded?: boolean; canManage: boolean; canCreateSite?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [error: ApiError] }>();
const { t, te } = useI18n();
const site = ref<LocationNode | null>(null);
const room = ref<LocationNode | null>(null);
const records = ref<LocationNode[]>([]);
const deleting = ref<LocationNode | null>(null);
const router = useRouter();
const locationRoute = useResolvedRecordQuery('location', async value => { const [kind, id] = value.split(':'); if (!['site', 'room', 'zone'].includes(kind ?? '') || !id) throw new ApiError(404, 'locations.errors.notFound'); return locationsApi.detail(kind!, id); }, showError);
const selectedLocation = locationRoute.record;
const parentLocation = ref<Pick<LocationNode, 'id' | 'kind' | 'name'> | null>(null);
watch(() => selectedLocation.value ? `${selectedLocation.value.kind}:${selectedLocation.value.id}:${selectedLocation.value.parentId}` : '', async (_, __, onCleanup) => {
 let current = true; onCleanup(() => { current = false; });
 parentLocation.value = null;
 const record = selectedLocation.value;
 if (!record || record.kind === 'site' || !record.parentId) return;
 const kind = record.kind === 'zone' ? 'room' : 'site';
 const known = kind === 'room' ? room.value : site.value;
 if (known?.id === record.parentId) { parentLocation.value = known; return; }
 try {
  const result = await locationsApi.context(record.kind, record.id);
  const parent = result.context[kind];
  if (current && parent?.id === record.parentId) parentLocation.value = { ...parent, kind };
 } catch (cause) { if (current) showError(cause); }
});
const navigationSite = ref(''); const navigationRoom = ref(''); let navigationSequence = 0;
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const formOpen = ref(false);
const editing = ref<LocationNode | null>(null);
const form = reactive({ name: '', code: '', description: '', wateringDays: '' as number | '' });
let requestSequence = 0;
let alive = true;
onUnmounted(() => { alive = false; requestSequence++; });
const kind = computed(() => room.value ? 'zone' : site.value ? 'room' : 'site');
const formKind = computed(() => editing.value?.kind ?? kind.value);
const path = computed(() => !site.value ? 'sites' : !room.value
  ? `sites/${encodeURIComponent(site.value.id)}/rooms`
  : `sites/${encodeURIComponent(site.value.id)}/rooms/${encodeURIComponent(room.value.id)}/zones`);

const baseline = ref('');
const snapshot = () => JSON.stringify(form);
const dirty = computed(() => saving.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, saving, { onNavigate: () => { formOpen.value = false; } });
const editorTabs = useFormTabs(formOpen, {"watering_alert_days": "conditions", "description": "notes"});
const editorTab = editorTabs.tab;
const validation = useFieldErrors(() => ({
 code: { id: 'location-code', message: 'locations.validation.codeInvalid' },
 name: { id: 'location-name', message: 'locations.validation.nameInvalid' },
 watering_alert_days: { id: 'location-watering-days', message: 'locations.validation.wateringDaysInvalid' },
 description: { id: 'location-description', message: 'locations.validation.descriptionInvalid' },
}), () => ({ name: form.name, code: form.code, watering_alert_days: form.wateringDays, description: form.description }), editorTabs.reveal);
const fields = validation.fields;

async function closeForm() {
 if (!await unsaved.confirmDiscard()) return false;
 formOpen.value = false; error.value = ''; validation.clear(); return true;
}
async function closeLocation() { if (await closeForm()) void locationRoute.close(); }
async function leave() { if (await closeForm()) emit('close'); }

function key(value: string): string {
  return /^(auth|locations)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed';
}
function showError(cause: unknown) {
  if (!alive) return;
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError) {
    if (formOpen.value) {
      validation.capture(cause);
      if (cause.code === 'locations.errors.codeTaken') { validation.set('code', cause.code); void validation.focus(); }
      if (cause.code === 'locations.errors.nameTaken') { validation.set('name', cause.code); void validation.focus(); }
    }
    if (cause.status === 422) error.value = 'auth.errors.validationFailed';
    if (cause.status === 401 || cause.status === 403) {
      records.value = []; formOpen.value = false; selectedLocation.value = null; deleting.value = null;
      emit('accessLost', cause);
    }
  }
}
async function load(target = 1) {
  const sequence = ++requestSequence;
  loading.value = true;
  error.value = '';
  try {
    let result = await locationsApi.list(path.value, search.value.trim(), target, sort.value, pageSize.value);
    if (sequence !== requestSequence) return;
    if (target > result.meta.last_page && result.meta.total > 0) {
      result = await locationsApi.list(path.value, search.value.trim(), result.meta.last_page, sort.value, pageSize.value);
    }
    if (sequence !== requestSequence) return;
    records.value = result.data;
    page.value = result.meta.current_page;
    lastPage.value = result.meta.last_page;
    total.value = result.meta.total;
  } catch (cause) { if (sequence === requestSequence) showError(cause); }
  finally { if (sequence === requestSequence) loading.value = false; }
}
async function navigate(level: 'sites' | 'rooms' | 'zones', entry?: Pick<LocationNode, 'id'>) {
  if (!await closeForm()) return;
  const query = { ...locationRoute.route.query };
  delete query.location; delete query.q; delete query.page;
  const siteId = level === 'sites' ? '' : level === 'rooms' && entry ? entry.id : site.value?.id ?? '';
  const roomId = level === 'zones' && entry ? entry.id : '';
  if (siteId) query.site = siteId; else delete query.site;
  if (roomId) query.room = roomId; else delete query.room;
  success.value = '';
  await router.push({ path: locationRoute.route.path, query });
}
async function selectBreadcrumb(key: string) {
 if (key === 'sites') return navigate('sites');
 const [kind, id] = key.split(':');
 if (!id) return;
 if (kind === 'site') await navigate('rooms', { id });
 else if (kind === 'room' && site.value) await navigate('zones', { id });
 else if (kind === 'zone' && await closeForm()) await locationRoute.open(`zone:${id}`);
}
async function loadContext(target = 1) {
 const current = ++navigationSequence;
 try {
  if (navigationRoom.value && !navigationSite.value) throw new ApiError(404, 'locations.errors.notFound');
  let parentSite = site.value?.id === navigationSite.value ? site.value : null;
  let parentRoom = room.value?.id === navigationRoom.value && room.value.parentId === navigationSite.value ? room.value : null;
  if (navigationSite.value && !parentSite) {
   const result = await locationsApi.list('sites', navigationSite.value, 1, 'name_asc', 10);
   parentSite = result.data.find(row => row.id === navigationSite.value) ?? null;
   if (!parentSite) throw new ApiError(404, 'locations.errors.notFound');
  }
  if (parentSite && navigationRoom.value && !parentRoom) {
   const result = await locationsApi.list(`sites/${encodeURIComponent(parentSite.id)}/rooms`, navigationRoom.value, 1, 'name_asc', 10);
   parentRoom = result.data.find(row => row.id === navigationRoom.value) ?? null;
   if (!parentRoom) throw new ApiError(404, 'locations.errors.notFound');
  }
  if (!alive || current !== navigationSequence) return;
  site.value = parentSite; room.value = parentRoom;
  await load(target);
 } catch (cause) { if (alive && current === navigationSequence) { records.value = []; showError(cause); } }

}
async function open(entry: LocationNode | null = null) {
  if (!await unsaved.confirmDiscard()) return;
  editing.value = entry;
  form.name = entry?.name ?? '';
  form.code = entry?.code ?? '';
  form.description = entry?.description ?? '';
  form.wateringDays = entry?.wateringAlertDays ?? '';
  validation.clear();
  error.value = success.value = '';
  baseline.value = snapshot(); formOpen.value = true;
}
async function save() {
  if (saving.value) return;
  validation.clear(); error.value = success.value = '';
  if (!form.name.trim() || form.name.length > 120) validation.set('name');
  if (form.wateringDays !== '' && (!Number.isInteger(form.wateringDays) || form.wateringDays < 1 || form.wateringDays > 3650)) validation.set('watering_alert_days');
  if (formKind.value !== 'site' && (editing.value || form.code.trim()) && !/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/.test(form.code.trim())) validation.set('code');
  if (form.description.length > 2000) validation.set('description');
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
  saving.value = true;
  try {
    const input = { name: form.name, ...(formKind.value !== 'site' && (editing.value || form.code.trim()) ? { code: form.code.trim() } : {}), description: form.description.trim() || null, watering_alert_days: form.wateringDays === '' ? null : form.wateringDays };
    const result = editing.value ? await locationsApi.update(photoPath(editing.value), editing.value.id, input) : await locationsApi.create(path.value, input);
    if (!alive) return;
    applyRecord(result);
    formOpen.value = false;
    success.value = editing.value ? 'locations.updated' : 'locations.created';
    await load(editing.value ? page.value : 1);
  } catch (cause) { showError(cause); }
  finally { saving.value = false; }
}
function photoPath(entry: LocationNode): string {
 return entry.kind === 'site' ? 'sites' : entry.kind === 'room' ? `sites/${encodeURIComponent(site.value!.id)}/rooms` : path.value;
}
function applyRecord(record: LocationNode) {
 records.value = records.value.map(entry => entry.id === record.id ? record : entry);
 if (site.value?.id === record.id) site.value = record;
 if (room.value?.id === record.id) room.value = record;
 if (selectedLocation.value?.id === record.id) selectedLocation.value = record;
}
async function setPhoto(id: string | null, file?: File, disk?: string) {
 const target = selectedLocation.value;
 if (!target || saving.value) return false;
 const targetPath = photoPath(target);
 saving.value = true; error.value = ''; success.value = '';
 try {
  if (file) {
   const document = await photosApi.upload(file, target.name, { type: target.kind, id: target.id }, disk);
   if (!alive) return false;
   id = document.id;
  }
  const result = await locationsApi.photo(targetPath, target.id, id);
  if (!alive) return false;
  applyRecord(result); success.value = 'locations.photoSaved'; return true;
 } catch (cause) { if (alive) showError(cause); return false; }
 finally { saving.value = false; }
}
async function deleteLocation() {
 if (!deleting.value || saving.value) return;
 const target = deleting.value; const targetPath = photoPath(target);
 saving.value = true; error.value = ''; success.value = '';
 try {
  await locationsApi.remove(targetPath, target.id); if (!alive) return;
  deleting.value = null;
  if (selectedLocation.value?.id === target.id) selectedLocation.value = null;
  if (site.value?.id === target.id) { site.value = null; room.value = null; }
  else if (room.value?.id === target.id) room.value = null;
  await load(records.value.length === 1 ? Math.max(1, page.value - 1) : page.value);
  success.value = 'locations.deleted';
 } catch (cause) { if (alive) showError(cause); }
 finally { saving.value = false; }
}
function uploadPhoto(file: File, disk?: string): Promise<boolean> { return setPhoto(null, file, disk); }
onMounted(() => loadContext(page.value));
const dialogId = useId();
const collectionQuery = useCollectionQuery({ paths: ['/sites'], fields: { q: queryField.text(search), site: queryField.identifier(navigationSite), room: queryField.identifier(navigationRoom), sort: queryField.choice(sort, sortOptions.common) }, page, pageSize, load: target => loadContext(target) });
const applyFilters = collectionQuery.apply;


const selectionHeader = ref<HTMLElement | null>(null);
</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <Card class="locations-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="locations-heading"><PageTitle icon="building">{{ t('locations.title') }}</PageTitle></div><div ref="selectionHeader" class="page-actions"><Button v-if="!embedded" :aria-label="t('locations.back')" type="default" :disabled="saving" @click="leave" :title="t('locations.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="loading" :disabled="saving" @click="load(page)" /><CollectionViewToggle :preference-key="`locations:${kind}`" :disabled="saving || loading" /><Button v-if="canManage && (kind === 'site' ? canCreateSite !== false : (room ?? site)?.canManage !== false)" :disabled="saving || loading" @click="open()" data-action-icon="plus" :aria-label="t(`locations.add.${kind}`)" :title="t(`locations.add.${kind}`)" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('locations.description') }}</div></div></template>

    <template #default><LocationBreadcrumbs :site="site" :room="room" :disabled="loading || saving" @select="selectBreadcrumb" @access-lost="showError" />

      <p v-if="!canManage" class="locations-readonly">{{ t('locations.readOnly') }}</p>
      <Message v-if="error && !(formOpen)" closable type="error" role="alert">{{ t(error) }}</Message>
      <Message v-if="success" closable type="success" role="status">{{ t(success) }}</Message>

      <form @submit.prevent="applyFilters" class="locations-search search-filters"><div class="filter-primary"><div class="filter-field"><label for="location-search">{{ t('locations.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="saving" :input-props="{ 'id': 'location-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.common" /></div>
    <ResetFilters @reset="search = ''; sort = 'name_asc'" /></form>
      <ConfirmRecordDeletion v-if="deleting" :name="deleting.name" :message="t('locations.deleteHelp')" :busy="saving" :error="error ? t(error) : ''" @cancel="deleting = null; error = ''" @confirm="deleteLocation" />
      <DetailDialog v-if="selectedLocation" record :title="selectedLocation.name" :icon="locationIcons[selectedLocation.kind]" :close-label="t('locations.closeDetails')" :busy="saving" :error="!formOpen && error ? t(error) : ''" @close="closeLocation"><nav class="record-actions" :aria-label="t('recordDetails.actions')"><div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: selectedLocation.kind, id: selectedLocation.id, name: selectedLocation.name }" :disabled="saving" /><Button v-if="canManage && selectedLocation.canManage !== false" type="default" :aria-label="t('locations.editNamed', { name: selectedLocation.name })" :disabled="saving" data-action-icon="edit" @click="open(selectedLocation)"><AppIcon name="edit" /><span>{{ t('recordDetails.edit') }}</span></Button><Button v-if="canManage && selectedLocation.canManage !== false" type="error" :aria-label="t('locations.deleteNamed', { name: selectedLocation.name })" :disabled="saving" @click="error = ''; deleting = selectedLocation" data-action-icon="trash"><AppIcon name="trash" /><span>{{ t('recordDetails.delete') }}</span></Button></div></nav>
       <Message v-if="success" type="success" closable>{{ t(success) }}</Message>
       <div class="location-detail-overview"><PhotoGallery :key="`${selectedLocation.id}:${selectedLocation.photoUrl}`" :kind="selectedLocation.kind" :record-id="selectedLocation.id" :name="selectedLocation.name" :cover-url="selectedLocation.photoUrl" @failure="showError"><template #actions><RecordPhotoActions overlay v-if="canManage && selectedLocation.canManage !== false" :disabled="saving" :has-photo="!!selectedLocation.hasPhoto" :fetch-photos="photosApi.library" @select="setPhoto" :upload-file="uploadPhoto" :upload-error="error ? t(error) : ''" @access-lost="showError" /></template></PhotoGallery><div class="location-zone-details"><div class="location-detail-tags">
        <Tag round size="small" type="default"><template #icon><AppIcon :name="locationIcons[selectedLocation.kind]" /></template>{{ t(`locations.singular.${selectedLocation.kind}`) }}</Tag>
        <Tag v-if="selectedLocation.code" round size="small" type="default" :aria-label="`${t('locations.code')} : ${selectedLocation.code}`"><template #icon><AppIcon name="hash" /></template><code :title="selectedLocation.code">{{ selectedLocation.code }}</code></Tag>
        <Tag v-if="parentLocation" round size="small" type="default" :title="`${t(`locations.singular.${parentLocation.kind}`)} : ${parentLocation.name}`"><template #icon><AppIcon :name="locationIcons[parentLocation.kind]" /></template>{{ parentLocation.name }}</Tag>
       </div><div class="location-zone-setting"><AppIcon name="water" /><div><small>{{ t('collection.wateringDelay') }}</small><strong>{{ selectedLocation.wateringAlertDays == null ? t('collection.inherited') : t('collection.days', { count: selectedLocation.wateringAlertDays }) }}</strong></div></div><RichNotes v-if="selectedLocation.description" :value="selectedLocation.description" /><p v-else class="location-muted">{{ t('locations.noNotes') }}</p></div></div>
       <LocationPlants :key="`${selectedLocation.kind}:${selectedLocation.id}`" :location="selectedLocation" @access-lost="showError" /></DetailDialog>
      <FormDialog :icon="locationIcons[formKind]" v-if="formOpen && canManage" :visible="true" :title="t(`locations.${editing ? 'editTitle' : 'createTitle'}.${formKind}`)" :close-label="t('locations.cancel')" :busy="saving" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><form :id="`${dialogId}-0`" @submit.prevent="save" class="dialog-form location-form" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><div class="field"><label for="location-name">{{ t('locations.name') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="form.name" maxlength="120" :status="!!fields.name ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'location-name' }"></InputText><small v-if="fields.name" id="location-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div>

        <div v-if="formKind !== 'site'" class="field"><label for="location-code">{{ t('locations.code') }}</label><InputText placeholder="" v-bind="validation.attrs('code')" v-model:value="form.code" maxlength="32" :status="!!fields.code ? 'error' : undefined" :disabled="saving" :input-props="{ 'id': 'location-code' }"></InputText><small>{{ t('locations.codeHelp') }}</small><small v-if="fields.code" id="location-code-error" role="alert" class="field-error">{{ t(fields.code) }}</small></div>
        </div></NTabPane><NTabPane name="conditions" display-directive="show"><template #tab><AppIcon name="settings" />{{ t('editorTabs.conditions') }}</template><div class="dialog-form"><div class="field"><label for="location-watering-days">{{ t('locations.wateringDays') }}</label><input id="location-watering-days" v-bind="validation.attrs('watering_alert_days')" v-model.number="form.wateringDays" type="number" min="1" max="3650" step="1" :disabled="saving" :aria-invalid="!!fields.watering_alert_days" :aria-describedby="fields.watering_alert_days ? 'location-watering-help location-watering-days-error' : 'location-watering-help'" /><small id="location-watering-help">{{ t('locations.wateringDaysHelp') }}</small><small v-if="fields.watering_alert_days" id="location-watering-days-error" role="alert" class="field-error">{{ t(fields.watering_alert_days) }}</small></div>
        </div></NTabPane><NTabPane name="notes" display-directive="show"><template #tab><AppIcon name="file" />{{ t('editorTabs.notes') }}</template><div class="dialog-form"><div class="field dialog-wide"><label for="location-description">{{ t('locations.notes') }}</label><NotesEditor id="location-description" v-model="form.description" :label="t('locations.notes')" :disabled="saving" :invalid="!!fields.description" :described-by="fields.description ? 'location-description-error' : undefined" /><small v-if="fields.description" id="location-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div></div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('locations.cancel')" type="default" :disabled="saving" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('locations.save') : t('actions.create')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>

      <div class="location-list-heading"><h3><AppIcon :name="locationIcons[kind]" />{{ t(`locations.level.${kind}`) }}</h3><span>{{ t(`locations.levelHelp.${kind}`) }}</span></div>
      <div :aria-busy="loading" class="location-results"><p v-if="loading" role="status">{{ t('locations.loading') }}</p>
        <div v-else-if="!records.length" class="resource-empty"><AppIcon name="location" /><span>{{ t(`locations.empty.${kind}`) }}</span></div>
        <CollectionTable :selection-target="selectionHeader" :preference-key="`locations:${kind}`" :key="kind" grid-class="resource-grid location-grid" v-if="records.length" :rows="records" :can-select="row => canManage && row.canManage !== false" :row-name="row => row.name" :remove="row => locationsApi.remove(photoPath(row), row.id)" :loading="loading || saving" @removed="load(page)" @access-lost="showError" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'children', label: t('collection.contents') }, { key: 'watering', label: t('collection.wateringDelay') }, { key: 'actions', label: t('collection.actions') }]">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(entry)" v-for="entry in records" :key="entry.id" :icon="locationIcons[kind]" :eyebrow="t(`locations.singular.${kind}`)"><template #title><button v-if="kind !== 'zone'" type="button" class="location-name" :disabled="saving" :aria-label="t('locations.open', { name: entry.name })" @click="navigate(kind === 'site' ? 'rooms' : 'zones', entry)"><span class="location-name-content"><span>{{ entry.name }}</span><AppIcon name="arrow" /></span></button><button v-else type="button" class="location-name" :disabled="saving" :aria-label="t('locations.open', { name: entry.name })" @click="locationRoute.open(`${entry.kind}:${entry.id}`)"><span class="location-name-content"><span>{{ entry.name }}</span><AppIcon name="arrow" /></span></button></template>
            <template v-if="entry.canSelect === false" #badge><Tag type="default">{{ t('collection.navigationOnly') }}</Tag></template>
            <template v-if="entry.canSelect !== false" #media><button type="button" class="location-cover record-card-media-open" :aria-label="t('locations.detailsNamed', { name: entry.name })" :disabled="saving" @click="error = ''; locationRoute.open(`${entry.kind}:${entry.id}`)"><RecordCardMedia :src="entry.photoUrl ? photoThumbnailUrl(entry.photoUrl) : null" :alt="entry.name" :empty-label="t('recordDetails.noPhotos')" :error-label="t('recordDetails.photoUnavailable')"><span class="record-card-media-action"><AppIcon name="expand" /></span></RecordCardMedia></button></template>
            <LocationTrail v-if="site" class="resource-path" :location="{ type: room ? 'room' : 'site', id: room?.id ?? site.id, site, room, zone: null }" />
            <dl class="resource-facts"><div v-if="entry.code"><dt>{{ t('locations.code') }}</dt><dd>{{ entry.code }}</dd></div>
              <div v-if="kind !== 'zone'"><dt><AppIcon :name="locationIcons[kind === 'site' ? 'room' : 'zone']" />{{ t(kind === 'site' ? 'locations.level.room' : 'locations.level.zone') }}</dt><dd>{{ entry.childrenCount }}</dd></div>
              <div v-if="entry.canSelect !== false" :class="{ 'resource-wide': kind === 'zone' }"><dt><AppIcon name="water" />{{ t('collection.wateringDelay') }}</dt><dd>{{ entry.wateringAlertDays == null ? t('collection.inherited') : t('collection.days', { count: entry.wateringAlertDays }) }}</dd></div></dl>
            <RichNotes v-if="entry.description" class="resource-note" :value="entry.description" />
            <template #management><ListActionButton icon="edit" v-if="canManage && entry.canManage !== false" :label="t('locations.editNamed', { name: entry.name })" :disabled="saving" @click="open(entry)" /><ListActionButton icon="trash" v-if="canManage && entry.canManage !== false" :label="t('locations.deleteNamed', { name: entry.name })" :disabled="saving" @click="error = ''; deleting = entry" /></template></ResourceCard></template><template #name="{ row: entry }"><div class="collection-table-name"><CollectionIdentity :name="entry.name" :photo-url="entry.photoUrl" image :label="t('locations.open', { name: entry.name })" :disabled="saving" @open="kind === 'zone' ? locationRoute.open(`${entry.kind}:${entry.id}`) : navigate(kind === 'site' ? 'rooms' : 'zones', entry)" /><span v-if="entry.code" class="card-reference">{{ entry.code }}</span><div v-if="entry.canSelect === false" class="collection-table-tags"><Tag type="default">{{ t('collection.navigationOnly') }}</Tag></div></div></template>
<template #children="{ row: entry }">{{ kind === 'zone' ? '—' : `${entry.childrenCount} ${t(kind === 'site' ? 'locations.level.room' : 'locations.level.zone')}` }}</template>
<template #watering="{ row: entry }">{{ entry.wateringAlertDays == null ? t('collection.inherited') : t('collection.days', { count: entry.wateringAlertDays }) }}</template>
<template #actions="{ row: entry }"><div class="collection-table-actions"><ListActionButton icon="eye" :label="t(kind === 'site' ? 'locations.viewRoomsNamed' : kind === 'room' ? 'locations.viewZonesNamed' : 'locations.open', { name: entry.name })" :disabled="saving" @click="kind === 'zone' ? locationRoute.open(`${entry.kind}:${entry.id}`) : navigate(kind === 'site' ? 'rooms' : 'zones', entry)" /><ListActionButton v-if="entry.canSelect !== false && kind !== 'zone'" icon="image" :label="t('locations.detailsNamed', { name: entry.name })" :disabled="saving" @click="error = ''; locationRoute.open(`${entry.kind}:${entry.id}`)" /><ListActionButton icon="edit" v-if="canManage && entry.canManage !== false" :label="t('locations.editNamed', { name: entry.name })" :disabled="saving" @click="open(entry)" /><ListActionButton icon="trash" v-if="canManage && entry.canManage !== false" :label="t('locations.deleteNamed', { name: entry.name })" :disabled="saving" @click="error = ''; deleting = entry" /></div></template></CollectionTable></div>
      <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="saving || loading" :label="t('locations.pagination')" :previous-label="t('locations.previous')" :next-label="t('locations.next')" @page="collectionQuery.go($event)" /></template></Card></template>

<style scoped>
.locations-heading { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.location-breadcrumbs { margin: var(--space-1-5) 0 var(--ui-content-gap); }
.location-breadcrumbs svg { width: .9rem; height: .9rem; flex-shrink: 0; }
.location-kicker, .location-muted, .locations-readonly { font-size: .75rem; color: var(--theme-muted); }
.location-list-heading { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); flex-wrap: wrap; margin: var(--ui-content-gap) 0 var(--space-3); }
.location-list-heading h3 { display: flex; align-items: center; gap: var(--space-1-5); font-size: .85rem; margin: 0; }.location-list-heading svg { width: 1rem; height: 1rem; }
.location-list-heading > span { color: var(--theme-muted); font-size: .72rem; }
.location-zone-details { min-width: 0; display: grid; gap: var(--ui-grid-gap); }.location-zone-details .resource-path { margin: 0; }
.location-zone-setting { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); background: var(--theme-subtle); border-radius: .65rem; }.location-zone-setting > svg { width: 1.2rem; height: 1.2rem; color: var(--theme-accent); }.location-zone-setting div { display: grid; gap: var(--space-1); }.location-zone-setting small { color: var(--theme-muted); }.location-zone-setting strong { font-size: .85rem; }
.location-cover:disabled { cursor: default; opacity: .65; }
.location-detail-overview { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--ui-grid-gap); align-items: start; }
@media (max-width: 600px) { .location-detail-overview { grid-template-columns: minmax(0, 1fr); } }

.location-detail-tags { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1-5); min-width: 0; }
.location-detail-tags :deep(.n-tag.n-tag--round) { max-width: 100%; height: auto; gap: var(--space-1-5); padding: var(--space-1) var(--space-2); }
.location-detail-tags :deep(.n-tag__content) { display: inline-flex; align-items: center; min-width: 0; white-space: normal; overflow-wrap: anywhere; }
.location-detail-tags :deep(.n-tag.n-tag--round .n-tag__icon) { flex: 0 0 auto; margin: 0; }
.location-detail-tags :deep(.n-tag__icon svg) { display: block; width: .85rem; height: .85rem; }
.location-detail-tags code { min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: inherit; font-weight: 600; overflow-wrap: anywhere; }
.location-name-content { display: flex; justify-content: space-between; align-items: center; gap: var(--space-2); min-width: 0; }
.location-name-content > span { min-width: 0; overflow-wrap: anywhere; }
.location-name-content > svg { width: 1rem; height: 1rem; color: var(--theme-muted); flex-shrink: 0; }
</style>
