<script setup lang="ts">
import { refreshDeviceReadings } from '../lib/device-readings';
import { computed, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button, NTag as Tag } from 'naive-ui';
import Message from './AppMessage.vue';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import FormDialog from './FormDialog.vue';
import ReferenceSelect from './ReferenceSelect.vue';
import type { Page } from '../api/types';
import type { ReferenceOption } from '../api/sensors';
import PlantWateringDialog from './PlantWateringDialog.vue';
import { assetsApi, type PumpContext, type PumpActivation } from '../api/assets';
import { ApiError } from '../api/auth';
const props = defineProps<{ assetId: string; name: string; plantId?: string; disabled?: boolean; labeled?: boolean; tagLabel?: string; hideTrigger?: boolean }>();
const emit = defineEmits<{ saved: []; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n(); const id = useId();
const sent = ref(false);
const opened = ref(false); const busy = ref(false); const loading = ref(false); const error = ref(''); const context = ref<PumpContext | null>(null); const selected = ref(''); const activation = ref<PumpActivation | null>(null); const watering = ref(false); let requestId = ''; let active = true;
const manyPlants = ref(false);
const plant = computed(() => context.value?.plants.find(row => row.id === selected.value));
const stoppable = computed(() => context.value?.stoppable ?? null);
const running = computed(() => context.value?.running && !context.value.running.stoppedAt ? context.value.running : null);
function failure(cause: unknown) { const code = cause instanceof ApiError ? cause.code : ''; error.value = te(code) ? code : 'auth.errors.requestFailed'; if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); }
async function load() { loading.value = true; try { const data = await assetsApi.pumpContext(props.assetId, props.plantId || selected.value ? { plant_id: props.plantId ?? selected.value } : {}); if (!active) return; context.value = data; manyPlants.value ||= data.plantsMeta.total > 50; selected.value = props.plantId ?? (data.plants.length === 1 ? data.plants[0]!.id : selected.value); } catch (cause) { if (active) failure(cause); } finally { loading.value = false; } }
async function open() { opened.value = true; sent.value = false; error.value = ''; activation.value = null; requestId = crypto.randomUUID(); await load(); }
async function start() {
 if (!plant.value || busy.value) return;
 busy.value = true; error.value = ''; sent.value = false;
 try { await assetsApi.activatePump(props.assetId, selected.value, requestId); requestId = crypto.randomUUID(); refreshDeviceReadings(props.assetId); if (active) { sent.value = true; await load(); } }
 catch (cause) { if (cause instanceof ApiError && cause.status >= 500) refreshDeviceReadings(props.assetId); if (active) { failure(cause); const message = error.value; await load(); error.value = message; } }
 finally { busy.value = false; }
}
function record() { if (plant.value?.activation) { activation.value = plant.value.activation; watering.value = true; } }
async function stop() { if (!stoppable.value || busy.value) return; busy.value = true; error.value = ''; try { await assetsApi.stopPump(props.assetId, stoppable.value.id); refreshDeviceReadings(props.assetId); await load(); } catch (cause) { if (cause instanceof ApiError && cause.status >= 500) refreshDeviceReadings(props.assetId); failure(cause); } finally { busy.value = false; } }
function close() { if (!busy.value) opened.value = false; }
async function saved() { watering.value = false; emit('saved'); await load(); }
async function plantOptions(search: string, page: number): Promise<Page<ReferenceOption>> {
 const data = await assetsApi.pumpContext(props.assetId, { search, page: String(page) });
 if (active && context.value) {
  const current = plant.value;
  context.value.plants = current && !data.plants.some(row => row.id === current.id) ? [current, ...data.plants] : data.plants;
 }
 return { data: data.plants.map(row => ({ id: row.id, name: row.name, detail: '', disabled: false })), meta: data.plantsMeta };
}
async function status() {
 if (!context.value || busy.value || loading.value || document.hidden) return;
 loading.value = true;
 try {
  const data = await assetsApi.pumpStatus(props.assetId);
  if (active && context.value) {
   const stopped = context.value.running && !data.running;
   Object.assign(context.value, data);
   if (stopped) refreshDeviceReadings(props.assetId);
  }
 } catch (cause) { if (active) failure(cause); }
 finally { loading.value = false; }
}
let poll: ReturnType<typeof setInterval> | undefined;
watch(() => opened.value && !!running.value, enabled => {
 if (poll) clearInterval(poll);
 poll = enabled ? setInterval(() => void status(), 3000) : undefined;
});
onUnmounted(() => { active = false; if (poll) clearInterval(poll); });
defineExpose({ open });
</script>
<template><Button v-if="tagLabel && !hideTrigger" text class="channel-switch-tag" :aria-label="t('assets.pump.open', { name })" :disabled="disabled" @click.stop="open"><Tag><AppIcon name="pump" />{{ tagLabel }}</Tag></Button><Button v-else-if="labeled && !hideTrigger" :aria-label="t('assets.pump.open', { name })" type="default" data-action-icon="pump" :disabled="disabled" @click.stop="open"><template #icon><AppIcon name="pump" /></template><span>{{ t('assets.categories.pump') }}</span></Button>
 <ListActionButton v-else-if="!hideTrigger" icon="pump" :label="t('assets.pump.open', { name })" :disabled="disabled" @click.stop="open" />
 <FormDialog v-if="opened && !watering" :visible="true" size="compact" icon="pump" :title="name" :close-label="t('actions.close')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && close()"><p v-if="loading" role="status">{{ t('assets.loading') }}</p>
  <Message v-if="sent" type="success" closable>{{ t('assets.pump.started', { name }) }}</Message>
  <template v-if="context"><p v-if="context.mode">{{ t(`assets.pump.modes.${context.mode}`) }}<span v-if="context.mode === 'timer'"> · {{ context.seconds }} s</span> · {{ t(`assets.pump.help.${context.mode}`) }}</p>
   <Message v-if="running || stoppable?.stopStatus === 'failed'" :type="stoppable?.stopStatus === 'failed' ? 'error' : 'info'" closable>{{ t(stoppable?.stopStatus === 'failed' ? 'assets.pump.stopFailed' : 'assets.pump.running') }}</Message>
   <p v-if="!context.plants.length">{{ t('assets.pump.noPlants') }}</p>
   <ReferenceSelect v-else-if="manyPlants && !plantId" v-model="selected" :label="t('assets.pump.plant')" :placeholder="t('assets.pump.choosePlant')" :selected="plant ? { id: plant.id, name: plant.name, detail: '', disabled: false } : null" :fetch-page="plantOptions" :disabled="busy" @access-lost="emit('accessLost', $event)" />
   <div v-else class="field"><label :for="`${id}-plant`">{{ t('assets.pump.plant') }}</label><select :id="`${id}-plant`" v-model="selected" :disabled="busy || !!plantId" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="" disabled>{{ t('assets.pump.choosePlant') }}</option><option v-for="row in context.plants" :key="row.id" :value="row.id">{{ row.name }}</option></select></div>
   <p v-if="plant?.activation">{{ t('assets.pump.pendingHelp') }}</p></template>
  <template #footer><div class="dialog-actions"><Button type="default" :disabled="busy" @click="close"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.close') }}</span></Button><Button v-if="stoppable" type="error" :disabled="busy || loading" @click="stop"><template #icon><AppIcon name="pump" /></template><span>{{ t('assets.pump.stop') }}</span></Button><Button v-if="plant?.activation" :disabled="busy || loading" @click="record"><template #icon><AppIcon name="water" /></template><span>{{ t('assets.pump.record') }}</span></Button><Button v-if="plant && !running" :loading="busy" :disabled="busy || loading" @click="start" type="primary"><template #icon><AppIcon name="pump" /></template><span>{{ t('assets.pump.start') }}</span></Button><ListActionButton icon="refresh" :label="t('actions.refresh')" :disabled="busy || loading" @click="load" /></div></template></FormDialog>
 <PlantWateringDialog v-if="watering && activation && plant" :plant-id="plant.id" :name="plant.name" :pump="{ id: activation.id, name, occurredAt: activation.occurredAt }" @close="watering = false; load()" @saved="saved" @access-lost="emit('accessLost', $event)"><template #pump-control><Message v-if="stoppable?.stopStatus === 'failed'" type="error" closable>{{ t('assets.pump.stopFailed') }}</Message><Button v-if="stoppable" type="error" :disabled="busy" @click="stop"><template #icon><AppIcon name="pump" /></template><span>{{ t('assets.pump.stop') }}</span></Button><Message v-if="error" type="error" closable>{{ t(error) }}</Message></template></PlantWateringDialog></template>
<style scoped>.field { display: grid; gap: var(--space-1-5); }.dialog-actions { flex-wrap: wrap; }</style>
