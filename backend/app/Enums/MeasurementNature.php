<?php

namespace App\Enums;

enum MeasurementNature: string
{
    case Other = 'other';
    case Temperature = 'temperature';
    case AirHumidity = 'air_humidity';
    case SubstrateMoisture = 'substrate_moisture';
    case Illuminance = 'illuminance';
    case CarbonDioxide = 'carbon_dioxide';
    case Ph = 'ph';
    case Conductivity = 'conductivity';
    case Pressure = 'pressure';
    case Power = 'power';
    case Energy = 'energy';
    case WaterLevel = 'water_level';
    case Battery = 'battery';
    case Binary = 'binary';

    public const DEVICE_CLASSES = [
        'temperature' => 'temperature', 'humidity' => 'air_humidity',
        'moisture' => 'substrate_moisture', 'illuminance' => 'illuminance',
        'carbon_dioxide' => 'carbon_dioxide', 'ph' => 'ph',
        'conductivity' => 'conductivity', 'pressure' => 'pressure',
        'atmospheric_pressure' => 'pressure', 'power' => 'power',
        'energy' => 'energy', 'water_level' => 'water_level',
        'battery' => 'battery', 'binary' => 'binary', 'On' => 'binary', 'Active' => 'binary', 'InUse' => 'binary', 'StatusActive' => 'binary',
    ];

    public static function fromDeviceClass(mixed $class, ?string $unit): self
    {
        $nature = is_string($class) ? self::from(self::DEVICE_CLASSES[$class] ?? 'other') : self::Other;

        return $nature === self::SubstrateMoisture && $unit !== '%' ? self::Other : $nature;
    }
}
