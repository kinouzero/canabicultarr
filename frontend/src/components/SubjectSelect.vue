<script setup lang="ts">
import { computed } from 'vue';
import { assetsApi } from '../api/assets';
import { sensorsApi } from '../api/sensors';
import { eventsApi } from '../api/events';
import type { ApiError } from '../api/auth';
import type { DocumentSubject } from '../api/documents';
import type { LocationContext } from '../api/types';
import ReferenceSelect from './ReferenceSelect.vue';
import LocationSelect from './LocationSelect.vue';
const props = defineProps<{ id: string; label: string; kind: string; placeholder: string; disabled?: boolean; invalid?: boolean; describedBy?: string }>();
const model = defineModel<DocumentSubject | null>({ required: true });
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const records = new Map<string, DocumentSubject>();
const location = computed({ get: () => model.value?.location ?? null, set: (value: LocationContext | null) => { model.value = value ? { type: value.type, id: value.id, name: value.type === 'zone' ? value.zone!.name : value.type === 'room' ? value.room!.name : value.site.name, location: value, deviceName: null } : null; } });
async function fetchPage(search: string, page: number) {
 if (props.kind === 'asset') {
  const result = await assetsApi.list(search, '', '', page);
  for (const row of result.data) records.set(row.id, { type: 'asset', id: row.id, name: row.name, location: row.locations.length === 1 ? row.locations[0]! : null, deviceName: null });
  return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: row.assetTag ?? '' })) };
 }
 if (props.kind === 'sensor') {
  const result = await sensorsApi.list(search, '', page);
  for (const row of result.data) records.set(row.id, { type: 'sensor', id: row.id, name: row.name, location: row.device.locations.length === 1 ? row.device.locations[0]! : null, deviceName: row.device.name });
  return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: row.device.name })) };
 }
 const result = await eventsApi.list({ search }, page);
 for (const row of result.data) records.set(row.id, { type: 'event', id: row.id, name: row.title, location: row.subject?.location ?? null, deviceName: row.subject?.deviceName ?? null });
 return { ...result, data: result.data.map(row => ({ id: row.id, name: row.title, detail: '' })) };
}
</script>
<template>
 <div v-if="kind === 'location'" class="field"><label :for="id">{{ label }}</label><LocationSelect :id="id" v-model="location" :label="label" :empty-label="placeholder" :disabled="disabled" :invalid="invalid" :described-by="describedBy" @access-lost="emit('accessLost', $event)" /></div>
 <ReferenceSelect v-else :key="kind" :id="id" :model-value="model?.id ?? ''" :selected="model ? { id: model.id, name: model.name, detail: model.deviceName ?? '' } : null" :label="label" :placeholder="placeholder" :fetch-page="fetchPage" :disabled="disabled" :invalid="invalid" :described-by="describedBy" @select="model = records.get($event) ?? (model?.id === $event ? model : null)" @access-lost="emit('accessLost', $event)" />
</template>
