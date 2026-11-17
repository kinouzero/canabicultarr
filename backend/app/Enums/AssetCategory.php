<?php

namespace App\Enums;

enum AssetCategory: string
{
    case Sensor = 'sensor';
    case Socket = 'socket';
    case Humidifier = 'humidifier';
    case Dehumidifier = 'dehumidifier';
    case Light = 'light';
    case Fan = 'fan';
    case Pump = 'pump';
    case Other = 'other';

    public const POWER_CONTROLLED = [self::Light, self::Socket, self::Fan];

    public function supportsPowerControl(): bool
    {
        return in_array($this, self::POWER_CONTROLLED, true);
    }
}
