<?php

namespace App\Enums;

enum MeasurementQuality: string
{
    case Unknown = 'unknown';
    case Good = 'good';
    case Suspect = 'suspect';
    case Invalid = 'invalid';

    public const USABLE = ['unknown', 'good'];
}
