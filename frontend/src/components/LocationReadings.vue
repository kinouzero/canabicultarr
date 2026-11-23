<script setup lang="ts">
import { computed, inject, ref, useId, watch, onScopeDispose } from 'vue';
import { NButton, NSelect, type SelectOption, type SelectGroupOption } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import { ApiError } from '../api/auth';
import { plantDashboardApi } from '../api/plants';
import type { PlantMeasurement } from '../api/plants';
import { useDisclosureState } from '../lib/disclosure-preferences';
import { insightPreferenceScope } from '../lib/insight-preferences';
import { preferenceStore, setUiPreference } from '../lib/ui-preferences';
import AppIcon from './AppIcon.vue';
import DisclosureSummary from './DisclosureSummary.vue';
import FormDialog from './FormDialog.vue';
import ListActionButton from './ListActionButton.vue';
import PlantReadings from './PlantReadings.vue';

const props = defineProps<{ contextKey: string; readings: PlantMeasurement[]; siteId?: string; locationKey?: string; total?: number }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const scope = inject(insightPreferenceScope, undefined);
const account = () => scope?.value ?? '';
const channelDisclosures = useDisclosureState('dashboard-channels');
const channelsExpanded = computed(() => channelDisclosures.isOpen(props.contextKey, channelDisclosures.isOpen('default', true)));
function rememberChannels(event: Event) {
 const details = event.currentTarget as HTMLDetailsElement;
 if (event.target === details && details.open !== channelsExpanded.value) channelDisclosures.set(props.contextKey, details.open);
}
const open = ref(false);
const menuOpen = ref(false);
watch(open, visible => { if (!visible) menuOpen.value = false; });
const selectId = useId();
const extra = ref<PlantMeasurement[]>([]);
const page = ref(1);
const loading = ref(false);
const failed = ref(false);
let controller: AbortController | undefined;
const readings = computed(() => {
 const seen = new Set<string>();
 return [...props.readings, ...extra.value].filter(row => { if (seen.has(row.sensorId)) return false; seen.add(row.sensorId); return true; });
});
const total = computed(() => props.total ?? readings.value.length);
watch(() => [props.readings, props.contextKey, props.siteId], () => { controller?.abort(); extra.value = []; page.value = 1; loading.value = false; failed.value = false; });
onScopeDispose(() => controller?.abort());
async function more() {
 if (!props.siteId || !props.locationKey || loading.value) return;
 const [type, id] = props.locationKey.split(':');
 if (!type || !id) return;
 const request = new AbortController(); controller = request; loading.value = true; failed.value = false;
 try {
  const result = await plantDashboardApi.tiles(props.siteId, type, id, page.value + 1, request.signal);
  if (request.signal.aborted) return;
  extra.value = [...extra.value, ...result.data]; page.value += 1;
 } catch (cause) {
  if (!request.signal.aborted) { failed.value = true; if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); }
 } finally { if (controller === request) loading.value = false; }
}
const key = (id: string) => `culture:dashboard-tiles:${props.contextKey}:${id}`;
const orderKey = (id: string) => `culture:dashboard-tile-order:${props.contextKey}:${id}`;
const ordered = computed(() => {
 const values = preferenceStore(account()).values;
 const rank = (id: string) => {
  const value = values[orderKey(id)];
  return typeof value === 'number' && Number.isFinite(value) ? value : Infinity;
 };
 return [...readings.value].sort((a, b) => rank(a.sensorId) - rank(b.sensorId));
});
const selected = computed({
 get: () => readings.value.filter(reading => preferenceStore(account()).values[key(reading.sensorId)] !== false).map(reading => reading.sensorId),
 set: (ids: string[]) => {
  const visible = new Set(ids);
  for (const reading of readings.value) setUiPreference(account(), key(reading.sensorId), visible.has(reading.sensorId));
 },
});
const visible = computed(() => {
 const ids = new Set(selected.value);
 return ordered.value.filter(reading => ids.has(reading.sensorId));
});
function move(id: string, direction: -1 | 1) {
 const index = visible.value.findIndex(reading => reading.sensorId === id);
 const neighbor = visible.value[index + direction];
 if (index < 0 || !neighbor) return;
 const ids = ordered.value.map(reading => reading.sensorId);
 const from = ids.indexOf(id); const to = ids.indexOf(neighbor.sensorId);
 [ids[from], ids[to]] = [ids[to]!, ids[from]!];
 ids.forEach((sensorId, rank) => setUiPreference(account(), orderKey(sensorId), rank));
}
function optionAttributes(option: SelectOption | SelectGroupOption) {
 return { role: 'option', 'aria-label': String(option.label ?? ''), 'aria-selected': selected.value.includes(String(option.value)) };
}
const options = computed(() => readings.value.map(reading => ({ value: reading.sensorId, label: `${reading.device.name} · ${reading.sensorName}` })));
</script>
<template>
 <details v-if="readings.length" :open="channelsExpanded" class="location-readings" @toggle="rememberChannels">
  <DisclosureSummary :level="3" icon="sensor">
   <span>{{ t('plantHome.locationChannels') }}</span>
   <small>{{ t('plantHome.tileCount', { count: visible.length, total }) }}</small>
  </DisclosureSummary>
  <div class="location-readings-toolbar">
   <NButton v-if="readings.length < total" size="small" :loading="loading" @click="more"><template #icon><AppIcon name="expand" /></template>{{ t(failed ? 'actions.retry' : 'selectControl.more') }}</NButton>
   <ListActionButton icon="settings" :label="t('plantHome.chooseTiles')" @click="open = true" />
  </div>
  <PlantReadings :context-key="contextKey" :readings="visible" tiles inline show-empty @access-lost="emit('accessLost', $event)" />
 </details>
 <FormDialog v-if="open" :visible="true" size="compact" icon="sensor" :title="t('plantHome.chooseTiles')" :close-label="t('actions.close')" @update:visible="open = $event">
  <p class="location-readings-help">{{ t('plantHome.tileSelectionHelp') }}</p>
  <label class="location-readings-label" :for="selectId">{{ t('plants.measurements.summaryChannels') }}</label>
  <NSelect v-model:value="selected" v-model:show="menuOpen" :data-select-popup="menuOpen ? '' : undefined" :options="options" :input-props="{ id: selectId, 'aria-label': t('plants.measurements.summaryChannels') }" :menu-props="{ role: 'listbox', 'aria-multiselectable': true }" :node-props="optionAttributes" multiple filterable clearable :max-tag-count="3" :aria-label="t('plants.measurements.summaryChannels')" :placeholder="t('plantHome.chooseTiles')" />
  <NButton v-if="readings.length < total" size="small" :loading="loading" @click="more"><template #icon><AppIcon name="expand" /></template>{{ t(failed ? 'actions.retry' : 'selectControl.more') }}</NButton>
  <section v-if="visible.length" class="tile-order" :aria-label="t('plantHome.tileOrder')">
   <h3>{{ t('plantHome.tileOrder') }}</h3>
   <ol>
    <li v-for="(reading, index) in visible" :key="reading.sensorId">
     <span class="tile-order-name"><strong>{{ reading.sensorName }}</strong><small>{{ reading.device.name }}</small></span>
     <span class="tile-order-actions">
      <ListActionButton icon="chevron" class="tile-order-up" :label="t('plantHome.moveTileUp', { name: reading.sensorName })" :disabled="index === 0" @click="move(reading.sensorId, -1)" />
      <ListActionButton icon="chevron" :label="t('plantHome.moveTileDown', { name: reading.sensorName })" :disabled="index === visible.length - 1" @click="move(reading.sensorId, 1)" />
     </span>
    </li>
   </ol>
  </section>
  <template #footer><div class="location-readings-footer">
   <NButton type="default" :disabled="selected.length === readings.length" @click="selected = readings.map(reading => reading.sensorId)"><template #icon><AppIcon name="expand" /></template>{{ t('insights.showAll') }}</NButton>
   <NButton type="primary" @click="open = false"><template #icon><AppIcon name="check" /></template>{{ t('actions.close') }}</NButton>
  </div></template>
 </FormDialog>
</template>
<style scoped>
.location-readings { min-width: 0; margin: var(--space-3) 0; }
.location-readings-toolbar { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: var(--space-3); margin: var(--space-2) 0; }
.location-readings-help { margin: 0 0 var(--space-3); color: var(--theme-muted); font-size: .8rem; }
.location-readings-label { display: block; margin-bottom: var(--space-2); font-size: .78rem; }
.location-readings-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-3); width: 100%; }
.tile-order { margin-top: var(--space-3); }
.tile-order h3 { margin: 0 0 var(--space-2); font-size: .82rem; }
.tile-order ol { display: grid; gap: var(--space-2); list-style: none; margin: 0; padding: 0; }
.tile-order li { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); padding: var(--space-2); border-radius: .5rem; background: var(--theme-subtle); }
.tile-order-name { display: grid; gap: var(--space-1); min-width: 0; }
.tile-order-name strong, .tile-order-name small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tile-order-name strong { font-size: .78rem; font-weight: 600; }
.tile-order-name small { color: var(--theme-muted); font-size: .7rem; }
.tile-order-actions { display: flex; gap: var(--space-1); flex-shrink: 0; }
.tile-order-up :deep(svg) { transform: rotate(180deg); }
</style>
