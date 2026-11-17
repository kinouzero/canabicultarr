<script setup lang="ts" generic="T extends PlantDashboardPage['data'][number]">
import StrainReferenceTag from './StrainReferenceTag.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { PlantDashboardPage } from '../api/plants';
import type { ApiError } from '../api/auth';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/plants.json';
import fr from '../locales/fr/plants.json';
import { actionLabel, phaseLabel } from '../lib/lifecycle';
import { plantActionIcon } from '../lib/plant-action-icon';
import { plantSummaryReadings } from '../lib/plant-readings';
import LocationTrail from './LocationTrail.vue';
import AppIcon from './AppIcon.vue';
import CollectionTable from './CollectionTable.vue';
import CollectionIdentity from './CollectionIdentity.vue';
import PlantPhaseIcon from './PlantPhaseIcon.vue';
import PlantAgeTag from './PlantAgeTag.vue';
import StrainGeneticsTag from './StrainGeneticsTag.vue';
import WateringNeeded from './WateringNeeded.vue';
import PlantReadings from './PlantReadings.vue';
import PlantRecordActions from './PlantRecordActions.vue';
const props = withDefaults(defineProps<{ rows: T[]; loading?: boolean; selectable?: boolean; selectedIds?: string[] }>(), { loading: false, selectable: false, selectedIds: () => [] });
const emit = defineEmits<{ open: [plant: T]; water: [plant: T]; maintenance: [plant: T]; edit: [plant: T]; lifecycle: [plant: T]; changed: []; accessLost: [cause: ApiError]; select: [id: string, checked: boolean] }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { plants: en }, fr: { plants: fr } });
const columns = computed(() => [
 ...(props.selectable ? [{ key: 'selection', label: t('collection.selection') }] : []),
 { key: 'name', label: t('collection.name') }, { key: 'location', label: t('assets.location') },
 { key: 'action', label: t('collection.nextAction') }, { key: 'watering', label: t('collection.watering') },
 { key: 'readings', label: t('plants.measurements.title') }, { key: 'actions', label: t('collection.actions') },
]);
</script>
<template>
<CollectionTable class="plant-table" :rows="rows" :columns="columns">
<template #heading-selection><span class="sr-only">{{ t('collection.selection') }}</span></template>
<template #selection="{ row }"><input v-if="row.canManage || row.canDelete" type="checkbox" class="collection-table-select" :checked="selectedIds.includes(row.id)" :disabled="loading" :aria-label="t('plants.batch.select', { name: row.name })" @change="emit('select', row.id, ($event.target as HTMLInputElement).checked)" /></template>
<template #name="{ row }"><div class="collection-table-name"><CollectionIdentity :name="row.name" :reference="row.givenName !== null ? `#${row.id.slice(0, 8)}` : undefined" :photo-url="row.photoUrl" image :label="t('plants.open', { name: row.name })" @open="emit('open', row)" /><div class="collection-table-tags"><span class="collection-record-tag" :title="phaseLabel(row.phase, t)"><PlantPhaseIcon :phase="row.phase" /><span>{{ phaseLabel(row.phase, t) }}</span></span><PlantAgeTag :plant="row" /><StrainReferenceTag class="collection-record-tag" :strain="row.strain" /><StrainGeneticsTag :classification="row.strain.classification" /></div></div></template>
<template #location="{ row }"><LocationTrail class="collection-table-location" :location="row.location" /></template>
<template #action="{ row }"><span v-if="row.phase !== 'maturation' && row.nextAction" class="collection-table-tag"><AppIcon :name="plantActionIcon(row.nextAction)" /><span>{{ actionLabel(row.nextAction, t) }}</span></span><span v-else>—</span></template>
<template #watering="{ row }"><div v-if="row.watering.status !== 'unavailable'" class="table-watering"><WateringNeeded :need="row.watering.need" /><span class="collection-table-tag"><AppIcon name="water" />{{ t(row.watering.nextWithProducts === null ? 'plants.watering.noChoice' : row.watering.nextWithProducts ? 'plants.watering.nextWith' : 'plants.watering.nextWithout') }}</span></div><span v-else>—</span></template>
<template #readings="{ row }"><PlantReadings show-empty v-if="plantSummaryReadings(row).length" inline compact :context-key="`table:${row.id}`" :readings="plantSummaryReadings(row)" :limit="row.summarySensorIds == null ? 2 : 0" @access-lost="emit('accessLost', $event)" /><span v-else>—</span></template>
<template #actions="{ row }"><PlantRecordActions :plant="row" :loading="loading" @changed="emit('changed')" @access-lost="emit('accessLost', $event)" @water="emit('water', row)" @maintenance="emit('maintenance', row)" @edit="emit('edit', row)" @lifecycle="emit('lifecycle', row)" /></template>
</CollectionTable>
</template>
<style scoped>
.table-watering { display: grid; gap: var(--space-2); }
</style>
