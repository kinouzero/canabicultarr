<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { assetsApi } from '../api/assets';
import { plantsApi, type Plant } from '../api/plants';
import type { ApiError } from '../api/auth';
import type { LocationContext } from '../api/types';
import LocationSelect from './LocationSelect.vue';
import RecordBatchAction from './RecordBatchAction.vue';
import ReferenceSelect from './ReferenceSelect.vue';

type Target = Pick<Plant, 'id' | 'name' | 'canManage' | 'canDelete'>;
const props = defineProps<{ rows: Target[]; disabled?: boolean }>();
const emit = defineEmits<{ applied: [ids: string[]]; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const id = useId();
const location = ref<LocationContext | null>(null);
const device = ref('');
const manageable = computed(() => props.rows.filter(row => row.canManage));
const deletable = computed(() => props.rows.filter(row => row.canDelete));
const name = (row: Target) => row.name;
async function devices(search: string, page: number) {
  const result = await assetsApi.list(search, '', '', page);
  return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: '', disabled: row.status === 'retired' })) };
}
async function remove(row: Target) {
  await plantsApi.remove(await plantsApi.get(row.id));
}
async function relocate(row: Target) {
  const destination = location.value;
  if (!destination) return;
  const plant = await plantsApi.get(row.id);
  if (destination.type === plant.location.type && destination.id === plant.location.id) return;
  await plantsApi.update(plant.id, { version: plant.version, location: { type: destination.type, id: destination.id } });
}
async function attach(row: Target) {
  const asset = device.value;
  if (!asset) return;
  const plant = await plantsApi.get(row.id);
  if (plant.devices.some(entry => entry.id === asset)) return;
  await plantsApi.update(plant.id, { version: plant.version, device_ids: [...plant.devices.map(entry => entry.id), asset] });
}
</script>

<template>
  <RecordBatchAction v-if="manageable.length" :rows="manageable" :disabled="disabled" :action="relocate" :name="name" :label="t('collection.batch.relocate')" :trigger-label="t('plants.batch.moveShort')" icon="location" :incomplete="!location" @applied="emit('applied', $event)" @access-lost="emit('accessLost', $event)">
    <template #default="{ busy }"><LocationSelect :disabled="busy" :id="id" v-model="location" :label="t('plants.location')" :empty-label="t('selectControl.empty')" @access-lost="emit('accessLost', $event)" /></template>
  </RecordBatchAction>
  <RecordBatchAction v-if="manageable.length" :rows="manageable" :disabled="disabled" :action="attach" :name="name" :label="t('plants.addDevice')" :trigger-label="t('plants.batch.equipmentShort')" icon="device" :incomplete="!device" @applied="emit('applied', $event)" @access-lost="emit('accessLost', $event)">
    <template #default="{ busy }"><ReferenceSelect :disabled="busy" :label="t('plants.addDevice')" :fetch-page="devices" v-model="device" @access-lost="emit('accessLost', $event)" /></template>
  </RecordBatchAction>
  <RecordBatchAction v-if="deletable.length" :rows="deletable" :disabled="disabled" :action="remove" :name="name" :label="t('recordDetails.delete')" icon="trash" destructive @applied="emit('applied', $event)" @access-lost="emit('accessLost', $event)" />
</template>
