<script setup lang="ts">
import { computed, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { assetsApi, type PowerSettings } from '../api/assets';
import Message from './AppMessage.vue';
import ListActionButton from './ListActionButton.vue';
const props = defineProps<{ assetId?: string; fixedProvider?: PowerSettings['provider']; disabled?: boolean; allowBinding?: boolean }>();
const model = defineModel<PowerSettings | null>({ required: true });
const { t } = useI18n(); const id = useId();
function initialSettings(provider: PowerSettings['provider'], entity = ''): PowerSettings {
 return { provider, entity, mode: 'manual', on_at: null, off_at: null, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone };
}
const provider = computed<PowerSettings['provider'] | ''>({
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
const targets = ref<{ id: string; name: string; disabled: boolean }[]>([]); const loading = ref(false); const error = ref(false); let sequence = 0;
const zones = [...new Set(['UTC', Intl.DateTimeFormat().resolvedOptions().timeZone, ...Intl.supportedValuesOf('timeZone')])].sort();
async function load() { const current = ++sequence; targets.value = []; error.value = false; if (!provider.value || !props.allowBinding) { loading.value = false; return; } loading.value = true;
 try { const rows = await assetsApi.powerTargets(provider.value, props.assetId); if (current === sequence) targets.value = rows; }
 catch { if (current === sequence) error.value = true; } finally { if (current === sequence) loading.value = false; }
}
watch(provider, () => { void load(); }, { immediate: true }); onUnmounted(() => { sequence++; });
</script>
<template>
 <fieldset class="dialog-section dialog-wide"><legend>{{ t('assets.power.settings') }}</legend><div class="dialog-form-grid">
  <div class="field"><label :for="`${id}-provider`">{{ t('assets.pump.connection') }}</label><input v-if="fixedProvider" :id="`${id}-provider`" :value="fixedProvider === 'homebridge' ? 'Homebridge' : 'Home Assistant'" readonly /><select v-else :id="`${id}-provider`" v-model="provider" :disabled="disabled || !allowBinding" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="">{{ t('assets.pump.noControl') }}</option><option value="home_assistant">Home Assistant</option><option value="homebridge">Homebridge</option></select></div>
  <div v-if="provider" class="field"><label :for="`${id}-entity`">{{ t('assets.power.entity') }}</label><select :id="`${id}-entity`" v-model="selectedEntity" :disabled="disabled || (loading && !fixedProvider) || !allowBinding" :required="!fixedProvider" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-if="mismatched" value="__mismatch__" disabled>{{ t('assets.pump.chooseEntity') }}</option><option value="" :disabled="!fixedProvider">{{ t(loading ? 'assets.loading' : fixedProvider ? 'assets.pump.noControl' : !error && !targets.length ? 'assets.pump.noTargets' : 'assets.pump.chooseEntity') }}</option><option v-if="selectedEntity && !mismatched && !targets.some(row => row.id === selectedEntity)" :value="selectedEntity">{{ selectedEntity }}</option><option v-for="target in targets" :key="target.id" :value="target.id" :disabled="target.disabled">{{ target.name }}</option></select></div>
  <div v-if="model && !mismatched" class="field"><label :for="`${id}-mode`">{{ t('assets.pump.mode') }}</label><select :id="`${id}-mode`" v-model="model.mode" :disabled="disabled" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="manual">{{ t('assets.power.manual') }}</option><option value="auto">{{ t('assets.power.auto') }}</option></select></div>
  <template v-if="!mismatched && model?.mode === 'auto'"><div class="field"><label :for="`${id}-on`">{{ t('assets.power.onAt') }}</label><input :id="`${id}-on`" type="time" v-model="model.on_at" :disabled="disabled" required /></div><div class="field"><label :for="`${id}-off`">{{ t('assets.power.offAt') }}</label><input :id="`${id}-off`" type="time" v-model="model.off_at" :disabled="disabled" required /></div><div class="field"><label :for="`${id}-zone`">{{ t('assets.power.timezone') }}</label><select :id="`${id}-zone`" v-model="model.timezone" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled" required><option v-for="zone in zones" :key="zone" :value="zone">{{ zone }}</option></select></div></template>
 </div><small v-if="!allowBinding">{{ t('assets.power.bindingHelp') }}</small><small v-if="fixedProvider && !loading && !error && !targets.length && allowBinding">{{ t('assets.pump.noTargets') }}</small><small v-if="mismatched" role="alert">{{ t('assets.power.connectionMismatch') }}</small><small v-if="model && !mismatched">{{ t(model.mode === 'auto' ? 'assets.power.scheduleHelp' : 'assets.power.manualHelp') }}</small><Message v-if="error" type="error" role="alert">{{ t('assets.pump.targetsFailed', { provider: provider === 'homebridge' ? 'Homebridge' : 'Home Assistant' }) }} <ListActionButton icon="refresh" :label="t('actions.retry')" :disabled="disabled || loading" @click="load" /></Message></fieldset>
</template>
