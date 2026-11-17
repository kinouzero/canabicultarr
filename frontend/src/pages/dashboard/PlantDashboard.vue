<script setup lang="ts">
import { toRef } from 'vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import { locationIcons } from '../../lib/location';
import CollectionPagination from '../../components/CollectionPagination.vue';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import { registerModuleMessages } from '../../lib/locale-messages';
import PlantBatchActions from '../../components/PlantBatchActions.vue';
import { useReferenceOptions } from '../../lib/reference-options';
import { useDisclosureState } from '../../lib/disclosure-preferences';
import { useCollectionSort } from '../../lib/collection-sorting';
import PageTitle from '../../components/PageTitle.vue';
import { queryString, useRecordQuery } from '../../lib/route-query';
import { phaseKeys as plantPhases, phaseLabel } from '../../lib/lifecycle';
import LocationReadings from '../../components/LocationReadings.vue';
import PlantPhotoCard from '../../components/PlantPhotoCard.vue';
import PlantPhaseIcon from '../../components/PlantPhaseIcon.vue';
import { formatDateTime } from '../../lib/date-time';
import { defineAsyncComponent, computed, watch, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import type { LocationContext } from '../../api/types';
import { ApiError } from '../../api/auth';
import { locationsApi } from '../../api/locations';
import { plantDashboardApi, dashboardSorts, type DashboardFilters, type PlantDashboardPage, type PlantAction, type PlantListScope, type Plant } from '../../api/plants';
import en from '../../locales/en/plant-home.json';
import fr from '../../locales/fr/plant-home.json';
import plantEn from '../../locales/en/plants.json';
import plantFr from '../../locales/fr/plants.json';
const metricIcons = { plants: 'leaf', planned: 'clock', due: 'bell', strains: 'strain' } as const;
const metricFilterLabels = { plants: 'showActive', planned: 'showPlanned', due: 'showDue' } as const;
const metricSelection = computed(() => ({ plants: !filters.dueOnly && filters.status === 'active', planned: !filters.dueOnly && filters.status === 'planned', due: filters.dueOnly }));
function selectMetric(key: keyof typeof metricIcons) {
 if (key === 'due') toggleDue();
 else if (key === 'plants' || key === 'planned') showStatus(key === 'plants' ? 'active' : 'planned');
}

const PlantLifecycleDialog = defineAsyncComponent(() => import('../../components/PlantLifecycleDialog.vue'));
const lifecycleTarget = ref<{ id: string; name: string; action: PlantAction } | null>(null);
function lifecycleSaved() { lifecycleTarget.value = null; void load(); }
const PlantWateringDialog = defineAsyncComponent(() => import('../../components/PlantWateringDialog.vue'));
const waterTarget = ref<{ id: string; name: string } | null>(null);
function watered() { waterTarget.value = null; void load(); }
const props = defineProps<{ initialSiteId: string | null; canManage: boolean }>();
const emit = defineEmits<{ siteChanged: [id: string | null]; browsePlants: [scope: PlantListScope]; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
registerModuleMessages(mergeLocaleMessage, { en: { plantHome: en, plants: plantEn }, fr: { plantHome: fr, plants: plantFr } });
const PlantRegistry = defineAsyncComponent(() => import('../culture/PlantRegistry.vue'));
const creatingPlant = ref(false);
const creationLocation = ref<LocationContext | null>(null);
const creationNotice = ref(false);
function createPlant() {
 if (!props.canManage || !site.value) return;
 creationLocation.value = { type: 'site', id: site.value.id, site: { ...site.value }, room: null, zone: null };
 creationNotice.value = false; creatingPlant.value = true;
}
function closeCreation() {
 creatingPlant.value = false;
 void nextTick(() => document.querySelector<HTMLButtonElement>('.dashboard-create')?.focus());
}
function plantCreated(plant: Plant) {
 Object.assign(filters, { status: plant.phase === 'planned' ? 'planned' : 'active', phase: '', strainId: '', dueOnly: false });
 creationNotice.value = true;
 void load();
}
const recordRoute = useRecordQuery('plant');
const selectedPlantId = ref<string | null>(queryString(recordRoute.route.query.plant) || null);
const selectedPlantAction = ref<'maintenance' | 'edit' | null>(null);
function openPlant(id: string, action: 'maintenance' | 'edit' | null = null) { selectedPlantAction.value = action; selectedPlantId.value = id; if (!action) void recordRoute.open(id); }
let pendingPlantFocus: { id: string; selector: string } | null = null;
function restorePlantFocus() {
 if (!pendingPlantFocus || loading.value) return;
 const target = document.querySelector<HTMLButtonElement>(`[data-testid="plant-card-${pendingPlantFocus.id}"] ${pendingPlantFocus.selector}`);
 if (target && !target.disabled && !target.closest('[inert]')) { target.focus(); if (document.activeElement === target) pendingPlantFocus = null; }
 else if (!target) { document.querySelector<HTMLButtonElement>('.refresh-button')?.focus(); pendingPlantFocus = null; }
}
function closePlant(syncRoute = true) {
 const id = selectedPlantId.value;
 if (id) pendingPlantFocus = { id, selector: selectedPlantAction.value === 'edit' ? '.plant-edit' : selectedPlantAction.value === 'maintenance' ? '.plant-maintenance' : '.open-plant' };
 selectedPlantId.value = null; selectedPlantAction.value = null;
 if (syncRoute) void recordRoute.close();
 void nextTick(restorePlantFocus);
}
watch(() => recordRoute.route.query.plant, value => {
 const id = queryString(value);
 if (id) { selectedPlantAction.value = null; selectedPlantId.value = id; }
 else if (selectedPlantId.value && !selectedPlantAction.value) closePlant(false);
});
const batchSelection = ref<string[]>([]); const batchOpen = ref(false);
function toggleBatch(id: string, checked: boolean) { batchSelection.value = checked ? [...new Set([...batchSelection.value, id])] : batchSelection.value.filter(value => value !== id); }
const roomPage = ref(1);
const zonePages = ref<Record<string, number>>({});
let pageScope = '';
function changeRoomPage(page: number) { roomPage.value = page; void load(); }
function changeZonePage(id: string, page: number) { zonePages.value[id] = page; void load(); }
const previewRows = ref<Record<string, PlantDashboardPage['data']>>({});
const previewStates = reactive<Record<string, 'loading' | 'ready' | 'error'>>({});
const previewBusy = computed(() => Object.values(previewStates).some(state => state !== 'ready'));
let dashboardController: AbortController | null = null;
const data = ref<PlantDashboardPage | null>(null); const site = ref<{ id: string; name: string } | null>(null);
const loading = ref(false); const error = ref(''); const noSites = ref(false); const loadingSites = ref(false); const updatedAt = ref<string | null>(null);
const siteCatalog = useReferenceOptions({
 value: () => site.value?.id ?? props.initialSiteId ?? '',
 selected: () => site.value ? { ...site.value, detail: '' } : null,
 disabled: () => loadingSites.value,
 fetchPage: () => sitePage, unavailable: () => t('selectControl.unavailable'),
 onError: cause => { if (cause instanceof ApiError && [401, 403].includes(cause.status)) failure(cause); },
});
const siteOptions = siteCatalog.options;
async function sitePage(search: string, page: number) {
 const result = await locationsApi.list('sites', search, page);
 // Ancestor sites remain navigable when access is limited to a room or zone.
 return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: '' })) };
}
let sequence = 0; let interval: ReturnType<typeof setInterval> | undefined;
const sort = useCollectionSort('dashboard', dashboardSorts);
const filters = reactive({ status: 'active' as DashboardFilters['status'], phase: '', strainId: '', dueOnly: false, sort });
const querySite = ref(props.initialSiteId ?? '');
const collectionQuery = useCollectionQuery({ paths: ['/'], page: false, fields: { site: queryField.identifier(querySite), status: queryField.choice(toRef(filters, 'status'), ['active', 'planned']), phase: queryField.identifier(toRef(filters, 'phase')), strain: queryField.identifier(toRef(filters, 'strainId')), due: queryField.boolean(toRef(filters, 'dueOnly')), sort: queryField.choice(sort, dashboardSorts) }, load: () => loadScope() });
const disclosurePreferences = useDisclosureState('dashboard');
const disclosures = computed<Record<string, boolean>>(() => {
 const result: Record<string, boolean> = {};
 groups.value.forEach((room, roomIndex) => {
  const key = disclosureKey('room', room.id);
  result[key] = disclosurePreferences.isOpen(key, roomIndex === 0);
  room.zones.forEach((zone, zoneIndex) => { const zoneKey = disclosureKey('zone', zone.key); result[zoneKey] = disclosurePreferences.isOpen(zoneKey, zoneIndex === 0); });
 });
 return result;
});
const strainOptions = ref<{ id: string; name: string }[]>([]);
const hasFilters = computed(() => !!filters.phase || !!filters.strainId || filters.dueOnly);
function resetFilters() { Object.assign(filters, { status: 'active', phase: '', strainId: '', dueOnly: false, sort: 'name_asc' }); }
function showStatus(status: 'active' | 'planned') {
 if (filters.status === status && !filters.dueOnly) return;
 Object.assign(filters, { status, phase: '', dueOnly: false });
}
function toggleDue() {
 if (loading.value) return;
 if (filters.status === 'planned') { filters.status = 'active'; filters.phase = ''; }
 filters.dueOnly = !filters.dueOnly;
}
const groups = computed(() => {
 const result = new Map<string, { name: string; zones: Map<string, { name: string; key: string; total: number; location: PlantListScope['location']; plants: PlantDashboardPage['data'] }> }>();
 for (const [key, header] of Object.entries(data.value?.groups ?? {})) {
  const location = header.location;
  // Headers remain available without fetching the gallery's cards.
  const roomId = location.room ? location.room.id : 'site';
  if (!result.has(roomId)) result.set(roomId, { name: location.room?.name ?? t('plantHome.siteLevel'), zones: new Map() });
  const room = result.get(roomId)!; const zoneId = location.zone?.id ?? 'room';
  room.zones.set(zoneId, { name: location.zone?.name ?? (location.room ? t('plantHome.roomLevel') : ''), key, total: header.total, location, plants: previewRows.value[key] ?? [] });
 }
 return [...result.entries()].map(([id, room]) => ({ id, name: room.name, pagination: data.value?.rooms?.[id], zones: [...room.zones.entries()].map(([zoneId, zone]) => ({ id: zoneId, ...zone })).filter(zone => zone.id !== 'room' || zone.total > 0).sort((a, b) => Number(a.id === 'room' || a.id === 'site') - Number(b.id === 'room' || b.id === 'site') || a.name.localeCompare(b.name, locale.value)) })).sort((a, b) => Number(a.id === 'site') - Number(b.id === 'site') || Number(!a.zones.some(zone => zone.id !== 'room' && zone.id !== 'site')) - Number(!b.zones.some(zone => zone.id !== 'room' && zone.id !== 'site')) || a.name.localeCompare(b.name, locale.value));
});
function disclosureKey(kind: 'room' | 'zone', id: string) { return `${site.value?.id}:${kind}:${id}`; }
function rememberDisclosure(key: string, event: Event) {
 const element = event.currentTarget as HTMLDetailsElement;
 if (element.isConnected && event.target === element && disclosures.value[key] !== element.open) disclosurePreferences.set(key, element.open);
}
function visibleGroups() {
 return groups.value.flatMap(room => disclosures.value[disclosureKey('room', room.id)]
  ? room.zones.filter(zone => zone.id === 'room' || disclosures.value[disclosureKey('zone', zone.key)]).map(zone => zone.key) : []);
}
async function ensurePreviews(keys: string[]) {
 const pending = [...new Set(keys)].filter(key => data.value?.groups?.[key] && !previewStates[key]);
 if (!pending.length || !site.value || !dashboardController) return;
 const current = sequence; const id = site.value.id; const signal = dashboardController.signal; const criteria = { ...filters };
 pending.forEach(key => { previewStates[key] = 'loading'; });
 for (let offset = 0; offset < pending.length; offset += 25) {
  const batch = pending.slice(offset, offset + 25);
  try {
   const result = await plantDashboardApi.previews(id, batch, criteria, signal);
   if (current !== sequence || signal.aborted) return;
   for (const key of batch) {
    previewRows.value[key] = result.data.filter(plant => `${plant.location.type}:${plant.location.id}` === key);
    previewStates[key] = 'ready';
   }
  } catch (cause) {
   if (current !== sequence || signal.aborted) return;
   if (cause instanceof ApiError && [401, 403].includes(cause.status)) { failure(cause); return; }
   batch.forEach(key => { previewStates[key] = 'error'; });
  }
 }
}
function retryPreview(key: string) { delete previewStates[key]; void ensurePreviews([key]); }
watch([disclosures, loading], () => { if (!loading.value) void ensurePreviews(visibleGroups()); });
function failure(cause: unknown) {
 const code = cause instanceof ApiError ? cause.code : '';
 error.value = /^auth\.errors\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) { creatingPlant.value = false; data.value = null; updatedAt.value = null; site.value = null; siteCatalog.records.value = []; emit('siteChanged', null); emit('accessLost', cause); }
}
async function load() {
 if (!site.value) return;
 const scope = JSON.stringify([site.value.id, filters.status, filters.phase, filters.strainId, filters.dueOnly]);
 if (scope !== pageScope) { roomPage.value = 1; zonePages.value = {}; pageScope = scope; }
 const selectedGroups = Object.keys(previewRows.value).filter(key => previewRows.value[key]!.some(plant => batchSelection.value.includes(plant.id)));
 dashboardController?.abort(); dashboardController = new AbortController();
 const current = ++sequence; const id = site.value.id; loading.value = true; error.value = '';
 try {
  const result = await plantDashboardApi.read(id, { ...filters }, { metadataOnly: true, roomPage: roomPage.value, zonePages: zonePages.value, signal: dashboardController.signal });
  if (current !== sequence) return;
  data.value = result; roomPage.value = result.room_meta?.current_page ?? 1; previewRows.value = {};
  for (const key of Object.keys(previewStates)) delete previewStates[key];
  strainOptions.value = result.filterOptions?.strains ?? []; updatedAt.value = new Date().toISOString();
  await ensurePreviews([...visibleGroups(), ...selectedGroups]);
 } catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) { loading.value = false; void nextTick(restorePlantFocus); } }
}
function choose(id: string) {
 const selected = siteOptions.value.find(option => option.id === id);
 if (!selected || siteCatalog.loading.value || siteCatalog.failed.value || selected.disabled || site.value?.id === id) return;
 noSites.value = false; filters.strainId = ''; strainOptions.value = []; querySite.value = id;
}
async function initialize() {
 const current = ++sequence; const previousSite = site.value; const selectedId = querySite.value;
 noSites.value = false; loading.value = true; loadingSites.value = true; error.value = '';
 if (!previousSite) { data.value = null; updatedAt.value = null; }
 try {
  siteCatalog.query.value = '';
  await siteCatalog.load();
  if (current !== sequence || siteCatalog.failed.value) return;
  const options = siteCatalog.records.value;
  if (!options.length) { site.value = null; data.value = null; updatedAt.value = null; noSites.value = true; emit('siteChanged', null); return; }
  let selected = options.find(option => option.id === selectedId) ?? (!previousSite && siteCatalog.chosen.value?.id === selectedId ? siteCatalog.chosen.value ?? undefined : undefined);
  if (!selected && selectedId) {
   const resolved = await sitePage(selectedId, 1);
   if (current !== sequence) return;
   selected = resolved.data.find(option => option.id === selectedId);
  } else selected ??= siteCatalog.chosen.value ?? undefined;
  if (selectedId && !selected && !previousSite) throw new ApiError(404, 'auth.errors.requestFailed');
  const nextSite = selected ?? options[0]!;
  site.value = nextSite;
  if (nextSite.id !== selectedId) { data.value = null; updatedAt.value = null; filters.strainId = ''; strainOptions.value = []; }
  emit('siteChanged', nextSite.id); loadingSites.value = false;
  if (queryString(recordRoute.route.query.site) === nextSite.id) await load();
 } catch (cause) { if (current === sequence) { data.value = null; site.value = null; failure(cause); } }
 finally { if (current === sequence) { loading.value = false; loadingSites.value = false; } }
}
async function loadScope() {
 if (!site.value || site.value.id !== querySite.value) {
  const selected = siteOptions.value.find(row => row.id === querySite.value && !row.disabled);
  if (!selected) { await initialize(); return; }
  site.value = selected; data.value = null; updatedAt.value = null; strainOptions.value = [];
  emit('siteChanged', selected.id);
 }
 await load();
}
onMounted(() => { void initialize(); interval = setInterval(() => { if (!document.hidden && !loading.value && !batchOpen.value && !batchSelection.value.length && !waterTarget.value && !lifecycleTarget.value && !creatingPlant.value && !selectedPlantId.value && site.value) void load(); }, 60_000); });
onUnmounted(() => { dashboardController?.abort(); sequence++; if (interval) clearInterval(interval); });

