<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Retired = 'retired';
}
