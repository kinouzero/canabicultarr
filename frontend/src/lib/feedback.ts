import { h } from 'vue';
import { useNotification, type NotificationApi } from 'naive-ui';
import { useI18n } from 'vue-i18n';

type FeedbackType = 'success' | 'error' | 'warning' | 'info';
const contexts = new WeakMap<NotificationApi, { recent: Map<string, number>; generation: number }>();

export function useFeedback() {
 const notifications = useNotification();
 const { t } = useI18n();
 let context = contexts.get(notifications);
 if (!context) { context = { recent: new Map(), generation: 0 }; contexts.set(notifications, context); }
 const state = context;
 function show(type: FeedbackType, message: string) {
  if (!message.trim()) return;
  const key = `${type}:${message}`; const now = Date.now();
  for (const [entry, time] of state.recent) if (now - time > 500) state.recent.delete(entry);
  if (state.recent.has(key)) return;
  state.recent.set(key, now);
  const generation = state.generation;
  queueMicrotask(() => {
   if (generation !== state.generation) return;
   notifications.create({ type, title: t(`feedback.${type}`),
    content: () => h('div', { role: type === 'error' ? 'alert' : 'status', 'data-feedback': type }, message),
    onClose: () => { state.recent.delete(key); },
    duration: type === 'error' ? 10000 : 6000, closable: true, keepAliveOnHover: true });
  });
 }
 return { show, success: (message: string) => show('success', message),
  clear: () => { state.generation++; state.recent.clear(); notifications.destroyAll(); } };
}
