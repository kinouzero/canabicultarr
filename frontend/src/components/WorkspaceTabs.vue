<script setup lang="ts">
import AppTabs from './AppTabs.vue';
import { computed } from 'vue';
import { NTab } from 'naive-ui';


import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import WorkspaceSectionTabs from './WorkspaceSectionTabs.vue';
import type { AppPanel, Workspace } from '../navigation';
const props = defineProps<{ workspace: Workspace; active: AppPanel; showTabs?: boolean; showSubpanels?: boolean }>();
const emit = defineEmits<{ select: [panel: AppPanel] }>();
const sections = computed(() => props.workspace.sections ?? props.workspace.panels.map(item => ({ ...item, panels: [item.id] })));
const selectedSection = computed(() => sections.value.find(section => section.panels.includes(props.active))!);
const { t } = useI18n();
function select(value: string | number) { const section = sections.value.find(item => item.id === value); if (section) emit('select', section.id); }
</script>
<template><section class="workspace-heading" :aria-label="t(workspace.label)" data-testid="workspace-heading"><div class="page-heading workspace-header"><div class="workspace-intro"><span class="workspace-symbol"><AppIcon :name="workspace.icon" /></span><div><h2 id="workspace-title">{{ t(workspace.label) }}</h2><p>{{ t(workspace.description) }}</p></div></div><div v-if="$slots.actions" class="page-actions"><slot name="actions" /></div></div>
  <AppTabs v-if="showTabs !== false" :aria-label="t(workspace.label)" :value="selectedSection.id" @update:value="select" animated><NTab v-for="item in sections" :id="`workspace-tab-${item.id}`" :key="item.id" :name="item.id" :aria-label="t(item.accessibleLabel ?? item.label)"><AppIcon :name="item.icon" /><span>{{ t(item.label) }}</span></NTab></AppTabs>
  <WorkspaceSectionTabs v-if="showSubpanels !== false" :workspace="workspace" :active="active" @select="emit('select', $event)" /></section></template>
<style scoped>
.workspace-heading { margin: 0 0 var(--ui-content-gap); }.workspace-intro { display: flex; align-items: center; gap: var(--ui-grid-gap); margin: 0 0 var(--ui-content-gap); }.workspace-symbol { display: grid; place-items: center; width: 3rem; height: 3rem; flex-shrink: 0; background: var(--theme-subtle, #e8f0e3); color: var(--theme-muted, #315a3b); border-radius: 1rem; }.workspace-symbol svg { width: 1.5rem; height: 1.5rem; } h2 { margin: 0; letter-spacing: -.04em; font-size: 1.7rem; } p { margin: var(--space-1-5) 0 0; color: var(--theme-muted, #61715f); line-height: 1.5; font-size: .85rem; }
@media (max-width: 700px) { .workspace-intro { align-items: flex-start; gap: var(--space-3); }h2 { font-size: 1.4rem; }.workspace-symbol { width: 2.5rem; height: 2.5rem; } }
</style>
