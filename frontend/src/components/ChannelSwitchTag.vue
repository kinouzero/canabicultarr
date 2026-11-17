<script setup lang="ts">
import { refreshDeviceReadings } from '../lib/device-readings';
import { computed, onUnmounted, ref, watch } from 'vue';
import { NButton, NTag } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import { ApiError } from '../api/auth';
import type { PlantMeasurement } from '../api/plants';
import { sensorsApi } from '../api/sensors';
import AppIcon from './AppIcon.vue';
import { assetCategoryIcon } from '../lib/asset-category';
import Message from './AppMessage.vue';
import PumpControl from './PumpControl.vue';
const props = defineProps<{ reading: PlantMeasurement; embedded?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const pump = ref<InstanceType<typeof PumpControl> | null>(null);
const busy = ref(false); const error = ref(''); const success = ref(false);
const live = ref<boolean | null>(null);
let pending: { id: string; on: boolean } | null = null;
let active = true;
onUnmounted(() => { active = false; });
watch([() => props.reading.value, () => props.reading.observedAt, () => props.reading.sourceRevision], () => { live.value = null; });
watch(() => props.reading.sourceRevision, () => { pending = null; });
const on = computed(() => live.value ?? (props.reading.value === null ? null : Number(props.reading.value) === 1));
const label = computed(() => t(on.value === null ? 'assets.power.unknown' : on.value ? 'plants.measurements.on' : 'plants.measurements.off'));
const actionLabel = computed(() => `${t(on.value ? 'assets.power.turnOff' : 'assets.power.turnOn')} · ${props.reading.sensorName}`);
async function toggle() {
 if (busy.value || !props.reading.switchControl?.canControl) return;
 busy.value = true; error.value = ''; success.value = false;
 const { sensorId, sourceRevision, device } = props.reading;
 const currentSource = () => active && props.reading.sensorId === sensorId && props.reading.sourceRevision === sourceRevision;
 try {
  const desired = on.value !== true;
  if (pending?.on !== desired) pending = { id: crypto.randomUUID(), on: desired };
  const result = await sensorsApi.switchPower(sensorId, pending.on, pending.id);
  pending = null;
  refreshDeviceReadings(device.id);
  if (currentSource()) { live.value = result.isOn; success.value = true; }
 } catch (cause) {
  if (!currentSource()) return;
  if (cause instanceof ApiError) {
   pending = null;
   if (cause.status >= 500) refreshDeviceReadings(device.id);
  }
  const code = cause instanceof ApiError ? cause.code : '';
  error.value = code && te(code) ? code : 'auth.errors.requestFailed';
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { busy.value = false; }
}
function activate() {
 if (!props.reading.switchControl?.canControl) return;
 if (props.reading.switchControl.kind === 'pump') void pump.value?.open();
 else void toggle();
}
defineExpose({ activate, busy });
</script>
<template>
 <PumpControl ref="pump" :hide-trigger="embedded" v-if="reading.switchControl?.kind === 'pump' && reading.switchControl.canControl" :asset-id="reading.device.id" :name="reading.device.name" :tag-label="label" @access-lost="emit('accessLost', $event)" />
 <NButton v-else-if="reading.switchControl?.canControl && !embedded" text class="channel-switch-tag" :disabled="busy" :loading="busy" :aria-label="actionLabel" :title="actionLabel" @click.stop="toggle"><NTag :type="on ? 'success' : 'default'"><AppIcon :name="assetCategoryIcon(reading.device.category)" />{{ label }}</NTag></NButton>
 <NTag v-if="embedded || !reading.switchControl?.canControl" :type="on ? 'success' : 'default'">{{ label }}</NTag>
 <Message v-if="error" type="error" closable>{{ t(error) }}</Message>
 <Message v-if="success" type="success" closable>{{ t('assets.power.sent') }}</Message>
</template>
<style scoped>
.channel-switch-tag { border-radius: 2rem; max-width: 100%; }
.channel-switch-tag:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.channel-switch-tag :deep(.n-tag__content) { white-space: nowrap; }
.channel-switch-tag svg { width: .8rem; height: .8rem; flex-shrink: 0; }
</style>
