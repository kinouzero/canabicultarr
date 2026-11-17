<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import '../styles/expected-actions.css';
import { useI18n } from 'vue-i18n';
import type { WateringNeed } from '../api/plants';
defineProps<{ need?: WateringNeed }>();
const { t, locale } = useI18n();
const number = (value: number) => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value);
</script>
<template>
 <span v-if="need?.needed" class="watering-needed expected-action expected-action-due" data-testid="watering-needed">
  <AppIcon class="expected-action-icon" name="water" />
  <span class="expected-action-content"><strong>{{ t('plants.watering.needed') }}</strong>
   <small v-if="need.reason === 'moisture' && need.measurement">{{ t('plants.watering.lowMoisture', { percent: number(need.measurement.percent), threshold: number(need.moistureThreshold ?? 35) }) }}</small>
   <small v-else>{{ t('plants.watering.elapsed', { days: need.alertDays }) }}</small>
  </span>
 </span>
</template>
