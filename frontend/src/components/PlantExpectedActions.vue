<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Plant } from '../api/plants';
import { actionDefinition, actionLabel } from '../lib/lifecycle';
import { plantActionIcon } from '../lib/plant-action-icon';
import { formatDateTime } from '../lib/date-time';
import AppIcon from './AppIcon.vue';
import WateringNeeded from './WateringNeeded.vue';
import '../styles/expected-actions.css';
const props = defineProps<{ plant: Pick<Plant, 'phase' | 'nextAction' | 'phaseDueAt' | 'isOverdue' | 'watering'> }>();
const { t, locale } = useI18n();
const lifecycle = computed(() => props.plant.phase !== 'maturation' && props.plant.nextAction && (props.plant.phaseDueAt || actionDefinition(props.plant.nextAction)?.target !== 'archived') ? props.plant.nextAction : null);
const watering = computed(() => props.plant.watering.status !== 'unavailable' ? props.plant.watering.need : undefined);
</script>
<template>
 <section v-if="lifecycle || watering?.needed" class="plant-expected-actions" :aria-label="t('collection.upcoming')">
  <h4 class="expected-actions-heading"><AppIcon name="clock" />{{ t('collection.upcoming') }}</h4>
  <div v-if="lifecycle" class="expected-action" :class="{ 'expected-action-due': plant.isOverdue }">
   <AppIcon class="expected-action-icon" :name="plantActionIcon(lifecycle)" />
   <span class="expected-action-content"><strong>{{ actionLabel(lifecycle, t) }}</strong><small v-if="plant.phaseDueAt">{{ formatDateTime(plant.phaseDueAt, locale) }}</small></span>
   <span v-if="plant.isOverdue" class="expected-action-status">{{ t('collection.overdue') }}</span>
  </div>
  <WateringNeeded :need="watering" />
 </section>
</template>
