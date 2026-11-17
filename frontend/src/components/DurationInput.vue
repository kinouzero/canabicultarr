<script setup lang="ts">
import PlantPhaseIcon from './PlantPhaseIcon.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
 id: string; label: string; minimum: number; inheritedDays?: number;
 disabled?: boolean; invalid?: boolean; validationError?: string; phase?: string;
}>();
// The parent and API always receive days. The unit is local presentation state.
const days = defineModel<number | ''>({ required: true });
const { t } = useI18n({ useScope: 'global' });
const initialDays = days.value === '' ? props.inheritedDays : days.value;
const unit = ref<'days' | 'weeks'>(initialDays && initialDays % 7 === 0 ? 'weeks' : 'days');
const factor = computed(() => unit.value === 'weeks' ? 7 : 1);
function display(value: number) { return String(Number((value / factor.value).toFixed(10))); }
const input = computed({
 get: () => days.value === '' ? '' : display(days.value),
 set: (value: string) => {
  if (value === '') { days.value = ''; return; }
  const converted = Number(value) * factor.value;
  if (!Number.isFinite(converted)) { days.value = ''; return; }
  // Discard floating-point noise, never a genuine fraction of a day.
  const integer = Math.round(converted);
  days.value = Math.abs(converted - integer) < 1e-8 ? integer : converted;
 },
});
const invalidDays = computed(() => days.value !== '' && (!Number.isInteger(days.value) || days.value < props.minimum || days.value > 3650));
</script>

<template>
 <div class="duration-input">
  <label :for="id"><PlantPhaseIcon v-if="phase" :phase="phase" /> {{ label }}</label>
  <div class="duration-controls">
   <input :id="id" v-model="input" type="number" :step="unit === 'days' ? 1 : 'any'" :min="minimum / factor" :max="3650 / factor" :placeholder="inheritedDays === undefined ? undefined : display(inheritedDays)" :disabled="disabled" :aria-invalid="invalidDays || !!invalid || !!validationError" :aria-describedby="invalidDays || validationError ? `${id}-error` : undefined" />
   <select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :id="`${id}-unit`" v-model="unit" :aria-label="t('catalog.unitInput', { stage: label })" :disabled="disabled">
    <option value="days">{{ t('catalog.days') }}</option><option value="weeks">{{ t('catalog.weeks') }}</option>
   </select>
  </div>
  <small v-if="invalidDays || validationError" role="alert" :id="`${id}-error`" class="duration-error field-error">{{ t(validationError || 'catalog.validation.duration') }}</small>
 </div>
</template>

<style scoped>
.duration-controls { display: grid; grid-template-columns: minmax(0, 1fr) 7rem; gap: var(--space-2); margin-top: var(--space-1); }
input, select { min-width: 0; width: 100%; box-sizing: border-box; font: inherit; border: 1px solid var(--theme-border, #aebfb5); border-radius: .5rem; padding: var(--space-3); background: var(--theme-surface, white); color: inherit; }
input:focus-visible, select:focus-visible { outline: 2px solid var(--theme-focus, #08764b); outline-offset: 3px; }
input[aria-invalid="true"] { border-color: var(--theme-danger, #b42318); }
.duration-error { display: block; color: var(--theme-danger, #b42318); margin-top: var(--space-1-5); }
</style>
