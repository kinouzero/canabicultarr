import { computed, onUnmounted, reactive } from 'vue';
import { documentFoldersApi, type DocumentFolder } from '../api/documents';
import type { Page } from '../api/types';

export function useFolderBrowser() {
 const folders = reactive(new Map<string, DocumentFolder>());
 const pages = reactive(new Map<string, Page<DocumentFolder>>());
 const pending = reactive(new Set<string>());
 const requests = new Map<string, AbortController>();
 let alive = true;
 function remember(rows: DocumentFolder[]) { for (const row of rows) folders.set(row.id, row); }
 async function children(parent: string | null, page = 1) {
  const key = parent ?? 'root'; requests.get(key)?.abort();
  const controller = new AbortController(); requests.set(key, controller); pending.add(key);
  try {
   const result = await documentFoldersApi.list(parent, page, controller.signal);
   if (!alive || requests.get(key) !== controller) return;
   // Replace the current page, retaining previously visited branches for the navigation tree.
   remember(result.data); pages.set(key, result);
  } finally { if (requests.get(key) === controller) { requests.delete(key); pending.delete(key); } }
 }
 async function path(id: string) {
  const key = `path:${id}`; requests.get(key)?.abort();
  const controller = new AbortController(); requests.set(key, controller);
  try {
   const result = await documentFoldersApi.show(id, controller.signal);
   if (!alive || requests.get(key) !== controller) return;
   remember(result.ancestors); remember([result.data]); return result.data;
  } finally { if (requests.get(key) === controller) requests.delete(key); }
 }
 async function paths(ids: string[]) {
  const missing = [...new Set(ids)].filter(id => !folders.has(id)); if (!missing.length) return;
  requests.get('paths')?.abort(); const controller = new AbortController(); requests.set('paths', controller);
  try { const rows = await documentFoldersApi.paths(missing, controller.signal); if (alive && requests.get('paths') === controller) remember(rows); }
  finally { if (requests.get('paths') === controller) requests.delete('paths'); }
 }
 function reset() { for (const controller of requests.values()) controller.abort(); requests.clear(); pending.clear(); pages.clear(); folders.clear(); }
 onUnmounted(() => { alive = false; reset(); });
 return { folders, pages, pending, remember, children, path, paths, reset, entries: computed(() => [...folders.values()]) };
}
