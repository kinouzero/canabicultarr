<script setup lang="ts">
import { computed, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button, NInput, NSwitch, NTag } from 'naive-ui';
import type { Plant, PlantMeasurement } from '../api/plants';
import { sensorsApi, type MeasurementNature } from '../api/sensors';
import { assetCategoryIcon } from '../lib/asset-category';
import { formatDateTime } from '../lib/date-time';
import { measurementNatureIcon } from '../lib/measurement-nature-icon';
import AppSwitch from './AppSwitch.vue';
import AppIcon from './AppIcon.vue';

interface Channel {
 id: string;
 name: string;
 type: string;
 nature: MeasurementNature;
 unit: string | null;
 enabled: boolean;
 reading?: PlantMeasurement;
}

const props = defineProps<{ devices: Plant['devices']; readings?: PlantMeasurement[]; disabled?: boolean; validationError?: string }>();
const model = defineModel<string[] | null>({ required: true });
const { t, locale } = useI18n();
const id = useId();
const channelsByDevice = ref(new Map<string, Channel[]>());
const loading = ref(false);
const failed = ref(false);
const search = ref('');
let sequence = 0;

const allChannels = computed(() => props.devices.flatMap(device => channelsByDevice.value.get(device.id) ?? []));
const automatic = computed({
 get: () => model.value === null,
 set: (value: boolean) => { model.value = value ? null : allChannels.value.slice(0, 4).map(channel => channel.id); },
});
const selected = computed(() => new Set(model.value ?? []));
const groups = computed(() => {
 const query = search.value.trim().toLocaleLowerCase();
 return props.devices.map(device => ({
  device,
  channels: (channelsByDevice.value.get(device.id) ?? []).filter(channel =>
   [channel.name, channel.type, channel.unit, device.name].some(value => value?.toLocaleLowerCase().includes(query))),
 })).filter(group => group.channels.length > 0);
});

function choose(channelId: string, include: boolean) {
 if (props.disabled || model.value === null) return;
 const next = new Set(model.value);
 if (include) next.add(channelId);
 else next.delete(channelId);
 model.value = [...next];
}

function chooseFromLabel(channelId: string) {
 if (automatic.value || props.disabled) return;
 choose(channelId, !selected.value.has(channelId));
 document.getElementById(`${id}-switch-${channelId}`)?.focus();
}

function readingValue(channel: Channel): string | null {
 const reading = channel.reading;
 if (!reading || reading.value === null || !Number.isFinite(Number(reading.value))) return null;
 if (channel.nature === 'binary') return t(Number(reading.value) === 1 ? 'plants.measurements.on' : 'plants.measurements.off');
 const value = new Intl.NumberFormat(locale.value, { maximumFractionDigits: reading.measurementType.decimalPlaces }).format(Number(reading.value));
 return [value, reading.unit].filter(Boolean).join(' ');
}

async function load() {
 const current = ++sequence;
 loading.value = true;
 failed.value = false;
 const readingsByDevice = new Map<string, PlantMeasurement[]>();
 for (const reading of props.readings ?? []) {
  const readings = readingsByDevice.get(reading.device.id) ?? [];
  readings.push(reading);
  readingsByDevice.set(reading.device.id, readings);
 }
 try {
  const entries = await Promise.all(props.devices.map(async device => {
   const known = readingsByDevice.get(device.id);
   if (known?.length) return [device.id, known.map(reading => ({
    id: reading.sensorId, name: reading.sensorName, type: reading.measurementType.name,
    nature: reading.measurementType.nature, unit: reading.unit, enabled: reading.isEnabled, reading,
   }))] as const;

   const channels: Channel[] = [];
   let page = 1;
   let last = 1;
   do {
    const result = await sensorsApi.list('', '', page, device.id);
    channels.push(...result.data.map(sensor => ({
     id: sensor.id, name: sensor.name, type: sensor.measurementType.name,
     nature: sensor.measurementType.nature, unit: sensor.measurementType.unit,
     enabled: sensor.isEnabled && device.status === 'active',
    })));
    last = result.meta.last_page;
    page++;
   } while (current === sequence && page <= last);
   return [device.id, channels] as const;
  }));
  if (current !== sequence) return;
  channelsByDevice.value = new Map(entries);
  if (model.value !== null) {
   const available = new Set(allChannels.value.map(channel => channel.id));
   const retained = model.value.filter(channelId => available.has(channelId));
   if (retained.length !== model.value.length) model.value = retained;
  }
 } catch {
  if (current === sequence) failed.value = true;
 } finally {
  if (current === sequence) loading.value = false;
 }
}

watch(() => props.devices.map(device => device.id).join(','), () => { void load(); }, { immediate: true });
onUnmounted(() => { sequence++; });
</script>

