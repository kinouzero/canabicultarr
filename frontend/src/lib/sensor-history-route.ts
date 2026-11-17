import { onScopeDispose, ref, watch, type InjectionKey } from 'vue';
import { sensorsApi } from '../api/sensors';
import { queryString, useRecordQuery } from './route-query';

/** Keep channel history in its workspace and restore it from direct links. */
export const sensorHistoryOpener: InjectionKey<(sensor: { id: string; name: string }) => void> = Symbol('sensor-history-opener');

export function useSensorHistoryRoute(onError: (cause: unknown) => void, options: { enabled?: () => boolean } = {}) {
 const record = useRecordQuery('sensor');
 const sensor = ref<{ id: string; name: string } | null>(null);
 let sequence = 0;
 async function resolve(value: unknown, available: boolean) {
  const current = ++sequence; const id = queryString(value);
  if (!id || !available) { sensor.value = null; return; }
  if (sensor.value?.id === id) return;
  sensor.value = null;
  try { const result = await sensorsApi.get(id); if (current === sequence) sensor.value = { id, name: result.name }; }
  catch (cause) { if (current === sequence) onError(cause); }
 }
 watch(() => [record.route.query.sensor, options.enabled?.() ?? true] as const, ([value, available]) => resolve(value, available), { immediate: true });
 function open(value: { id: string; name: string }) { if (queryString(record.route.query.sensor) === value.id && !sensor.value) void resolve(value.id, options.enabled?.() ?? true); else void record.open(value.id); }
 function close() { void record.close(); }
 onScopeDispose(() => { sequence++; });
 return { sensor, open, close };
}
