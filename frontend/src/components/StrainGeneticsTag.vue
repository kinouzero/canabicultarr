<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { instanceDisplaySettings } from '../lib/date-time';
import { geneticsColorStyle, type GeneticsColors } from '../lib/genetics-colors';
const props = defineProps<{ classification?: 'indica' | 'sativa' | 'hybrid' | null; colors?: GeneticsColors }>();
const { t } = useI18n();
const label = computed(() => props.classification ? t(`strainGenetics.${props.classification}`) : null);
const style = computed(() => props.classification ? geneticsColorStyle((props.colors ?? instanceDisplaySettings.value.geneticsColors)[props.classification]) : undefined);
</script>
<template>
 <span v-if="label" class="genetics-tag" :class="classification" :style="style" :title="t('strainGenetics.label', { value: label })" :aria-label="t('strainGenetics.label', { value: label })">{{ label }}</span>
</template>
<style scoped>
.genetics-tag { display: inline-flex; align-items: center; width: fit-content; max-width: 100%; box-sizing: border-box; border: 1px solid var(--theme-border, #cbdcc0); border-radius: 2rem; padding: var(--space-1) var(--space-2); background: var(--theme-subtle, #eff6e8); color: var(--theme-muted, #426332); font-size: .65rem; font-weight: 650; line-height: 1.3; overflow-wrap: anywhere; }
</style>
