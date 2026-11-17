<script setup lang="ts">
import { measurementNatureIcon } from '../lib/measurement-nature-icon';
import { ref } from 'vue';
import type { MeasurementNature } from '../api/sensors';
import { useI18n } from 'vue-i18n';
import DetailDialog from './DetailDialog.vue';
import SensorAnalysis from './SensorAnalysis.vue';
import type { ApiError } from '../api/auth';
defineProps<{ sensorId: string; name: string }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const nature = ref<MeasurementNature>();
</script>
<template><DetailDialog :title="t('insights.historyFor', { name })" :close-label="t('insights.close')" :icon="measurementNatureIcon(nature)" @close="emit('close')"><SensorAnalysis :sensor-id="sensorId" @loaded="nature = $event.measurementType.nature" @access-lost="emit('accessLost', $event)" /></DetailDialog></template>
