<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NTreeSelect, type TreeSelectInst } from 'naive-ui';
import type { LocationOptionNode } from '../lib/location-options';
import { NButton as Button } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import { ApiError } from '../api/auth';
import type { LocationContext } from '../api/types';
import { useLocationOptions } from '../lib/location-options';
const props = defineProps<{ id: string; label: string; placeholder: string; disabled?: boolean; invalid?: boolean; describedBy?: string }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const model = defineModel<LocationContext | null>({ required: true });
const { t } = useI18n();
const { catalog, key, nodes, choose } = useLocationOptions(model, () => props.disabled, cause => { if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); });
const expanded = ref<Record<string, boolean>>({});
const open = ref(false);
const treeControl = ref<TreeSelectInst | null>(null);
const expandedKeys = computed({ get: () => Object.keys(expanded.value).filter(key => expanded.value[key]), set: (keys: (string | number)[]) => { expanded.value = Object.fromEntries(keys.map(key => [key, true])); } });
function visibility(value: boolean) { open.value = value; if (value) expand(); else closed(); }
const popupTarget = ref<HTMLElement>();
onMounted(() => {
 const root = document.getElementById(props.id);
 if (root) {
  root.focus = () => treeControl.value?.focus();
  popupTarget.value = root.closest<HTMLElement>('.app-dialog') ?? undefined;
 }
 void catalog.load();
});
function closed() { open.value = false; void nextTick(() => { if (!open.value && !catalog.failed.value) catalog.search(''); }); }
function retry() {
 if (!open.value) { catalog.query.value = ''; void catalog.load(); }
 else void catalog.load(catalog.retryPage.value);
}
function expand() {
 if (catalog.query.value) {
  const keys: Record<string, boolean> = {};
  function visit(rows: LocationOptionNode[]) { for (const row of rows) { if (row.children?.length) { keys[row.key!] = true; visit(row.children); } } }
  visit(nodes.value); expanded.value = keys;
 } else if (model.value) expanded.value = { ...expanded.value, [`site:${model.value.site.id}`]: true, ...(model.value.room ? { [`room:${model.value.room.id}`]: true } : {}) };
}
watch(nodes, expand);
</script>
<template><div class="location-options">
  <NTreeSelect ref="treeControl" :show="open" :to="popupTarget" class="location-tree-select app-tree-select" :value="key || null" @update:value="value => choose(String(value ?? ''))" v-model:expanded-keys="expandedKeys" :options="nodes" show-path separator=" / " :node-props="({ option }) => ({ role: 'treeitem', 'aria-label': String(option.label ?? ''), 'aria-selected': option.key === key, 'aria-disabled': !!option.disabled })" @update:show="visibility" :placeholder="placeholder" :status="invalid ? 'error' : undefined" :id="id" :aria-label="label" :aria-describedby="describedBy" :aria-expanded="open" role="combobox" aria-haspopup="tree" tabindex="-1" :loading="catalog.loading.value" :disabled="disabled || !catalog.initialized.value && catalog.loading.value" data-testid="plant-location"><template #header><div data-select-popup class="tree-filter"><InputText :value="catalog.query.value" :placeholder="t('selectControl.filter')" :input-props="{ 'aria-label': t('selectControl.filter') }" @update:value="catalog.search($event)" @keydown.esc.stop.prevent="visibility(false); treeControl?.focus()" /></div></template><template #action><div class="location-footer" @mousedown.prevent><Button v-if="catalog.more.value && !catalog.failed.value" type="default" :disabled="disabled || catalog.loading.value" @click="catalog.loadMore"><template #icon><AppIcon name="plus" /></template>{{ t('selectControl.more') }}</Button><Message closable v-if="catalog.failed.value" type="error" role="alert">{{ t('auth.errors.requestFailed') }}<Button type="default" :disabled="disabled || catalog.loading.value" @click="retry">{{ t('actions.refresh') }}</Button></Message></div></template></NTreeSelect>
  <Message v-if="catalog.failed.value && !open" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }} <Button attr-type="button" :aria-label="t('collection.refresh')" type="default" :disabled="disabled || catalog.loading.value" @click="retry"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></Message></div></template>
<style scoped>
.location-options { min-width: 0; display: grid; gap: var(--space-1-5); }.app-tree-select { width: 100%; min-width: 0; font-size: .8rem; }.app-tree-select :deep(.n-base-selection-label) { white-space: normal; overflow-wrap: anywhere; }.location-footer:has(> *) { padding: var(--space-2); }
</style>
