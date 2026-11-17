import { computed, ref, watch, type Ref } from 'vue';

export function useAutoSave(snapshot: () => string, ready: Readonly<Ref<boolean>>, save: () => Promise<boolean>) {
 const rejected = ref<string | null>(null);
 const failed = computed(() => rejected.value === snapshot());
 let inFlight: Promise<void> | undefined;

 async function flush(retry = false) {
  if (inFlight) return inFlight;
  if (!ready.value || (failed.value && !retry)) return;
  const submitted = snapshot();
  inFlight = save().then(saved => { rejected.value = saved ? null : submitted; });
  try { await inFlight; }
  finally { inFlight = undefined; }
 }

 watch([snapshot, ready], ([value, enabled], [previous], onCleanup) => {
  if (value !== previous) rejected.value = null;
  if (!enabled || failed.value) return;
  const timer = setTimeout(() => { void flush(); }, 650);
  onCleanup(() => clearTimeout(timer));
 });
 return { failed, flush };
}
