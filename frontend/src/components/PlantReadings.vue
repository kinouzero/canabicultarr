<script setup lang="ts">
import { useDeviceReadings } from '../lib/device-readings';
import ChannelSwitchTag from './ChannelSwitchTag.vue';
import DisclosureSummary from './DisclosureSummary.vue';
import { useDisclosureState, useRememberDisclosure } from '../lib/disclosure-preferences';
import { measurementNatureIcon } from '../lib/measurement-nature-icon';
import { computed, defineAsyncComponent, inject, ref, shallowReactive, useId } from 'vue';
import { sensorHistoryOpener } from '../lib/sensor-history-route';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import { assetCategoryIcon } from '../lib/asset-category';
import type { ApiError } from '../api/auth';
import type { PlantMeasurement } from '../api/plants';
import { formatDateTime } from '../lib/date-time';
const vRememberDisclosure = useRememberDisclosure('PlantReadings');
defineOptions({ inheritAttrs: false });
const props = defineProps<{ contextKey: string; readings: PlantMeasurement[]; compact?: boolean; inline?: boolean; limit?: number; tiles?: boolean; showEmpty?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, locale } = useI18n();
const SensorHistory = defineAsyncComponent(() => import('./SensorHistory.vue'));
const selected = ref<PlantMeasurement | null>(null);
const openHistory = inject(sensorHistoryOpener, null);
function showHistory(reading: PlantMeasurement) {
 if (openHistory) openHistory({ id: reading.sensorId, name: reading.sensorName });
 else selected.value = reading;
}
const overflow = useDisclosureState('PlantReadings');
const expanded = computed({ get: () => overflow.isOpen(`${props.contextKey}:more`), set: value => overflow.set(`${props.contextKey}:more`, value) });
const gridId = useId();
const switchControls = shallowReactive(new Map<string, InstanceType<typeof ChannelSwitchTag>>());
function setSwitchControl(id: string, control: unknown) {
 if (control) switchControls.set(id, control as InstanceType<typeof ChannelSwitchTag>);
 else switchControls.delete(id);
}
function interactive(reading: PlantMeasurement) { return reading.measurementType.nature !== 'binary' || reading.switchControl?.canControl; }
function actionLabel(reading: PlantMeasurement) {
 const name = reading.sensorName;
 if (reading.measurementType.nature !== 'binary') return t('insights.historyFor', { name });
 return reading.switchControl?.canControl ? t('plants.measurements.controlFor', { name }) : name;
}
function activateReading(reading: PlantMeasurement) {
 if (reading.measurementType.nature === 'binary') switchControls.get(reading.sensorId)?.activate();
 else showHistory(reading);
}
const available = computed(() => props.readings.filter(reading => props.showEmpty || reading.value !== null || !!reading.switchControl));
const hiddenCount = computed(() => props.compact && props.limit !== 0 ? Math.max(0, available.value.length - (props.limit ?? 4)) : 0);
const visibleSource = computed(() => hiddenCount.value && !expanded.value ? available.value.slice(0, props.limit ?? 4) : available.value);
const visible = useDeviceReadings(() => visibleSource.value, cause => emit('accessLost', cause));
function value(reading: PlantMeasurement): string {
 if (reading.value === null || !Number.isFinite(Number(reading.value))) return '—';
 if (reading.measurementType.nature === 'binary') return t(Number(reading.value) ? 'plants.measurements.on' : 'plants.measurements.off');
 return new Intl.NumberFormat(locale.value, { maximumFractionDigits: reading.measurementType.decimalPlaces }).format(Number(reading.value));
}
function timestamp(reading: PlantMeasurement): string { return reading.observedAt ? formatDateTime(reading.observedAt, locale.value) : t('plants.measurements.noReading'); }
</script>
<template>
 <component :is="inline ? 'div' : 'details'" v-remember-disclosure="`${contextKey}:readings`" v-bind="$attrs" :open="inline ? undefined : true" v-if="available.length" class="plant-readings" :class="{ compact, inline, tiles }" role="group" :aria-label="t('plants.measurements.title')">
  <DisclosureSummary v-if="!inline" class="readings-heading"><span><AppIcon name="sensor" />{{ t('plants.measurements.title') }}</span></DisclosureSummary>
  <span :id="gridId" class="readings-grid" :tabindex="tiles ? 0 : undefined" :role="tiles ? 'region' : undefined" :aria-label="tiles ? t('plants.measurements.title') : undefined">
   <component :is="interactive(reading) ? 'button' : 'div'" :type="interactive(reading) ? 'button' : undefined" :disabled="reading.measurementType.nature === 'binary' && switchControls.get(reading.sensorId)?.busy" v-for="reading in visible" @click.stop="activateReading(reading)" :aria-label="actionLabel(reading)" :key="reading.sensorId" class="plant-reading" :class="{ muted: !reading.isEnabled || reading.isStale }" :title="`${actionLabel(reading)} · ${reading.device.name} · ${timestamp(reading)}`">
    <span class="reading-name">
     <AppIcon :name="measurementNatureIcon(reading.measurementType.nature)" />
     <span>{{ reading.sensorName }}</span>
     <AppIcon v-if="interactive(reading)" class="reading-action" :name="reading.measurementType.nature !== 'binary' ? 'chart' : assetCategoryIcon(reading.device.category)" />
    </span>
    <strong class="reading-value"><ChannelSwitchTag v-if="reading.measurementType.nature === 'binary'" :ref="el => setSwitchControl(reading.sensorId, el)" embedded :reading="reading" @access-lost="emit('accessLost', $event)" /><span v-else class="reading-number">{{ value(reading) }}</span><small v-if="reading.unit && reading.measurementType.nature !== 'binary'">{{ reading.unit }}</small></strong>
    <span v-if="!compact" class="reading-device" :title="`${reading.device.name} · ${reading.measurementType.name}`">{{ reading.device.name }} · {{ reading.measurementType.name }}</span>
    <span v-if="!reading.isEnabled" class="reading-state">{{ t('plants.measurements.disabled') }}</span>
    <span v-if="reading.value === null" class="reading-state">{{ t('plants.measurements.noReading') }}</span>
    <span v-if="reading.observedAt" class="reading-date">
     <span v-if="reading.isEnabled" class="reading-age" :class="reading.isStale ? 'warning' : 'fresh'" role="img" :aria-label="t(reading.isStale ? 'plants.measurements.stale' : 'plants.measurements.fresh')" :title="t(reading.isStale ? 'plants.measurements.stale' : 'plants.measurements.fresh')"><AppIcon :name="reading.isStale ? 'clock' : 'check'" /></span>
     <time :datetime="reading.observedAt">{{ timestamp(reading) }}</time>
    </span>
   </component>
  </span>
  <button v-if="hiddenCount" type="button" class="readings-more" :aria-expanded="expanded" :aria-controls="gridId" @click="expanded = !expanded"><AppIcon name="chevron" class="button-icon app-disclosure-icon" />{{ expanded ? t('plants.measurements.less') : t('plants.measurements.more', { count: hiddenCount }) }}</button>
 </component>
 <SensorHistory v-if="selected" :sensor-id="selected.sensorId" :name="selected.sensorName" @close="selected = null" @access-lost="selected = null; emit('accessLost', $event)" />
