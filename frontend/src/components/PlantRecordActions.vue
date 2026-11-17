<script setup lang="ts">
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/plant-home.json';
import fr from '../locales/fr/plant-home.json';
import { useI18n } from 'vue-i18n';
import type { PlantDashboardPage } from '../api/plants';
import type { ApiError } from '../api/auth';
import { actionLabel } from '../lib/lifecycle';
import { canRecordMaintenance } from '../lib/plant-maintenance';
import { plantActionIcon } from '../lib/plant-action-icon';
import RecordCardActions from './RecordCardActions.vue';
import PlantCardFileActions from './PlantCardFileActions.vue';
import ListActionButton from './ListActionButton.vue';
withDefaults(defineProps<{ plant: PlantDashboardPage['data'][number]; loading?: boolean; showPhotos?: boolean }>(), { showPhotos: true });
const emit = defineEmits<{ changed: []; accessLost: [cause: ApiError]; edit: []; water: []; maintenance: []; lifecycle: [] }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { plantHome: en }, fr: { plantHome: fr } });
</script>
<template>
  <RecordCardActions v-if="plant.canManage || plant.canDelete" class="card-actions plant-card-actions">

   <div v-if="plant.canManage" class="plant-card-care">
   <ListActionButton v-if="plant.watering.status !== 'unavailable'" icon="water" :label="t('plantHome.waterPlant', { name: plant.name, reference: plant.id.slice(0, 8) })" :disabled="loading" @click.stop="emit('water')" />
   <ListActionButton v-if="canRecordMaintenance(plant.phase)" class="plant-maintenance" icon="scissors" :label="t('plants.maintenance.forPlant', { name: plant.name })" :disabled="loading" @click.stop="emit('maintenance')" />
   <ListActionButton v-if="plant.nextAction" class="plant-lifecycle" :icon="plantActionIcon(plant.nextAction)" :label="t('plants.actionFor', { action: actionLabel(plant.nextAction, t), name: plant.name })" :disabled="loading" @click.stop="emit('lifecycle')" />
   </div>
   <div class="plant-card-utilities">
   <PlantCardFileActions :show-photos="showPhotos" :plant-id="plant.id" :name="plant.name" :can-manage="plant.canManage" :can-delete="!!plant.canDelete" :disabled="loading" @changed="emit('changed')" @access-lost="emit('accessLost', $event)">
   <ListActionButton v-if="plant.canManage" class="plant-edit" icon="edit" :label="t('plants.edit', { name: plant.name })" :disabled="loading" @click.stop="emit('edit')" />
   </PlantCardFileActions>
   </div>
  </RecordCardActions>
</template>
