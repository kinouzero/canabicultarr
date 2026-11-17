<script setup lang="ts">
import { useId } from 'vue';
import { sortOptions } from '../lib/collection-sorting';
import { useI18n } from 'vue-i18n';
withDefaults(defineProps<{ options?: readonly string[]; disabled?: boolean }>(), {
 options: () => sortOptions.common,
});
const model = defineModel<string>({ required: true });
const id = useId();
const { t } = useI18n();
</script>
<template>
 <div class="filter-field"><label :for="`${id}-sort`">{{ t('collection.sort') }}</label><select :id="`${id}-sort`" v-model="model" :disabled="disabled" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-for="option in options" :key="option" :value="option">{{ t(`collection.sorts.${option}`) }}</option></select></div>
</template>
