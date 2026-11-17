import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { PlantMeasurement } from '../src/api/plants';
import { plantSummaryReadings } from '../src/lib/plant-readings.ts';

const missing: PlantMeasurement = { sensorId: 'moisture', sensorName: 'Soil moisture', device: { id: 'probe', name: 'Probe' }, measurementType: { name: 'Moisture', nature: 'substrate_moisture', decimalPlaces: 1 }, value: null, unit: '%', observedAt: null, isEnabled: true, isStale: false };
const zero: PlantMeasurement = { ...missing, sensorId: 'temperature', value: '0', observedAt: '2026-11-18T12:00:00Z' };

test('automatic summaries retain assigned channels before their first measurement and valid zeros', () => {
 assert.deepEqual(plantSummaryReadings({ measurements: [missing, zero], summarySensorIds: null }), [missing, zero]);
});

test('explicit summaries retain selected missing channels and honor an empty selection', () => {
 assert.deepEqual(plantSummaryReadings({ measurements: [missing, zero], summarySensorIds: ['moisture'] }), [missing]);
 assert.deepEqual(plantSummaryReadings({ measurements: [missing, zero], summarySensorIds: [] }), []);
 assert.deepEqual(plantSummaryReadings({ measurements: [], summarySensorIds: null }), []);
});
