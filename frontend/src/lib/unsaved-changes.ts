import { onUnmounted, ref, watch, type Ref } from 'vue';
import { useRouter, type RouteLocationNormalized } from 'vue-router';

// Drafts stay in their owning form, never in browser storage.
export function useUnsavedChanges(dirty: Readonly<Ref<boolean>>, busy: Readonly<Ref<boolean>>, options: { guardRoute?: boolean; preserveDraft?: (to: RouteLocationNormalized, from: RouteLocationNormalized) => boolean; onNavigate?: () => void; flush?: () => Promise<void> } = {}) {
 const router = useRouter();
 const pending = ref(false);
 let resolve: ((discard: boolean) => void) | undefined;
 let decision: Promise<boolean> | undefined;
 async function confirmDiscard(hasChanges?: boolean): Promise<boolean> {
  if (busy.value) return false;
  if (hasChanges === undefined && dirty.value && !decision && options.flush) await options.flush();
  if (busy.value) return false;
  if (!(hasChanges ?? dirty.value)) return true;
  if (decision) return decision;
  pending.value = true;
  decision = new Promise<boolean>(done => { resolve = done; });
  return decision;
 }
 function finish(discard: boolean) {
  const done = resolve;
  resolve = undefined; decision = undefined; pending.value = false;
  done?.(discard);
 }
 function beforeUnload(event: BeforeUnloadEvent) { event.preventDefault(); event.returnValue = ''; }
 watch(dirty, changed => { if (changed) window.addEventListener('beforeunload', beforeUnload); else window.removeEventListener('beforeunload', beforeUnload); }, { immediate: true, flush: 'sync' });
 const removeGuard = options.guardRoute === false ? () => {} : router.beforeEach((to, from) => to.fullPath === from.fullPath || options.preserveDraft?.(to, from) ? true : confirmDiscard());
 const removeAfter = router.afterEach((to, from, failure) => { if (!failure && to.fullPath !== from.fullPath && !options.preserveDraft?.(to, from)) options.onNavigate?.(); });
 onUnmounted(() => { removeGuard(); removeAfter(); window.removeEventListener('beforeunload', beforeUnload); finish(false); });
 return { pending, confirmDiscard, finish };
}
