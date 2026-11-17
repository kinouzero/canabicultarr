<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import { ApiError } from '../api/auth';
import type { LocationContext } from '../api/types';
import { locationLabel } from '../lib/location';
import { useLocationOptions } from '../lib/location-options';
const props = defineProps<{ id: string; label: string; emptyLabel: string; disabled?: boolean; invalid?: boolean; describedBy?: string; resetAfterSelection?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const model = defineModel<LocationContext | null>({ required: true });
const { t } = useI18n();
const { catalog, key, flat, choose } = useLocationOptions(model, () => props.disabled, cause => { if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); });
function select(event: Event) { const element = event.target as HTMLSelectElement; choose(element.value); if (props.resetAfterSelection) element.value = ''; }
onMounted(() => { void catalog.load(); });
</script>
<template><div class="location-options">
  <select :id="id" :value="key" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty'), query: catalog.query.value, onSearch: catalog.search, pending: catalog.loading.value || catalog.failed.value, failed: catalog.failed.value, more: catalog.more.value && !catalog.failed.value ? { label: catalog.loading.value ? t('selectControl.loading') : t('selectControl.more'), load: catalog.loadMore } : undefined }" :aria-label="label" :aria-invalid="!!invalid" :aria-describedby="describedBy" :disabled="disabled || !catalog.initialized.value && catalog.loading.value" @change="select"><option value="" :disabled="catalog.loading.value || catalog.failed.value">{{ emptyLabel }}</option>
   <option v-if="model && !flat.some(row => row.key === key)" :value="key" disabled>{{ locationLabel(model, true) }}</option>
   <option v-for="row in flat" :key="row.key" :value="row.key" :disabled="!row.selectable">{{ locationLabel(row.context, true) }}</option></select>
  <Message v-if="catalog.failed.value" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }} <Button attr-type="button" :aria-label="t('collection.refresh')" type="default" :disabled="disabled || catalog.loading.value" @click="catalog.load(catalog.retryPage.value)"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></Message></div></template>
<style scoped>
.location-options { min-width: 0; display: grid; gap: var(--space-1-5); }select, input { width: 100%; min-width: 0; }
</style>
