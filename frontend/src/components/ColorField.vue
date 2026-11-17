<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const value = defineModel<string>({ required: true });
defineProps<{ id: string; label: string; disabled?: boolean }>();
const { t } = useI18n();
const valid = computed(() => /^#[0-9a-f]{6}$/i.test(value.value));
</script>

<template>
 <div class="color-field">
  <div class="color-heading"><label :for="id">{{ label }}</label><slot /></div>
  <div class="color-control" :class="{ 'is-invalid': !valid, 'is-disabled': disabled }">
   <span class="color-swatch" :style="{ backgroundColor: valid ? value : undefined }">
    <input class="color-input color-picker" type="color" :value="valid ? value : '#000000'" :disabled="disabled" :aria-label="t('instanceSettings.themes.pickColor', { name: label })" @input="value = ($event.target as HTMLInputElement).value" />
   </span>
   <input :id="id" v-model="value" class="color-input color-hex" type="text" :disabled="disabled" maxlength="7" pattern="#[0-9a-fA-F]{6}" required :aria-invalid="!valid" autocomplete="off" autocapitalize="off" spellcheck="false" />
  </div>
 </div>
</template>

<style scoped>
.color-field { display: flex; flex-direction: column; justify-content: end; min-width: 0; gap: var(--space-1-5); }
.color-heading { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); }
.color-heading label { margin: 0; color: var(--theme-muted); font-size: .76rem; font-weight: 550; line-height: 1.4; cursor: pointer; }
.color-control { display: flex; align-items: center; gap: var(--space-3); height: var(--app-control-height, 2.5rem); box-sizing: border-box; min-width: 0; padding: var(--space-1) var(--space-3) var(--space-1) var(--space-1); border: 1px solid var(--theme-border); border-radius: .65rem; background: var(--theme-surface); transition: border-color .15s, box-shadow .15s; }
.color-control:hover { border-color: var(--theme-focus); }
.color-control:focus-within { border-color: var(--theme-focus); box-shadow: 0 0 0 3px color-mix(in srgb, var(--theme-focus) 16%, transparent); }
.color-control.is-invalid { border-color: var(--theme-danger); }
.color-control:has(:disabled), .color-control.is-disabled { opacity: .6; }
.color-swatch { position: relative; flex: 0 0 1.75rem; height: 1.75rem; border-radius: .4rem; box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--theme-text) 12%, transparent); }
.color-picker { position: absolute; inset: 0; width: 100%; height: 100%; padding: 0; border: 0; opacity: 0; cursor: pointer; }
.color-hex { width: 100%; min-width: 0; padding: 0; border: 0; outline: none; background: transparent; color: var(--theme-text); font: .82rem ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: .025em; }
.color-input:focus-visible { outline: none; }
.color-input:disabled { cursor: not-allowed; }
</style>
