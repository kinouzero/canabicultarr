<script setup lang="ts">
import AppTabs from './AppTabs.vue';
import { useRoute, useRouter } from 'vue-router';
import { catalogTabs, isTab, tabAtPath } from '../tab-paths';
import { registerModuleMessages } from '../lib/locale-messages';
import plantsEn from '../locales/en/plants.json';
import plantsFr from '../locales/fr/plants.json';
import eventTypeEn from '../locales/en/event-types.json';
import eventTypeFr from '../locales/fr/event-types.json';
import CollectionViewToggle from './CollectionViewToggle.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NTabPane } from 'naive-ui';




import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import PageTitle from './PageTitle.vue';
import CommentTemplates from './CommentTemplates.vue';
import SensorConfiguration from './SensorConfiguration.vue';
import EventTypeSettings from './EventTypeSettings.vue';
import MaintenanceTypeSettings from './MaintenanceTypeSettings.vue';
import type { ApiError } from '../api/auth';
defineProps<{ selectionTarget?: HTMLElement | null; hideHeading?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, mergeLocaleMessage } = useI18n({ useScope: 'global' });
registerModuleMessages(mergeLocaleMessage, { en: { eventTypes: eventTypeEn, plants: plantsEn }, fr: { eventTypes: eventTypeFr, plants: plantsFr } });
const route = useRoute(); const router = useRouter();
const activeTab = computed(() => tabAtPath(catalogTabs, route.path) ?? 'templates');
function selectTab(value: string | number) {
 if (!isTab(catalogTabs, value) || value === activeTab.value) return;
 void router.push({ path: catalogTabs[value], query: route.query });
}
const templates = ref<InstanceType<typeof CommentTemplates> | null>(null);
const measurements = ref<InstanceType<typeof SensorConfiguration> | null>(null);
const maintenance = ref<InstanceType<typeof MaintenanceTypeSettings> | null>(null);
const eventTypes = ref<InstanceType<typeof EventTypeSettings> | null>(null);
const current = computed(() => activeTab.value === 'templates' ? templates.value : activeTab.value === 'measurements' ? measurements.value : activeTab.value === 'eventTypes' ? eventTypes.value : maintenance.value);
const viewPreference = computed(() => activeTab.value === 'measurements' ? 'measurement-types' : activeTab.value === 'maintenance' ? 'maintenance-types' : undefined);
const canCreate = computed(() => activeTab.value === 'eventTypes' ? eventTypes.value?.canManage === true : activeTab.value !== 'templates' || templates.value?.canManage === true);
const creationLabel = computed(() => t(activeTab.value === 'eventTypes' ? 'eventTypes.add' : activeTab.value === 'templates' ? 'commentTemplates.add' : activeTab.value === 'measurements' ? 'measurementTypes.add' : 'plants.maintenance.addType'));
const loading = computed(() => current.value?.loading ?? false);
const saving = computed(() => current.value?.saving ?? false);
function create() { current.value?.create(); }
async function reload() { await current.value?.reload(); }
defineExpose({ viewPreference, loading, saving, canCreate, creationLabel, create, reload });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><section class="catalog-settings module-workspace" data-testid="catalog-settings"><header v-if="!hideHeading" class="page-heading catalog-settings-heading"><PageTitle icon="library">{{ t('workspaces.tabs.catalogs') }}</PageTitle>
   <div ref="selectionHeader" class="page-actions"><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="current?.loading" :disabled="!current || current.saving" @click="current?.reload()" />
    <CollectionViewToggle v-if="viewPreference" :preference-key="viewPreference" :disabled="loading || saving" />
    <ListActionButton v-if="canCreate" icon="plus" :label="creationLabel" :disabled="!current || current.loading || current.saving" @click="current?.create()" /></div></header>
  <AppTabs type="bar" class="button-tabs" :value="activeTab" @update:value="selectTab" animated><NTabPane name="templates" display-directive="show:lazy"><template #tab><AppIcon name="message" />{{ t('workspaces.tabs.commentTemplates') }}</template><CommentTemplates ref="templates" hide-actions v-if="activeTab === 'templates'" @access-lost="emit('accessLost', $event)" /></NTabPane><NTabPane name="measurements" display-directive="show:lazy"><template #tab><AppIcon name="chart" />{{ t('measurementTypes.title') }}</template><SensorConfiguration ref="measurements" hide-actions v-if="activeTab === 'measurements'" embedded kind="types" :can-manage="true" @access-lost="emit('accessLost', $event)" /></NTabPane><NTabPane name="maintenance" display-directive="show:lazy"><template #tab><AppIcon name="scissors" />{{ t('plants.maintenance.catalog') }}</template><MaintenanceTypeSettings ref="maintenance" :selection-target="selectionTarget ?? selectionHeader" hide-actions v-if="activeTab === 'maintenance'" @access-lost="emit('accessLost', $event)" /></NTabPane><NTabPane name="eventTypes" display-directive="show:lazy"><template #tab><AppIcon name="journal" />{{ t('eventTypes.title') }}</template><EventTypeSettings ref="eventTypes" hide-actions v-if="activeTab === 'eventTypes'" @access-lost="emit('accessLost', $event)" /></NTabPane></AppTabs></section></template>
<style scoped>
.catalog-settings-heading { margin-bottom: var(--ui-content-gap); }
</style>
