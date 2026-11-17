<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useCollectionView } from '../lib/collection-view';
import ListActionButton from './ListActionButton.vue';
import '../styles/collection-layout.css';
const props = defineProps<{ preferenceKey: string; disabled?: boolean }>();
const { t } = useI18n();
const mode = useCollectionView(() => props.preferenceKey);
</script>
<template>
 <div class="collection-view-controls" role="group" :aria-label="t('collection.view')">
  <ListActionButton v-for="view in (['cards', 'rows'] as const)" :key="view" :label="t(`collection.views.${view}`)" :icon="view === 'cards' ? 'layoutGrid' : 'table'" :disabled="disabled" :aria-pressed="mode === view" @click="mode = view" />
 </div>
</template>
