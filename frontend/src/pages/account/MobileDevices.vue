<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { ApiError } from '../../api/auth';
import { mobileDevicesApi, type MobileDevice } from '../../api/mobileDevices';
import AppIcon from '../../components/AppIcon.vue';
import FormDialog from '../../components/FormDialog.vue';
import OfflineSitesSettings from './OfflineSitesSettings.vue';
import { clearOfflineSites } from '../../lib/offline-sites.js';
import MobilePairingConfirmation from './MobilePairingConfirmation.vue';
import Message from '../../components/AppMessage.vue';
import { qrImage, qrSvg } from '../../lib/qr-label';

defineProps<{ email: string }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const secure = window.location.protocol === 'https:' && window.isSecureContext;
const loopback = ['127.0.0.1', 'localhost', '[::1]'].includes(window.location.hostname);
const devices = ref<MobileDevice[]>([]);
const busy = ref(false);
const error = ref('');
const pairing = ref<{ link: string; image: string } | null>(null);
const removal = ref<MobileDevice | null>(null);
const enteringLink = ref(false);
const enteredLink = ref('');
const confirmationCode = ref('');

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try { await action(); }
  catch (cause) {
    error.value = cause instanceof ApiError && cause.code === 'mobilePairing.limit' ? cause.code : 'mobilePairing.requestFailed';
    if (cause instanceof ApiError && cause.status === 401) emit('accessLost', cause);
  } finally { busy.value = false; }
}

function issue() {
  return run(async () => {
    const { code } = await mobileDevicesApi.issue();
    const link = new URL('/', window.location.origin);
    link.hash = `mobile-pairing=${code}`;
    pairing.value = { link: link.href, image: qrImage(qrSvg(link.href)) };
  });
}

function acceptLink() {
  try {
    const link = new URL(enteredLink.value.trim());
    const match = /^#mobile-pairing=([A-Za-z0-9_-]{43})$/.exec(link.hash);
    if (link.origin === window.location.origin && link.pathname === '/' && !link.search && match?.[1]) {
      confirmationCode.value = match[1];
      closeLinkForm();
      return;
    }
  } catch { /* Invalid URLs use the same message as a link to another instance. */ }
  error.value = 'mobilePairing.invalidLink';
}

function closeLinkForm() {
  enteringLink.value = false;
  enteredLink.value = '';
  error.value = '';
}

function remove() {
  const device = removal.value;
  if (!device) return;
  return run(async () => {
    if (device.current) await clearOfflineSites().catch(() => {});
    await mobileDevicesApi.remove(device.id);
    if (device.current) await clearOfflineSites().catch(() => {});
    devices.value = devices.value.filter(item => item.id !== device.id);
    removal.value = null;
  });
}

function load() { return run(async () => { devices.value = await mobileDevicesApi.list(); }); }
onMounted(load);
</script>

<template>
  <section class="mobile-devices" :aria-label="t('mobilePairing.title')">
    <h3><AppIcon name="device" /> {{ t('mobilePairing.title') }}</h3>
    <p>{{ t(secure ? 'mobilePairing.manageHelp' : 'mobilePairing.httpsRequired') }}</p>
    <p v-if="!secure && loopback">{{ t('mobilePairing.desktopHttpsHelp') }}</p>
    <div v-if="secure" class="device-actions">
      <Button :disabled="busy" @click="issue">
        <template #icon><AppIcon name="qrcode" /></template>{{ t('mobilePairing.issue') }}
      </Button>
      <Button :disabled="busy" @click="error = ''; enteringLink = true">{{ t('mobilePairing.useLink') }}</Button>
    </div>
    <Message v-if="error && !removal && !enteringLink" type="error" role="alert" closable>{{ t(error) }}</Message>
    <ul v-if="devices.length" class="device-list">
      <li v-for="device in devices" :key="device.id">
        <span>{{ device.label }} <small v-if="device.current">{{ t('mobilePairing.current') }}</small></span>
        <Button size="small" :disabled="busy" @click="removal = device">{{ t('mobilePairing.remove') }}</Button>
      </li>
    </ul>
  </section>
  <OfflineSitesSettings v-if="secure && devices.some(device => device.current)" />
  <FormDialog v-if="pairing" :visible="true" icon="qrcode" size="compact" :title="t('mobilePairing.issue')"
    :close-label="t('actions.cancel')" @update:visible="!$event && (pairing = null)">
    <p>{{ t('mobilePairing.scanHelp') }}</p>
    <img class="pairing-qr" :src="pairing.image" :alt="t('mobilePairing.qr')">
    <label for="mobile-pairing-link">{{ t('mobilePairing.link') }}</label>
    <input id="mobile-pairing-link" :value="pairing.link" readonly autocomplete="off" spellcheck="false" @focus="($event.target as HTMLInputElement).select()">
  </FormDialog>
  <FormDialog v-if="enteringLink" :visible="true" icon="device" size="compact" :title="t('mobilePairing.useLink')"
    :close-label="t('actions.cancel')" :error="error ? t(error) : ''" @update:visible="!$event && closeLinkForm()">
    <form id="mobile-pairing-enter-link" class="dialog-form" @submit.prevent="acceptLink">
      <div class="field">
        <label for="mobile-pairing-entered-link">{{ t('mobilePairing.link') }}</label>
        <input id="mobile-pairing-entered-link" v-model="enteredLink" required type="url" maxlength="512" autocomplete="off" spellcheck="false">
      </div>
    </form>
    <template #footer><Button type="primary" attr-type="submit" form="mobile-pairing-enter-link">{{ t('mobilePairing.continue') }}</Button></template>
  </FormDialog>
  <MobilePairingConfirmation v-if="confirmationCode" :code="confirmationCode" :email="email"
    @paired="load" @close="confirmationCode = ''" />
  <FormDialog v-if="removal" :visible="true" icon="device" size="compact" :title="t('mobilePairing.remove')"
    :close-label="t('actions.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && (removal = null)">
    <p>{{ t(removal.current ? 'offlineJournal.removeDeviceHelp' : 'mobilePairing.removeHelp', { name: removal.label }) }}</p>
    <template #footer><Button type="error" :loading="busy" @click="remove">{{ t('mobilePairing.remove') }}</Button></template>
  </FormDialog>
</template>

<style scoped>
.mobile-devices, .device-list { display: grid; gap: var(--ui-content-gap); }
.mobile-devices { justify-items: start; border-top: 1px solid var(--theme-border); padding-top: var(--ui-content-gap); }
.mobile-devices h3, .mobile-devices p { margin: 0; }
.mobile-devices h3 { display: flex; align-items: center; gap: var(--space-2); font-size: .87rem; }
.device-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.device-list { list-style: none; padding: 0; margin: 0; width: 100%; }
.device-list li { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); overflow-wrap: anywhere; }
.pairing-qr { width: min(100%, 12rem); display: block; background: white; margin: var(--ui-content-gap) auto; }
</style>
