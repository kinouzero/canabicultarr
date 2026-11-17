<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import DetailDialog from './DetailDialog.vue';
import ListActionButton from './ListActionButton.vue';
import { ApiError } from '../api/auth';
import { notificationsApi, type InboxNotification } from '../api/notifications';
import { formatDateTime } from '../lib/date-time';
import en from '../locales/en/notifications.json';
import fr from '../locales/fr/notifications.json';
defineProps<{ disabled?: boolean }>();
const emit = defineEmits<{ opened: []; closed: []; select: [panel: 'alerts' | 'notifications']; accessLost: [cause: ApiError] }>();
const { t, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { notifications: en }); mergeLocaleMessage('fr', { notifications: fr });
const opened = ref(false); const loading = ref(false); const marking = ref(false); const error = ref('');
const unread = ref(0); const items = ref<InboxNotification[]>([]); const through = ref(''); const page = ref(1); const lastPage = ref(1);
let active = true; let sequence = 0; let timer: ReturnType<typeof setInterval> | undefined;
async function refresh(target = page.value, summary = !opened.value) {
 if (marking.value) return;
 const current = ++sequence; if (!summary) { loading.value = true; error.value = ''; }
 try {
  const result = await notificationsApi.inbox(target, summary);
  if (!active || current !== sequence) return;
  unread.value = result.unreadCount ?? 0;
  if (!summary) { items.value = result.items ?? []; through.value = result.readThrough ?? ''; page.value = result.page ?? 1; lastPage.value = result.lastPage ?? 1; }
 } catch (cause) {
  if (!active || current !== sequence) return;
  if (!summary) error.value = 'auth.errors.requestFailed';
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { if (active && current === sequence) loading.value = false; }
}
function open() { opened.value = true; emit('opened'); void refresh(1, false); }
function close() { if (marking.value) return; opened.value = false; sequence++; loading.value = false; void nextTick(() => emit('closed')); }
function select(panel: 'alerts' | 'notifications') { close(); emit('select', panel); }
async function markRead() {
 if (!through.value || marking.value || loading.value) return;
 marking.value = true; error.value = '';
 try { await notificationsApi.readInbox(through.value); marking.value = false; if (active) await refresh(); }
 catch (cause) { if (active) { error.value = 'auth.errors.requestFailed'; if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); } }
 finally { if (active) marking.value = false; }
}
function poll() { if (document.visibilityState === 'visible') void refresh(); }
onMounted(() => { void refresh(1, true); timer = setInterval(poll, 60000); document.addEventListener('visibilitychange', poll); });
onUnmounted(() => { active = false; sequence++; clearInterval(timer); document.removeEventListener('visibilitychange', poll); });
</script>
<template><button type="button" class="notification-bell" data-testid="notification-bell" :disabled="disabled" :title="t('notifications.inbox.open')" :aria-label="unread ? t('notifications.inbox.openUnread', { count: unread }) : t('notifications.inbox.open')" aria-haspopup="dialog" :aria-expanded="opened" @click="open"><AppIcon name="bell" /><span v-if="unread" class="notification-badge" aria-hidden="true">{{ unread > 99 ? '99+' : unread }}</span></button>
 <DetailDialog v-if="opened" module icon="bell" :title="t('notifications.inbox.title')" :close-label="t('notifications.inbox.close')" :busy="marking" :error="error ? t(error) : ''" @close="close"><div class="inbox-toolbar"><span>{{ t('notifications.inbox.unread', { count: unread }) }}</span><div><ListActionButton icon="refresh" :label="t('notifications.inbox.refresh')" :loading="loading" :disabled="marking" @click="refresh()" /><Button type="default" :loading="marking" :disabled="loading || !unread || !through" @click="markRead"><template #icon><AppIcon name="check" /></template><span>{{ t('notifications.inbox.markRead') }}</span></Button></div></div>
  <p v-if="loading && !items.length" role="status">{{ t('auth.loading') }}</p>
  <p v-else-if="!items.length && !error" class="inbox-empty">{{ t('notifications.inbox.empty') }}</p>
  <ul v-else class="inbox-items"><li v-for="item in items" :key="item.id" :class="{ unread: item.unread }"><AppIcon :name="item.event === 'recovered' ? 'shield' : 'bell'" /><div><div class="inbox-item-heading"><strong>{{ item.name }}</strong><span v-if="item.unread" class="inbox-new">{{ t('notifications.inbox.new') }}</span></div><p>{{ t(`notifications.events.${item.event}`) }} · {{ t(`notifications.severity.${item.severity}`) }}<template v-if="item.sensorName"> · {{ item.sensorName }}</template></p><time :datetime="item.occurredAt">{{ formatDateTime(item.occurredAt, locale) }}</time></div></li></ul>
  <nav v-if="lastPage > 1" class="inbox-pagination" :aria-label="t('notifications.historyPagination')"><Button type="default" :disabled="loading || marking || page <= 1" @click="refresh(page - 1, false)"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('notifications.previous') }}</span></Button><span>{{ t('notifications.page', { current: page, total: lastPage }) }}</span><Button type="default" :disabled="loading || marking || page >= lastPage" @click="refresh(page + 1, false)"><template #icon><AppIcon name="arrow" /></template><span>{{ t('notifications.next') }}</span></Button></nav>
  <div class="inbox-footer"><Button :disabled="marking" @click="select('alerts')" type="primary"><template #icon><AppIcon name="bell" /></template><span>{{ t('notifications.inbox.alerts') }}</span></Button><Button type="default" :disabled="marking" @click="select('notifications')"><template #icon><AppIcon name="settings" /></template><span>{{ t('notifications.inbox.settings') }}</span></Button></div></DetailDialog></template>
