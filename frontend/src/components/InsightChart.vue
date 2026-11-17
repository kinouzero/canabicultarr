<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Chart from 'chart.js/auto';
import type { ChartConfiguration } from 'chart.js';
import { instanceTheme } from '../lib/theme';
import { appearanceIsDark } from '../lib/appearance';
export interface InsightSeries { label: string; values: (number | null)[]; color?: string }
const props = withDefaults(defineProps<{ label: string; labels: string[]; series: InsightSeries[]; type?: 'line' | 'bar' | 'doughnut'; horizontal?: boolean; unit?: string | null }>(), { type: 'line' });
const palette = computed(() => instanceTheme.value[appearanceIsDark.value ? 'dark' : 'light']);
const colors = computed(() => [palette.value.accent, palette.value.info, palette.value.warning, palette.value.success, palette.value.danger, palette.value.muted]);
const data = computed(() => ({ labels: props.labels, datasets: props.series.map((series, index) => {
 const color = series.color ?? colors.value[index % colors.value.length]!;
 return { label: series.label, data: series.values, borderColor: color, backgroundColor: props.type === 'doughnut' ? props.labels.map((_, i) => colors.value[i % colors.value.length]) : props.type === 'bar' ? color : `${color}22`, borderWidth: 2, fill: props.type === 'line' && props.series.length === 1, tension: .2, pointRadius: 1.5, pointHitRadius: 12, spanGaps: false };
}) }));
const options = computed(() => ({ responsive: true, maintainAspectRatio: false, animation: false as const, locale: document.documentElement.lang || 'en', indexAxis: props.horizontal ? 'y' as const : 'x' as const,
 plugins: { legend: { display: props.type === 'doughnut' || props.series.length > 1, position: 'bottom' as const, labels: { color: palette.value.text, usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (context: { dataset: { label?: string }; formattedValue: string }) => `${context.dataset.label}: ${context.formattedValue}${props.unit ? ` ${props.unit}` : ''}` } } },
 ...(props.type === 'doughnut' ? { cutout: '65%' } : { scales: { x: { grid: { display: !!props.horizontal, color: palette.value.border }, ticks: { color: palette.value.muted, maxTicksLimit: 8, maxRotation: 0 } }, y: { beginAtZero: props.type === 'bar', grid: { color: palette.value.border, display: !props.horizontal }, ticks: { color: palette.value.muted, maxTicksLimit: 7 }, title: { display: !!props.unit && !props.horizontal, text: props.unit ?? '', color: palette.value.muted } } } })
}));
const canvas = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;
function render() {
 chart?.destroy(); chart = null;
 if (!canvas.value?.isConnected) return;
 chart = new Chart(canvas.value, { type: props.type, data: data.value, options: options.value } as ChartConfiguration);
}
onMounted(render);
watch([data, options, () => props.type], render, { flush: 'post' });
onBeforeUnmount(() => { chart?.destroy(); chart = null; });
</script>
<template><figure class="insight-chart"><div class="chart-canvas"><canvas ref="canvas" role="img" :aria-label="label" /></div><figcaption>{{ label }}</figcaption></figure></template>
<style scoped>
.insight-chart { min-width: 0; margin: var(--space-3) 0 0; } .insight-chart .chart-canvas { height: 18rem; position: relative; } .insight-chart :deep(canvas) { max-width: 100%; } figcaption { font-size: .7rem; color: var(--theme-muted); margin-top: var(--space-2); }
@media(max-width: 600px) { .insight-chart .chart-canvas { height: 15rem; } }
</style>
