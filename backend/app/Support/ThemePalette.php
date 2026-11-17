<?php

namespace App\Support;

class ThemePalette
{
    public const KEYS = ['canvas', 'surface', 'subtle', 'border', 'text', 'muted', 'accent', 'accentText', 'focus', 'success', 'warning', 'danger', 'info', 'overlay', 'actionWater', 'actionPlant', 'actionHarvest', 'actionMaintenance', 'actionEdit', 'actionMedia', 'actionLink', 'shadow', 'photoOverlay', 'photoText'];

    public static function resolve(?array $theme): array
    {
        return array_replace_recursive(self::defaults(), $theme ?? []);
    }

    public static function defaults(): array
    {
        return [
            'preset' => 'botanical',
            'light' => [
                'canvas' => '#f6f8f3',
                'surface' => '#ffffff',
                'subtle' => '#f0f4e9',
                'border' => '#dce5d5',
                'text' => '#2a4030',
                'muted' => '#617154',
                'accent' => '#607b45',
                'accentText' => '#ffffff',
                'focus' => '#648752',
                'success' => '#24734b',
                'warning' => '#956c16',
                'danger' => '#b34545',
                'info' => '#356d9d',
                'overlay' => '#12241b',
                'actionWater' => '#1767a6',
                'actionPlant' => '#38723f',
                'actionHarvest' => '#985020',
                'actionMaintenance' => '#237463',
                'actionEdit' => '#86570c',
                'actionMedia' => '#7351a2',
                'actionLink' => '#1767a6',
                'shadow' => '#12241b',
                'photoOverlay' => '#142d21',
                'photoText' => '#ffffff',
            ],
            'dark' => [
                'canvas' => '#111812',
                'surface' => '#1b241d',
                'subtle' => '#222e24',
                'border' => '#364438',
                'text' => '#e2eade',
                'muted' => '#b1bfaa',
                'accent' => '#577740',
                'accentText' => '#ffffff',
                'focus' => '#9bbf7f',
                'success' => '#93d5ae',
                'warning' => '#e7c87b',
                'danger' => '#f3a5a0',
                'info' => '#a1c8ef',
                'overlay' => '#000000',
                'actionWater' => '#79baff',
                'actionPlant' => '#9ad28e',
                'actionHarvest' => '#f1ac72',
                'actionMaintenance' => '#77cdbb',
                'actionEdit' => '#edc164',
                'actionMedia' => '#c6a3ee',
                'actionLink' => '#79baff',
                'shadow' => '#000000',
                'photoOverlay' => '#08110c',
                'photoText' => '#ffffff',
            ],
        ];
    }
}
