<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ReferenceSelect from './ReferenceSelect.vue';
import LocationTreeSelect from './LocationTreeSelect.vue';
import type { LocationContext } from '../api/types';
import type { ReferenceOption } from '../api/sensors';
import { seedStockEmpty, type PlantStrainChoice } from '../lib/strain-stock';
import { strainsApi } from '../api/strains';
const props = defineProps<{ creating?: boolean; disabled?: boolean; strainError?: string; locationError?: string }>();
const emit = defineEmits<{ accessLost: [cause: import('../api/auth').ApiError] }>();
const strain = defineModel<PlantStrainChoice | null>('strain', { required: true });
const location = defineModel<LocationContext | null>('location', { required: true });
const { t } = useI18n({ useScope: 'global' });
async function strains(search: string, page: number) {
 const result = await strainsApi.list(search, page);
 return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, seedStock: row.seedStock, detail: props.creating && seedStockEmpty(row) ? t('plants.errors.seedStockEmpty') : '', disabled: !!props.creating && seedStockEmpty(row) })) };
}
function chooseStrain(option: ReferenceOption | null) {
 const choice = option as (ReferenceOption & PlantStrainChoice) | null;
 strain.value = choice ? { id: choice.id, name: choice.name, seedStock: choice.seedStock } : null;
}
</script>
<template>
 <div class="field">
  <label for="plant-strain-select">{{ t('plants.strain') }}</label>
  <ReferenceSelect id="plant-strain-select" hide-label :label="t('plants.strain')" :placeholder="t('plants.chooseStrain')" :model-value="strain?.id ?? ''" :selected="strain ? { ...strain, detail: '' } : null" :fetch-page="strains" :disabled="disabled" :invalid="!!strainError" :described-by="strainError ? 'plant-strain-select-error' : undefined" @option="chooseStrain" @access-lost="emit('accessLost', $event)" /><small v-if="strainError" id="plant-strain-select-error" class="field-error" role="alert">{{ t(strainError) }}</small>
 </div>
 <div id="plant-location-field" tabindex="-1" class="field plant-location-field" :aria-describedby="locationError ? 'plant-location-field-error' : undefined">
  <label for="plant-location-select">{{ t('plants.location') }}</label>
  <LocationTreeSelect id="plant-location-select" v-model="location" :label="t('plants.location')" :placeholder="t('plants.chooseLocation')" :disabled="disabled" :invalid="!!locationError" :described-by="locationError ? 'plant-location-field-error' : undefined" @access-lost="emit('accessLost', $event)" /><small v-if="locationError" id="plant-location-field-error" class="field-error" role="alert">{{ t(locationError) }}</small>
 </div>
</template>
