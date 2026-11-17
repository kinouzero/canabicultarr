<script setup lang="ts">
import AppIcon from '../../components/AppIcon.vue';
import PageTitle from '../../components/PageTitle.vue';
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { passwordRecoveryApi } from '../../api/passwordRecovery';
import en from '../../locales/en/password-recovery.json';
import fr from '../../locales/fr/password-recovery.json';
import { NInput as Password } from 'naive-ui';
const props = defineProps<{ token: string }>();
const emit = defineEmits<{ close: []; completed: [email: string]; discardToken: [] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { passwordRecovery: en }); mergeLocaleMessage('fr', { passwordRecovery: fr });
let token = props.token;
const resetting = ref(!!token); const loading = ref(true); const enabled = ref(false); const busy = ref(false); const sent = ref(false);
const error = ref(''); const fields = ref<Record<string, string>>({});
const form = reactive({ email: '', password: '', password_confirmation: '' });
let active = true;
function clearSecrets() { form.password = form.password_confirmation = ''; }
function key(value: string) { return /^(auth\.(errors|validation)|passwordRecovery)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function failure(cause: unknown) {
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed'); fields.value = {};
  if (cause instanceof ApiError) for (const [field, messages] of Object.entries(cause.fields)) if (['email','password','token'].includes(field) && messages[0]) fields.value[field] = key(messages[0]);
}
async function submit() {
  if (busy.value || !enabled.value) return;
  busy.value = true; error.value = ''; fields.value = {}; sent.value = false;
  try {
    if (resetting.value) {
      await passwordRecoveryApi.reset({ ...form, token });
      token = ''; emit('discardToken');
      if (active) emit('completed', form.email.trim());
    } else {
      await passwordRecoveryApi.request(form.email, locale.value === 'fr' ? 'fr' : 'en');
      if (active) sent.value = true;
    }
  } catch (cause) { if (active) failure(cause); }
  finally { clearSecrets(); if (active) busy.value = false; }
}
function requestAnother() { token = ''; emit('discardToken'); resetting.value = false; error.value = ''; fields.value = {}; clearSecrets(); }
onMounted(async () => {
  emit('discardToken');
  try { const result = await passwordRecoveryApi.status(); if (active) enabled.value = result.enabled; }
  catch (cause) { if (active) failure(cause); }
  finally { if (active) loading.value = false; }
});
onUnmounted(() => { active = false; token = ''; clearSecrets(); });
</script>

<template><Card class="auth-card"><template #header><div class="card-heading-block"><PageTitle icon="shield">{{ t(resetting ? 'passwordRecovery.resetTitle' : 'passwordRecovery.requestTitle') }}</PageTitle><div class="card-subtitle"><p>{{ t('passwordRecovery.description') }}</p></div></div></template>

    <template #default><p v-if="loading" role="status">{{ t('passwordRecovery.loading') }}</p>
      <Message v-if="!loading && !enabled" closable type="info">{{ t('passwordRecovery.unavailable') }}</Message>
      <Message v-if="error" closable type="error" role="alert">{{ t(error) }}</Message>
      <Message v-if="sent" closable type="success" role="status">{{ t('passwordRecovery.sent') }}</Message>
      <form v-if="!loading && enabled" @submit.prevent="submit" novalidate><div class="field"><label for="recovery-email">{{ t('auth.email') }}</label>
          <InputText placeholder="" v-model:value="form.email" maxlength="254" :disabled="busy" :status="!!fields.email ? 'error' : undefined" :input-props="{ 'id': 'recovery-email', type: 'email', 'autocomplete': 'username', 'aria-describedby': 'recovery-email-help recovery-email-error' }"></InputText>
          <small id="recovery-email-help" v-if="resetting">{{ t('passwordRecovery.emailHelp') }}</small>
          <small id="recovery-email-error" v-if="fields.email" class="field-error">{{ t(fields.email) }}</small></div>
        <template v-if="resetting"><Message closable type="info">{{ t('passwordRecovery.effects') }}</Message>
          <small v-if="fields.token" class="field-error">{{ t(fields.token) }}</small>
          <div class="field"><label for="recovery-password">{{ t('auth.password') }}</label>
            <Password placeholder="" v-model:value="form.password" :disabled="busy" :status="!!fields.password ? 'error' : undefined" type="password" :input-props="{ 'id': 'recovery-password', 'autocomplete': 'new-password', 'aria-describedby': 'recovery-password-help recovery-password-error' }"></Password>
            <small id="recovery-password-help">{{ t('auth.passwordHelp') }}</small>
            <small v-if="fields.password" id="recovery-password-error" class="field-error">{{ t(fields.password) }}</small></div>
          <div class="field"><label for="recovery-confirmation">{{ t('auth.passwordConfirmation') }}</label>
            <Password placeholder="" v-model:value="form.password_confirmation" :disabled="busy" type="password" :input-props="{ 'id': 'recovery-confirmation', 'autocomplete': 'new-password' }"></Password></div></template>
        <Button attr-type="submit" :loading="busy" class="submit-button" type="primary"><template #icon><AppIcon :name="resetting ? 'shield' : 'mail'" /></template><span>{{ t(resetting ? 'passwordRecovery.reset' : 'passwordRecovery.send') }}</span></Button>
        <Button v-if="resetting" type="default" :disabled="busy" class="submit-button" @click="requestAnother"><template #icon><AppIcon name="refresh" /></template><span>{{ t('passwordRecovery.requestAnother') }}</span></Button></form>
      <Button :aria-label="t('passwordRecovery.back')" type="default" :disabled="busy" class="submit-button" @click="emit('close')"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('actions.back') }}</span></Button></template></Card></template>
