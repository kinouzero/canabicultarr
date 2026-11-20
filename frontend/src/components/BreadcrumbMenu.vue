<script setup lang="ts">
import { computed, h, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton, NDropdown, NInput, type DropdownOption, type DropdownRenderOption, type DropdownNodeProps } from 'naive-ui';
import { ApiError } from '../api/auth';
import { useReferenceOptions } from '../lib/reference-options';
import type { BreadcrumbOption, BreadcrumbPageLoader } from '../lib/breadcrumb-options';
import AppIcon from './AppIcon.vue';
import '../styles/breadcrumbs.css';
const props = defineProps<{ label: string; value?: string; options?: BreadcrumbOption[]; fetchPage?: BreadcrumbPageLoader; disabled?: boolean }>();
const emit = defineEmits<{ select: [id: string]; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const show = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
let requested = false;
const emptyPage: BreadcrumbPageLoader = async () => ({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } });
const catalog = useReferenceOptions<BreadcrumbOption>({
 value: () => undefined, selected: () => null, disabled: () => props.disabled,
 fetchPage: () => props.fetchPage ?? emptyPage, unavailable: () => t('selectControl.unavailable'),
 onError: cause => { if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); },
});
watch(() => [props.value, props.options], () => { show.value = false; });
const choices = computed(() => props.fetchPage ? catalog.records.value : props.options ?? []);
function updateShow(value: boolean) {
 show.value = value && !props.disabled;
 if (show.value && props.fetchPage && !requested) { requested = true; void catalog.load(); }
}
function choose(key: string | number) {
 const option = choices.value.find(item => item.id === key);
 if (!show.value || props.disabled || props.fetchPage && (catalog.loading.value || catalog.failed.value) || !option || option.disabled) return;
 show.value = false; emit('select', option.id);
}
function searchKey(event: KeyboardEvent) {
 if (event.key === 'Escape') { event.preventDefault(); show.value = false; void nextTick(() => trigger.value?.focus()); }
 event.stopPropagation();
}
const menuOptions = computed<Array<DropdownOption | DropdownRenderOption>>(() => {
 const result: Array<DropdownOption | DropdownRenderOption> = [];
 if (props.fetchPage) result.push({ type: 'render', key: 'search', render: () => h('div', { class: 'breadcrumb-menu-controls' }, [h(NInput, {
  value: catalog.query.value, placeholder: t('selectControl.filter'), maxlength: 120, disabled: props.disabled,
  inputProps: { type: 'search', 'aria-label': `${t('selectControl.filter')} ${props.label}` },
  onUpdateValue: catalog.search, onKeydown: searchKey,
 })]) });
 result.push(...choices.value.map(option => ({ key: option.id, label: option.detail ? `${option.name} · ${option.detail}` : option.name,
  disabled: option.disabled || !!props.fetchPage && (catalog.loading.value || catalog.failed.value), icon: () => h(AppIcon, { name: option.icon }),
 })));
 if (props.fetchPage && (catalog.loading.value || catalog.failed.value || !choices.value.length || catalog.more.value)) result.push({ type: 'render', key: 'feedback', render: () => h('div', { class: 'breadcrumb-menu-feedback' },
  catalog.loading.value ? [h('span', { role: 'status' }, t('selectControl.loading'))] : catalog.failed.value
   ? [h('span', { role: 'alert' }, t('auth.errors.requestFailed')), h(NButton, { size: 'small', onClick: () => catalog.load(catalog.retryPage.value) }, { icon: () => h(AppIcon, { name: 'refresh' }), default: () => t('actions.retry') })]
   : [!choices.value.length ? h('span', { role: 'status' }, t('selectControl.empty')) : null, catalog.more.value ? h(NButton, { size: 'small', onClick: catalog.loadMore }, { icon: () => h(AppIcon, { name: 'plus' }), default: () => t('selectControl.more') }) : null],
 ) });
 return result;
});
const nodeProps: DropdownNodeProps = option => ({ role: 'menuitem', 'aria-disabled': option.disabled ? 'true' : undefined, title: typeof option.label === 'string' ? option.label : undefined });
const menuProps = () => ({ role: 'menu', class: props.fetchPage ? 'breadcrumb-remote-menu' : undefined, 'aria-hidden': show.value ? undefined : 'true' as const, style: 'max-height: min(60dvh, 26rem); max-width: calc(100vw - 2rem)' });
</script>
<template><NDropdown scrollable trigger="click" placement="bottom-start" display-directive="if" :show="show" :options="menuOptions" :value="value" :node-props="nodeProps" :menu-props="menuProps" @update:show="updateShow" :on-select="choose"><button ref="trigger" type="button" class="breadcrumb-menu-trigger" :disabled="disabled" :aria-label="label" :title="label" aria-haspopup="menu" :aria-expanded="show" @keydown.down.prevent="updateShow(true)"><AppIcon name="chevron" class="app-disclosure-icon" /></button></NDropdown></template>
