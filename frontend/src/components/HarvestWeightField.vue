<script setup lang="ts">
import { useI18n } from 'vue-i18n';
defineProps<{ disabled: boolean; validationError?: string }>();
const value = defineModel<string | number>({ required: true });
const { t } = useI18n();
</script>
<template>
 <div class="field"><label for="plant-harvest-weight">{{ t('plants.harvestWeight') }}</label><input id="plant-harvest-weight" v-model="value" type="number" inputmode="decimal" min="0.01" max="9999999999.99" step="0.01" required :disabled="disabled" :aria-invalid="!!validationError" :aria-describedby="validationError ? 'plant-harvest-weight-error' : undefined" /><small v-if="validationError" id="plant-harvest-weight-error" class="field-error" role="alert">{{ t(validationError) }}</small></div>
</template>
<style scoped>
input { min-width: 0; min-height: var(--app-control-height); }
</style>
