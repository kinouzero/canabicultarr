<script setup lang="ts">
import { journalTabs } from '../tab-paths';
import { useCollectionQuery } from '../lib/collection-query';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Message from './AppMessage.vue';
import { NTag as Tag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import CollectionTable from './CollectionTable.vue';
import CollectionPagination from './CollectionPagination.vue';
import { useCollectionPageSize } from '../lib/collection-page-size';
import { formatDateTime } from '../lib/date-time';
import { ApiError } from '../api/auth';
import { notificationsApi, type Delivery } from '../api/notifications';
import en from '../locales/en/notifications.json';
import fr from '../locales/fr/notifications.json';
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { notifications: en }); mergeLocaleMessage('fr', { notifications: fr });
const pageSize = useCollectionPageSize('notification-deliveries');
const channelIcons = { email: 'mail', push: 'notification', webhook: 'webhook' } as const;
const columns = computed(() => ['event', 'channel', 'status', 'date'].map(key => ({ key, label: t(`notifications.historyColumns.${key}`) })));
const deliveries = ref<Delivery[]>([]);
const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const error = ref(''); let sequence = 0;
function key(code: string) { return /^(auth|notifications)\.errors\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed'; }
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('notifications.noDate'); }
async function load(target = page.value) {
 const current = ++sequence; loading.value = true; error.value = '';
 try {
  let result = await notificationsApi.deliveries(target, pageSize.value);
  if (current !== sequence) return;
  if (target > result.meta.last_page) result = await notificationsApi.deliveries(result.meta.last_page, pageSize.value);
  if (current !== sequence) return;
  deliveries.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (cause) {
  if (current !== sequence) return;
  error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) { deliveries.value = []; emit('accessLost', cause); }
 } finally { if (current === sequence) loading.value = false; }
}
const collectionQuery = useCollectionQuery({ paths: [journalTabs.notifications], prefix: 'deliveries.', fields: {}, page, pageSize, load: target => load(target) });
defineExpose({ reload: () => load(), loading });
onMounted(() => load(page.value)); onUnmounted(() => { sequence++; });
</script>
<template><section class="notification-history" data-testid="notification-history" :aria-label="t('notifications.historyTitle')"><Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message>
          <p>{{ t('notifications.historyHelp') }}</p><p v-if="loading" role="status">{{ t('notifications.loadingHistory') }}</p><p v-else-if="!deliveries.length" class="operational-empty" role="status">{{ t('notifications.noDeliveries') }}</p>
          <CollectionTable v-if="deliveries.length" :rows="deliveries" :columns="columns"><template #event="{ row }"><strong>{{ t(`notifications.events.${row.event}`) }}</strong></template>
           <template #channel="{ row }"><span class="delivery-channel"><AppIcon :name="channelIcons[row.channel]" />{{ t(`notifications.channels.${row.channel}`) }}</span></template>
           <template #status="{ row }"><div class="delivery-outcome"><Tag :type="row.status === 'failed' ? 'error' : row.status === 'sent' ? 'success' : 'default'">{{ t(`notifications.statuses.${row.status}`) }}</Tag><small>{{ t('notifications.attempts', { count: row.attempts }) }}</small><small v-if="row.errorCode" class="operational-warning">{{ t(key(row.errorCode)) }}</small><small v-if="row.status === 'pending'">{{ t('notifications.nextAttempt', { date: date(row.nextAttemptAt) }) }}</small></div></template>
           <template #date="{ row }"><time :datetime="row.createdAt">{{ date(row.createdAt) }}</time></template></CollectionTable>
          <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="loading" :label="t('notifications.historyPagination')" :previous-label="t('notifications.previous')" :next-label="t('notifications.next')" @page="collectionQuery.go($event)" /></section></template>

<style scoped>
.notification-history { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.notification-history > p { margin: 0; font-size: .8rem; color: var(--theme-muted); }
.delivery-channel { display: inline-flex; align-items: center; gap: var(--ui-icon-gap); }
.delivery-channel svg { width: 1rem; height: 1rem; flex-shrink: 0; }
.delivery-outcome { display: grid; justify-items: start; gap: var(--space-1-5); max-width: 24rem; }
.delivery-outcome small { color: var(--theme-muted); }
time { font-size: .78rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>
