import type { PlantMeasurement } from '../api/plants';

export type LiveReading = Pick<PlantMeasurement, 'sensorId' | 'value' | 'unit' | 'observedAt' | 'quality' | 'isStale' | 'sourceRevision'>;
export interface ReadingSnapshot { reading: LiveReading; identity: string; baseVersion: string }
function identity(reading: PlantMeasurement): string {
 return JSON.stringify([reading.device.id, reading.sourceRevision ?? null, reading.measurementType.nature, reading.measurementType.name, reading.unit, reading.isEnabled, reading.switchControl?.kind, reading.switchControl?.canControl]);
}
function version(reading: PlantMeasurement): string {
 return JSON.stringify([reading.value, reading.observedAt, reading.unit, reading.quality]);
}
export function readingStamp(reading: PlantMeasurement): string { return JSON.stringify([identity(reading), version(reading)]); }
export function createReadingSnapshot(base: PlantMeasurement, reading: LiveReading): ReadingSnapshot | null {
 if ((base.sourceRevision ?? null) !== (reading.sourceRevision ?? null)) return null;
 return { reading, identity: identity(base), baseVersion: version(base) };
}
export function snapshotIsCurrent(base: PlantMeasurement, snapshot: ReadingSnapshot): boolean {
 if (identity(base) !== snapshot.identity) return false;
 if (!snapshot.reading.observedAt && version(base) !== snapshot.baseVersion) return false;
 return !(base.observedAt && snapshot.reading.observedAt && base.observedAt > snapshot.reading.observedAt);
}
export function mergeReadingSnapshot(base: PlantMeasurement, snapshot?: ReadingSnapshot): PlantMeasurement {
 return snapshot && snapshotIsCurrent(base, snapshot) ? { ...base, ...snapshot.reading } : base;
}
