<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { NBreadcrumb, NBreadcrumbItem } from 'naive-ui';
import BreadcrumbMenu from './BreadcrumbMenu.vue';
import type { BreadcrumbOption } from '../lib/breadcrumb-options';
import AppIcon from './AppIcon.vue';
import type { AppPanel, BreadcrumbItem } from '../navigation';
const props = defineProps<{ items: BreadcrumbItem[] }>();
const emit = defineEmits<{ select: [panel: AppPanel] }>();
const { t } = useI18n();
interface BreadcrumbView { id: AppPanel; icon: string; label: string; current: boolean; options: BreadcrumbOption[]; onSelect: (key: string | number) => void }
const model = computed<BreadcrumbView[]>(() => props.items.map((item, index) => ({
 onSelect: key => select(key, index),
 id: item.id, icon: item.icon, label: t(item.label), current: index === props.items.length - 1,
 options: (item.alternatives ?? []).map(choice => ({ id: choice.id, name: t(choice.label), detail: '', icon: choice.icon })),
})));
function select(key: string | number, index: number) {
 if (!model.value[index]?.options.some(option => option.id === key)) return;
 emit('select', key as AppPanel);
}

</script>
<template>
 <NBreadcrumb class="app-breadcrumb" :aria-label="t('navigation.breadcrumb')"><NBreadcrumbItem v-for="(item, index) in model" :key="`${index}:${item.id}`"><span class="breadcrumb-segment">
  <component :is="item.current ? 'span' : 'button'" :type="item.current ? undefined : 'button'" class="breadcrumb-link" :class="{ 'is-current': item.current }" :aria-current="item.current ? 'page' : undefined" @click="!item.current && emit('select', item.id)"><AppIcon :name="item.icon" /><span>{{ item.label }}</span></component>
  <BreadcrumbMenu v-if="item.options.length > 1" :label="t('navigation.choosePage', { name: item.label })" :options="item.options" :value="item.id" @select="item.onSelect" />
 </span></NBreadcrumbItem></NBreadcrumb>
</template>
