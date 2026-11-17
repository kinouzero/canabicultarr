import { computed, inject, onScopeDispose, shallowRef, watch } from 'vue';
import { apiRequest, ApiError } from '../api/auth';
import type { PlantMeasurement } from '../api/plants';

import { createReadingSnapshot, mergeReadingSnapshot, readingStamp, snapshotIsCurrent, type LiveReading, type ReadingSnapshot } from './device-reading-snapshots';
import { insightPreferenceScope } from './insight-preferences';
type Subscriber = { readings: () => PlantMeasurement[]; account: () => string; update: (asset: string, rows: LiveReading[] | null, expected: Map<string, string>) => void; failed: (cause: unknown) => void };
const subscribers = new Set<Subscriber>();
type Refresh = { controller: AbortController; timers: Set<ReturnType<typeof setTimeout>> };
const refreshes = new Map<string, Refresh>();
const interested = (asset: string) => [...subscribers].filter(subscriber => subscriber.readings().some(row => row.device.id === asset));
function cancel(asset: string, refresh: Refresh) {
 refresh.controller.abort(); refresh.timers.forEach(clearTimeout); refreshes.delete(asset);
}

/** Read only mounted channels, once per device/batch, after an explicit command. */
export function refreshDeviceReadings(asset: string) {
 const previous = refreshes.get(asset);
 if (previous) cancel(asset, previous);
 if (!interested(asset).length) return;
 const refresh: Refresh = { controller: new AbortController(), timers: new Set() };
 refreshes.set(asset, refresh);
 let busy = false; let queued = false;
 async function read() {
  if (busy) { queued = true; return; }
  busy = true;
  try {
   const recipients = interested(asset).map(subscriber => ({ subscriber, account: subscriber.account(), expected: new Map(subscriber.readings().filter(row => row.device.id === asset).map(row => [row.sensorId, readingStamp(row)])) }));
   const ids = [...new Set(recipients.flatMap(recipient => [...recipient.expected.keys()]))];
   const publish = (batch: string[], rows: LiveReading[] | null, cause?: unknown) => {
    if (refresh.controller.signal.aborted || refreshes.get(asset) !== refresh) return;
    recipients.forEach(({ subscriber, account, expected }) => {
     if (!subscribers.has(subscriber) || subscriber.account() !== account) return;
     subscriber.update(asset, rows, new Map(batch.filter(id => expected.has(id)).map(id => [id, expected.get(id)!])));
     if (cause) subscriber.failed(cause);
    });
   };
   for (let index = 0; index < ids.length; index += 10) {
    if (refresh.controller.signal.aborted) break;
    const batch = ids.slice(index, index + 10);
    const query = new URLSearchParams(); batch.forEach(id => query.append('sensor_ids[]', id));
    try {
     const rows = await apiRequest<LiveReading[]>(`assets/${encodeURIComponent(asset)}/live-readings?${query}`, undefined, { signal: refresh.controller.signal });
     publish(batch, rows);
    } catch (cause) {
     publish(batch, null, cause);
    }
   }
  } finally {
   busy = false;
   if (queued && !refresh.controller.signal.aborted) { queued = false; void read(); }
   else if (!refresh.timers.size && refreshes.get(asset) === refresh) refreshes.delete(asset);
  }
 }
 // Bounded reconciliation handles provider propagation without permanent polling.
 for (const delay of [1000, 3000]) {
  const timer = setTimeout(() => { refresh.timers.delete(timer); void read(); }, delay);
  refresh.timers.add(timer);
 }
 void read();
}

export function useDeviceReadings(source: () => PlantMeasurement[], accessLost: (cause: ApiError) => void) {
 const scope = inject(insightPreferenceScope, undefined);
 const account = () => scope?.value ?? '';
 let previousAccount = account();
 const snapshots = shallowRef(new Map<string, ReadingSnapshot>());
 const subscriber: Subscriber = {
  readings: source, account,
  update(asset, rows, expected) {
   const byId = new Map(rows?.map(row => [row.sensorId, row]));
   const next = new Map(snapshots.value);
   for (const reading of source().filter(row => row.device.id === asset)) {
    if (expected.get(reading.sensorId) !== readingStamp(reading)) continue;
    const previous = mergeReadingSnapshot(reading, next.get(reading.sensorId));
    const live = byId.get(reading.sensorId) ?? { sensorId: reading.sensorId, value: previous.value, unit: previous.unit, observedAt: previous.observedAt, quality: previous.quality, sourceRevision: reading.sourceRevision, isStale: true };
    const snapshot = createReadingSnapshot(reading, live);
    if (snapshot) next.set(reading.sensorId, snapshot);
   }
   snapshots.value = next;
  },
  failed(cause) { if (cause instanceof ApiError && [401, 403].includes(cause.status)) accessLost(cause); },
 };
 subscribers.add(subscriber);
 const stop = watch([source, account], () => {
  const current = new Map(source().map(row => [row.sensorId, row]));
  if (previousAccount !== account()) {
   snapshots.value = new Map(); previousAccount = account();
   for (const [asset, refresh] of refreshes) if (current.size && [...current.values()].some(row => row.device.id === asset)) cancel(asset, refresh);
  } else snapshots.value = new Map([...snapshots.value].filter(([id, snapshot]) => { const base = current.get(id); return base && snapshotIsCurrent(base, snapshot); }));
  for (const [asset, refresh] of refreshes) if (!interested(asset).length) cancel(asset, refresh);
 });
 onScopeDispose(() => {
  stop(); subscribers.delete(subscriber);
  for (const [asset, refresh] of refreshes) if (!interested(asset).length) cancel(asset, refresh);
 });
 return computed(() => source().map(reading => mergeReadingSnapshot(reading, snapshots.value.get(reading.sensorId))));
}
