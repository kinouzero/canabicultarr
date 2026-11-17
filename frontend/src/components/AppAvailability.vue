<script setup lang="ts">
import DisclosureSummary from './DisclosureSummary.vue';
import AppIcon from './AppIcon.vue';
import { useRememberDisclosure } from '../lib/disclosure-preferences';
import { onMounted, onUnmounted, ref, shallowRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import FormDialog from './FormDialog.vue';
const vRememberDisclosure = useRememberDisclosure('AppAvailability');
const props = defineProps<{ menuHelp?: boolean; helpOpen?: boolean }>();
const emit = defineEmits<{ close: []; closed: [] }>();

interface InstallPrompt extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}
const { t } = useI18n();
const offline = ref(!navigator.onLine);
const installing = ref(false);
const notice = ref('');
const prompt = shallowRef<InstallPrompt | null>(null);
const standalone = window.matchMedia('(display-mode: standalone)');
const installed = ref(standalone.matches || Boolean((navigator as Navigator & { standalone?: boolean }).standalone));
function connectionChanged() { offline.value = !navigator.onLine; }
function offerInstall(event: Event) { event.preventDefault(); prompt.value = event as InstallPrompt; }
function appInstalled() { installed.value = true; prompt.value = null; notice.value = 'pwa.installed'; }
function displayChanged() { installed.value = standalone.matches; }
async function install() {
  if (!prompt.value || installing.value) return;
  const pending = prompt.value;
  prompt.value = null;
  installing.value = true;
  notice.value = '';
  try {
    await pending.prompt();
    // Acceptance is not confirmation that installation finished; appinstalled is.
    await pending.userChoice;
  } catch { notice.value = 'pwa.installFailed'; }
  finally { installing.value = false; }
}
onMounted(() => {
  window.addEventListener('online', connectionChanged);
  window.addEventListener('offline', connectionChanged);
  window.addEventListener('beforeinstallprompt', offerInstall);
  window.addEventListener('appinstalled', appInstalled);
  standalone.addEventListener('change', displayChanged);
});
onUnmounted(() => {
  window.removeEventListener('online', connectionChanged);
  window.removeEventListener('offline', connectionChanged);
  window.removeEventListener('beforeinstallprompt', offerInstall);
  window.removeEventListener('appinstalled', appInstalled);
  standalone.removeEventListener('change', displayChanged);
});
</script>

<template><aside class="app-availability" :aria-label="menuHelp ? undefined : t('pwa.installHelp')"><Message v-if="offline" type="warning" closable role="status">{{ t('pwa.offlineSignal') }}</Message>
    <Message v-if="notice" type="info" closable role="status">{{ t(notice) }}</Message>
    <div v-if="!menuHelp && !installed" class="installation"><Button v-if="prompt || installing" type="default" :loading="installing" :disabled="offline" @click="install"><template #icon><AppIcon name="download" /></template><span>{{ t('pwa.install') }}</span></Button>
      <details v-remember-disclosure="'installation-help'"><DisclosureSummary icon="help">{{ t('pwa.installHelp') }}</DisclosureSummary><p>{{ t('pwa.help') }}</p><p>{{ t('pwa.connectionRequired') }}</p></details></div></aside>
  <FormDialog v-if="menuHelp" :visible="helpOpen" :title="t('pwa.installHelp')" :close-label="t('pwa.closeHelp')" icon="download" size="compact" @update:visible="!$event && emit('close')" @closed="emit('closed')">
    <div class="dialog-body"><p v-if="installed">{{ t('pwa.installed') }}</p>
    <Button v-else-if="prompt || installing" type="default" :loading="installing" :disabled="offline" @click="install"><template #icon><AppIcon name="download" /></template><span>{{ t('pwa.install') }}</span></Button>
    <p>{{ t('pwa.help') }}</p><p>{{ t('pwa.connectionRequired') }}</p></div></FormDialog></template>

<style scoped>
.app-availability { max-width: 72rem; margin: 0 auto; padding: 0 var(--space-8); }
.installation { display: flex; gap: var(--space-3); align-items: flex-start; flex-wrap: wrap; font-size: .85rem; }
.installation details { flex: 1 1 16rem; padding: var(--space-2) 0; }
p { max-width: 52rem; line-height: 1.6; }
.app-availability .n-alert { margin-bottom: var(--ui-content-gap); }
@media (max-width: 480px) { .app-availability { padding: 0 var(--space-4); } }
</style>
