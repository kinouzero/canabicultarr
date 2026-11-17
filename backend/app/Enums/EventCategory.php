<?php

namespace App\Enums;

enum EventCategory: string
{
    case Observation = 'observation';
    case Maintenance = 'maintenance';
    case Incident = 'incident';
    case Note = 'note';
    case Custom = 'custom';
}
