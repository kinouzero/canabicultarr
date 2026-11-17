<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { useReferenceOptions } from '../lib/reference-options';
import { computed, onMounted, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import { ApiError } from '../api/auth';
import type { Page } from '../api/types';
import type { ReferenceOption } from '../api/sensors';

const props = defineProps<{
 id?: string;
 label: string;
 placeholder?: string;
 modelValue?: string;
 selected?: ReferenceOption | null;
 disabled?: boolean;
 invalid?: boolean;
 describedBy?: string;
 hideWhenEmpty?: boolean;
 hideLabel?: boolean;
 fetchPage: (search: string, page: number) => Promise<Page<ReferenceOption>>;
 excludeIds?: string[];
}>();
const emit = defineEmits<{ 'update:modelValue': [id: string]; select: [id: string]; option: [value: ReferenceOption | null]; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const generatedId = useId();
const { options, loading, failed, initialized, query, more, retryPage, load, search, loadMore } = useReferenceOptions({
 value: () => props.modelValue, selected: () => props.selected, disabled: () => props.disabled,
 fetchPage: () => props.fetchPage, unavailable: () => t('selectControl.unavailable'),
 onError: cause => { if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); },
});
const visible = computed(() => !props.hideWhenEmpty || options.value.length || !!query.value || more.value || failed.value);
function choose(event: Event) {
 const select = event.target as HTMLSelectElement;
 const id = select.value;
 const option = options.value.find(row => row.id === id);
 if (props.disabled || loading.value || failed.value || id && (!option || option.disabled || props.excludeIds?.includes(id))) return;
 emit('update:modelValue', id);
 emit('select', id);
 emit('option', option ?? null);
 // Add-another controls reset so an unlinked item can be chosen again.
 if (props.modelValue === undefined) { select.value = ''; select.dispatchEvent(new Event('input', { bubbles: true })); }
}
onMounted(() => { void load(); });
</script>
<template><div v-if="visible" class="field reference-select"><template v-if="!hideWhenEmpty || options.length || query || (more && initialized && !failed)"><label v-if="!hideLabel" :for="id ?? generatedId">{{ label }}</label>
   <select :id="id ?? generatedId" :aria-label="label" :value="modelValue ?? ''" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty'), query, onSearch: search, pending: loading || failed, failed, more: more && !failed ? { label: loading ? t('selectControl.loading') : t('selectControl.more'), load: loadMore } : undefined }" :disabled="disabled || failed || !initialized && loading" :aria-invalid="!!invalid" :aria-describedby="describedBy" @change="choose"><option value="" :disabled="loading || failed">{{ loading ? t('selectControl.loading') : placeholder ?? t('selectControl.choose') }}</option>
    <option v-for="row in options" :key="row.id" :value="row.id" :disabled="loading || failed || row.disabled || excludeIds?.includes(row.id)">{{ row.name }}{{ row.detail ? ` · ${row.detail}` : '' }}</option></select></template>
  <Message v-if="failed" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }} <Button attr-type="button" :aria-label="t('collection.refresh')" type="default" :disabled="disabled || loading" @click="load(retryPage)"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></Message></div></template>
<style scoped>
.reference-select { min-width: 0; }
label { display: block; margin-bottom: var(--space-1-5); }
select { width: 100%; min-width: 0; max-width: 100%; box-sizing: border-box; }
</style>
