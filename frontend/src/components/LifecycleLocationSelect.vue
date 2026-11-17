<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Message from './AppMessage.vue';
import LocationSelect from './LocationSelect.vue';
import { locationsApi } from '../api/locations';
import type { LocationContext } from '../api/types';
import { ApiError } from '../api/auth';
const props = defineProps<{ id: string; disabled?: boolean; invalid?: boolean; describedBy?: string }>();
const model = defineModel<Pick<LocationContext, 'type' | 'id'> | null | undefined>({ required: true });
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const context = ref<LocationContext | null>(null);
const unavailable = ref(false);
watch(model, async (reference, _, cleanup) => {
 let active = true; cleanup(() => { active = false; });
 unavailable.value = false;
 if (!reference) { context.value = null; return; }
 if (context.value?.id === reference.id && context.value.type === reference.type) return;
 const label = { id: reference.id, name: t('selectControl.unavailable') };
 context.value = { ...reference, site: reference.type === 'site' ? label : { id: '', name: '' }, room: reference.type === 'room' ? label : null, zone: reference.type === 'zone' ? label : null };
 try { const result = await locationsApi.context(reference.type, reference.id); if (active) context.value = result.context; }
 catch (cause) { if (active) { unavailable.value = true; if (cause instanceof ApiError && cause.status === 401) emit('accessLost', cause); } }
}, { immediate: true });
function select(value: LocationContext | null) { context.value = value; unavailable.value = false; model.value = value ? { type: value.type, id: value.id } : null; }
</script>
<template><div class="field"><label :for="props.id">{{ t('catalog.workflow.targetLocation') }}</label>
  <LocationSelect :id="props.id" :model-value="context" :label="t('catalog.workflow.targetLocation')" :empty-label="t('catalog.workflow.keepLocation')" :disabled="props.disabled" :invalid="props.invalid" :described-by="props.describedBy" @update:model-value="select" @access-lost="emit('accessLost', $event)" />
  <small>{{ t('catalog.workflow.relocationHelp') }}</small>
  <Message v-if="unavailable" type="warning" closable>{{ t('catalog.workflow.locationUnavailable') }}</Message></div></template>
