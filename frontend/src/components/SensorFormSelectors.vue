<script setup lang="ts">
import { measurementNatureIcon } from '../lib/measurement-nature-icon';
import AppIcon from './AppIcon.vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import { ApiError } from '../api/auth';
import { assetsApi } from '../api/assets';
import { measurementTypesApi, type SensorDevice, type MeasurementTypeSummary } from '../api/sensors';
import type { Page } from '../api/types';
const props = defineProps<{ disabled?: boolean; lockDevice: boolean; lockType: boolean; errors: Record<string, string> }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const device = defineModel<SensorDevice | null>('device', { required: true });
const type = defineModel<MeasurementTypeSummary | null>('type', { required: true });
const { t } = useI18n();
const devices = ref<SensorDevice[]>([]); const types = ref<MeasurementTypeSummary[]>([]);
const loading = ref(true); const failed = ref(false); let alive = true;
const deviceOptions = computed(() => device.value && !devices.value.some(row => row.id === device.value!.id) ? [device.value, ...devices.value] : devices.value);
const typeOptions = computed(() => type.value && !types.value.some(row => row.id === type.value!.id) ? [type.value, ...types.value] : types.value);
const deviceId = computed({ get: () => device.value?.id ?? '', set: (id: string) => { device.value = deviceOptions.value.find(row => row.id === id) ?? null; } });
const typeId = computed({ get: () => type.value?.id ?? '', set: (id: string) => { type.value = typeOptions.value.find(row => row.id === id) ?? null; } });
async function allPages<T>(fetch: (page: number) => Promise<Page<T>>): Promise<T[]> {
 const rows: T[] = []; let page = 1, last = 1;
 do { const result = await fetch(page); rows.push(...result.data); last = result.meta.last_page; page++; } while (page <= last && alive);
 return rows;
}
async function load() {
 loading.value = true; failed.value = false;
 try {
  const [deviceRows, typeRows] = await Promise.all([
   props.lockDevice ? [] : allPages(page => assetsApi.list('', '', '', page)),
   props.lockType ? [] : allPages(page => measurementTypesApi.list('', page)),
  ]);
  if (alive) { devices.value = deviceRows; types.value = typeRows; }
 } catch (cause) {
  if (!alive) return;
  failed.value = true;
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { if (alive) loading.value = false; }
}
onMounted(() => { void load(); }); onUnmounted(() => { alive = false; });
</script>
<template><div class="field"><label for="sensor-device">{{ t('sensors.device') }}</label><select id="sensor-device" v-model="deviceId" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled || loading || failed || lockDevice" :aria-invalid="!!errors.device_id" :aria-describedby="errors.device_id ? 'sensor-device-error' : undefined"><option value="" disabled>{{ t('sensors.pickDevice') }}</option><option v-for="row in deviceOptions" :key="row.id" :value="row.id" :disabled="row.status === 'retired'">{{ row.name }}</option></select><small v-if="errors.device_id" id="sensor-device-error" class="field-error" role="alert">{{ t(errors.device_id) }}</small></div>
 <div class="field"><label for="sensor-type">{{ t('sensors.measurementType') }}</label><div class="icon-select"><AppIcon :name="measurementNatureIcon(type?.nature)" /><select id="sensor-type" v-model="typeId" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled || loading || failed || lockType" :aria-invalid="!!errors.measurement_type_id" :aria-describedby="errors.measurement_type_id ? 'sensor-type-error' : undefined"><option value="" disabled>{{ t('sensors.pickType') }}</option><option v-for="row in typeOptions" :key="row.id" :value="row.id">{{ row.name }} · {{ row.unit ?? t('measurementTypes.noUnit') }}</option></select></div><small v-if="errors.measurement_type_id" id="sensor-type-error" class="field-error" role="alert">{{ t(errors.measurement_type_id) }}</small></div>
 <Message v-if="failed" class="dialog-wide" type="error" closable role="alert">{{ t('auth.errors.requestFailed') }} <Button :aria-label="t('collection.refresh')" type="default" :disabled="disabled || loading" @click="load"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></Message></template>
