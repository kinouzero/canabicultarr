<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button, NSelect as Select } from 'naive-ui';
import FormDialog from '../../components/FormDialog.vue';
import AppIcon from '../../components/AppIcon.vue';
import Message from '../../components/AppMessage.vue';
import { listOfflineFiles, fetchOfflineSites, refreshOfflineCopy } from '../../lib/offline-sites-access';
import { useOfflineSync } from '../../lib/offline-sync';
import { formatDateTime } from '../../lib/date-time';
import {
  clearOfflineDirectory, clearOfflineLibrary, readOfflineDrafts, readOfflineState, updateOfflineDrafts,
  watchOfflineSitesChange, type OfflineDraft, type OfflineSitesMetadata,
} from '../../lib/offline-sites.js';

const { t, locale } = useI18n();
const passphrase = ref('');
const working = ref(false);
const error = ref(false);
const metadata = ref<OfflineSitesMetadata | null>(null);
const pendingCount = ref(0);
const reviewed = ref<OfflineDraft[] | null>(null);
const savedFiles = ref(0);
const chooseFiles = ref(false);
const fileIds = ref<string[]>([]);
const fileOptions = ref<{ value: string; label: string }[]>([]);
const fileSearchLoading = ref(false);
const fileSearchError = ref(false);
let fileSearchTimer: ReturnType<typeof setTimeout>;
let fileSearchRequest: AbortController | undefined;

function searchFiles(search = '') {
  clearTimeout(fileSearchTimer);
  fileSearchRequest?.abort();
  const request = new AbortController();
  fileSearchRequest = request;
  fileSearchError.value = false;
  fileSearchLoading.value = true;
  fileSearchTimer = setTimeout(async () => {
    try {
      const files = await listOfflineFiles(search.trim().slice(0, 120), request.signal);
      if (request.signal.aborted) return;
      const options = new Map(fileOptions.value.filter(option => fileIds.value.includes(option.value)).map(option => [option.value, option]));
      for (const file of files) options.set(file.id, { value: file.id, label: file.title });
      fileOptions.value = [...options.values()];
    } catch {
      if (!request.signal.aborted) fileSearchError.value = true;
    } finally {
      if (!request.signal.aborted) fileSearchLoading.value = false;
    }
  }, search ? 250 : 0);
}

function updateFileSelection(value: string[] | null) {
  fileIds.value = value?.slice(0, 4) ?? [];
}

function editFiles() {
  chooseFiles.value = true;
  searchFiles();
}

function cancelFiles() {
  clearTimeout(fileSearchTimer);
  fileSearchRequest?.abort();
  chooseFiles.value = false;
  fileIds.value = [];
  fileOptions.value = [];
}

let reviewSecret = '';
let reviewGeneration = 0;

const sync = useOfflineSync();
const { active: autoSyncEnabled, sentCount, sendError, stop: stopAutoSync } = sync;
const busy = computed(() => working.value || sync.running.value);

async function enableAutoSync() {
  const secret = passphrase.value;
  if (secret.length < 12 || busy.value) return;
  passphrase.value = '';
  await run(async () => {
    if (!await sync.enable(secret)) throw new Error('Offline copy unavailable');
  });
}

async function run(work?: () => Promise<void>) {
  if (working.value || (work && sync.running.value)) return;
  working.value = true;
  error.value = false;
  try { await work?.(); }
  catch { error.value = true; }
  finally {
    try {
      const state = await readOfflineState();
      metadata.value = state.metadata;
      pendingCount.value = state.outbox?.count ?? 0;
      savedFiles.value = state.library?.count ?? 0;
    } catch { error.value = true; }
    working.value = false;
  }
}

async function save() {
  if (passphrase.value.length < 12) return;
  const secret = passphrase.value;
  passphrase.value = '';
  await run(async () => {
    await refreshOfflineCopy(secret, fileIds.value);
    cancelFiles();
  });
}

async function openDrafts(secret: string) {
  const journal = await readOfflineDrafts(secret);
  if (!journal) return null;
  const current = await fetchOfflineSites();
  if (current.accountId !== journal.accountId || current.deviceId !== journal.deviceId) throw new Error('Mobile registration changed');
  return journal;
}

