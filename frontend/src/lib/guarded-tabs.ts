import { nextTick, ref, watch, type Ref } from 'vue';

// Controlled tabs may emit selection before an asynchronous guard completes.
// Restore it while an asynchronous draft decision is pending, without unmounting the editor.
export function useGuardedTabs(current: Ref<string>, canChange: (next: string) => Promise<boolean>) {
 const value = ref(current.value);
 let pending = false;
 watch(current, next => { value.value = next; }, { flush: 'sync' });
 async function change(next: string | number) {
  value.value = String(next);
  await nextTick();
  value.value = current.value;
  if (pending || String(next) === current.value) return;
  pending = true;
  try { if (await canChange(String(next))) current.value = String(next); }
  finally { pending = false; }
 }
 return { value, change };
}
