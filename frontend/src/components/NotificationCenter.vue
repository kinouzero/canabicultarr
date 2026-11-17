<script setup lang="ts">
import AppSwitch from './AppSwitch.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useAutoSave } from '../lib/auto-save';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { useFieldErrors } from '../lib/field-errors';
import PageTitle from './PageTitle.vue';
import ResourceCard from './ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import ListActionButton from './ListActionButton.vue';
import AppIcon from './AppIcon.vue';
import { computed, watch, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import Message from './AppMessage.vue';
import { ApiError } from '../api/auth';
import { notificationsApi, type NotificationStatus, type NotificationPreferences, type NotificationSettings } from '../api/notifications';
import en from '../locales/en/notifications.json';
import fr from '../locales/fr/notifications.json';
const props = defineProps<{ embedded?: boolean; nested?: boolean; hideHeading?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError]; stateChange: [state: { dirty: boolean; busy: boolean }] }>();
const { t, te, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { notifications: en }); mergeLocaleMessage('fr', { notifications: fr });
const channelIcons = { email: 'mail', push: 'notification', webhook: 'webhook' } as const;
const status = ref<NotificationStatus | null>(null);
const busy = ref(false); const error = ref(''); const notice = ref('');
const settings = reactive<NotificationSettings>({ email_enabled: false, push_enabled: false, locale: 'en', version: 0, webhook_enabled: false, include_recovery: true, minimum_severity: 'info' });
let sequence = 0; let alive = true;
const settingsBaseline = ref<NotificationSettings | null>(null);
const pending = ref(false);
function snapshot(value: NotificationPreferences) { return JSON.stringify(Object.entries(value).filter(([field]) => field !== 'version').sort(([a], [b]) => a.localeCompare(b))); }
const settingsDirty = computed(() => !!status.value?.canManage && !!settingsBaseline.value && snapshot(settings) !== snapshot(settingsBaseline.value));
const dirty = computed(() => settingsDirty.value || pending.value);
const unsaved = useUnsavedChanges(dirty, pending, { guardRoute: !props.nested, flush: () => autoSave.flush() });
watch([dirty, pending], () => emit('stateChange', { dirty: dirty.value, busy: pending.value }), { immediate: true, flush: 'sync' });
const settingsValidation = useFieldErrors(() => ({
 email_enabled: { id: 'notification-global-email', message: 'notifications.validation.channelInvalid' },
 push_enabled: { id: 'notification-global-push', message: 'notifications.validation.channelInvalid' },
 webhook_enabled: { id: 'notification-global-webhook', message: 'notifications.validation.channelInvalid' },
 include_recovery: { id: 'notification-recovery', message: 'notifications.validation.recoveryInvalid' },
 minimum_severity: { id: 'notification-severity', message: 'notifications.validation.severityInvalid' },
 locale: { id: 'notification-webhook-locale', message: 'notifications.validation.localeInvalid' },
}), () => ({ ...settings }));
const settingsFields = settingsValidation.fields;
watch(() => snapshot(settings), () => { notice.value = ''; }, { flush: 'sync' });
function key(code: string) { return /^(auth|notifications)\.errors\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed'; }
function failure(cause: unknown, feedback?: ReturnType<typeof useFieldErrors>) {
  if (!alive) return;
  error.value = cause instanceof ApiError && cause.status === 422 ? 'notifications.errors.inputInvalid' : key(cause instanceof ApiError ? cause.code : cause instanceof Error ? cause.message : 'auth.errors.requestFailed');
  feedback?.capture(cause);
  if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) { status.value = null; settingsBaseline.value = null; emit('accessLost', cause); }
}
async function leave() { if (await unsaved.confirmDiscard()) emit('close'); }
async function load() {
  if (busy.value || !await unsaved.confirmDiscard()) return;
  const current = ++sequence; busy.value = true; error.value = notice.value = '';
  try {
    const configuration = await notificationsApi.status();
    if (current !== sequence) return;
    status.value = configuration; Object.assign(settings, configuration.settings); settingsBaseline.value = { ...configuration.settings };
    settingsValidation.clear();
  } catch (cause) { if (current === sequence) failure(cause); } finally { if (current === sequence) busy.value = false; }
}
async function action(work: () => Promise<void>, feedback?: ReturnType<typeof useFieldErrors>) {
  if (busy.value) return;
  const current = sequence; busy.value = pending.value = true; error.value = notice.value = '';
  try { await work(); if (alive && current === sequence) notice.value = 'notifications.saved'; }
  catch (cause) { if (current === sequence) failure(cause, feedback); }
  finally { if (current === sequence) busy.value = pending.value = false; }
}
function saveSettings() {
 if (busy.value || !status.value?.canManage || !settingsDirty.value) return;
 settingsValidation.clear(); error.value = notice.value = '';
 if (!['info', 'warning', 'critical'].includes(settings.minimum_severity)) settingsValidation.set('minimum_severity');
 if (!['en', 'fr'].includes(settings.locale)) settingsValidation.set('locale');
 if (Object.keys(settingsFields.value).length) { error.value = 'notifications.errors.inputInvalid'; void settingsValidation.focus(); return; }
 return action(async () => { const result = await notificationsApi.updateSettings({ ...settings }); if (!alive) return; status.value = result; Object.assign(settings, result.settings); settingsBaseline.value = { ...result.settings }; }, settingsValidation);
}
const hasConflict = computed(() => error.value === 'notifications.errors.conflict');
const autoSave = useAutoSave(() => snapshot(settings), computed(() => settingsDirty.value && !hasConflict.value && !busy.value && !unsaved.pending.value), async () => {
 await saveSettings();
 return !settingsDirty.value;
});
defineExpose({ reload: load, busy, flush: () => autoSave.flush() });
onMounted(load); onUnmounted(() => { alive = false; sequence++; emit('stateChange', { dirty: false, busy: false }); });
</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <Card class="operational-page notifications-card"><template v-if="!hideHeading" #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="notification">{{ t('notifications.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('notifications.back')" type="default" :disabled="busy" @click="leave" :title="t('notifications.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div class="page-actions"><ListActionButton icon="refresh" :label="t('notifications.reload')" :disabled="busy" @click="load" /></div></div><div class="card-subtitle">{{ t('notifications.description') }}</div></div></template>

    <template #default><Message v-if="error" closable type="error" role="alert">{{ t(error) }} <Button v-if="autoSave.failed.value && error === 'auth.errors.requestFailed'" text @click="autoSave.flush(true)">{{ t('actions.retry') }}</Button></Message>
      <Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>
      <p v-if="busy && !status" class="operational-empty" role="status">{{ t('notifications.loading') }}</p>
      <template v-if="status"><section class="operational-panel" :aria-label="t('notifications.channelsTitle')"><h3>{{ t('notifications.channelsTitle') }}</h3>
          <ul class="channel-list operational-list"><ResourceCard v-for="channel in (['email','push','webhook'] as const)" :key="channel" :icon="channelIcons[channel]" :eyebrow="t(status.channels[channel].configured ? 'notifications.configured' : 'notifications.notConfigured')" :tone="status.channels[channel].configured ? 'success' : 'muted'"><template #title>{{ t(`notifications.channels.${channel}`) }}</template><template #badge><Tag :type="status.settings[`${channel}_enabled`] ? 'success' : 'default'">{{ t(status.settings[`${channel}_enabled`] ? 'notifications.enabled' : 'notifications.disabled') }}</Tag></template></ResourceCard></ul>
          <p>{{ t('notifications.defaultHelp') }}</p>
          <p>{{ t('notifications.webhookFormat', { format: status.channels.webhook.format }) }}</p></section>
        <section v-if="status.canManage" class="operational-panel" :aria-label="t('notifications.settingsTitle')"><h3>{{ t('notifications.settingsTitle') }}</h3><p>{{ t('notifications.settingsHelp') }}</p>
          <form class="notification-settings-form" @submit.prevent="autoSave.flush()" novalidate><div v-for="channel in (['email','push','webhook'] as const)" :key="channel" class="field"><AppSwitch class="check" :id="`notification-global-${channel}`" v-bind="settingsValidation.attrs(`${channel}_enabled`)" v-model="settings[`${channel}_enabled`]" :disabled="busy || (!status.channels[channel].configured && !settings[`${channel}_enabled`])">{{ t('notifications.enableChannel', { channel: t(`notifications.channels.${channel}`) }) }}</AppSwitch><small v-if="settingsFields[`${channel}_enabled`]" :id="`notification-global-${channel}-error`" class="field-error" role="alert">{{ t(settingsFields[`${channel}_enabled`]) }}</small></div>
            <div class="field"><AppSwitch class="check" id="notification-recovery" v-bind="settingsValidation.attrs('include_recovery')" v-model="settings.include_recovery" :disabled="busy">{{ t('notifications.includeRecovery') }}</AppSwitch><small v-if="settingsFields.include_recovery" id="notification-recovery-error" class="field-error" role="alert">{{ t(settingsFields.include_recovery) }}</small></div>
            <div class="field"><label for="notification-severity">{{ t('notifications.minimumSeverity') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="notification-severity" v-bind="settingsValidation.attrs('minimum_severity')" v-model="settings.minimum_severity" :disabled="busy"><option v-for="value in ['info','warning','critical']" :key="value" :value="value">{{ t(`notifications.severity.${value}`) }}</option></select><small v-if="settingsFields.minimum_severity" id="notification-severity-error" class="field-error" role="alert">{{ t(settingsFields.minimum_severity) }}</small></div>
            <div class="field"><label for="notification-webhook-locale">{{ t('notifications.webhookLocale') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="notification-webhook-locale" v-bind="settingsValidation.attrs('locale')" v-model="settings.locale" :disabled="busy"><option value="en">{{ t('english') }}</option><option value="fr">{{ t('french') }}</option></select><small v-if="settingsFields.locale" id="notification-webhook-locale-error" class="field-error" role="alert">{{ t(settingsFields.locale) }}</small></div>
            <p v-if="settingsDirty && !hasConflict && !autoSave.failed.value" class="autosave-status" role="status">{{ t('actions.saving') }}</p></form></section></template></template></Card></template>

<style scoped>
.check { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-2) 0; font-size: .8rem; line-height: 1.5; }

.notification-settings-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: var(--ui-grid-gap) var(--ui-section-gap); }
.notification-settings-form > .autosave-status { grid-column: 1 / -1; margin: 0; }
@media (max-width: 600px) { .notification-settings-form { grid-template-columns: minmax(0, 1fr); } }
</style>