const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><PlantRegistry v-if="creatingPlant" detail-only initial-action="create" :can-manage="canManage" :initial-location="creationLocation" @close="closeCreation" @created="plantCreated" @access-lost="failure" />
 <PlantRegistry v-if="selectedPlantId" :key="selectedPlantId" detail-only :initial-plant-id="selectedPlantId" :initial-action="selectedPlantAction" :can-manage="false" @close="closePlant" @changed="load()" @access-lost="failure" />
 <section class="plant-home" data-testid="plant-home"><PlantLifecycleDialog v-if="lifecycleTarget" :plant-id="lifecycleTarget.id" :name="lifecycleTarget.name" :action="lifecycleTarget.action" @close="lifecycleTarget = null" @saved="lifecycleSaved" @access-lost="failure" />
  <PlantWateringDialog v-if="waterTarget" :plant-id="waterTarget.id" :name="waterTarget.name" @close="waterTarget = null" @saved="watered" @access-lost="failure" />
  <div class="home-heading"><div><p class="eyebrow">{{ t('plantHome.eyebrow') }}</p><PageTitle icon="home">{{ t('plantHome.title') }}</PageTitle><p class="home-description">{{ t('plantHome.description') }}</p></div></div>
  <div class="dashboard-toolbar"><div class="site-controls"><label class="site-selector" for="dashboard-site"><AppIcon :name="locationIcons.site" /><span class="site-label">{{ t('plantHome.site') }}</span><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty'), query: siteCatalog.query.value, onSearch: siteCatalog.search, pending: siteCatalog.loading.value || siteCatalog.failed.value, failed: siteCatalog.failed.value, more: siteCatalog.more.value && !siteCatalog.failed.value ? { label: siteCatalog.loading.value ? t('selectControl.loading') : t('selectControl.more'), load: siteCatalog.loadMore } : undefined }" id="dashboard-site" :aria-label="t('plantHome.chooseSite')" :value="site?.id ?? ''" :disabled="loadingSites || !siteCatalog.initialized.value" @change="choose(($event.target as HTMLSelectElement).value)"><option v-if="!siteOptions.length" value="">{{ t(loadingSites ? 'plantHome.loading' : noSites ? 'plantHome.noSites' : 'plantHome.chooseSite') }}</option><option v-for="option in siteOptions" :key="option.id" :value="option.id" :disabled="siteCatalog.loading.value || siteCatalog.failed.value || option.disabled">{{ option.name }}</option></select></label></div>
   <div ref="selectionHeader" class="page-actions dashboard-actions"><ListActionButton class="refresh-button" icon="refresh" :label="t('plantHome.refresh')" :disabled="loading" :loading="loading" @click="initialize()" />
    <Button v-if="canManage" class="dashboard-create list-action-button" data-action-icon="plus" :disabled="loadingSites || !site" @click="createPlant" :aria-label="t('plants.add')" :title="t('plants.add')" type="primary"><AppIcon name="plus" /></Button></div></div>
  <Message v-if="siteCatalog.failed.value" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }} <Button attr-type="button" :aria-label="t('collection.refresh')" type="default" :disabled="siteCatalog.loading.value || loadingSites" @click="data ? siteCatalog.load(siteCatalog.retryPage.value) : initialize()"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></Message>
  <p v-if="!updatedAt && !noSites" class="metric-update-pending" role="status">{{ t(loading ? 'plantHome.firstUpdatePending' : 'plantHome.notUpdated') }}</p>
  <div v-if="data" class="home-metrics"><component v-for="key in ['plants', 'planned', 'due', 'strains'] as const" :key="key" :is="key === 'strains' ? 'div' : 'button'" class="metric-tile" :class="{ 'metric-switch': key !== 'strains', selected: key !== 'strains' && metricSelection[key] }" :type="key === 'strains' ? undefined : 'button'" :aria-label="key === 'strains' ? undefined : t(`plantHome.${metricFilterLabels[key]}`)" :aria-pressed="key === 'strains' ? undefined : metricSelection[key]" :disabled="key === 'strains' ? undefined : loading" @click="selectMetric(key)"><span class="metric-label"><AppIcon :name="metricIcons[key]" /><span>{{ t(hasFilters && key === 'plants' ? 'plantHome.matchingPlants' : `plantHome.${key}`) }}</span></span>
    <strong :data-testid="`home-${key}`">{{ data.summary[key] ?? 0 }}</strong>
    <small v-if="updatedAt" class="metric-updated" :class="{ 'refresh-failed': error || siteCatalog.failed.value }"><AppIcon :name="error || siteCatalog.failed.value ? 'warning' : 'clock'" /><span>{{ t(error || siteCatalog.failed.value ? 'plantHome.refreshFailed' : 'plantHome.updated') }} <time :datetime="updatedAt" :title="formatDateTime(updatedAt, locale)">{{ formatDateTime(updatedAt, locale, 'time') }}</time></span></small></component></div>
  <form v-if="site" class="dashboard-tools" :aria-label="t('plantHome.filters')" @submit.prevent="collectionQuery.apply()"><div class="dashboard-field"><label for="dashboard-phase">{{ t('plantHome.phaseFilter') }}</label><div class="phase-select"><PlantPhaseIcon v-if="filters.phase" :phase="filters.phase" /><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="dashboard-phase" v-model="filters.phase" :disabled="loading || filters.status === 'planned'"><option value="">{{ t('plantHome.allPhases') }}</option><option v-for="phase in plantPhases.filter(value => value !== 'archived' && value !== 'planned')" :key="phase" :value="phase">{{ phaseLabel(phase, t) }}</option></select></div></div>
   <div class="dashboard-field"><label for="dashboard-strain">{{ t('plantHome.strainFilter') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="dashboard-strain" v-model="filters.strainId" :disabled="loading"><option value="">{{ t('plantHome.allStrains') }}</option><option v-for="strain in strainOptions" :key="strain.id" :value="strain.id">{{ strain.name }}</option></select></div>
   <div class="dashboard-field"><label for="dashboard-sort">{{ t('plantHome.sort') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="dashboard-sort" v-model="filters.sort" :disabled="loading"><option v-for="sort in dashboardSorts" :key="sort" :value="sort">{{ t(`plantHome.sorts.${sort}`) }}</option></select></div>
   <div class="dashboard-filter-actions"><button class="filter-reset" :aria-label="t('plantHome.resetFilters')" type="button" :disabled="loading || (!hasFilters && filters.status === 'active' && filters.sort === 'name_asc')" @click="resetFilters"><AppIcon name="refresh" class="button-icon" />{{ t('actions.reset') }}</button><small>{{ t('plantHome.sortHelp') }}</small></div></form>
  <Message v-if="creationNotice" closable type="success" role="status">{{ t('plants.created') }}</Message>
  <Message v-if="error" closable type="error" role="alert">{{ t(error) }}</Message>
  <p v-if="loading" class="loading-state" role="status">{{ t('plantHome.loading') }}</p>
   <PlantBatchActions :selection-target="selectionHeader" v-model="batchSelection" :plants="groups.flatMap(room => room.zones.flatMap(zone => zone.plants.slice(0, 10)))" :disabled="loading || previewBusy" @opened="batchOpen = $event" @changed="load()" @access-lost="emit('accessLost', $event)" />
  <div v-if="data" class="dashboard-results"><div v-if="!data.meta.total && !groups.length" class="home-empty"><span class="empty-symbol"><AppIcon name="leaf" /></span><h3>{{ t(hasFilters ? 'plantHome.noFilteredPlants' : filters.status === 'planned' ? 'plantHome.noPlanned' : 'plantHome.noActive') }}</h3><p>{{ t(hasFilters ? 'plantHome.noFilteredPlantsHelp' : filters.status === 'planned' ? 'plantHome.noPlannedHelp' : 'plantHome.noActiveHelp') }}</p></div>
   <details v-for="room in groups" :key="disclosureKey('room', room.id)" class="home-room" :open="disclosures[disclosureKey('room', room.id)]" @toggle="rememberDisclosure(disclosureKey('room', room.id), $event)"><DisclosureSummary :level="1" :icon="locationIcons[room.id === 'site' ? 'site' : 'room']" class="room-heading"><h3>{{ room.name }}</h3><nav v-if="room.pagination && room.pagination.last_page > 1" class="room-zone-pagination" :aria-label="t('plantHome.zonePages', { name: room.name })" @click.stop @keydown.stop><ListActionButton icon="arrowLeft" :label="t('plantHome.previousZones', { name: room.name })" :disabled="loading || room.pagination.current_page <= 1" @click.prevent="changeZonePage(room.id, room.pagination!.current_page - 1)" />
     <span>{{ room.pagination.current_page }} / {{ room.pagination.last_page }}</span>
     <ListActionButton icon="arrow" :label="t('plantHome.nextZones', { name: room.name })" :disabled="loading || room.pagination.current_page >= room.pagination.last_page" @click.prevent="changeZonePage(room.id, room.pagination!.current_page + 1)" /></nav></DisclosureSummary>
    <LocationReadings v-if="disclosures[disclosureKey('room', room.id)]" :site-id="site?.id" :location-key="`${room.id === 'site' ? 'site' : 'room'}:${room.id === 'site' ? site?.id : room.id}`" :total="data?.locationReadingsMeta?.[`${room.id === 'site' ? 'site' : 'room'}:${room.id === 'site' ? site?.id : room.id}`]?.total" :context-key="`dashboard:${site?.id}:room:${room.id}`" :readings="data?.locationReadings?.[`${room.id === 'site' ? 'site' : 'room'}:${room.id === 'site' ? site?.id : room.id}`] ?? []" @access-lost="failure" />
    <component :is="zone.id === 'room' ? 'section' : 'details'" v-for="zone in room.zones" :key="zone.key" class="home-zone" v-bind="zone.id === 'room' ? {} : { open: disclosures[disclosureKey('zone', zone.key)] }" @toggle="zone.id !== 'room' && rememberDisclosure(disclosureKey('zone', zone.key), $event)"><DisclosureSummary :level="2" :icon="locationIcons.zone" v-if="zone.id !== 'room'" class="zone-heading"><h4>{{ zone.name }}</h4></DisclosureSummary>
     <h4 v-else-if="zone.name" class="unassigned-heading"><AppIcon :name="locationIcons[zone.location.type]" />{{ zone.name }}</h4>
     <template v-if="disclosures[disclosureKey('room', room.id)] && (zone.id === 'room' || disclosures[disclosureKey('zone', zone.key)])"><LocationReadings v-if="zone.id !== 'room'" :site-id="site?.id" :location-key="zone.key" :total="data?.locationReadingsMeta?.[zone.key]?.total" :context-key="`dashboard:${zone.key}`" :readings="data?.locationReadings?.[zone.key] ?? []" @access-lost="failure" /><p v-if="previewStates[zone.key] === 'loading'" class="zone-preview-status" role="status">{{ t('plantHome.loading') }}</p>
      <div v-else-if="previewStates[zone.key] === 'error'" class="zone-preview-status" role="alert"><span>{{ t('auth.errors.requestFailed') }}</span><Button type="default" @click="retryPreview(zone.key)"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.retry') }}</span></Button></div>
      <div v-else-if="previewStates[zone.key] === 'ready'" class="zone-gallery" tabindex="0" role="region" :aria-label="t('plantHome.zoneGallery', { name: zone.name || room.name })"><PlantPhotoCard v-for="plant in zone.plants.slice(0, 10)" :key="plant.id" :plant="plant" selectable :selected="batchSelection.includes(plant.id)" :loading="loading" :data-testid="`plant-card-${plant.id}`"
        @changed="load()" @access-lost="failure" @select="toggleBatch(plant.id, $event)" @open="openPlant(plant.id)" @water="waterTarget = { id: plant.id, name: plant.name }" @maintenance="openPlant(plant.id, 'maintenance')" @edit="openPlant(plant.id, 'edit')" @lifecycle="plant.nextAction && (lifecycleTarget = { id: plant.id, name: plant.name, action: plant.nextAction })" /></div>
      <div v-if="zone.total > 0" class="zone-gallery-footer"><Button type="default" :aria-label="t('plantHome.viewZonePlants', { name: zone.name || room.name, count: data.groups?.[zone.key]?.total ?? zone.plants.length })" @click="emit('browsePlants', { location: zone.location, status: filters.status })"><span>{{ t('plantHome.viewAllPlants', { count: data.groups?.[zone.key]?.total ?? zone.plants.length }) }}</span><AppIcon name="arrow" /></Button></div></template></component></details></div>
  <CollectionPagination v-if="data?.room_meta && data.room_meta.last_page > 1" :page="data.room_meta.current_page" :last-page="data.room_meta.last_page" :total="data.room_meta.total" :disabled="loading" :label="t('plantHome.roomPages')" @page="changeRoomPage" />
  <div v-if="noSites && !loading && !error" class="home-empty"><span class="empty-symbol"><AppIcon name="location" /></span><h3>{{ t('plantHome.noSites') }}</h3><p>{{ t('plantHome.noSitesHelp') }}</p></div></section></template>
<style scoped>
.room-zone-pagination { display: flex; align-items: center; gap: var(--ui-control-gap); margin-inline-start: auto; flex-shrink: 0; font-size: .7rem; font-weight: 400; white-space: nowrap; }
.room-heading > h3 { flex: 1; min-width: 0; }
/* Nest the group headings without narrowing the horizontal plant galleries. */
.home-room > .home-zone { margin-top: var(--space-3); }
.home-zone > .zone-heading { width: calc(100% - .85rem); margin-inline-start: var(--space-3); }
.home-zone > .zone-gallery { margin-top: var(--space-2); }
.unassigned-heading { display: flex; align-items: center; gap: var(--space-1-5); margin: var(--space-3) var(--space-3) var(--space-2); color: var(--theme-muted); font-size: .75rem; font-weight: 550; }
.unassigned-heading svg { width: .9rem; height: .9rem; flex-shrink: 0; }

.zone-preview-status { display: flex; align-items: center; gap: var(--space-3); font-size: .8rem; color: var(--theme-muted); padding: var(--space-3) 0; }
.zone-gallery { display: flex; align-items: flex-start; gap: var(--space-3); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x proximity; scrollbar-width: thin; scrollbar-color: var(--theme-border) transparent; padding: var(--space-1) var(--space-0-5) var(--space-3); }
.zone-gallery > :deep(.photo-plant) { flex: 0 0 min(20rem, 100%); width: min(20rem, 100%); max-width: 100%; scroll-snap-align: start; }
.zone-gallery:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; border-radius: .6rem; }
.zone-gallery-footer { display: flex; justify-content: flex-end; padding-top: var(--space-2); }
.zone-gallery-footer svg { width: 1rem; height: 1rem; flex-shrink: 0; }

.plant-home { min-width: 0; }.home-heading { display: flex; justify-content: space-between; align-items: center; gap: var(--ui-grid-gap); flex-wrap: wrap; margin: var(--space-2) 0 var(--ui-content-gap); }h2 { margin: var(--space-2) 0 var(--space-2); font-size: clamp(1.6rem, 2.4vw, 2.15rem); font-weight: 650; letter-spacing: -.055em; color: var(--theme-text, #193c30); }.eyebrow { margin: 0; color: var(--theme-muted, #63745e); font-size: .62rem; letter-spacing: .17em; font-weight: 700; }.home-description { color: var(--theme-muted, #63745e); font-size: .82rem; margin: 0; line-height: 1.6; }
.dashboard-toolbar { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-3); }
.site-controls { display: flex; align-items: stretch; gap: var(--space-2); min-width: 0; flex: 0 1 24rem; }
.site-selector { position: relative; display: flex; align-items: center; gap: var(--space-2); min-width: 0; flex: 1; height: var(--app-control-height); box-sizing: border-box; padding: 0 var(--space-3); border: 1px solid var(--theme-border, #dee6d9); border-radius: .6rem; background: var(--theme-surface, #fff); color: var(--theme-text, #33523d); cursor: pointer; font: inherit; }
.site-selector .site-label { color: var(--theme-muted, #63745e); flex-shrink: 0; font-size: .8rem; font-weight: 650; line-height: 1.45; }
.site-selector:has(select:disabled) { opacity: .65; cursor: default; }
.site-selector:focus-within { outline: 2px solid var(--theme-focus, #648752); outline-offset: 2px; }
.site-controls svg, .dashboard-create svg { width: 1rem; height: 1rem; flex-shrink: 0; }
.dashboard-create { flex-shrink: 0; }
.dashboard-tools { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: var(--ui-grid-gap); margin-bottom: var(--ui-content-gap); padding: var(--space-4); border: 1px solid var(--theme-border, #dee6d9); border-radius: 1rem; background: var(--theme-surface, #fbfcf8); }
.dashboard-field { min-width: 0; }.dashboard-field label { display: block; font-size: .72rem; color: var(--theme-muted, #63745e); margin-bottom: var(--space-1-5); }.dashboard-field select { width: 100%; min-width: 0; padding: var(--space-2); border: 1px solid var(--theme-border, #c7d3c0); border-radius: .5rem; background: var(--theme-surface, white); font: inherit; font-size: .8rem; color: inherit; }.dashboard-field select:focus-visible, .metric-switch:focus-visible { outline: 2px solid var(--theme-focus, #648752); outline-offset: 2px; }
.dashboard-filter-actions { grid-column: 1 / -1; display: flex; gap: var(--space-3) var(--ui-grid-gap); flex-wrap: wrap; align-items: center; }.filter-reset { border: 0; background: transparent; color: var(--theme-muted, #45633a); text-decoration: underline; font: inherit; font-size: .75rem; cursor: pointer; }.filter-reset:disabled { opacity: .5; cursor: default; }.dashboard-filter-actions small { font-size: .65rem; color: var(--theme-muted, #63745e); }
.home-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: var(--space-3); margin-bottom: var(--ui-content-gap); }
.metric-tile { display: grid; border: 1px solid transparent; font: inherit; color: inherit; box-sizing: border-box; grid-template-rows: minmax(0, 1fr) 2.75rem minmax(0, 1fr); justify-items: center; align-items: center; gap: var(--space-1); min-width: 0; padding: var(--space-2); border-radius: .6rem; background: var(--theme-subtle, #f5f7f1); }
.home-metrics strong { font-size: 1.4rem; color: var(--theme-text, #375132); font-weight: 650; font-variant-numeric: tabular-nums; }
.home-metrics p { grid-column: 1 / -1; justify-self: end; margin: 0; color: var(--theme-muted, #63745e); font-size: .65rem; }
.metric-updated { display: inline-flex; align-items: flex-start; justify-content: center; gap: var(--ui-icon-gap); max-width: 100%; color: var(--theme-muted); font-size: .6rem; line-height: 1.4; text-align: center; }
.metric-updated svg { width: .8rem; height: .8rem; flex-shrink: 0; margin-top: .05rem; }
.metric-updated time { white-space: nowrap; }
.metric-updated.refresh-failed { color: var(--theme-warning); }
.metric-update-pending { color: var(--theme-muted); font-size: .75rem; }
.metric-label { display: inline-flex; align-items: center; justify-content: center; gap: var(--space-1-5); max-width: 100%; color: var(--theme-muted, #63745e); font-size: .75rem; text-align: center; line-height: 1.4; }
.metric-label > svg { width: 1rem; height: 1rem; flex-shrink: 0; }.metric-label > span { min-width: 0; }
.metric-tile > strong { justify-self: center; align-self: center; text-align: center; line-height: 1; display: inline-flex; align-items: center; justify-content: center; min-width: 2.75rem; height: 2.75rem; box-sizing: border-box; }
.metric-switch { cursor: pointer; }.metric-switch:enabled:hover { background: var(--theme-border, #dce8d3); }.metric-switch:disabled { cursor: default; opacity: .6; }.home-room { margin: 0 0 var(--ui-content-gap); }.home-zone { margin: 0 0 var(--ui-content-gap); }
.home-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 20rem; padding: var(--space-8); border: 1px dashed var(--theme-border, #cfdbca); border-radius: 1.2rem; text-align: center; background: var(--theme-surface, #fbfcf8); }.empty-symbol { display: grid; place-items: center; background: var(--theme-subtle, #edf3e7); border-radius: 1.5rem; width: 5rem; height: 5rem; color: var(--theme-muted, #91a77e); margin-bottom: var(--ui-content-gap); }.empty-symbol svg { width: 2.5rem; height: 2.5rem; }.home-empty h3 { font-size: 1.15rem; letter-spacing: -.03em; }.home-empty p { font-size: .8rem; color: var(--theme-muted, #63745e); max-width: 28rem; line-height: 1.8; }.loading-state { color: var(--theme-muted, #63745e); padding: var(--space-8) 0; }button:focus-visible { outline: 2px solid var(--theme-focus, #648752); outline-offset: -3px; }.refresh-button:disabled { opacity: .5; }h3, h4 { overflow-wrap: anywhere; }
@media (max-width: 700px) { .dashboard-toolbar { gap: var(--space-2); }.site-controls { flex: 1 1 0; }.dashboard-actions { flex: 0 0 auto; flex-wrap: nowrap; }.home-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }.home-metrics p { justify-self: start; }.plant-image { height: 13rem; } }
@media (prefers-reduced-motion: reduce) { .photo-plant { transition: none; }.photo-plant:hover { transform: none; } }
</style>
