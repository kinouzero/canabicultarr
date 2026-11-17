<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import DeviceAssignment from './DeviceAssignment.vue';
import MeasurementQuality from './MeasurementQuality.vue';
import AppIcon from './AppIcon.vue';
import type { Measurement } from '../api/measurements';
import en from '../locales/en/measurement-provenance.json';
import fr from '../locales/fr/measurement-provenance.json';
defineProps<{ reading: Measurement }>();
const { t, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { measurementProvenance: en }); mergeLocaleMessage('fr', { measurementProvenance: fr });
</script>
<template>
 <div class="measurement-origin"><MeasurementQuality :quality="reading.quality" />
  <DeviceAssignment v-if="reading.provenance?.assignments" :device="reading.provenance.assignments" />
  <DeviceAssignment v-else-if="reading.location" :device="{ locations: [reading.location], plants: [] }" />
  <span v-if="!reading.provenance?.assignments" class="origin-status"><AppIcon name="info" />{{ t(`measurementProvenance.${reading.provenance?.status ?? 'legacy'}`) }}</span>
  <small v-if="reading.provenance?.source">{{ t(`measurementProvenance.sources.${reading.provenance.source.kind}`) }}</small>
 </div>
</template>
<style scoped>
.measurement-origin { display: grid; gap: var(--space-1-5); min-width: 10rem; }.origin-status { display: inline-flex; align-items: center; gap: var(--space-1-5); color: var(--theme-muted); }.origin-status svg { width: 1rem; height: 1rem; flex-shrink: 0; }.measurement-origin small { color: var(--theme-muted); font-size: .7rem; }
</style>
