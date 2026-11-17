<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import { NInput as InputText } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import { useAutoSave } from '../lib/auto-save';
import { ApiError } from '../api/auth';
import { connectionSettingsApi, type ConnectionField, type ConnectionGroup } from '../api/connectionSettings';
import en from '../locales/en/connectionSettings.json';
import fr from '../locales/fr/connectionSettings.json';
const props = defineProps<{ group: ConnectionGroup; canManage: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError]; stateChange: [state: { dirty: boolean; busy: boolean }] }>();
const { t, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { connectionSettings: en }); mergeLocaleMessage('fr', { connectionSettings: fr });
const fields = ref<ConnectionField[]>([]); const values = reactive<Record<string, string | number | boolean>>({});
const version = ref(0); const loading = ref(false); const busy = ref(false); const error = ref(''); const success = ref('');
const original = reactive<Record<string, string | number | boolean>>({});
const clearedSecrets = reactive(new Set<string>());
const editingSecret = ref(false);
const fieldErrors = ref<Record<string, string>>({});
let sequence = 0;
function normalized(value: string | number | boolean | undefined, field: ConnectionField) { return value === '' || value == null ? null : ['port', 'interval'].includes(field.type) && Number.isFinite(Number(value)) ? Number(value) : value; }
const changedFields = computed(() => fields.value.filter(field => !field.locked && (field.type === 'secret' ? values[field.name] !== '' || clearedSecrets.has(field.name) : normalized(values[field.name], field) !== normalized(original[field.name], field))));
const isDirty = computed(() => props.canManage && changedFields.value.length > 0);
watch([isDirty, busy], () => emit('stateChange', { dirty: isDirty.value, busy: busy.value }), { immediate: true, flush: 'sync' });
function change(field: ConnectionField, value: string | number | boolean) { values[field.name] = value; clearedSecrets.delete(field.name); delete fieldErrors.value[field.name]; success.value = ''; }
function clearSecret(field: ConnectionField) { values[field.name] = ''; clearedSecrets.add(field.name); delete fieldErrors.value[field.name]; success.value = ''; }
function failure(cause: unknown) {
 error.value = cause instanceof ApiError && cause.status === 409 ? 'instanceSettings.conflict' : cause instanceof ApiError && cause.status === 422 ? 'connectionSettings.invalid' : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && cause.status === 422) {
  for (const field of fields.value) if (cause.fields[`values.${field.name}`]?.length) fieldErrors.value[field.name] = `connectionSettings.validation.${field.locked ? 'locked' : ['url', 'port', 'email', 'interval'].includes(field.type) ? field.type : 'value'}`;
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
}
function fill(result: { version: number; fields: ConnectionField[] }) {
 fields.value = result.fields; version.value = result.version; clearedSecrets.clear(); fieldErrors.value = {};
 for (const name of Object.keys(values)) { delete values[name]; delete original[name]; }
 for (const field of result.fields) original[field.name] = values[field.name] = field.type === 'secret' ? '' : field.value ?? '';
}
async function load() {
 const current = ++sequence; loading.value = true; error.value = ''; success.value = '';
 try { const result = await connectionSettingsApi.get(props.group); if (current === sequence) fill(result); }
 catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) loading.value = false; }
}
function changes() {
 return Object.fromEntries(changedFields.value.map(field => [field.name, normalized(values[field.name], field)]));
}
async function save() {
 if (busy.value || loading.value || !isDirty.value) return;
 const group = props.group; const current = sequence; busy.value = true; error.value = ''; success.value = ''; fieldErrors.value = {};
 const changed = changes();
 try { const result = await connectionSettingsApi.save(group, version.value, changed); if (current === sequence) { fill(result); success.value = 'connectionSettings.saved'; } }
 catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) busy.value = false; }
}
async function check() {
 if (busy.value || loading.value || isDirty.value || !props.canManage) return;
 const current = sequence; busy.value = true; error.value = ''; success.value = '';
 try { const result = await connectionSettingsApi.check(props.group, locale.value); if (current === sequence) success.value = result.message; }
 catch (cause) { if (current === sequence) { failure(cause); if (cause instanceof ApiError && ['connectionSettings.webhookBlocked', 'connectionSettings.checkFailed', 'connectionSettings.notConfigured', 'connectionSettings.pushSubscriptionRequired'].includes(cause.code)) error.value = cause.code; } }
 finally { if (current === sequence) busy.value = false; }
}
const hasConflict = computed(() => error.value === 'instanceSettings.conflict');
const autoSave = useAutoSave(() => JSON.stringify(changes()), computed(() => isDirty.value && !hasConflict.value && !busy.value && !loading.value && !editingSecret.value), async () => {
 await save();
 return !isDirty.value;
});
defineExpose({ reload: load, loading, busy, flush: () => autoSave.flush() });
watch(() => props.group, () => { busy.value = false; void load(); }, { immediate: true });
onUnmounted(() => { sequence++; emit('stateChange', { dirty: false, busy: false }); });
</script>
<template><section class="connection-settings" :aria-label="t(`connectionSettings.groups.${group}`)"><header class="settings-section-heading"><h3>{{ t(`connectionSettings.groups.${group}`) }}</h3><p>{{ t(group === 'authentication' ? 'connectionSettings.authenticationHelp' : group === 'storage' ? 'connectionSettings.storageHelp' : 'connectionSettings.help') }}</p></header>
  <Message v-if="error" type="error" closable>{{ t(error) }} <Button v-if="autoSave.failed.value && error === 'auth.errors.requestFailed'" text @click="autoSave.flush(true)">{{ t('actions.retry') }}</Button></Message><Message v-if="success" type="success" closable>{{ t(success) }}</Message>
  <p v-if="loading" role="status">{{ t('instanceSettings.loading') }}</p>
  <form v-else-if="version" class="dialog-form connection-form" novalidate @submit.prevent="autoSave.flush()"><div v-for="field in fields" :key="field.name" class="field"><label :for="`connection-${field.name}`"><span>{{ t(`connectionSettings.fields.${field.name}`) }}</span><span v-if="field.locked" class="environment-tag"><AppIcon name="shield" />{{ t('connectionSettings.environment') }}</span></label>
    <select v-if="field.type === 'boolean' || field.type === 'select'" :id="`connection-${field.name}`" v-model="values[field.name]" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="field.locked || !canManage || busy" :aria-invalid="!!fieldErrors[field.name]" :aria-describedby="fieldErrors[field.name] ? `connection-error-${field.name}` : undefined" @change="change(field, values[field.name] ?? '')"><option value="">{{ t('connectionSettings.default') }}</option>
     <template v-if="field.type === 'boolean'"><option :value="true">{{ t('connectionSettings.yes') }}</option><option :value="false">{{ t('connectionSettings.no') }}</option></template>
     <template v-else><option v-for="option in field.options" :key="option" :value="option">{{ field.name === 'DOCUMENTS_DISK' ? t(`connectionSettings.disks.${option}`) : option }}</option></template></select>
    <InputText v-else :value="String(values[field.name] ?? '')" @update:value="change(field, $event ?? '')" @focus="editingSecret = field.type === 'secret'" @blur="editingSecret = false" :disabled="field.locked || !canManage || busy" :placeholder="field.type === 'secret' && field.hasValue ? t('connectionSettings.secretSaved') : undefined" :min="field.min" :max="field.max" :step="field.step" :maxlength="field.type === 'secret' ? 8192 : field.type === 'url' || field.name === 'ALERT_WEBHOOK_ALLOWED_INTERNAL' ? 2048 : 255" :input-props="{ 'id': `connection-${field.name}`, type: field.type === 'secret' ? 'password' : ['port', 'interval'].includes(field.type) ? 'number' : 'text', 'aria-invalid': !!fieldErrors[field.name], 'aria-describedby': fieldErrors[field.name] ? `connection-error-${field.name}` : undefined, 'autocomplete': 'off' }"></InputText>
    <small v-if="field.type === 'interval'">{{ t('connectionSettings.intervalHelp') }}</small>
    <small v-if="field.type === 'secret' && !field.locked && field.hasValue">{{ t('connectionSettings.keepSecret') }} <button type="button" class="clear-secret" :disabled="busy" @click="clearSecret(field)"><AppIcon name="trash" class="button-icon" />{{ t('connectionSettings.clearSecret') }}</button></small>
    <small v-if="fieldErrors[field.name]" :id="`connection-error-${field.name}`" class="field-error">{{ t(fieldErrors[field.name]!) }}</small></div>
   <p v-if="isDirty && !hasConflict && !autoSave.failed.value" class="dialog-wide draft-status" role="status">{{ t(editingSecret ? 'connectionSettings.secretSaveOnBlur' : 'actions.saving') }}</p>
   <p v-if="group !== 'authentication'" class="dialog-wide check-help">{{ t(['mail', 'webhook', 'push'].includes(group) ? 'connectionSettings.notificationCheckHelp' : 'connectionSettings.checkHelp') }}</p>
   <div v-if="canManage" class="connection-actions dialog-wide"><Button v-if="group !== 'authentication'" attr-type="button" type="default" :aria-label="t('connectionSettings.check')" :loading="busy" :disabled="busy || !canManage || isDirty" @click="check"><template #icon><AppIcon name="diagnostics" /></template><span>{{ t('actions.test') }}</span></Button></div></form></section></template>
<style scoped>
.connection-settings > p { color: var(--theme-muted); font-size: .82rem; line-height: 1.6; }
label { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); flex-wrap: wrap; }
.environment-tag { display: inline-flex; gap: var(--space-1); align-items: center; font-size: .65rem; color: var(--theme-muted); }
.environment-tag svg { width: .8rem; height: .8rem; }
.connection-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; margin-top: var(--space-2); }
small { font-size: .72rem; color: var(--theme-muted); }
.clear-secret { background: none; border: 0; padding: 0; color: var(--theme-accent); font: inherit; text-decoration: underline; cursor: pointer; }
.field-error { color: var(--theme-danger, #b42318); font-size: .75rem; }
.draft-status { margin: 0; color: var(--theme-muted); font-size: .75rem; }
</style>
