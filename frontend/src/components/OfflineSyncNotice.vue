<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { NButton as Button, NInput as Input } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import FormDialog from './FormDialog.vue';
import { useOfflineSync } from '../lib/offline-sync';
import { panelPaths } from '../router';

const { t } = useI18n();
const { pending, active, running, sendError, enable, stop } = useOfflineSync();
const open = ref(false);
const passphrase = ref('');
const unlocking = ref(false);
const failed = ref(false);

function close() {
  open.value = false;
  failed.value = false;
  passphrase.value = '';
}

async function unlock() {
  if (unlocking.value || passphrase.value.length < 12) return;
  unlocking.value = true;
  failed.value = false;
  const secret = passphrase.value;
  passphrase.value = '';
  try {
    if (await enable(secret)) close();
    else failed.value = true;
  } catch {
    failed.value = true;
  } finally {
    unlocking.value = false;
  }
}
</script>

<template>
  <div v-if="active || pending" class="offline-sync-notice">
    <p role="status">{{ active ? t('offlineSites.autoActive') : t('offlineJournal.pending', { count: pending }) }}</p>
    <Button v-if="active" size="small" @click="stop">{{ t('offlineSites.autoStop') }}</Button>
    <Button v-else size="small" :disabled="running" @click="open = true">
      <template #icon><AppIcon name="refresh" /></template>{{ t('offlineSites.unlockToSync') }}
    </Button>
    <RouterLink v-if="sendError && pending && !running" :to="panelPaths.security">
      {{ t('offlineJournal.review') }}
    </RouterLink>
  </div>
  <FormDialog v-if="open" :visible="open" :title="t('offlineSites.unlockToSync')" :close-label="t('actions.close')"
    :busy="unlocking" :error="failed ? t('offlineSites.unlockError') : undefined" size="compact" icon="refresh" @update:visible="close">
    <form class="dialog-form" @submit.prevent="unlock">
      <div class="field">
        <label for="offline-sync-passphrase">{{ t('offlineSites.passphrase') }}</label>
        <Input v-model:value="passphrase" type="password" :disabled="unlocking"
          :input-props="{ id: 'offline-sync-passphrase', autocomplete: 'off', maxlength: 256 }" />
      </div>
      <div class="form-actions">
        <Button type="primary" attr-type="submit" :loading="unlocking" :disabled="passphrase.length < 12">
          {{ t('offlineSites.autoStart') }}
        </Button>
      </div>
    </form>
  </FormDialog>
</template>

<style scoped>
.offline-sync-notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  margin-block-end: var(--space-3);
}
.offline-sync-notice p { margin: 0; }
</style>
