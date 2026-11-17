import { nextTick, onScopeDispose, ref, watch, type Ref } from 'vue';
import { isNavigationFailure, useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { useLiveFilters } from './live-filters';
import { pageSizes } from './collection-page-size';

export interface QueryField {
 read: () => string;
 restore: (value: unknown) => void;
 keepEmpty?: boolean;
}

function field<T extends string | number | boolean>(model: Ref<T>, parse: (value: string) => T | undefined): QueryField {
 const fallback = model.value;
 return {
  keepEmpty: fallback !== '',
  read: () => String(model.value),
  restore: raw => {
   const value = typeof raw === 'string' ? parse(raw) ?? fallback : fallback;
   if (model.value !== value) model.value = value;
  },
 };
}
export const queryField = {
 text: (model: Ref<string>, maximum = 120) => field(model, raw => raw.length <= maximum ? raw : undefined),
 identifier: (model: Ref<string>): QueryField => {
  const fallback = model.value;
  return { keepEmpty: fallback !== '', read: () => model.value, restore: raw => {
   const value = raw == null ? fallback : typeof raw === 'string' && (raw === '' || /^[A-Za-z0-9_-]{1,64}$/.test(raw)) ? raw : '!invalid';
   if (model.value !== value) model.value = value;
  } };
 },
 localDateTime: (model: Ref<string>): QueryField => {
  const fallback = model.value;
  let restoredInput: string | null = null;
  let restoredInstant = '';
  return {
   keepEmpty: fallback !== '',
   read: () => {
    if (restoredInput !== null && model.value === restoredInput) return restoredInstant;
    if (!model.value) return '';
    const date = new Date(model.value);
    return Number.isFinite(+date) ? date.toISOString() : '!invalid';
   },
   restore: raw => {
    restoredInput = null; restoredInstant = '';
    if (raw == null) { model.value = fallback; return; }
    if (raw === '') { model.value = ''; return; }
    if (typeof raw !== 'string' || !/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,3})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/.test(raw)) { model.value = '!invalid'; return; }
    const day = new Date(`${raw.slice(0, 10)}T00:00:00Z`);
    if (!Number.isFinite(+day) || day.toISOString().slice(0, 10) !== raw.slice(0, 10)) { model.value = '!invalid'; return; }
    const date = new Date(raw);
    model.value = Number.isFinite(+date) ? new Date(+date - date.getTimezoneOffset() * 60000).toISOString().slice(0, -1).replace(/:00\.000$/, '').replace(/\.000$/, '') : '!invalid';
    if (Number.isFinite(+date)) {
     // Preserve the exact supplied instant during a repeated DST hour until the input changes.
     restoredInput = model.value; restoredInstant = date.toISOString();
    }
   },
  };
 },
 choice: <T extends string>(model: Ref<T>, choices: readonly string[]) => field(model, raw => choices.includes(raw) ? raw as T : undefined),
 boolean: (model: Ref<boolean>) => field(model, raw => raw === 'true' ? true : raw === 'false' ? false : undefined),
 integer: (model: Ref<number>, allowed?: readonly number[]) => field(model, raw => {
  const value = /^\d{1,7}$/.test(raw) ? Number(raw) : 0;
  return Number.isSafeInteger(value) && value >= 1 && value <= 1000000 && (!allowed || allowed.includes(value)) ? value : undefined;
 }),
};

/** URL state owns read criteria; drafts and record parameters remain with their existing owners. */
export function useCollectionQuery(options: {
 fields: Record<string, QueryField>;
 page?: Ref<number> | false;
 pageSize?: Ref<number>;
 prefix?: string;
 paths?: readonly string[];
 contextKeys?: readonly string[];
 presentationKeys?: readonly string[];
 enabled?: () => boolean;
 // Keep debounced filter writes from navigating while an editor is open.
 paused?: () => boolean;
 load: (page: number) => unknown;
}) {
 const route = useRoute();
 const router = useRouter();
 const page = options.page || ref(1);
 const fields = { ...options.fields, ...(options.page === false ? {} : { page: queryField.integer(page) }), ...(options.pageSize ? { perPage: queryField.integer(options.pageSize, pageSizes) } : {}) };
 const key = (name: string) => `${options.prefix ?? ''}${name}`;
 const enabled = () => (!options.paths || options.paths.includes(route.path)) && (options.enabled?.() ?? true);
 const signature = () => JSON.stringify([options.paths && options.paths.length > 1 ? route.path : null, ...Object.keys(fields).map(name => route.query[key(name)] ?? null), ...(options.contextKeys ?? []).map(name => route.query[name] ?? null)]);
 let restoring = false;
 let alive = true;
 let restoration = 0;
 let navigation = 0;
 let pendingTarget: number | undefined;
 const criteria = () => JSON.stringify(Object.entries(fields).filter(([name]) => name !== 'page' && !options.presentationKeys?.includes(name)).map(([name, binding]) => [name, binding.read()]));
 function restore() {
  pendingTarget = undefined;
  for (const [name, binding] of Object.entries(fields)) binding.restore(route.query[key(name)]);
 }
 if (enabled()) restore();
 let previousCriteria = criteria();

 async function navigate(target: number) {
  if (restoring || !alive) return;
  if (options.paused?.()) { pendingTarget = target; return; }
  pendingTarget = undefined;
  if (!enabled()) { void options.load(target); return; }
  const query: LocationQueryRaw = { ...route.query };
  for (const [name, binding] of Object.entries(fields)) {
   const value = name === 'page' ? String(target) : binding.read();
   if (value || binding.keepEmpty) query[key(name)] = value; else delete query[key(name)];
  }
  const next = { path: route.path, query };
  if (router.resolve(next).fullPath === route.fullPath) { void options.load(target); return; }
  const current = ++navigation;
  const result = await router.push(next);
  if (isNavigationFailure(result) && alive && current === navigation) {
   restoring = true; restore(); previousCriteria = criteria(); await nextTick(); restoring = false;
  }
 }
 watch(() => options.paused?.() ?? false, paused => {
  if (!paused && pendingTarget !== undefined) void navigate(pendingTarget);
 });
 watch(signature, async (value, previous) => {
  if (!alive || !enabled() || value === previous) return;
  const current = ++restoration;
  restoring = true; restore(); previousCriteria = criteria(); await nextTick();
  if (!alive || current !== restoration) return;
  restoring = false;
  void options.load(page.value);
 });
 const apply = useLiveFilters({
  when: () => enabled() && !restoring,
  search: options.fields.q ? () => options.fields.q!.read() : undefined,
  filters: () => Object.entries(fields).filter(([name]) => !['q', 'page'].includes(name)).map(([, binding]) => binding.read()),
  apply: () => navigate(criteria() === previousCriteria ? page.value : 1),
 });
 onScopeDispose(() => { alive = false; restoration++; });
 return { page, apply, go: (target: number) => navigate(target) };
}
