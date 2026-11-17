import { computed, onUnmounted, ref, shallowRef, watch } from 'vue';
import { ApiError } from '../api/auth';
import type { ReferenceOption } from '../api/sensors';
import type { Page } from '../api/types';

/** One bounded catalog per mounted control, with no cross-account option cache. */
export function useReferenceOptions<T extends ReferenceOption = ReferenceOption>(source: {
 value: () => string | undefined;
 selected: () => T | null | undefined;
 disabled: () => boolean | undefined;
 fetchPage: () => (search: string, page: number) => Promise<Page<T>>;
 unavailable: () => string;
 onError: (cause: unknown) => void;
}) {
 const records = shallowRef<T[]>([]);
 const loading = ref(true); const failed = ref(false); const initialized = ref(false);
 const remembered = shallowRef<T | null>(null);
 const query = ref(''); const page = ref(0); const lastPage = ref(1); const retryPage = ref(1);
 let sequence = 0; let timer: ReturnType<typeof setTimeout> | undefined;
 const chosen = computed(() => source.selected()?.id === source.value() ? source.selected() : remembered.value?.id === source.value() ? remembered.value : null);
 const options = computed(() => {
  const selected = chosen.value ?? (source.value() ? { id: source.value()!, name: source.unavailable(), detail: '', disabled: true } : null);
  return selected && !records.value.some(row => row.id === selected.id) ? [selected, ...records.value] : records.value;
 });
 const more = computed(() => page.value < lastPage.value);
 watch(source.value, id => {
  remembered.value = records.value.find(row => row.id === id) ?? (remembered.value?.id === id ? remembered.value : null);
  if (id && !chosen.value && initialized.value) void load();
 });
 async function load(target = 1) {
  clearTimeout(timer); const current = ++sequence; const search = query.value.trim();
  retryPage.value = target; loading.value = true; failed.value = false;
  try {
   const result = await source.fetchPage()(search, target);
   if (current !== sequence) return;
   const id = source.value();
   remembered.value = records.value.find(row => row.id === id) ?? chosen.value ?? null;
   if (id && !chosen.value && !result.data.some(row => row.id === id) && !search && target === 1) {
    const selected = await source.fetchPage()(id, 1);
    if (current !== sequence) return;
    remembered.value = selected.data.find(row => row.id === id) ?? null;
   }
   records.value = [...new Map((target === 1 ? result.data : [...records.value, ...result.data]).map(row => [row.id, row])).values()];
   page.value = result.meta.current_page; lastPage.value = result.meta.last_page; initialized.value = true;
  } catch (cause) {
   if (current !== sequence) return;
   failed.value = true;
   if (cause instanceof ApiError && [401, 403].includes(cause.status)) records.value = [];
   source.onError(cause);
  } finally { if (current === sequence) loading.value = false; }
 }
 function search(value: string) {
  if (source.disabled() || query.value === value) return;
  query.value = value; sequence++; loading.value = true; failed.value = false;
  clearTimeout(timer); timer = setTimeout(() => { void load(); }, 250);
 }
 function loadMore() { if (!source.disabled() && !loading.value && !failed.value && more.value) return load(page.value + 1); }
 watch(source.fetchPage, () => { void load(); });
 onUnmounted(() => { sequence++; clearTimeout(timer); });
 return { records, chosen, options, loading, failed, initialized, query, page, more, retryPage, load, search, loadMore };
}