<style scoped>
.notification-bell { position: relative; display: inline-grid; place-items: center; flex-shrink: 0; width: 2.25rem; height: 2.25rem; border: 0; border-radius: .55rem; background: transparent; color: var(--theme-text, #173e32); cursor: pointer; }
.notification-bell:hover { background: var(--theme-subtle, #f2f6f1); }.notification-bell > svg { width: 1.2rem; height: 1.2rem; }.notification-bell:focus-visible { outline: 2px solid var(--theme-focus, #63834d); outline-offset: 2px; }.notification-bell:disabled { opacity: .5; cursor: wait; }
.notification-badge { position: absolute; top: -.15rem; right: -.2rem; display: grid; place-items: center; min-width: 1.05rem; height: 1.05rem; padding: 0 var(--space-1); border-radius: 1rem; background: var(--theme-danger, #b74545); color: var(--theme-accent-text); font-size: .6rem; line-height: 1; font-weight: 700; }
.inbox-toolbar, .inbox-toolbar > div, .inbox-item-heading, .inbox-pagination, .inbox-footer { display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; }.inbox-toolbar { justify-content: space-between; font-size: .8rem; margin-bottom: var(--ui-content-gap); }.inbox-items { display: grid; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }.inbox-items > li { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-3); border: 1px solid var(--theme-border, #e1e8dc); border-radius: .75rem; }.inbox-items > li.unread { background: var(--theme-subtle, #f3f7ee); }.inbox-items > li > svg { width: 1.15rem; height: 1.15rem; flex-shrink: 0; margin-top: var(--space-0-5); }.inbox-items > li > div { min-width: 0; flex: 1; }.inbox-item-heading strong { overflow-wrap: anywhere; font-size: .85rem; }.inbox-items p, .inbox-items time { color: var(--theme-muted, #63745e); font-size: .75rem; line-height: 1.5; }.inbox-items p { margin: var(--space-1) 0; }.inbox-new { color: var(--theme-success, #52723e); font-size: .65rem; font-weight: 650; }.inbox-empty { text-align: center; color: var(--theme-muted, #63745e); padding: var(--space-8) var(--space-4); }.inbox-pagination { justify-content: space-between; margin-top: var(--ui-content-gap); font-size: .75rem; }.inbox-footer { justify-content: flex-end; margin-top: var(--ui-content-gap); padding-top: var(--space-4); border-top: 1px solid var(--theme-border, #e1e8dc); }
</style>
