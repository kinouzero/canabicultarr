<script setup lang="ts">
import AppTabs from './AppTabs.vue';
import { computed } from 'vue';
import { NTab } from 'naive-ui';


import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import type { AppPanel, Workspace } from '../navigation';
const props = defineProps<{ workspace: Workspace; active: AppPanel }>();
const emit = defineEmits<{ select: [panel: AppPanel] }>();
const section = computed(() => props.workspace.sections?.find(item => item.panels.includes(props.active)));
const panels = computed(() => props.workspace.panels.filter(item => section.value?.panels.includes(item.id)));
const { t } = useI18n();
</script>
<template><AppTabs v-if="section && panels.length > 1" class="workspace-section-tabs" :aria-label="t(section.label)" :value="active" @update:value="emit('select', $event as AppPanel)" animated><NTab v-for="item in panels" :key="item.id" :name="item.id" :data-testid="`workspace-section-${item.id}`" :aria-label="t(item.accessibleLabel ?? item.label)"><AppIcon :name="item.icon" />{{ t(item.label) }}</NTab></AppTabs></template>
<style scoped>
.workspace-section-tabs { margin-bottom: var(--ui-content-gap); }
</style>
