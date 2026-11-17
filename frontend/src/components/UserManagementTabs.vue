<script setup lang="ts">
import AppTabs from './AppTabs.vue';
import { ref, watch } from 'vue';
import { useGuardedTabs } from '../lib/guarded-tabs';
import { NTab } from 'naive-ui';


import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
const props = defineProps<{ active: 'users' | 'profiles' }>();
const emit = defineEmits<{ select: [panel: 'users' | 'profiles'] }>();
const { t } = useI18n();
const selection = ref<string>(props.active);
watch(() => props.active, next => { selection.value = next; });
// Only the completed route changes selection; a route guard can keep the draft open.
const guarded = useGuardedTabs(selection, async next => { emit('select', next as 'users' | 'profiles'); return false; });
</script>
<template><AppTabs :value="guarded.value.value" @update:value="guarded.change" class="user-management-tabs" animated><NTab name="users" data-testid="workspace-section-users"><AppIcon name="users" />{{ t('workspaces.tabs.users') }}</NTab><NTab name="profiles" data-testid="workspace-section-profiles"><AppIcon name="shield" />{{ t('accessProfiles.title') }}</NTab></AppTabs></template>
<style scoped>
.user-management-tabs { margin-bottom: var(--ui-content-gap); }
</style>
