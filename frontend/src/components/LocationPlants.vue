<script setup lang="ts">
import { queryField, useCollectionQuery } from '../lib/collection-query';
import { queryString, useRecordQuery } from '../lib/route-query';
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/plants.json';
import fr from '../locales/fr/plants.json';
import { locationsApi, type LocationNode, type LocationPlant } from '../api/locations';
import { ApiError } from '../api/auth';
import { useCollectionPageSize } from '../lib/collection-page-size';
import AppIcon from './AppIcon.vue';
import PlantBatchActions from './PlantBatchActions.vue';
import PlantTable from './PlantTable.vue';
import { strainsApi } from '../api/strains';
import { applyLifecycle, lifecycleStages } from '../lib/lifecycle';
import ResetFilters from './ResetFilters.vue';
import { useInsightPreferences } from '../lib/insight-preferences';
import CollectionPagination from './CollectionPagination.vue';
import ListActionButton from './ListActionButton.vue';
import Message from './AppMessage.vue';
const PlantRegistry = defineAsyncComponent(() => import('../pages/culture/PlantRegistry.vue'));
const PlantWateringDialog = defineAsyncComponent(() => import('./PlantWateringDialog.vue'));
const PlantLifecycleDialog = defineAsyncComponent(() => import('./PlantLifecycleDialog.vue'));
const props = defineProps<{ location: LocationNode }>();
const emit = defineEmits<{ accessLost: [error: ApiError] }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { plants: en }, fr: { plants: fr } });
const batchSelection = ref<string[]>([]);
function selectPlant(id: string, checked: boolean) {
 batchSelection.value = checked ? [...new Set([...batchSelection.value, id])] : batchSelection.value.filter(value => value !== id);
}
const rows = ref<LocationPlant[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const pageSize = useCollectionPageSize('location-plants');
const id = useId();
const preferences = useInsightPreferences('location-plants', { status: 'all' });
const status = computed({ get: () => ['all', 'active', 'planned'].includes(preferences.status) ? preferences.status : 'all', set: (value: string) => { preferences.status = value; } });
const plantAction = ref<'edit' | 'maintenance' | undefined>();
const waterTarget = ref<LocationPlant | null>(null);
const lifecycleTarget = ref<LocationPlant | null>(null);
const plantRoute = useRecordQuery('plant');
function openPlant(plant: LocationPlant, action?: 'edit' | 'maintenance') { plantAction.value = action; if (action) plantId.value = plant.id; else void plantRoute.open(plant.id); }
function closePlant() { const action = plantAction.value; plantId.value = ''; plantAction.value = undefined; if (!action) void plantRoute.close(); void load(); }
const loading = ref(false); const error = ref(false); const plantId = ref(''); let sequence = 0;
onUnmounted(() => { sequence++; });
async function load(target = page.value) {
 const current = ++sequence; loading.value = true; error.value = false;
 try {
  if (!lifecycleStages.value.length) { const settings = await strainsApi.settings(); if (current !== sequence) return; applyLifecycle(settings.stages, settings.icons); }
  let result = await locationsApi.plants(props.location, target, pageSize.value, status.value);
  if (current !== sequence) return;
  if (target > result.meta.last_page) result = await locationsApi.plants(props.location, Math.max(1, result.meta.last_page), pageSize.value, status.value);
  if (current !== sequence) return;
  rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (cause) {
  if (current !== sequence) return;
  rows.value = []; error.value = true;
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { if (current === sequence) loading.value = false; }
}
const collectionQuery = useCollectionQuery({ paths: ['/sites'], prefix: 'locationPlants.', fields: { status: queryField.choice(status, ['all', 'active', 'planned']) }, page, pageSize, load: target => load(target) });
watch(() => plantRoute.route.query.plant, value => { if (!plantAction.value) plantId.value = queryString(value); }, { immediate: true });
onMounted(() => { void load(page.value); });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><section class="location-plants" :aria-label="t('locations.currentPlants')"><header><h4><AppIcon name="leaf" />{{ t('locations.currentPlants') }}</h4><div ref="selectionHeader" class="page-actions"><ListActionButton icon="refresh" :label="t('actions.refresh')" :disabled="loading" @click="load()" /></div></header>
  <div class="location-plants-controls"><div class="field"><label :for="`${id}-status`">{{ t('plants.status') }}</label><select :id="`${id}-status`" v-model="status" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="all">{{ t('plants.all') }}</option><option value="active">{{ t('plants.active') }}</option><option value="planned">{{ t('plants.phases.planned') }}</option></select></div><ResetFilters :disabled="status === 'all'" @reset="status = 'all'" /></div>
  <Message v-if="error" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }}</Message>
  <p v-else-if="loading" role="status">{{ t('plants.loading') }}</p>
  <p v-else-if="!rows.length">{{ t(status === 'all' ? 'locations.noPlants' : 'locations.noMatchingPlants') }}</p>
  <PlantBatchActions :selection-target="selectionHeader" v-model="batchSelection" :plants="rows" :disabled="loading" @changed="load()" @access-lost="emit('accessLost', $event)" />
  <PlantTable v-if="!error && !loading && rows.length" :rows="rows" :selectable="rows.some(row => row.canManage || row.canDelete)" :selected-ids="batchSelection" @select="selectPlant" :loading="loading" @open="openPlant($event)" @edit="openPlant($event, 'edit')" @maintenance="openPlant($event, 'maintenance')" @water="waterTarget = $event" @lifecycle="lifecycleTarget = $event" @changed="load()" @access-lost="emit('accessLost', $event)" />
  <CollectionPagination v-if="!error && !loading" v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" @page="collectionQuery.go($event)" />
  <PlantRegistry v-if="plantId" :key="plantId" detail-only :can-manage="false" :initial-plant-id="plantId" :initial-action="plantAction" @close="closePlant()" @changed="load()" @access-lost="emit('accessLost', $event)" />
  <PlantWateringDialog v-if="waterTarget" :plant-id="waterTarget.id" :name="waterTarget.name" @close="waterTarget = null; load()" @changed="load()" @access-lost="emit('accessLost', $event)" />
  <PlantLifecycleDialog v-if="lifecycleTarget?.nextAction" :plant-id="lifecycleTarget.id" :name="lifecycleTarget.name" :action="lifecycleTarget.nextAction" @close="lifecycleTarget = null; load()" @changed="load()" @access-lost="emit('accessLost', $event)" /></section></template>
<style scoped>
.location-plants { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-3); min-width: 0; }
.location-plants > header { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
.location-plants h4 { display: flex; align-items: center; gap: var(--space-2); margin: 0; }
.location-plants p { margin: 0; color: var(--theme-muted); font-size: .8rem; }
.location-plants-controls { display: flex; align-items: end; flex-wrap: wrap; gap: var(--space-3); }
.location-plants-controls .field { flex: 1 1 12rem; max-width: 18rem; margin: 0; }
.location-plants-controls :deep(.filter-reset-row) { margin-left: auto; }
@media(max-width: 480px) { .location-plants-controls .field { max-width: none; } }
</style>