function send() {
  const secret = passphrase.value;
  if (secret.length < 12 || busy.value) return;
  passphrase.value = '';
  return sync.send(secret);
}

function review() {
  const secret = passphrase.value;
  if (secret.length < 12 || busy.value) return;
  passphrase.value = '';
  return run(async () => {
    const generation = reviewGeneration;
    const journal = await openDrafts(secret);
    if (generation !== reviewGeneration || !journal) return;
    reviewed.value = journal.drafts;
    reviewSecret = secret;
  });
}

function closeReview() {
  reviewGeneration++;
  reviewSecret = '';
  reviewed.value = null;
}

function removeDraft(id: string) {
  return run(async () => {
    await updateOfflineDrafts([id], reviewSecret);
    reviewed.value = reviewed.value?.filter(note => note.operation_id !== id) ?? null;
    if (!reviewed.value?.length) closeReview();
  });
}

let unwatch = () => {};
onMounted(() => {
  unwatch = watchOfflineSitesChange(() => {
    closeReview();
    void run();
  });
  document.addEventListener('visibilitychange', closeReview);
  void run();
});
onUnmounted(() => {
  unwatch();
  cancelFiles();
  document.removeEventListener('visibilitychange', closeReview);
  closeReview();
});
</script>

<template>
  <section class="offline-sites-settings" :aria-label="t('offlineSites.title')">
    <h3><AppIcon name="download" /> {{ t('offlineSites.title') }}</h3>
    <p>{{ t('offlineSites.settingsHelp') }}</p>
    <p>{{ t('offlineSites.storageRisk') }}</p>
    <Message v-if="error" type="error" role="alert" closable>{{ t('offlineSites.saveError') }}</Message>
    <Message v-if="sentCount" type="success" role="status" closable>{{ t('offlineJournal.sent', { count: sentCount }) }}</Message>
    <Message v-if="sendError" type="warning" role="status" closable>{{ t('offlineJournal.sendError') }}</Message>
    <p v-if="pendingCount" role="status">{{ t('offlineJournal.pending', { count: pendingCount }) }}</p>
    <p v-if="metadata" role="status">{{ t('offlineSites.savedUntil', { date: new Date(metadata.expiresAt).toLocaleString(locale) }) }}</p>
    <p v-if="autoSyncEnabled" role="status">{{ t('offlineSites.autoActive') }}</p>
    <p v-if="savedFiles">{{ t('offlineFiles.saved', { count: savedFiles }) }}</p>
    <form class="dialog-form" @submit.prevent="save">
      <div class="field">
        <label for="offline-sites-passphrase">{{ t('offlineSites.passphrase') }}</label>
        <input id="offline-sites-passphrase" v-model="passphrase" type="password" autocomplete="new-password"
          minlength="12" maxlength="256" required :disabled="busy" aria-describedby="offline-sites-passphrase-help">
        <small id="offline-sites-passphrase-help">{{ t('offlineSites.passphraseHelp') }}</small>
      </div>
      <div v-if="chooseFiles" class="field">
        <label for="offline-files-picker">{{ t('offlineFiles.title') }}</label>
        <Select :value="fileIds" :options="fileOptions" :loading="fileSearchLoading" multiple remote filterable clearable
          :disabled="busy" :placeholder="t('offlineFiles.search')" :input-props="{ id: 'offline-files-picker' }"
          @search="searchFiles" @update:value="updateFileSelection" />
        <small>{{ t('offlineFiles.limit') }}</small>
        <Message v-if="fileSearchError" type="error">{{ t('offlineFiles.searchError') }}</Message>
      </div>
      <div class="offline-site-actions">
        <Button type="primary" attr-type="submit" :loading="busy" :disabled="passphrase.length < 12">
          <template #icon><AppIcon name="download" /></template>{{ t('offlineSites.save') }}
        </Button>
        <Button v-if="!chooseFiles" :disabled="busy" @click="editFiles">
          <template #icon><AppIcon name="folder" /></template>{{ t('offlineFiles.choose') }}
        </Button>
        <Button v-else :disabled="busy" @click="cancelFiles">{{ t('actions.cancel') }}</Button>
        <Button v-if="savedFiles" :disabled="busy" @click="run(async () => { await clearOfflineLibrary(); })">
          {{ t('offlineFiles.remove') }}
        </Button>
        <Button v-if="autoSyncEnabled" @click="stopAutoSync">{{ t('offlineSites.autoStop') }}</Button>
        <Button v-else-if="metadata || pendingCount" :disabled="busy || passphrase.length < 12" @click="enableAutoSync">
          <template #icon><AppIcon name="refresh" /></template>{{ t('offlineSites.autoStart') }}
        </Button>
        <Button v-if="pendingCount" :disabled="busy || passphrase.length < 12" @click="send">
          <template #icon><AppIcon name="arrowUp" /></template>{{ t('offlineJournal.send') }}
        </Button>
        <Button v-if="pendingCount" :disabled="busy || passphrase.length < 12" @click="review">
          <template #icon><AppIcon name="edit" /></template>{{ t('offlineJournal.review') }}
        </Button>
        <Button v-if="metadata" :disabled="busy" @click="run(() => clearOfflineDirectory())">
          <template #icon><AppIcon name="trash" /></template>{{ t('offlineSites.clear') }}
        </Button>
      </div>
    </form>
  </section>
  <FormDialog v-if="reviewed" :visible="true" :busy="busy" icon="edit" size="compact"
    :title="t('offlineJournal.review')" :close-label="t('actions.cancel')" @update:visible="!$event && closeReview()">
    <p>{{ t('offlineJournal.discardHelp') }}</p>
    <ul class="journal-drafts">
      <li v-for="draft in reviewed" :key="draft.operation_id">
        <template v-if="draft.kind === 'maintenance' || draft.kind === 'watering'">
          <strong>{{ draft.plant_name }} · {{ draft.kind === 'maintenance' ? draft.type : t(draft.fertilizers?.length ? 'offlinePlants.withFertilizer' : 'offlinePlants.watering') }}</strong>
          <p v-if="draft.kind === 'watering' && draft.water_liters">{{ t('offlinePlants.volumeValue', { liters: draft.water_liters }) }}</p>
          <p v-if="draft.kind === 'watering' && draft.fertilizers?.length">{{ draft.fertilizers.map(product => product.name).join(', ') }}</p>
          <p v-if="draft.comment">{{ draft.comment }}</p>
          <p v-if="draft.attachment">{{ draft.attachment.name }}</p>
        </template>
        <template v-else-if="draft.kind === 'photo'">
          <strong>{{ draft.plant_name }} · {{ t('offlinePlants.photoFile') }}</strong>
          <p>{{ draft.attachment.name }}</p>
        </template>
        <template v-else-if="draft.kind === 'creation'">
          <strong>{{ draft.name || draft.strain_name }} · {{ t('offlinePlants.planned') }}</strong>
          <p>{{ draft.strain_name }} · {{ draft.location_name }}</p>
        </template>
        <template v-else>
          <strong>{{ draft.title }}</strong>
          <p>{{ draft.body }}</p>
        </template>
        <p v-if="'occurred_at' in draft"><time :datetime="draft.occurred_at">{{ formatDateTime(draft.occurred_at, locale) }}</time></p>
        <Message v-if="draft.rejection" type="warning">{{ t(draft.rejection) }}</Message>
        <Button type="error" :disabled="busy" @click="removeDraft(draft.operation_id)">
          <template #icon><AppIcon name="trash" /></template>{{ t('offlineJournal.discard') }}
        </Button>
      </li>
    </ul>
  </FormDialog>
</template>

<style scoped>
.offline-sites-settings { display: grid; gap: var(--ui-content-gap); }
.offline-sites-settings h3, .offline-sites-settings p { margin: 0; }
.offline-sites-settings h3 { display: flex; align-items: center; gap: var(--space-2); font-size: .87rem; }
.journal-drafts { display: grid; gap: var(--ui-content-gap); padding-inline-start: var(--space-5); overflow-wrap: anywhere; }
.journal-drafts p { white-space: pre-wrap; }
.offline-site-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
