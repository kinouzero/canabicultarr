<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { ApiError } from '../../api/auth';
import { mobileDevicesApi } from '../../api/mobileDevices';
import FormDialog from '../../components/FormDialog.vue';

const props = defineProps<{ code: string; email: string }>();
const emit = defineEmits<{ close: []; paired: [] }>();
const { t } = useI18n();
const label = ref('');
const busy = ref(false);
const error = ref('');
const paired = ref(false);

async function confirm() {
  if (busy.value || !label.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    await mobileDevicesApi.redeem(props.code, label.value.trim());
    paired.value = true;
    emit('paired');
  } catch (cause) {
    error.value = cause instanceof ApiError && cause.code === 'mobilePairing.invalidCode' ? cause.code : 'mobilePairing.requestFailed';
  } finally { busy.value = false; }
}
</script>

<template>
  <FormDialog :visible="true" icon="device" size="compact" :title="t('mobilePairing.confirm')" :busy="busy"
    :error="error ? t(error) : ''" :close-label="t('actions.cancel')" @update:visible="!$event && emit('close')">
    <p v-if="paired" role="status">{{ t('mobilePairing.success') }}</p>
    <form v-else id="mobile-pairing-confirmation" class="dialog-form" @submit.prevent="confirm">
      <p>{{ t('mobilePairing.confirmHelp', { email }) }}</p>
      <div class="field">
        <label for="mobile-device-label">{{ t('mobilePairing.label') }}</label>
        <input id="mobile-device-label" v-model="label" required maxlength="80" autocomplete="off" :disabled="busy">
      </div>
    </form>
    <template #footer>
      <Button v-if="!paired" type="primary" form="mobile-pairing-confirmation" attr-type="submit" :loading="busy" :disabled="!label.trim()">
        {{ t('mobilePairing.confirm') }}
      </Button>
      <Button v-else @click="emit('close')">{{ t('mobilePairing.close') }}</Button>
    </template>
  </FormDialog>
</template>
