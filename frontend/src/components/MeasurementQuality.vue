<script setup lang="ts">
import { registerModuleMessages } from '../lib/locale-messages';
import { NTag as Tag } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import type { MeasurementQuality } from '../api/measurements';
import en from '../locales/en/measurement-quality.json';
import fr from '../locales/fr/measurement-quality.json';
defineProps<{ quality?: MeasurementQuality | null }>();
const { t, mergeLocaleMessage } = useI18n({ useScope: 'global' });
registerModuleMessages(mergeLocaleMessage, { en: { measurementQuality: en }, fr: { measurementQuality: fr } });
</script>
<template><Tag class="measurement-quality" :type="quality === 'invalid' ? 'error' : quality === 'suspect' ? 'warning' : quality === 'good' ? 'success' : 'default'" :title="t('measurementQuality.help')"><AppIcon :name="quality === 'good' ? 'check' : quality === 'invalid' || quality === 'suspect' ? 'warning' : 'info'" /><span class="quality-label">{{ t(`measurementQuality.${quality ?? 'unknown'}`) }}</span></Tag></template>

<style scoped>
.measurement-quality :deep(.n-tag__content) { white-space: nowrap; overflow-wrap: normal; overflow: hidden; }
.quality-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
