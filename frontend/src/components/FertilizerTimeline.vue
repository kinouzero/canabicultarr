<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FertilizerPeriodView } from '../lib/fertilizer-periods';
const props = defineProps<{ periods: FertilizerPeriodView[]; durationDays: number | null; selectedId?: string }>();
const { t, n } = useI18n();
const horizon = computed(() => props.durationDays || Math.max(1, ...props.periods.map(period => period.endDays ?? (period.startDays ?? 0) + 7)));
function bar(period: FertilizerPeriodView) {
 const start = Math.max(0, Math.min(100, (period.startDays ?? 0) / horizon.value * 100));
 const end = Math.max(start, Math.min(100, (period.endDays ?? horizon.value) / horizon.value * 100));
 return { marginInlineStart: `${start}%`, width: `${end - start}%` };
}
</script>
<template>
 <ol class="fertilizer-timeline" :aria-label="t('plants.fertilizerSchedule.title')">
  <li v-for="(period, index) in periods" :key="period.id" :class="{ active: period.active, selected: period.id === selectedId }">
   <div class="period-heading"><strong>{{ period.name || t('plants.fertilizerSchedule.period', { count: index + 1 }) }}</strong><small v-if="period.status">{{ t(`plants.fertilizerSchedule.status.${period.status}`) }}</small></div>
   <div class="period-track" aria-hidden="true"><span :style="bar(period)" /></div>
   <small>{{ n(period.start) }} {{ t(`plants.fertilizerSchedule.units.${period.unit}`) }} → {{ period.end === null ? t('plants.fertilizerSchedule.stageEnd') : `${n(period.end)} ${t(`plants.fertilizerSchedule.units.${period.unit}`)}` }}</small>
   <span class="period-products">{{ period.products.map(product => product.name || t('plants.fertilizers.unavailable')).join(' · ') || t('plants.fertilizerSchedule.noProducts') }}</span>
  </li>
 </ol>
</template>
<style scoped>
.fertilizer-timeline { display: grid; gap: var(--space-2); margin: 0; padding: 0; list-style: none; min-width: 0; }.fertilizer-timeline li { display: grid; min-width: 0; padding: var(--space-3); border-radius: .65rem; background: var(--theme-subtle); gap: var(--space-2); }.fertilizer-timeline li.selected { box-shadow: inset 3px 0 var(--theme-accent); }.period-heading { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: var(--space-2); }.period-heading strong { font-size: .8rem; overflow-wrap: anywhere; }.fertilizer-timeline small,.period-products { font-size: .72rem; color: var(--theme-muted); overflow-wrap: anywhere; }.period-products { line-height: 1.5; }.period-track { height: .35rem; border-radius: 1rem; background: var(--theme-surface); overflow: hidden; }.period-track span { display: block; height: 100%; border-radius: inherit; background: var(--theme-accent); opacity: .5; }.active .period-track span,.selected .period-track span { opacity: 1; }.active strong { color: var(--theme-accent); }
</style>
