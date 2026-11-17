<script setup lang="ts">
import AppSwitch from '../../components/AppSwitch.vue';
import { computed, watch, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useAutoSave } from '../../lib/auto-save';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { mergeNotificationPreferences } from '../../lib/notification-preferences';
import { formatDateTime } from '../../lib/date-time';
import { ApiError } from '../../api/auth';
import { notificationsApi, type NotificationStatus, type NotificationPreferences, type BrowserSubscription } from '../../api/notifications';
import { browserPushSupported, localBrowserSubscription, subscribeBrowser } from '../../lib/browserNotifications';
import en from '../../locales/en/notifications.json';
import fr from '../../locales/fr/notifications.json';
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { notifications: en }); mergeLocaleMessage('fr', { notifications: fr });
const status = ref<NotificationStatus | null>(null);
const devices = ref<BrowserSubscription[]>([]);
const busy = ref(false); const pending = ref(false); const error = ref(''); const notice = ref('');
const local = ref(false); const supported = browserPushSupported();
const preference = reactive<NotificationPreferences>({ email_enabled: false, push_enabled: false, locale: 'en', version: 0 });
const preferenceBaseline = ref<NotificationPreferences | null>(null);
let alive = true; let sequence = 0;
function snapshot(value: NotificationPreferences) { return JSON.stringify(Object.entries(value).filter(([field]) => field !== 'version').sort(([a], [b]) => a.localeCompare(b))); }
const preferenceDirty = computed(() => !!status.value && !!preferenceBaseline.value && snapshot(preference) !== snapshot(preferenceBaseline.value));
const dirty = computed(() => preferenceDirty.value || pending.value);
const unsaved = useUnsavedChanges(dirty, pending, { flush: () => autoSave.flush() });
const preferenceValidation = useFieldErrors(() => ({
 email_enabled: { id: 'notification-email', message: 'notifications.validation.channelInvalid' },
 push_enabled: { id: 'notification-push', message: 'notifications.validation.channelInvalid' },
 locale: { id: 'notification-locale', message: 'notifications.validation.localeInvalid' },
}), () => ({ ...preference }));
const preferenceFields = preferenceValidation.fields;
watch(() => snapshot(preference), () => { notice.value = ''; }, { flush: 'sync' });
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('notifications.noDate'); }
function key(code: string) { return /^(auth|notifications)\.errors\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed'; }
function failure(cause: unknown, feedback?: ReturnType<typeof useFieldErrors>) {
  if (!alive) return;
  error.value = cause instanceof ApiError && cause.status === 422 ? 'notifications.errors.inputInvalid' : key(cause instanceof ApiError ? cause.code : cause instanceof Error ? cause.message : 'auth.errors.requestFailed');
  feedback?.capture(cause);
  if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) { status.value = null; devices.value = []; preferenceBaseline.value = null; emit('accessLost', cause); }
}
function applyPreferences(result: NotificationPreferences, retainDraft = false) {
 const next = retainDraft && preferenceBaseline.value ? mergeNotificationPreferences(preference, preferenceBaseline.value, result) : result;
 preferenceBaseline.value = { ...result }; Object.assign(preference, next);
}
async function load() {
 if (busy.value || !await unsaved.confirmDiscard()) return;
 const current = ++sequence; busy.value = true; error.value = notice.value = '';
 try {
  const [configuration, preferences, subscriptions, browser] = await Promise.all([notificationsApi.status(), notificationsApi.preferences(), notificationsApi.subscriptions(), localBrowserSubscription()]);
  if (!alive || current !== sequence) return;
  status.value = configuration; applyPreferences(preferences); devices.value = subscriptions; local.value = Boolean(browser); preferenceValidation.clear();
 } catch (cause) { if (alive && current === sequence) failure(cause); }
 finally { if (alive && current === sequence) busy.value = false; }
}
async function action(work: () => Promise<void>, feedback?: ReturnType<typeof useFieldErrors>) {
  if (busy.value) return;
  const current = sequence; busy.value = pending.value = true; error.value = notice.value = '';
  try { await work(); if (alive && current === sequence) notice.value = 'notifications.saved'; }
  catch (cause) { if (current === sequence) failure(cause, feedback); }
  finally { if (current === sequence) busy.value = pending.value = false; }
}
function savePreferences() {
 if (busy.value || !status.value || !preferenceDirty.value) return;
 preferenceValidation.clear(); error.value = notice.value = '';
 if (!['en', 'fr'].includes(preference.locale)) preferenceValidation.set('locale');
 if (Object.keys(preferenceFields.value).length) { error.value = 'notifications.errors.inputInvalid'; void preferenceValidation.focus(); return; }
 return action(async () => { const result = await notificationsApi.updatePreferences({ ...preference }); if (alive) applyPreferences(result); }, preferenceValidation);
}
async function enableBrowser() {
  // This call starts permission work synchronously from the button's click.
  if (busy.value || !status.value?.channels.push.publicKey) return;
  const keyValue = status.value.channels.push.publicKey;
  await action(async () => {
    const browser = await subscribeBrowser(keyValue);
    try { const result = await notificationsApi.subscribe(browser.subscription.toJSON()); if (!alive) return; applyPreferences(result.preferences, true); local.value = true; }
    catch (cause) { if (browser.created) await browser.subscription.unsubscribe().catch(() => false); throw cause; }
    const subscriptions = await notificationsApi.subscriptions(); if (alive) devices.value = subscriptions;
  });
}
function disableBrowser() { return action(async () => { const browser = await localBrowserSubscription(); if (browser) { await notificationsApi.removeBrowser(browser.endpoint); await browser.unsubscribe(); } if (!alive) return; local.value = false; const subscriptions = await notificationsApi.subscriptions(); if (alive) devices.value = subscriptions; }); }
function removeDevice(id: string) { return action(async () => { await notificationsApi.remove(id); if (!alive) return; const subscriptions = await notificationsApi.subscriptions(); if (alive) devices.value = subscriptions; }); }
const hasConflict = computed(() => error.value === 'notifications.errors.conflict');
const autoSave = useAutoSave(() => snapshot(preference), computed(() => preferenceDirty.value && !hasConflict.value && !busy.value && !unsaved.pending.value), async () => {
 await savePreferences();
 return !preferenceDirty.value;
});
defineExpose({ reload: load, busy, flush: () => autoSave.flush() });
onMounted(load); onUnmounted(() => { alive = false; sequence++; });
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <section class="notification-preferences" data-testid="notification-preferences"><p class="section-help">{{ t('notifications.personalHelp') }}</p>
   <Message v-if="error" closable type="error" role="alert">{{ t(error) }} <Button v-if="autoSave.failed.value && error === 'auth.errors.requestFailed'" text @click="autoSave.flush(true)">{{ t('actions.retry') }}</Button></Message>
   <Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>
   <p v-if="busy && !status" role="status">{{ t('notifications.loading') }}</p>
   <template v-if="status"><div class="notification-columns"><section class="account-section" :aria-label="t('notifications.preferencesTitle')"><h3><AppIcon name="mail" />{{ t('notifications.preferencesTitle') }}</h3>
          <form @submit.prevent="autoSave.flush()" novalidate><div class="field"><AppSwitch class="check" id="notification-email" v-bind="preferenceValidation.attrs('email_enabled')" v-model="preference.email_enabled" :disabled="busy || (!status.channels.email.configured && !preference.email_enabled)">{{ t('notifications.emailOptIn') }}</AppSwitch><small v-if="preferenceFields.email_enabled" id="notification-email-error" class="field-error" role="alert">{{ t(preferenceFields.email_enabled) }}</small></div>
            <div class="field"><AppSwitch class="check" id="notification-push" v-bind="preferenceValidation.attrs('push_enabled')" v-model="preference.push_enabled" :disabled="busy || (!status.channels.push.configured && !preference.push_enabled)">{{ t('notifications.pushOptIn') }}</AppSwitch><small v-if="preferenceFields.push_enabled" id="notification-push-error" class="field-error" role="alert">{{ t(preferenceFields.push_enabled) }}</small></div>
            <div class="field"><label for="notification-locale">{{ t('notifications.personalLocale') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="notification-locale" v-bind="preferenceValidation.attrs('locale')" v-model="preference.locale" :disabled="busy"><option value="en">{{ t('english') }}</option><option value="fr">{{ t('french') }}</option></select><small v-if="preferenceFields.locale" id="notification-locale-error" class="field-error" role="alert">{{ t(preferenceFields.locale) }}</small></div>
            <p v-if="preferenceDirty && !hasConflict && !autoSave.failed.value" class="autosave-status" role="status">{{ t('actions.saving') }}</p></form></section>
        <section class="account-section" :aria-label="t('notifications.browserTitle')"><h3><AppIcon name="device" />{{ t('notifications.browserTitle') }}</h3><p>{{ t('notifications.pushPrivacy') }}</p>
          <p v-if="!supported">{{ t('notifications.browserHelp') }}</p>
          <div class="heading"><Button :disabled="busy || !supported || !status.channels.push.configured || !status.settings.push_enabled" @click="enableBrowser" type="primary"><template #icon><AppIcon name="bell" /></template><span>{{ t('notifications.enableBrowser') }}</span></Button><Button v-if="local" type="default" :disabled="busy" @click="disableBrowser"><template #icon><AppIcon name="unlink" /></template><span>{{ t('notifications.disableBrowser') }}</span></Button></div>
          <p v-if="!devices.length">{{ t('notifications.noSubscriptions') }}</p>
          <ul v-else class="device-list"><li v-for="(device,index) in devices" :key="device.id"><p>{{ t('notifications.subscription', { number: index + 1, date: date(device.createdAt) }) }}</p><p v-if="!device.currentKey || !device.accountCurrent">{{ t('notifications.oldKey') }}</p><ListActionButton icon="trash" :label="t('notifications.removeSubscription', { number: index + 1 })" :disabled="busy" @click="removeDevice(device.id)" /></li></ul></section></div></template></section></template>
<style scoped>
.notification-columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); gap: var(--space-3); margin-top: var(--space-2); }
.check { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-2) 0; font-size: .8rem; line-height: 1.5; }

.device-list { list-style: none; padding: 0; }
.device-list li { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-2); padding: var(--space-3) 0; border-top: 1px solid var(--theme-border); }
.device-list p { margin: 0; font-size: .78rem; line-height: 1.6; overflow-wrap: anywhere; }
form { max-width: 42rem; }
</style>
