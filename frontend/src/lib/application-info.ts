import { ref, shallowRef } from 'vue';
import { applicationInfoApi, type ApplicationInfo } from '../api/application-info';

export const applicationVersion: string = import.meta.env.VITE_APP_VERSION;

const data = shallowRef<ApplicationInfo | null>(null);
const busy = ref(false);
const error = shallowRef<unknown>(null);
let pending: Promise<void> | undefined;
let loadedAt = 0;
let generation = 0;
const refreshInterval = 12 * 60 * 60 * 1000;

function load(force = false): Promise<void> {
 if (pending) return pending;
 if (!force && data.value && Date.now() - loadedAt < refreshInterval) return Promise.resolve();
 const current = generation;
 busy.value = true; error.value = null;
 pending = applicationInfoApi.get().then(result => {
  if (!result || typeof result.version !== 'string' || !result.update || typeof result.update.status !== 'string') throw new Error('Invalid application information');
  if (current === generation) { data.value = result; loadedAt = Date.now(); }
 }).catch(cause => {
  if (current === generation) { data.value = null; error.value = cause; }
 }).finally(() => {
  if (current === generation) { busy.value = false; pending = undefined; }
 });
 return pending;
}
function reset() {
 generation++; data.value = null; error.value = null; busy.value = false; loadedAt = 0; pending = undefined;
}
export const applicationInfo = { data, busy, error, load, reset, refreshInterval };
