<script setup lang="ts">
import ReferenceSelect from './ReferenceSelect.vue';
import { storageLocationsApi, type StorageLocation } from '../api/inventory';
import type { ApiError } from '../api/auth';
const props = defineProps<{ id: string; label: string; placeholder?: string; includeArchived?: boolean; disabled?: boolean; invalid?: boolean; describedBy?: string }>();
async function fetchPage(search: string, page: number) { const result = await storageLocationsApi.list(search, page, props.includeArchived ? 'all' : 'active'); return { ...result, data: result.data.map(row => ({ ...row, detail: row.description ?? '' })) }; }
const model = defineModel<StorageLocation | null>({ required: true });
defineEmits<{ accessLost: [cause: ApiError] }>();
</script>
<template>
 <ReferenceSelect :id="props.id" :label="label" :placeholder="placeholder" :model-value="model?.id ?? ''" :selected="model ? { ...model, detail: model.description ?? '' } : null" :fetch-page="fetchPage" :disabled="disabled" :invalid="invalid" :described-by="describedBy" @option="model = $event ? { id: $event.id, name: $event.name } : null" @access-lost="$emit('accessLost', $event)" />
</template>
