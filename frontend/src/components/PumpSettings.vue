<script setup lang="ts">
import { computed, onUnmounted, ref, watch, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Message from './AppMessage.vue';
import ListActionButton from './ListActionButton.vue';
import { assetsApi, type PumpSettings } from '../api/assets';
const model = defineModel<PumpSettings | null>({ required: true });
const props = defineProps<{ assetId?: string; fixedProvider?: PumpSettings['provider']; disabled?: boolean }>();
const { t } = useI18n(); const id = useId();
function initialSettings(provider: PumpSettings['provider'], entity = ''): PumpSettings {
 return { provider, entity, mode: 'auto', seconds: null };
}
const provider = computed<PumpSettings['provider'] | ''>({
 get: () => props.fixedProvider ?? model.value?.provider ?? '',
 set: value => { model.value = value ? initialSettings(value) : null; },
});
const mismatched = computed(() => !!props.fixedProvider && !!model.value && model.value.provider !== props.fixedProvider);
const selectedEntity = computed({
 get: () => mismatched.value ? '__mismatch__' : model.value?.entity ?? '',
 set: entity => {
  if (!entity && props.fixedProvider) { model.value = null; return; }
  if (provider.value) model.value = model.value?.provider === provider.value ? { ...model.value, entity } : initialSettings(provider.value, entity);
 },
});
const targets = ref<{ id: string; name: string; disabled: boolean }[]>([]); const loading = ref(false); const failed = ref(false);
let sequence = 0;
async function load() {
 const current = ++sequence; targets.value = []; failed.value = false;
 if (!provider.value) { loading.value = false; return; }
 loading.value = true;
 try { const rows = await assetsApi.pumpTargets(provider.value, props.assetId); if (current === sequence) targets.value = rows; }
 catch { if (current === sequence) failed.value = true; }
 finally { if (current === sequence) loading.value = false; }
}
watch(provider, () => { void load(); }, { immediate: true }); onUnmounted(() => { sequence++; });
function mode(value: string) { if (model.value) model.value = { ...model.value, mode: value as PumpSettings['mode'], seconds: value === 'timer' ? model.value.seconds ?? 60 : null }; }
</script>
<template><fieldset class="pump-settings dialog-wide" data-testid="pump-settings"><legend>{{ t('assets.pump.settings') }}</legend>
 <div class="pump-settings-grid"><div class="field"><label :for="`${id}-provider`">{{ t('assets.pump.connection') }}</label><input v-if="fixedProvider" :id="`${id}-provider`" :value="fixedProvider === 'homebridge' ? 'Homebridge' : 'Home Assistant'" readonly /><select v-else :id="`${id}-provider`" v-model="provider" :disabled="disabled" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="">{{ t('assets.pump.noControl') }}</option><option value="home_assistant">Home Assistant</option><option value="homebridge">Homebridge</option></select></div>
 <div v-if="provider" class="field"><label :for="`${id}-entity`">{{ t('assets.pump.entity') }}</label><select :id="`${id}-entity`" v-model="selectedEntity" :disabled="disabled || (loading && !fixedProvider)" :required="!fixedProvider" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-if="mismatched" value="__mismatch__" disabled>{{ t('assets.pump.chooseEntity') }}</option><option value="" :disabled="!fixedProvider">{{ t(loading ? 'assets.loading' : fixedProvider ? 'assets.pump.noControl' : !failed && !targets.length ? 'assets.pump.noTargets' : 'assets.pump.chooseEntity') }}</option><option v-if="selectedEntity && !mismatched && !targets.some(row => row.id === selectedEntity)" :value="selectedEntity">{{ selectedEntity }}</option><option v-for="target in targets" :key="target.id" :value="target.id" :disabled="target.disabled">{{ target.name }}</option></select></div>
 <div v-if="model && !mismatched" class="field"><label :for="`${id}-mode`">{{ t('assets.pump.mode') }}</label><select :id="`${id}-mode`" :value="model.mode" @change="mode(($event.target as HTMLSelectElement).value)" :disabled="disabled" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="auto">{{ t('assets.pump.modes.auto') }}</option><option value="timer">{{ t('assets.pump.modes.timer') }}</option><option value="manual">{{ t('assets.pump.modes.manual') }}</option></select></div>
 <div v-if="!mismatched && model?.mode === 'timer'" class="field"><label :for="`${id}-seconds`">{{ t('assets.pump.seconds') }}</label><input :id="`${id}-seconds`" type="number" min="1" max="86400" step="1" :value="model.seconds" @input="model = { ...model!, seconds: Number(($event.target as HTMLInputElement).value) }" :disabled="disabled" required /></div></div><small v-if="fixedProvider && !loading && !failed && !targets.length">{{ t('assets.pump.noTargets') }}</small><small v-if="mismatched" role="alert">{{ t('assets.power.connectionMismatch') }}</small><small v-if="model && !mismatched">{{ t(`assets.pump.help.${model.mode}`) }}</small>
 <Message v-if="failed" type="error" role="alert">{{ t('assets.pump.targetsFailed', { provider: provider === 'homebridge' ? 'Homebridge' : 'Home Assistant' }) }} <ListActionButton icon="refresh" :label="t('actions.retry')" :disabled="disabled || loading" @click="load" /></Message></fieldset></template>
<style scoped>
.pump-settings { border: 0; padding: 0; margin: 0; min-width: 0; }.pump-settings legend { font-weight: 600; font-size: .88rem; padding-right: var(--space-2); }.pump-settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: var(--space-3); }.field { display: grid; gap: var(--space-1-5); }small { display: block; margin-top: var(--space-3); color: var(--theme-muted); }
</style>