</template>
<style scoped>
.plant-readings { display: block; margin-top: var(--ui-content-gap); min-width: 0; }readings-heading svg { width: 1rem; height: 1rem; }.readings-grid { margin-top: var(--space-2); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-2); }.plant-reading { font: inherit; color: inherit; text-align: left; cursor: pointer; min-width: 0; display: flex; flex-direction: column; gap: var(--space-1); padding: var(--space-3); border: 1px solid var(--theme-border); border-radius: .6rem; background: var(--theme-subtle); }.reading-name { display: flex; align-items: center; gap: var(--space-1); color: var(--theme-muted); font-size: .7rem; min-width: 0; }.reading-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.reading-name svg, .reading-state svg { width: .85rem; height: .85rem; flex-shrink: 0; }.reading-value { display: flex; align-items: baseline; gap: var(--space-1); flex-wrap: wrap; font-size: 1.05rem; font-weight: 650; line-height: 1.3; overflow-wrap: anywhere; }.reading-value small { font-size: .7rem; font-weight: 500; }.reading-device, .reading-date, .reading-state, .readings-more { font-size: .65rem; color: var(--theme-muted); overflow-wrap: anywhere; }.reading-state { display: inline-flex; gap: var(--space-1); align-items: center; }.readings-more { display: inline-block; margin-top: var(--space-2); padding: var(--space-1) 0; border: 0; background: transparent; font-family: inherit; cursor: pointer; text-decoration: underline; text-underline-offset: .15rem; }.readings-more:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 3px; }.plant-reading.muted .reading-value { color: var(--theme-muted); }.compact { gap: var(--space-1-5); margin-top: var(--space-3); }.compact .plant-reading { font: inherit; color: inherit; text-align: left; cursor: pointer; padding: var(--space-2); }.compact .reading-value { font-size: .85rem; }.compact .reading-name { font-size: .62rem; }
.reading-date { display: flex; align-items: center; gap: var(--space-1); }.reading-age { display: inline-flex; flex: 0 0 auto; }.reading-age.warning { color: var(--theme-warning); }.reading-age.fresh { color: var(--theme-success); }.reading-age svg { width: .85rem; height: .85rem; }
.reading-action { margin-inline-start: auto; color: var(--theme-focus); }
.plant-reading:disabled { cursor: wait; }
.plant-reading:enabled:hover { border-color: var(--theme-accent); }.plant-reading:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.plant-readings.inline { margin: 0; }
.inline .readings-grid { display: flex; flex-direction: column; gap: var(--space-1-5); margin-top: 0; }
.inline .plant-reading { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: var(--space-1) var(--space-2); padding: var(--space-1) 0; border: 0; border-bottom: 1px solid var(--theme-border); border-radius: 0; background: transparent; }
.inline .reading-name { font-size: .7rem; }
.inline .reading-value { font-size: .8rem; }
.inline :is(.reading-date, .reading-state) { grid-column: 1 / -1; }
.plant-readings.tiles { margin: 0; }
.tiles .readings-grid { margin: 0; display: flex; flex-direction: row; align-items: stretch; gap: var(--space-3); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x proximity; scrollbar-width: thin; scrollbar-color: var(--theme-border) transparent; padding: var(--space-1) var(--space-0-5) var(--space-3); }
.tiles .readings-grid:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.tiles .plant-reading { flex: 0 0 min(13rem, 100%); width: min(13rem, 100%); max-width: 100%; scroll-snap-align: start; box-sizing: border-box; display: flex; flex-direction: column; align-items: stretch; padding: var(--space-3); border: 1px solid var(--theme-border); border-radius: .6rem; background: var(--theme-subtle); }
.tiles .reading-value { font-size: 1.05rem; flex-wrap: nowrap; white-space: nowrap; overflow: hidden; }
.tiles :is(.reading-device, .reading-state, .reading-number, .reading-date time) { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tiles .reading-value small { flex-shrink: 0; }
.tiles .reading-date { flex-wrap: nowrap; }
.plant-reading:not(button) { cursor: default; }
</style>
