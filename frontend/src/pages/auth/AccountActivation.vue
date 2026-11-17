<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NCard as Card } from 'naive-ui';
import { NButton as Button } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import AppIcon from '../../components/AppIcon.vue';
import PageTitle from '../../components/PageTitle.vue';
import { ApiError, authApi } from '../../api/auth';
const props = defineProps<{ token: string; email: string; newlyCreated: boolean; purpose?: 'activation' | 'email-change' }>();
const emit = defineEmits<{ close: []; completed: [email: string] }>();
const { t, te, locale } = useI18n();
let token = props.token;
const email = ref(props.email); const confirming = ref(!!token); const busy = ref(false); const sent = ref(false); const error = ref('');
let active = true;
async function submit() {
  if (busy.value) return;
  busy.value = true; error.value = ''; sent.value = false;
  try {
    if (confirming.value) {
      await (props.purpose === 'email-change' ? authApi.confirmEmailChange : authApi.confirmActivation)(email.value, token);
      token = '';
      if (active) emit('completed', email.value);
    } else {
      await authApi.resendActivation(email.value, locale.value === 'fr' ? 'fr' : 'en');
      if (active) sent.value = true;
    }
  } catch (cause) {
    const key = cause instanceof ApiError ? Object.values(cause.fields)[0]?.[0] ?? cause.code : 'auth.errors.requestFailed';
    if (active) error.value = /^(accountActivation|emailChange|auth\.(errors|validation))\.[A-Za-z]+$/.test(key) && te(key) ? key : 'auth.errors.requestFailed';
  } finally { if (active) busy.value = false; }
}
function requestAnother() { token = ''; confirming.value = false; error.value = ''; }
onUnmounted(() => { active = false; token = ''; });
</script>

<template><Card class="auth-card"><template #header><div class="card-heading-block"><PageTitle icon="mail">{{ t(purpose === 'email-change' ? 'emailChange.title' : 'accountActivation.title') }}</PageTitle><div class="card-subtitle"><p>{{ t(purpose === 'email-change' ? 'emailChange.description' : confirming ? 'accountActivation.description' : 'accountActivation.resendDescription') }}</p></div></div></template>

    <template #default><Message v-if="newlyCreated && !sent" closable type="info">{{ t('accountActivation.checkEmail') }}</Message>
      <Message v-if="sent" closable type="success" role="status">{{ t('accountActivation.sent') }}</Message>
      <Message v-if="error" closable type="error" role="alert">{{ t(error) }}</Message>
      <form @submit.prevent="submit" novalidate><div class="field"><label for="activation-email">{{ t('auth.email') }}</label>
          <InputText placeholder="" v-model:value="email" maxlength="254" :disabled="busy" :input-props="{ 'id': 'activation-email', type: 'email', 'autocomplete': 'email', 'aria-describedby': 'activation-email-help' }"></InputText>
          <small id="activation-email-help">{{ t(purpose === 'email-change' ? 'emailChange.emailHelp' : 'accountActivation.emailHelp') }}</small></div>
        <Button attr-type="submit" :loading="busy" class="submit-button" type="primary"><template #icon><AppIcon name="mail" /></template><span>{{ t(purpose === 'email-change' ? 'emailChange.confirm' : confirming ? 'accountActivation.confirm' : 'accountActivation.resend') }}</span></Button>
        <Button v-if="confirming && purpose !== 'email-change'" type="default" :disabled="busy" class="submit-button" @click="requestAnother"><template #icon><AppIcon name="refresh" /></template><span>{{ t('accountActivation.resend') }}</span></Button></form>
      <Button type="default" :disabled="busy" class="submit-button" @click="emit('close')"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('auth.backToLogin') }}</span></Button></template></Card></template>
