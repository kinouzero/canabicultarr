<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import HarvestWeightField from './HarvestWeightField.vue';

const date = defineModel<string>('date', { required: true });
const weight = defineModel<string | number>('weight', { required: true });
defineProps<{ requiresWeight?: boolean; disabled?: boolean; dateError?: string; weightError?: string }>();
const { t } = useI18n();
</script>

<template>
 <div class="action-fields">
  <div class="action-details" :class="{ 'with-weight': requiresWeight }">
   <div class="field">
    <label for="plant-action-date">{{ t('plants.occurredAt') }}</label>
    <input id="plant-action-date" v-model="date" type="datetime-local" :disabled="disabled"
     :aria-invalid="!!dateError" :aria-describedby="dateError ? 'plant-action-date-error' : 'plant-action-date-help'" />
    <small v-if="dateError" id="plant-action-date-error" class="field-error" role="alert">{{ t(dateError) }}</small>
    <small v-else id="plant-action-date-help">{{ t('plants.nowHelp') }}</small>
   </div>
   <HarvestWeightField v-if="requiresWeight" v-model="weight" :validation-error="weightError" :disabled="disabled" />
  </div>
 </div>
</template>

<style scoped>
.action-fields { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.action-details { display: grid; align-items: start; gap: var(--ui-grid-gap); min-width: 0; }
.action-details.with-weight { grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); }
.action-details input { min-width: 0; min-height: var(--app-control-height); }
@media (max-width: 520px) {
 .action-details.with-weight { grid-template-columns: minmax(0, 1fr); }
}
</style>
