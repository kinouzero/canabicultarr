<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ReferenceSelect from './ReferenceSelect.vue';
import { eventTypesApi, systemEventTypes, type EventType, type EventTypeSnapshot } from '../api/event-types';
import type { ApiError } from '../api/auth';
import type { ReferenceOption } from '../api/sensors';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/event-types.json';
import fr from '../locales/fr/event-types.json';
const props = defineProps<{ id: string; label: string; hideLabel?: boolean; includeArchived?: boolean; selected?: EventTypeSnapshot | null; disabled?: boolean; invalid?: boolean; describedBy?: string; emptyLabel?: string }>();
const model = defineModel<string>({ required: true });
const emit = defineEmits<{ accessLost: [cause: ApiError]; metadata: [type: EventType | null] }>();
const { t, mergeLocaleMessage } = useI18n({ useScope: 'global' });
registerModuleMessages(mergeLocaleMessage, { en: { eventTypes: en }, fr: { eventTypes: fr } });
const records = new Map<string, EventType>();
async function fetchPage(search: string, page: number) {
 const result = await eventTypesApi.list(search, props.includeArchived ? 'all' : 'active', page);
 for (const type of result.data) records.set(type.id, type);
 if (model.value && records.has(model.value)) emit('metadata', records.get(model.value)!);
 const builtin = page === 1 ? Object.keys(systemEventTypes).map(id => ({ id, name: t(`events.categories.${id}`), detail: t('eventTypes.system') })).filter(row => !search || row.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())) : [];
 return { ...result, data: [...builtin, ...result.data.map(row => ({ id: row.id, name: row.name, detail: row.is_archived ? t('eventTypes.archived') : '' }))] };
}
function selected(option: ReferenceOption | null) { emit('metadata', option ? records.get(option.id) ?? null : null); }
</script>
<template><ReferenceSelect :id="id" v-model="model" :label="label" :hide-label="hideLabel" :placeholder="emptyLabel" :selected="props.selected ? { ...props.selected, detail: '' } : null" :fetch-page="fetchPage" :disabled="disabled" :invalid="invalid" :described-by="describedBy" @option="selected" @access-lost="emit('accessLost', $event)" /></template>
