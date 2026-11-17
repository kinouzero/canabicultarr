<?php

namespace App\Enums;

enum DelegatedPermission: string
{
    case Administration = 'administration.view';
    case Plants = 'plants.manage';
    case Strains = 'strains.manage';
    case Locations = 'locations.manage';
    case Assets = 'assets.manage';
    case Sensors = 'sensors.manage';
    case Inventory = 'inventory.manage';
    case Alerts = 'alerts.manage';
    case HomeAssistant = 'integrations.manage';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
