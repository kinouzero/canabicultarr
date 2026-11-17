import type { Plant } from '../api/plants';
export function plantSummaryReadings(plant: Pick<Plant, 'measurements' | 'summarySensorIds'>) {
 return (plant.measurements ?? []).filter(reading => plant.summarySensorIds == null || plant.summarySensorIds.includes(reading.sensorId));
}
