import { inject, onMounted, onUnmounted, readonly, ref, watch, type InjectionKey } from 'vue';
import { ApiError } from '../api/auth';
import { fetchOfflineSites, reconcileOfflineSites, refreshOfflineCopy, uploadOfflineDraft } from './offline-sites-access';
import { clearOfflineDirectory, clearOfflineSites, readOfflineDrafts, readOfflineState, updateOfflineDrafts, unlockOfflineSites, watchOfflineSitesChange } from './offline-sites.js';
import { offlineDraftRejections } from './offline-draft-rejections';

export function createOfflineSync(accountId: () => string | undefined) {
  const active = ref(false);
  const running = ref(false);
  const pending = ref(0);
  const sentCount = ref(0);
  const sendError = ref(false);
  let session: { secret: string; accountId: string; deviceId: string; expiresAt: number } | undefined;
  let request: AbortController | undefined;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let generation = 0;
  let unwatch = () => {};
  let reconciling: Promise<void> | undefined;

  function stop() {
    generation++;
    clearTimeout(timer);
    session = undefined;
    active.value = false;
    request?.abort();
  }

  function reset() {
    stop();
    pending.value = sentCount.value = 0;
    sendError.value = false;
  }

  async function readPending() {
    const account = accountId();
    if (!account || !window.isSecureContext || typeof indexedDB === 'undefined') return;
    const state = await readOfflineState().catch(() => null);
    if (account !== accountId()) return;
    pending.value = state?.outbox?.accountId === account ? state.outbox.count : 0;
    const local = state?.metadata ?? state?.outbox;
    if (!running.value && session && (session.accountId !== local?.accountId || session.deviceId !== local?.deviceId)) stop();
  }

  async function enable(secret: string) {
    const account = accountId();
    if (!account || running.value || session) return false;
    const attempt = generation;
    await reconciling;
    const scope = await unlockOfflineSites(secret, false) ?? await readOfflineDrafts(secret);
    if (!scope || scope.accountId !== account || account !== accountId() || attempt !== generation || session || running.value) return false;
    const duration = 15 * 60_000;
    session = { secret, accountId: account, deviceId: scope.deviceId, expiresAt: Date.now() + duration };
    active.value = true;
    timer = setTimeout(stop, duration);
    void resume();
    return true;
  }

  async function send(secret: string, automatic?: NonNullable<typeof session>) {
    const account = accountId();
    if (!account || running.value) return;
    const operation = new AbortController();
    const signal = operation.signal;
    request = operation;
    running.value = true;
    sentCount.value = 0;
    sendError.value = false;

    async function synchronize() {
      if (signal.aborted) return;
      const state = await readOfflineState();
      const scope = state.metadata ?? state.outbox;
      if (signal.aborted) return;
      if (!scope || scope.accountId !== account || (automatic && scope.deviceId !== automatic.deviceId)) {
        if (automatic) stop();
        return;
      }
      const journal = await readOfflineDrafts(secret);
      if (!journal && !automatic) return;

      let current;
      try {
        current = await fetchOfflineSites(signal);
      } catch (cause) {
        if (!signal.aborted && cause instanceof ApiError) {
          if ([401, 403].includes(cause.status)) {
            await clearOfflineSites(state.revision);
            stop();
          } else if (cause.status === 409) await clearOfflineDirectory(state.revision);
        }
        throw cause;
      }
      if (signal.aborted) return;
      if (current.accountId !== account || current.deviceId !== scope.deviceId
        || (journal && (journal.accountId !== account || journal.deviceId !== scope.deviceId))) {
        await clearOfflineSites(state.revision);
        stop();
        return;
      }
      if (state.metadata && state.metadata.version !== current.version) await clearOfflineDirectory(state.revision);
      const accepted: string[] = [];
      const rejected = new Map<string, string>();
      let refreshPlants = false;
      for (const draft of journal?.drafts ?? []) {
        if (signal.aborted) break;
        try {
          await uploadOfflineDraft(draft, scope, signal);
          accepted.push(draft.operation_id);
          if (draft.kind && draft.kind !== 'note') refreshPlants = true;
        } catch (cause) {
          if (signal.aborted) break;
          sendError.value = true;
          if (cause instanceof ApiError) {
            const reason = [cause.code, ...Object.values(cause.fields).flat()].find(code => Object.hasOwn(offlineDraftRejections, code));
            if (reason) rejected.set(draft.operation_id, offlineDraftRejections[reason]);
          }
          if (!(cause instanceof ApiError) || ![403, 404, 409, 422].includes(cause.status)) break;
        }
      }
      if (signal.aborted) return;
      if (accepted.length || rejected.size) await updateOfflineDrafts(accepted, secret, rejected, signal);
      if (signal.aborted) return;
      sentCount.value = accepted.length;
      if (state.metadata && (refreshPlants || automatic)) {
        try {
          await refreshOfflineCopy(secret, [], signal, current);
        } catch (cause) {
          if (!signal.aborted && cause instanceof ApiError && [401, 403, 409].includes(cause.status)) {
            await reconcileOfflineSites(scope.accountId);
          }
          throw cause;
        }
      }
    }

    try {
      // Read the queue after acquiring the origin-wide lock so a waiting tab sees prior receipts.
      if (navigator.locks) await navigator.locks.request('canabicultarr-offline-sync', { signal }, synchronize);
      else await synchronize();
    } catch {
      if (!signal.aborted) sendError.value = true;
    } finally {
      running.value = false;
      request = undefined;
      await readPending();
    }
  }

  async function resume() {
    if (session && session.expiresAt <= Date.now()) stop();
    const account = accountId();
    if (!account || running.value || !navigator.onLine || document.visibilityState !== 'visible') return;
    if (session) await send(session.secret, session);
    else {
      reconciling ??= reconcileOfflineSites(account).finally(() => { reconciling = undefined; });
      await reconciling;
      await readPending();
    }
  }

  watch(accountId, () => { reset(); void readPending(); }, { flush: 'sync' });
  onMounted(() => {
    if (!window.isSecureContext || typeof indexedDB === 'undefined') return;
    unwatch = watchOfflineSitesChange(() => void readPending());
    window.addEventListener('online', resume);
    window.addEventListener('pageshow', resume);
    document.addEventListener('visibilitychange', resume);
  });
  onUnmounted(() => {
    reset();
    unwatch();
    window.removeEventListener('online', resume);
    window.removeEventListener('pageshow', resume);
    document.removeEventListener('visibilitychange', resume);
  });
  return { active: readonly(active), running: readonly(running), pending: readonly(pending), sentCount: readonly(sentCount),
    sendError: readonly(sendError), reset, enable, stop, send, resume };
}

export const offlineSyncKey: InjectionKey<ReturnType<typeof createOfflineSync>> = Symbol('offlineSync');

export function useOfflineSync() {
  const sync = inject(offlineSyncKey);
  if (!sync) throw new Error('Offline sync is unavailable outside the application');
  return sync;
}
