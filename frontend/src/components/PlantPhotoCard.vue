<script setup lang="ts">
import StrainReferenceTag from './StrainReferenceTag.vue';
import { plantSummaryReadings } from '../lib/plant-readings';
import { registerModuleMessages } from '../lib/locale-messages';
import { photoThumbnailUrl } from '../lib/photo-urls';
import RecordCardMedia from './RecordCardMedia.vue';
import { phaseLabel } from '../lib/lifecycle';
import { usePlantAge } from '../lib/plant-age';
import LocationTrail from './LocationTrail.vue';
import { useI18n } from 'vue-i18n';
import { computed, useId } from 'vue';
import type { PlantDashboardPage } from '../api/plants';
import { formatDateTime } from '../lib/date-time';
import AppIcon from './AppIcon.vue';
import PlantRecordActions from './PlantRecordActions.vue';
import PlantPhaseIcon from './PlantPhaseIcon.vue';
import StrainGeneticsTag from './StrainGeneticsTag.vue';
import type { ApiError } from '../api/auth';
import PlantReadings from './PlantReadings.vue';
import PlantExpectedActions from './PlantExpectedActions.vue';
import en from '../locales/en/plant-home.json';
import fr from '../locales/fr/plant-home.json';
import RecordCardSelection from './RecordCardSelection.vue';
import '../styles/photo-cards.css';
const props = defineProps<{ plant: PlantDashboardPage['data'][number]; loading?: boolean; registry?: boolean; selectable?: boolean; selected?: boolean }>();
const emit = defineEmits<{ select: [value: boolean]; open: []; water: []; maintenance: []; edit: []; lifecycle: []; changed: []; accessLost: [cause: ApiError] }>();
const { t, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
registerModuleMessages(mergeLocaleMessage, { en: { plantHome: en }, fr: { plantHome: fr } });
const { label: ageLabel, compact: ageCompact } = usePlantAge(() => props.plant);
const readingsId = useId();
const summaryReadings = computed(() => plantSummaryReadings(props.plant));
function date(value: string) { return formatDateTime(value, locale.value); }
</script>
<template>
 <article class="photo-plant record-card" :data-selected="selected" :class="{ 'batch-selectable': selectable && (plant.canManage || plant.canDelete) }">
  <RecordCardSelection v-if="selectable && (plant.canManage || plant.canDelete)" class="plant-batch-select" :selection="{ label: t('plants.batch.select', { name: plant.name }), checked: selected, disabled: loading, toggle: checked => emit('select', checked) }" />
  <div class="plant-card-opening">
  <button type="button" class="open-plant" :aria-describedby="summaryReadings.length ? readingsId : undefined" :aria-label="registry ? t('plants.open', { name: plant.name }) : t('plantHome.openPlant', { name: plant.name, reference: plant.id.slice(0, 8) })" @click="emit('open')"></button>
   <RecordCardMedia class="plant-image" :src="plant.photoUrl ? photoThumbnailUrl(plant.photoUrl) : null" :empty-label="t('plantHome.noPhoto')" :error-label="t('plantHome.missingPhoto')">
    <span class="photo-top-tags">
     <span class="photo-phase"><PlantPhaseIcon :phase="plant.phase" /><span>{{ phaseLabel(plant.phase, t) }}</span></span>
     <span v-if="ageLabel" class="photo-age" :title="ageLabel"><AppIcon name="clock" /><span>{{ ageCompact }}</span></span>
    </span>
    <span class="photo-bottom-tags">
     <StrainGeneticsTag class="photo-genetics" :classification="plant.strain.classification" />
     <StrainReferenceTag class="photo-reference photo-strain card-reference" :strain="plant.strain" />
    </span>
   </RecordCardMedia>
   <span class="plant-card-body">
    <span class="plant-card-top"><span class="plant-card-identity"><span class="plant-name record-card-title">{{ plant.name }}</span><span v-if="plant.givenName !== null" class="plant-card-reference card-reference" :title="plant.id">#{{ plant.id.slice(0, 8) }}</span></span><AppIcon name="arrow" /></span>
    <LocationTrail v-if="registry" class="card-location" :location="plant.location" />
   </span>
  </div>
  <div class="plant-card-followup">
    <PlantExpectedActions :plant="plant" />
    <span v-if="plant.watering.status !== 'unavailable'" class="card-watering">
     <span v-if="plant.watering.last"><AppIcon name="clock" />{{ t('plants.watering.last') }} · {{ date(plant.watering.last.occurredAt) }} · {{ t(plant.watering.last.withProducts ? 'plants.watering.with' : 'plants.watering.without') }}</span>
     <strong><AppIcon name="water" />{{ t(plant.watering.nextWithProducts === null ? 'plants.watering.noChoice' : plant.watering.nextWithProducts ? 'plants.watering.nextWith' : 'plants.watering.nextWithout') }}<span v-if="plant.watering.status === 'flush'"> · {{ t('plants.watering.flush') }}</span></strong>
    </span>
  </div>
  <PlantRecordActions :show-photos="false" :plant="plant" :loading="loading" @changed="emit('changed')" @access-lost="emit('accessLost', $event)" @edit="emit('edit')" @water="emit('water')" @maintenance="emit('maintenance')" @lifecycle="emit('lifecycle')" />
  <PlantReadings show-empty :context-key="`card:${plant.id}`" @access-lost="emit('accessLost', $event)" v-if="summaryReadings.length" class="card-readings" :id="readingsId" :readings="summaryReadings" :limit="plant.summarySensorIds == null ? 4 : 0" compact />
 </article>
</template>

<style scoped>
.plant-card-opening { position: relative; }
.plant-card-opening > .open-plant { position: absolute; inset: 0; z-index: 1; }
.photo-bottom-tags { z-index: 2; pointer-events: none; }
.photo-strain { pointer-events: auto; }
.plant-card-identity { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-1) var(--space-2); min-width: 0; }
.plant-card-reference { max-width: 100%; font-family: ui-monospace, monospace; font-size: .65rem; font-weight: 400; color: var(--theme-muted); }
.photo-bottom-tags { position: absolute; inset-inline: .75rem; bottom: .75rem; display: flex; align-items: center; justify-content: space-between; gap: var(--space-1-5); }
.photo-bottom-tags .photo-genetics { position: static; flex: 0 1 auto; max-width: 40%; }
.plant-image .photo-bottom-tags .photo-strain { position: static; display: inline-flex; gap: var(--space-1-5); min-width: 0; max-width: 60%; }
.photo-strain > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.photo-strain svg { width: .75rem; height: .75rem; flex-shrink: 0; }
.photo-plant { container: plant-card / inline-size; }
.batch-selectable { position: relative; }
.plant-batch-select { position: absolute; top: .75rem; inset-inline-start: .75rem; z-index: 2; }
.batch-selectable .photo-top-tags { inset-inline-start: 2.7rem; align-items: center; min-height: 1.6rem; }
.batch-selected .plant-batch-select { border-color: var(--app-accent); background: var(--app-accent); color: var(--theme-accent-text, white); }
.card-location :deep(.location-trail-link) { position: relative; z-index: 2; }
</style>
