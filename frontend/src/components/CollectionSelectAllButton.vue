<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ListActionButton from './ListActionButton.vue';

defineProps<{ label: string; pressed: boolean | 'mixed'; disabled?: boolean }>();
const { t } = useI18n();
const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
 <ListActionButton class="select-all-button" :icon="pressed === true ? 'close' : 'check'" :label="pressed === true ? t('collection.batch.clearSelection') : label" :aria-pressed="pressed" :disabled="disabled" @click="emit('toggle')" />
</template>