<template>
 <fieldset v-if="devices.length || validationError" id="plant-summary-channels" tabindex="-1" :aria-invalid="!!validationError"
  :aria-describedby="validationError ? 'plant-summary-channels-error' : undefined" class="summary-channel-selection dialog-wide" :disabled="disabled">
  <legend>{{ t('plants.measurements.summaryChannels') }}</legend>
  <small v-if="validationError" id="plant-summary-channels-error" class="field-error" role="alert">{{ t(validationError) }}</small>

  <div class="channel-toolbar">
   <AppSwitch v-model="automatic" :disabled="disabled || loading || failed">{{ t('plants.measurements.automatic') }}</AppSwitch>
   <NTag v-if="!automatic" size="small" round role="status">{{ t('plants.measurements.selectedChannels', selected.size) }}</NTag>
  </div>
  <p>{{ t('plants.measurements.summaryHelp') }}</p>
  <small v-if="loading" role="status">{{ t('plants.loading') }}</small>
  <p v-else-if="failed" class="channel-load-error" role="alert">
   {{ t('plants.measurements.loadFailed') }}
   <Button :aria-label="t('plants.reload')" :disabled="disabled" type="default" @click="load">
    <template #icon><AppIcon name="refresh" /></template>{{ t('actions.refresh') }}
   </Button>
  </p>
  <p v-else-if="!allChannels.length">{{ t('plants.measurements.noChannels') }}</p>
  <template v-else>
   <NInput v-if="allChannels.length > 4 || search" v-model:value="search" clearable :disabled="disabled"
    :placeholder="t('plants.measurements.searchChannels')" :input-props="{ 'aria-label': t('plants.measurements.searchChannels') }">
    <template #prefix><AppIcon name="search" /></template>
   </NInput>
   <p v-if="!groups.length" role="status">{{ t('plants.measurements.noMatchingChannels') }}</p>
   <section v-for="{ device, channels } in groups" :key="device.id" class="channel-group" :aria-labelledby="`${id}-device-${device.id}`">
    <h4 :id="`${id}-device-${device.id}`"><AppIcon :name="assetCategoryIcon(device.category)" />{{ device.name }}</h4>
    <ul>
     <li v-for="channel in channels" :key="channel.id" class="channel-row" :class="{ 'is-selected': !automatic && selected.has(channel.id) }">
      <AppIcon class="channel-type-icon" :name="measurementNatureIcon(channel.nature)" />
      <div class="channel-identity">
       <label :id="`${id}-name-${channel.id}`" :for="automatic ? undefined : `${id}-switch-${channel.id}`" @click.prevent="chooseFromLabel(channel.id)">{{ channel.name }}</label>
       <small :id="`${id}-type-${channel.id}`">{{ channel.type }}<template v-if="channel.unit"> · {{ channel.unit }}</template></small>
      </div>
      <div class="channel-observation" :id="`${id}-reading-${channel.id}`">
       <span>{{ readingValue(channel) ?? t('plants.measurements.noReading') }}</span>
       <time v-if="channel.reading?.observedAt" :datetime="channel.reading.observedAt">{{ formatDateTime(channel.reading.observedAt, locale) }}</time>
       <NTag v-if="!channel.enabled || channel.reading?.isStale" size="small" round :type="channel.enabled ? 'warning' : 'default'">
        {{ t(channel.enabled ? 'plants.measurements.stale' : 'plants.measurements.disabled') }}
       </NTag>
      </div>
      <NSwitch v-if="!automatic" :id="`${id}-switch-${channel.id}`" size="small" :value="selected.has(channel.id)" :disabled="disabled" :aria-disabled="disabled || undefined"
       :aria-labelledby="`${id}-name-${channel.id}`" :aria-describedby="`${id}-type-${channel.id} ${id}-reading-${channel.id}`" @update:value="choose(channel.id, $event)" />
     </li>
    </ul>
   </section>
  </template>
 </fieldset>
</template>

<style scoped>
.summary-channel-selection p { margin: 0; color: var(--theme-muted); font-size: .78rem; line-height: 1.5; }
.channel-toolbar, .channel-load-error { display: flex; align-items: center; flex-wrap: wrap; gap: var(--ui-control-gap); }
.channel-toolbar { justify-content: space-between; }
.channel-group { min-width: 0; }
.channel-group + .channel-group { margin-block-start: var(--space-2); }
.channel-group h4 {
 display: flex; align-items: center; gap: var(--ui-icon-gap); margin: 0 0 var(--space-1); font-size: .82rem; overflow-wrap: anywhere;
}
.channel-group h4 svg, .channel-type-icon { flex-shrink: 0; inline-size: 1rem; block-size: 1rem; }
.channel-group ul { display: grid; padding: 0; margin: 0; list-style: none; }
.channel-row {
 display: grid; grid-template-columns: 1rem minmax(0, 1fr) minmax(0, auto) auto; align-items: center;
 gap: var(--ui-control-gap); min-width: 0; min-height: var(--app-control-height); padding: var(--space-2);
 border-block-start: 1px solid var(--theme-border); overflow-wrap: anywhere;
}
.channel-row.is-selected { background: var(--theme-subtle); }
.channel-identity, .channel-observation { display: grid; gap: var(--space-0-5); min-width: 0; font-size: .8rem; }
.channel-identity label { font-weight: 600; cursor: pointer; }
.channel-identity label:not([for]) { cursor: inherit; }
.channel-identity small, .channel-observation time { color: var(--theme-muted); font-size: .72rem; }
.channel-observation { justify-items: end; text-align: end; font-variant-numeric: tabular-nums; }
.channel-observation :deep(.n-tag) { max-width: 100%; }
@media (max-width: 36rem) {
 .channel-row { grid-template-columns: 1rem minmax(0, 1fr) auto; column-gap: var(--ui-control-gap); row-gap: var(--space-1); }
 .channel-observation { grid-column: 2; grid-row: 2; justify-items: start; text-align: start; }
 .channel-row > .n-switch { grid-column: 3; grid-row: 1 / 3; }
}
</style>
