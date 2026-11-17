import type { MeasurementNature } from '../api/sensors';

const icons: Record<MeasurementNature, string> = {
 other: 'sensor',
 temperature: 'temperature',
 air_humidity: 'humidity',
 substrate_moisture: 'substrate',
 illuminance: 'light',
 carbon_dioxide: 'carbonDioxide',
 ph: 'ph',
 conductivity: 'conductivity',
 pressure: 'pressure',
 power: 'power',
 energy: 'energy',
 water_level: 'waterLevel',
 battery: 'battery',
 binary: 'binary',
};

export function measurementNatureIcon(nature?: MeasurementNature): string {
 return nature ? icons[nature] : icons.other;
}
