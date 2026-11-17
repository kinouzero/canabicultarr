<script setup lang="ts">
import { refreshDeviceReadings } from '../lib/device-readings';
import { computed, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import { assetsApi, type AssetCategory, type PowerContext, type PowerSettings } from '../api/assets';
import { ApiError } from '../api/auth';
import FormDialog from './FormDialog.vue';
import ListActionButton from './ListActionButton.vue';
import AppIcon from './AppIcon.vue';
import { assetCategoryIcon } from '../lib/asset-category';
const props = defineProps<{ assetId: string; name: string; category: AssetCategory; settings: PowerSettings; labeled?: boolean }>();
const { t } = useI18n(); const open = ref(false); const busy = ref(false); const loading = ref(false); const error = ref(''); const sent = ref(false); const context = ref<PowerContext | null>(null);
const commandIcon = computed(() => assetCategoryIcon(props.category));
const offIcons: Partial<Record<AssetCategory, string>> = { light: 'lightOff', socket: 'socketOff' };
const offIcon = computed(() => offIcons[props.category] ?? commandIcon.value);
let active = true; let controller: AbortController | null = null;
function failure(cause: unknown) { error.value = cause instanceof ApiError && ['assets.power.busy', 'assets.power.uncertain', 'assets.power.unavailable', 'assets.power.changed'].includes(cause.code) ? cause.code : 'auth.errors.requestFailed'; }
async function load() { controller?.abort(); const request = new AbortController(); controller = request; loading.value = true;
 try { const result = await assetsApi.powerContext(props.assetId, request.signal); if (active && controller === request) context.value = result; }
 catch (cause) { if (active && !request.signal.aborted) failure(cause); } finally { if (active && controller === request) loading.value = false; }
}
function show() { open.value = true; error.value = ''; sent.value = false; context.value = null; void load(); }
function close() { if (busy.value) return; controller?.abort(); open.value = false; }
async function command(isOn: boolean) { if (busy.value || loading.value) return; busy.value = true; error.value = ''; sent.value = false;
 try { const result = await assetsApi.power(props.assetId, isOn, crypto.randomUUID()); refreshDeviceReadings(props.assetId); if (active) { context.value = { isOn: result.isOn, lastCommand: result }; sent.value = true; } }
 catch (cause) { if (cause instanceof ApiError && cause.status >= 500) refreshDeviceReadings(props.assetId); if (active) { context.value = null; failure(cause); } } finally { if (active) busy.value = false; }
}
onUnmounted(() => { active = false; controller?.abort(); });
</script>
<template><Button v-if="labeled" type="default" @click.stop="show"><template #icon><AppIcon :name="commandIcon" /></template><span>{{ t('assets.power.open') }}</span></Button>
 <ListActionButton v-else :icon="commandIcon" :label="t('assets.power.openNamed', { name })" @click.stop="show" />
 <FormDialog v-if="open" :visible="true" :icon="commandIcon" size="compact" :title="t('assets.power.openNamed', { name })" :close-label="t('actions.close')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && close()"><p v-if="loading" role="status">{{ t('assets.loading') }}</p>
  <p v-else>{{ t(context?.isOn === true ? 'assets.power.on' : context?.isOn === false ? 'assets.power.off' : 'assets.power.unknown') }}</p>
  <p v-if="settings.mode === 'auto'">{{ t('assets.power.schedule', { on: settings.on_at, off: settings.off_at, zone: settings.timezone }) }}</p>
  <p v-else>{{ t('assets.power.manual') }}</p>
  <Message v-if="sent" type="success" closable>{{ t('assets.power.sent') }}</Message>
  <Message v-if="context?.lastCommand?.status === 'uncertain'" type="warning" closable>{{ t('assets.power.uncertain') }}</Message>
  <small v-if="settings.mode === 'auto'">{{ t('assets.power.overrideHelp') }}</small>
  <template #footer><div class="dialog-actions"><Button :disabled="busy || loading" @click="command(true)" type="primary"><template #icon><AppIcon :name="commandIcon" /></template><span>{{ t('assets.power.turnOn') }}</span></Button><Button type="default" :disabled="busy || loading" @click="command(false)"><template #icon><AppIcon :name="offIcon" /></template><span>{{ t('assets.power.turnOff') }}</span></Button><ListActionButton icon="refresh" :label="t('actions.refresh')" :disabled="busy || loading" @click="error = ''; load()" /></div></template></FormDialog></template>
