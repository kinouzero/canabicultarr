<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { useI18n } from 'vue-i18n';
defineProps<{ disabled?: boolean }>();
const emit = defineEmits<{ reset: [] }>();
const { t } = useI18n();
</script>
<template><div class="filter-reset-row"><button :aria-label="t('collection.resetFilters')" type="button" class="filter-reset-link" :disabled="disabled" @click="emit('reset')"><AppIcon name="refresh" class="button-icon" />{{ t('actions.reset') }}</button></div></template>
<style scoped>
.filter-reset-row { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
.filter-reset-link { padding: 0; border: 0; background: transparent; color: var(--theme-muted); font: inherit; font-size: .75rem; text-decoration: underline; text-underline-offset: .15em; cursor: pointer; }
.filter-reset-link:hover { color: var(--theme-accent); }.filter-reset-link:disabled { opacity: .5; cursor: default; }
</style>
