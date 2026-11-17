<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import ColorField from './ColorField.vue';
import { paletteGroups, themePresets, presetTheme, type PaletteKey, type ThemePreset, type ThemeSettings } from '../lib/theme';
const theme = defineModel<ThemeSettings>({ required: true });
defineProps<{ disabled: boolean }>();
const { t } = useI18n();
const mode = ref<'light' | 'dark'>('light');
const palette = computed(() => theme.value[mode.value]);
function color(key: PaletteKey, value: string) { theme.value = { ...theme.value, preset: 'custom', [mode.value]: { ...palette.value, [key]: value } }; }
function choose(preset: ThemePreset) { theme.value = presetTheme(preset); }
</script>
<template><div class="theme-editor"><p>{{ t('instanceSettings.themes.help') }}</p>
  <div class="theme-controls"><div class="field"><label for="theme-preset">{{ t('instanceSettings.themes.presets') }}</label><select id="theme-preset" :value="theme.preset" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled" @change="($event.target as HTMLSelectElement).value === 'custom' ? theme = { ...theme, preset: 'custom' } : choose(($event.target as HTMLSelectElement).value as ThemePreset)"><option v-for="(_, id) in themePresets" :key="id" :value="id">{{ t(`instanceSettings.themes.names.${id}`) }}</option><option value="custom">{{ t('instanceSettings.themes.names.custom') }}</option></select></div>
   <div class="field"><label for="theme-palette-mode">{{ t('instanceSettings.themes.paletteMode') }}</label><select id="theme-palette-mode" v-model="mode" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-for="value in ['light', 'dark'] as const" :key="value" :value="value">{{ t(`appearance.${value}`) }}</option></select></div><Button attr-type="button" :disabled="disabled" type="default" :aria-label="t('instanceSettings.themes.reset')" @click="choose('botanical')"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.reset') }}</span></Button></div>
  <div class="theme-preview" :style="{ background: palette.canvas, color: palette.text, borderColor: palette.border }" :aria-label="t('instanceSettings.preview')"><div :style="{ background: palette.surface, borderColor: palette.border }"><strong>{{ t('instanceSettings.themes.previewTitle') }}</strong><p :style="{ color: palette.muted }">{{ t('instanceSettings.themes.previewText') }}</p><span class="preview-button" :style="{ background: palette.accent, color: palette.accentText }">{{ t('instanceSettings.themes.previewAction') }}</span><span v-for="state in ['success', 'warning', 'danger', 'info'] as const" :key="state" class="preview-state" :style="{ color: palette[state], background: `color-mix(in srgb, ${palette[state]} 14%, ${palette.surface})` }">{{ t(`instanceSettings.themes.tokens.${state}`) }}</span></div>
   <div class="preview-actions" :style="{ background: palette.surface, borderColor: palette.border }"><span v-for="key in paletteGroups.actions" :key="key" class="preview-state" :style="{ color: palette[key], background: `color-mix(in srgb, ${palette[key]} 8%, ${palette.surface})`, border: `1px solid ${palette[key]}` }">{{ t(`instanceSettings.themes.tokens.${key}`) }}</span></div></div>
  <fieldset v-for="(keys, group) in paletteGroups" :key="group" class="palette-fields" :disabled="disabled"><legend>{{ t(`instanceSettings.themes.groups.${group}`) }} · {{ t(`appearance.${mode}`) }}</legend>
   <div class="palette-grid"><ColorField v-for="key in keys" :key="key" :id="`palette-${key}`" :label="t(`instanceSettings.themes.tokens.${key}`)" :model-value="palette[key]" :disabled="disabled" @update:model-value="color(key, $event)" /></div></fieldset></div></template>
<style scoped>
.theme-controls { display: flex; flex-wrap: wrap; align-items: end; gap: var(--space-3); margin: var(--ui-content-gap) 0; }.theme-controls .field { flex: 1 1 12rem; min-width: 0; }.theme-controls label { display: block; margin-bottom: var(--space-1-5); }.theme-controls select { width: 100%; }.theme-preview { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: var(--space-3); padding: var(--space-4); border: 1px solid; border-radius: .8rem; margin-bottom: var(--ui-content-gap); }.preview-actions { align-content: start; }.theme-preview > div { padding: var(--space-4); border: 1px solid; border-radius: .65rem; }.preview-button, .preview-state { display: inline-flex; margin: var(--space-1) var(--space-1-5) var(--space-1) 0; padding: var(--space-2) var(--space-3); border-radius: .5rem; font-size: .75rem; }.palette-fields { border: 1px solid var(--theme-border); border-radius: .7rem; margin: 0 0 var(--ui-content-gap); padding: var(--space-4); min-width: 0; }.palette-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: var(--space-3) var(--ui-grid-gap); }legend { font-size: .8rem; padding: 0 var(--space-1-5); }
@media (max-width: 700px) { .theme-preview { grid-template-columns: minmax(0, 1fr); } }
</style>
