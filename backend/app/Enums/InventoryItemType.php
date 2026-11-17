<?php

namespace App\Enums;

enum InventoryItemType: string
{
    case Seeds = 'seeds';
    case Fertilizer = 'fertilizer';
    case Substrate = 'substrate';
    case Container = 'container';
    case Other = 'other';
}
