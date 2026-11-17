import { useRoute, useRouter } from 'vue-router';
import { tabAtPath } from '../tab-paths';
import { nextTick, onScopeDispose, shallowRef, watch, type Ref, type InjectionKey } from 'vue';

export const filePreviewOpener: InjectionKey<(file: { id: string }) => void> = Symbol('file-preview-opener');

export function queryString(value: unknown): string { return typeof value === 'string' ? value : ''; }
/** Close a record opened here with Back; direct links close without leaving the app. */
export function useRecordQuery(key: 'plant' | 'strain' | 'sensor' | 'asset' | 'stock' | 'file' | 'event' | 'location' | 'storageLocations' | 'assetHistory') {
 const route = useRoute(); const router = useRouter();
 function open(id: string) {
  if (queryString(route.query[key]) === id) return;
  return router.push({ path: route.path, query: { ...route.query, [key]: id }, state: { recordParent: route.fullPath, recordKey: key } });
 }
 function close() {
  if (!route.query[key]) return;
  const query = { ...route.query }; delete query[key];
  const parent = router.resolve({ path: route.path, query }).fullPath;
  if (window.history.state?.recordKey === key && window.history.state?.recordParent === parent) router.back();
  else return router.replace({ path: route.path, query });
 }
 return { route, open, close };
}

/** Resolve read-only records only after guarded navigation; stale requests cannot reopen dialogs. */
export function useResolvedRecordQuery<T>(key: Parameters<typeof useRecordQuery>[0], resolve: (id: string) => Promise<T>, onError: (cause: unknown) => void, options: { enabled?: () => boolean } = {}) {
 const query = useRecordQuery(key);
 const record = shallowRef<T | null>(null);
 let sequence = 0;
 async function load(value: unknown) {
  const current = ++sequence;
  record.value = null;
  const id = queryString(value);
  if (options.enabled?.() === false || value == null || value === '') return;
  try {
   await Promise.resolve();
   if (current !== sequence) return;
   if (!id || !/^[\w:-]{1,100}$/.test(id)) throw new Error('Invalid record identifier');
   const result = await resolve(id);
   if (current === sequence) record.value = result;
  } catch (cause) { if (current === sequence) onError(cause); }
 }
 watch(() => [query.route.query[key], options.enabled?.() ?? true] as const, ([value]) => load(value), { immediate: true });
 onScopeDispose(() => { sequence++; });
 return { record, open: (id: string) => queryString(query.route.query[key]) === id ? load(id) : query.open(id), close: query.close, route: query.route };
}

/** Persist the selected tab in its path, restoring it if navigation is blocked. */
export function usePathChoice<T extends string>(model: Ref<T>, tabs: Record<T, string>) {
 const route = useRoute(); const router = useRouter();
 let restoring = false; let sequence = 0; let alive = true;
 function restore(value: T) {
  restoring = true; model.value = value;
  void nextTick(() => { restoring = false; });
 }
 const initial = tabAtPath(tabs, route.path);
 if (initial) model.value = initial;
 watch(() => route.path, path => {
  const tab = tabAtPath(tabs, path);
  if (tab) restore(tab);
 });
 watch(model, async value => {
  const selected = tabAtPath(tabs, route.path);
  if (restoring || !selected || selected === value) return;
  const current = ++sequence;
  const result = await router.push({ path: tabs[value], query: route.query, hash: route.hash });
  if (result && alive && current === sequence) restore(tabAtPath(tabs, route.path) ?? selected);
 });
 onScopeDispose(() => { alive = false; sequence++; });
}
