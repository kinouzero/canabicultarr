import { onScopeDispose, watch, type WatchSource } from 'vue';

/** Debounce text entry; apply selected filters immediately and cancel pending work. */
export function useLiveFilters(options: {
 search?: WatchSource<string>;
 filters?: WatchSource<unknown>;
 when?: () => boolean;
 apply: () => unknown;
}) {
 let timer: ReturnType<typeof setTimeout> | undefined;
 function cancel() { clearTimeout(timer); timer = undefined; }
 function apply() { cancel(); if (options.when?.() !== false) return options.apply(); }
 if (options.search) watch(options.search, () => { cancel(); if (options.when?.() !== false) timer = setTimeout(apply, 300); });
 if (options.filters) watch(options.filters, apply);
 onScopeDispose(cancel);
 return apply;
}
