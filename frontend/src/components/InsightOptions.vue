<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NSwitch as ToggleSwitch } from 'naive-ui';
import FormDialog from './FormDialog.vue';
import AppIcon from './AppIcon.vue';
const props = defineProps<{ modelValue: string[]; options: { id: string; label: string }[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();
const { t } = useI18n(); const open = ref(false); const id = useId();
const icons: Record<string, string> = {
 summary: 'chart', health: 'sensor', channels: 'device', trend: 'chart',
 reliability: 'shield', readings: 'journal', stages: 'leaf', strains: 'strain',
 locations: 'location', activity: 'clock', upcoming: 'hourglass',
};
const visibleCount = computed(() => props.options.filter(option => props.modelValue.includes(option.id)).length);
function toggle(value: string, checked: boolean) {
 const selected = new Set(props.modelValue);
 if (checked) selected.add(value); else selected.delete(value);
 emit('update:modelValue', [...selected]);
}
function toggleLabel(event: MouseEvent, option: string) {
 if ((event.target as HTMLElement).closest('[role="switch"]')) return;
 event.preventDefault();
 toggle(option, !props.modelValue.includes(option));
 document.getElementById(`${id}-${option}`)?.focus();
}
</script>
<template><Button type="default" data-action-icon="settings" @click="open = true" :aria-label="t('insights.customize')" :title="t('insights.customize')" class="list-action-button"><template #icon><AppIcon name="settings" /></template></Button>
 <FormDialog v-if="open" :visible="true" size="compact" icon="eye" :title="t('insights.customize')" :close-label="t('insights.close')" @update:visible="!$event && (open = false)"><div class="display-options"><p class="display-options-help">{{ t('insights.preferenceHelp') }}</p>
   <div class="display-options-toolbar"><span class="display-options-count" role="status"><AppIcon name="eye" />{{ t('insights.visibleSections', { count: visibleCount, total: options.length }) }}</span>
    <Button attr-type="button" type="default" text :disabled="visibleCount === options.length" @click="emit('update:modelValue', options.map(option => option.id))"><template #icon><AppIcon name="expand" /></template><span>{{ t('insights.showAll') }}</span></Button></div>
   <ul class="display-options-list"><li v-for="option in options" :key="option.id"><label :for="`${id}-${option.id}`" class="display-option" :class="{ selected: modelValue.includes(option.id) }" @click="toggleLabel($event, option.id)"><span class="display-option-icon"><AppIcon :name="icons[option.id] ?? 'chart'" /></span>
      <span class="display-option-copy"><span :id="`${id}-${option.id}-label`" class="display-option-title">{{ option.label }}</span><small v-if="icons[option.id]" :id="`${id}-${option.id}-help`">{{ t(`insights.displayOptions.${option.id}`) }}</small></span>
      <ToggleSwitch :id="`${id}-${option.id}`" :value="modelValue.includes(option.id)" :aria-labelledby="`${id}-${option.id}-label`" @update:value="toggle(option.id, $event)"></ToggleSwitch></label></li></ul></div>
  <template #footer><div class="display-options-footer"><span><AppIcon name="check" />{{ t('insights.appliedImmediately') }}</span><Button :aria-label="t('insights.close')" @click="open = false" type="primary"><template #icon><AppIcon name="check" /></template><span>{{ t('actions.close') }}</span></Button></div></template></FormDialog></template>
<style scoped>
.display-options { min-width: 0; }
.display-options-help { margin: 0 0 var(--space-3); color: var(--theme-muted); }
.display-options-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-3); }
.display-options-count { display: inline-flex; align-items: center; gap: var(--space-1-5); font-size: .72rem; color: var(--theme-muted); font-variant-numeric: tabular-nums; }
.display-options-count svg { width: 1rem; height: 1rem; }
.display-options-toolbar .n-button { font-size: .72rem; padding: var(--space-1-5) var(--space-2); }
.display-options-list { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-1); }
.display-option { display: flex; align-items: center; gap: var(--space-3); min-width: 0; padding: var(--space-3); border-radius: .65rem; cursor: pointer; transition: background .15s; }
.display-option.selected { background: var(--theme-subtle); }
.display-option:hover { background: color-mix(in srgb, var(--theme-accent) 9%, var(--theme-surface)); }
.display-option:focus-within { outline: 2px solid var(--theme-focus); outline-offset: 1px; }
.display-option-icon { display: grid; place-items: center; flex: 0 0 2rem; width: 2rem; height: 2rem; border-radius: .55rem; color: var(--theme-muted); background: var(--theme-surface); }
.display-option-icon svg { width: 1.15rem; height: 1.15rem; }
.display-option.selected .display-option-icon { color: var(--theme-focus); }
.display-option-copy { flex: 1; min-width: 0; display: grid; gap: var(--space-0-5); }
.display-option-title { font-size: .8rem; line-height: 1.4; font-weight: 550; color: var(--theme-text); }
.display-option-copy small { font-size: .71rem; line-height: 1.45; color: var(--theme-muted); overflow-wrap: anywhere; }
.display-option .n-switch { flex: 0 0 auto; }
.display-options-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-3); width: 100%; }
.display-options-footer > span { display: inline-flex; align-items: center; gap: var(--space-1-5); color: var(--theme-muted); font-size: .7rem; }
.display-options-footer > span svg { width: .9rem; height: .9rem; color: var(--theme-success); }
.display-options-footer > .n-button { margin-inline-start: auto; }
@media (max-width: 500px) {
 .display-option { padding: var(--space-3) var(--space-2); gap: var(--space-2); }
 .display-option-icon { flex-basis: 1.75rem; width: 1.75rem; height: 1.75rem; }
}
</style>
