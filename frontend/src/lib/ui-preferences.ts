import { reactive } from 'vue';
import { ApiError, apiRequest } from '../api/auth';
export type PreferenceValue = string | boolean | number | string[];
const accounts = new Map<string, { values: Record<string, PreferenceValue> }>();
let activeAccount = '';
let controller = new AbortController();
let pending: Record<string, PreferenceValue> = {};
let saving = false;

export function preferenceStore(account: string) {
 let store = accounts.get(account);
 if (!store) { store = reactive({ values: {} as Record<string, PreferenceValue> }); accounts.set(account, store); }
 return store;
}

export async function loadUiPreferences(account: string) {
 controller.abort(); controller = new AbortController();
 const requestController = controller; const signal = requestController.signal;
 activeAccount = account; pending = {}; saving = false;
 const timeout = setTimeout(() => requestController.abort(), 2500);
 try {
  const values = await apiRequest<Record<string, PreferenceValue>>('account/ui-preferences', undefined, { signal });
  if (activeAccount === account && !signal.aborted && values && !Array.isArray(values)) preferenceStore(account).values = values;
 } catch { /* Preferences must not prevent signing in when Redis is unavailable. */ }
 finally { clearTimeout(timeout); }
 // A read timeout must not disable subsequent writes.
 if (activeAccount === account && signal.aborted) controller = new AbortController();
}

async function flush() {
 if (saving || !Object.keys(pending).length) return;
 saving = true;
 const account = activeAccount; const signal = controller.signal; const entries = Object.entries(pending); const batch = Object.fromEntries(entries.slice(0, 100)); pending = Object.fromEntries(entries.slice(100));
 try { await apiRequest('account/ui-preferences', { entries: Object.entries(batch).map(([key, value]) => ({ key, value })) }, { method: 'PATCH', signal }); }
 catch (cause) {
  if (activeAccount !== account || signal.aborted) return;
  if (!(cause instanceof ApiError && cause.status === 422)) {
   pending = { ...batch, ...pending };
   saving = false;
   return; // Retry transient failures only on the next interaction.
  }
  // Rejected fields must not poison subsequent valid preference updates.
 }
 if (activeAccount !== account || signal.aborted) return;
 saving = false;
 if (Object.keys(pending).length) void flush();
}

export function setUiPreference(account: string, key: string, value: PreferenceValue) {
 const store = preferenceStore(account);
 if (JSON.stringify(store.values[key]) === JSON.stringify(value)) return;
 store.values[key] = value;
 if (!account || account !== activeAccount) return;
 pending[key] = value;
 queueMicrotask(() => void flush());
}

export function clearUiPreferences() {
 controller.abort(); activeAccount = ''; pending = {}; saving = false; accounts.clear();
}
