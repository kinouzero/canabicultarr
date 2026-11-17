<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import RemovableTag from './RemovableTag.vue';
import LocationSelect from './LocationSelect.vue';
import AppIcon from './AppIcon.vue';
import type { LocationContext } from '../api/types';
import type { ApiError } from '../api/auth';
import { locationLabel, locationIcons } from '../lib/location';
const props = defineProps<{ id: string; label: string; disabled?: boolean; invalid?: boolean; describedBy?: string }>();
const model = defineModel<LocationContext[]>({ required: true });
defineEmits<{ accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const candidate = ref<LocationContext | null>(null);
watch(candidate, value => {
 if (!value || props.disabled) return;
 if (!model.value.some(location => location.type === value.type && location.id === value.id)) model.value = [...model.value, value];
 candidate.value = null;
});
function remove(location: LocationContext) {
 if (!props.disabled) model.value = model.value.filter(value => value.type !== location.type || value.id !== location.id);
}
</script>
<template><div class="location-assignments"><LocationSelect :id="id" v-model="candidate" :label="label" :empty-label="t('assets.addLocation')" :disabled="disabled || model.length >= 100" :invalid="invalid" :described-by="describedBy" reset-after-selection @access-lost="$emit('accessLost', $event)" />
  <ul v-if="model.length" class="assignment-chips" :aria-label="label"><li v-for="location in model" :key="`${location.type}:${location.id}`"><RemovableTag :label="t('locationAccess.remove', { name: locationLabel(location) })" :disabled="disabled" @remove="remove(location)"><template #icon><AppIcon :name="locationIcons[location.type]" /></template>{{ locationLabel(location) }}</RemovableTag></li></ul></div></template>
<style scoped>
.location-assignments { display: grid; gap: var(--space-2); min-width: 0; }.assignment-chips { display: flex; flex-wrap: wrap; gap: var(--space-1-5); list-style: none; padding: 0; margin: 0; }.assignment-chips li { min-width: 0; max-width: 100%; }.assignment-chips :deep(.n-tag) { max-width: 100%; font-size: .75rem; }.assignment-chips :deep(.n-tag__content) { overflow-wrap: anywhere; white-space: normal; }.assignment-chips svg { width: 1rem; height: 1rem; flex-shrink: 0; }
.assignment-remove { display: inline-flex; align-items: center; justify-content: center; border: 0; padding: var(--space-0-5); background: transparent; color: inherit; cursor: pointer; border-radius: 50%; }.assignment-remove:focus-visible { outline: 2px solid var(--theme-accent-fill); outline-offset: 2px; }
</style>
