import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { PlantMeasurement } from '../src/api/plants';
import { createReadingSnapshot, mergeReadingSnapshot, readingStamp, snapshotIsCurrent, type LiveReading } from '../src/lib/device-reading-snapshots.ts';

function base(overrides: Partial<PlantMeasurement> = {}): PlantMeasurement {
 return { sensorId: 'channel', sensorName: 'Pump state', device: { id: 'pump', name: 'Pump' }, sourceRevision: 'source-a', measurementType: { name: 'State', nature: 'binary', decimalPlaces: 0 }, value: '0', unit: null, observedAt: '2026-11-17T12:00:00Z', quality: 'good', isEnabled: true, isStale: false, switchControl: { kind: 'pump', canControl: true }, ...overrides };
}
function live(overrides: Partial<LiveReading> = {}): LiveReading {
 return { sensorId: 'channel', sourceRevision: 'source-a', value: '1', unit: null, observedAt: '2026-11-17T12:05:00Z', quality: 'good', isStale: false, ...overrides };
}

test('an unavailable live reading stops masking a recovered canonical measurement', () => {
 const original = base();
 const snapshot = createReadingSnapshot(original, live({ value: null, observedAt: null, quality: 'invalid', isStale: true }))!;
 assert.equal(mergeReadingSnapshot(original, snapshot).value, null);
 const recovered = base({ value: '1', observedAt: '2026-11-17T12:06:00Z' });
 assert.equal(mergeReadingSnapshot(recovered, snapshot), recovered);
 assert.equal(snapshotIsCurrent(recovered, snapshot), false);
});

test('a fresh live sample survives collection lag and yields to a newer canonical sample', () => {
 const snapshot = createReadingSnapshot(base(), live())!;
 assert.equal(mergeReadingSnapshot(base({ observedAt: '2026-11-17T12:01:00Z' }), snapshot).value, '1');
 const newer = base({ value: '0', observedAt: '2026-11-17T12:06:00Z' });
 assert.equal(mergeReadingSnapshot(newer, snapshot), newer);
});

test('device, source and permission changes invalidate snapshots for an unchanged channel ID', () => {
 const original = base(); const snapshot = createReadingSnapshot(original, live())!;
 for (const changed of [base({ device: { id: 'other-pump', name: 'Other pump' } }), base({ sourceRevision: 'source-b' }), base({ sourceRevision: null }), base({ isEnabled: false }), base({ switchControl: { kind: 'pump', canControl: false } })]) {
  assert.equal(snapshotIsCurrent(changed, snapshot), false);
  assert.equal(mergeReadingSnapshot(changed, snapshot), changed);
  assert.notEqual(readingStamp(changed), readingStamp(original));
 }
 assert.equal(createReadingSnapshot(base({ sourceRevision: 'source-b' }), live()), null);
});

test('live data preserves current names and failed reads do not mutate canonical records', () => {
 const original = base(); const snapshot = createReadingSnapshot(original, live())!;
 const renamed = base({ sensorName: 'Renamed channel', device: { id: 'pump', name: 'Renamed pump' } });
 const merged = mergeReadingSnapshot(renamed, snapshot);
 assert.equal(merged.sensorName, 'Renamed channel');
 assert.equal(merged.device.name, 'Renamed pump');
 assert.equal(merged.value, '1');
 assert.equal(original.value, '0');
});
