<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import FormDialog from '../../components/FormDialog.vue';
import AppIcon from '../../components/AppIcon.vue';
import { oidcProviderName } from '../../lib/branding';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { accountSecurityApi, type AccountSecurityStatus, type SecurityResult } from '../../api/accountSecurity';
import en from '../../locales/en/account-security.json';
import fr from '../../locales/fr/account-security.json';
import { NInput as Password } from 'naive-ui';
defineProps<{ oidcLinked: boolean }>();
const emit = defineEmits<{ secured: [result: SecurityResult]; accessLost: [cause: ApiError] }>();
const { t, te, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { accountSecurity: en }); mergeLocaleMessage('fr', { accountSecurity: fr });
const status = ref<AccountSecurityStatus | null>(null);
const busy = ref(false); const loading = ref(false); const error = ref('');
const fields = ref<Record<string, string>>({});
const activeForm = ref<'password' | 'revoke' | null>(null);
const password = reactive({ current_password: '', password: '', password_confirmation: '' });
const revokePassword = ref('');
const dialogId = useId();
function open(kind: 'password' | 'revoke') { clearSecrets(); error.value = ''; fields.value = {}; activeForm.value = kind; }
function close() { if (busy.value) return; clearSecrets(); activeForm.value = null; error.value = ''; fields.value = {}; }
let sequence = 0;
function clearSecrets() { password.current_password = password.password = password.password_confirmation = revokePassword.value = ''; }
function key(value: string) { return /^(auth\.(errors|validation)|accountSecurity\.errors)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function failure(cause: unknown) {
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed'); fields.value = {};
  if (cause instanceof ApiError) {
    for (const [field, messages] of Object.entries(cause.fields)) if (['current_password','password','password_confirmation'].includes(field) && messages[0]) fields.value[field] = key(messages[0]);
    if (cause.status === 401 || cause.status === 403) { status.value = null; clearSecrets(); emit('accessLost', cause); }
  }
}
async function load() {
  activeForm.value = null; const current = ++sequence; loading.value = true; status.value = null; error.value = ''; fields.value = {}; clearSecrets();
  try { const result = await accountSecurityApi.status(); if (current === sequence) status.value = result; }
  catch (cause) { if (current === sequence) failure(cause); }
  finally { if (current === sequence) loading.value = false; }
}
async function submit(kind: 'password' | 'revoke') {
  if (busy.value || loading.value || !status.value?.localPasswordAvailable) return;
  const current = sequence; busy.value = true; error.value = ''; fields.value = {}; activeForm.value = kind;
  try {
    if (kind === 'password') await accountSecurityApi.changePassword({ ...password });
    else await accountSecurityApi.revokeSessions(revokePassword.value);
    if (current === sequence) { clearSecrets(); emit('secured', kind === 'password' ? 'passwordChanged' : 'sessionsRevoked'); }
  } catch (cause) { if (current === sequence) failure(cause); }
  finally { clearSecrets(); if (current === sequence) busy.value = false; }
}
defineExpose({ reload: load, loading, busy });
onMounted(load); onUnmounted(() => { sequence++; clearSecrets(); });
</script>

<template><section class="security-card"><p class="section-help">{{ t('accountSecurity.description') }}</p>
  <Message v-if="error && !activeForm" closable type="error" role="alert">{{ t(error) }}</Message>
  <p v-if="loading" role="status">{{ t('auth.loading') }}</p>
  <template v-if="status"><section class="security-summary account-section" :aria-label="t('accountSecurity.sessionsTitle')"><span class="security-symbol"><AppIcon name="device" /></span><div><h3>{{ t('accountSecurity.sessionsTitle') }}</h3><p v-if="status.otherSessions !== null" data-testid="other-session-count">{{ t('accountSecurity.otherSessions', { count: status.otherSessions }) }}</p><p v-else>{{ t('accountSecurity.sessionCountUnavailable') }}</p><small>{{ t('accountSecurity.sessionCountHelp') }}</small></div></section>
   <p v-if="oidcLinked" class="provider-note"><AppIcon name="check" />{{ t('accountSecurity.oidcHelp', { provider: oidcProviderName }) }}</p>
   <Message v-if="!status.localPasswordAvailable" closable type="warning">{{ t('accountSecurity.errors.localPasswordUnavailable') }}</Message>
   <div class="security-actions"><section class="account-section"><h3><AppIcon name="shield" />{{ t('accountSecurity.passwordTitle') }}</h3><p class="section-help">{{ t('auth.passwordHelp') }}</p><Button attr-type="button" type="default" :disabled="busy || !status.localPasswordAvailable" @click="open('password')"><template #icon><AppIcon name="edit" /></template><span>{{ t('accountSecurity.managePassword') }}</span></Button></section><section class="account-section"><h3><AppIcon name="device" />{{ t('accountSecurity.revokeTitle') }}</h3><p class="section-help">{{ t('accountSecurity.revokeHelp') }}</p><Button attr-type="button" type="default" :disabled="busy || !status.localPasswordAvailable" @click="open('revoke')"><template #icon><AppIcon name="logout" /></template><span>{{ t('accountSecurity.manageSessions') }}</span></Button></section></div></template>
  <FormDialog v-if="activeForm && status" :visible="true" icon="shield" size="compact" :title="t(activeForm === 'password' ? 'accountSecurity.passwordTitle' : 'accountSecurity.revokeTitle')" :close-label="t('accountSecurity.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && close()"><Message closable type="warning">{{ t('accountSecurity.signOutHelp') }}</Message>
   <form :id="`${dialogId}-security`" class="dialog-form" novalidate @submit.prevent="submit(activeForm!)"><template v-if="activeForm === 'password'"><div class="field"><label for="security-current-password">{{ t('accountSecurity.currentPassword') }}</label><Password placeholder="" v-model:value="password.current_password" :disabled="busy || !status.localPasswordAvailable" :status="activeForm === 'password' && !!fields.current_password ? 'error' : undefined" type="password" :input-props="{ 'id': 'security-current-password', 'autocomplete': 'current-password', 'aria-describedby': 'security-current-error' }"></Password><small v-if="activeForm === 'password' && fields.current_password" id="security-current-error" class="field-error">{{ t(fields.current_password) }}</small></div>
            <div class="field"><label for="security-new-password">{{ t('accountSecurity.newPassword') }}</label><Password placeholder="" v-model:value="password.password" show-password-on="click" :disabled="busy || !status.localPasswordAvailable" :status="activeForm === 'password' && !!fields.password ? 'error' : undefined" type="password" :input-props="{ 'id': 'security-new-password', 'autocomplete': 'new-password', 'aria-describedby': 'security-password-help security-password-error' }"></Password><small id="security-password-help">{{ t('auth.passwordHelp') }}</small><small v-if="activeForm === 'password' && fields.password" id="security-password-error" class="field-error">{{ t(fields.password) }}</small></div>
            <div class="field"><label for="security-confirm-password">{{ t('accountSecurity.confirmPassword') }}</label><Password placeholder="" v-model:value="password.password_confirmation" :disabled="busy || !status.localPasswordAvailable" type="password" :input-props="{ 'id': 'security-confirm-password', 'autocomplete': 'new-password' }"></Password></div></template><template v-else><div class="field"><label for="security-revoke-password">{{ t('accountSecurity.currentPassword') }}</label><Password placeholder="" v-model:value="revokePassword" :disabled="busy || !status.localPasswordAvailable" :status="activeForm === 'revoke' && !!fields.current_password ? 'error' : undefined" type="password" :input-props="{ 'id': 'security-revoke-password', 'autocomplete': 'current-password', 'aria-describedby': 'security-revoke-error' }"></Password><small v-if="activeForm === 'revoke' && fields.current_password" id="security-revoke-error" class="field-error">{{ t(fields.current_password) }}</small></div></template></form>
   <template #footer><div class="dialog-actions"><Button attr-type="button" :aria-label="t('accountSecurity.cancel')" type="default" :disabled="busy" @click="close"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button attr-type="submit" :form="`${dialogId}-security`" :loading="busy" type="primary"><template #icon><AppIcon :name="activeForm === 'password' ? 'shield' : 'logout'" /></template><span>{{ t(activeForm === 'password' ? 'accountSecurity.changePassword' : 'accountSecurity.revokeSessions') }}</span></Button></div></template></FormDialog></section></template>
<style scoped>
.security-card { display: grid; gap: var(--space-3); min-width: 0; }.account-section { padding: 0; min-width: 0; }.account-section h3 { display: flex; align-items: center; gap: var(--space-2); font-size: .87rem; margin: 0 0 var(--space-2); }.account-section h3 svg { width: 1.05rem; height: 1.05rem; }.section-help, small { color: var(--theme-muted); font-size: .76rem; line-height: 1.6; }.section-help { margin: 0 0 var(--space-2); }.security-summary { display: flex; align-items: center; gap: var(--ui-grid-gap); padding-bottom: var(--space-5); border-bottom: 1px solid var(--theme-border); }.security-summary p { margin: var(--space-1) 0; font-size: .87rem; }.security-symbol { display: grid; place-items: center; width: 2.8rem; height: 2.8rem; border-radius: .8rem; background: var(--theme-surface); flex-shrink: 0; }.security-symbol svg { width: 1.4rem; height: 1.4rem; }.security-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ui-grid-gap); }.security-actions .account-section { display: flex; flex-direction: column; align-items: start; gap: var(--space-1-5); }.security-actions .section-help { flex: 1; }.provider-note { display: flex; gap: var(--space-2); font-size: .76rem; line-height: 1.6; color: var(--theme-muted); }.provider-note svg { width: 1rem; height: 1rem; flex-shrink: 0; }p, small, h3 { overflow-wrap: anywhere; }.field-error { color: var(--theme-danger); }
@media(max-width:700px) { .security-actions { grid-template-columns: minmax(0, 1fr); } }
</style>
