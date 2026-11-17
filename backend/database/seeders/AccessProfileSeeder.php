<?php

namespace Database\Seeders;

use App\Models\PermissionRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class AccessProfileSeeder extends Seeder
{
    public const PROFILES = [
        'administrator' => ['name' => 'Administrator', 'description' => 'Manage all application features, accounts and settings.', 'permissions' => ['administration.view', 'plants.manage', 'strains.manage', 'locations.manage', 'assets.manage', 'sensors.manage', 'inventory.manage', 'alerts.manage', 'integrations.manage']],
        'observer' => ['name' => 'Observer', 'description' => 'View shared information and manage personal journal entries and files.', 'permissions' => []],
        'grower' => ['name' => 'Grower', 'description' => 'Track plants, watering, maintenance, harvests and plant photos in allowed locations.', 'permissions' => ['plants.manage']],
        'cultivation_manager' => ['name' => 'Cultivation manager', 'description' => 'Manage plants, strains, locations and stock. Account and global settings administration remains separate.', 'permissions' => ['plants.manage', 'strains.manage', 'locations.manage', 'inventory.manage']],
        'technician' => ['name' => 'Technician', 'description' => 'Manage locations, hardware, measurement channels, alerts and integrations.', 'permissions' => ['locations.manage', 'assets.manage', 'sensors.manage', 'alerts.manage', 'integrations.manage']],
        'stock_manager' => ['name' => 'Stock manager', 'description' => 'Manage inventory, seed stocks and stock movements.', 'permissions' => ['inventory.manage']],
        'monitoring_manager' => ['name' => 'Monitoring manager', 'description' => 'Configure alert rules and measurement channels, and inspect trends and sensor health.', 'permissions' => ['sensors.manage', 'alerts.manage']],
    ];

    public static function defaultProfile(string $key = 'observer'): PermissionRole
    {
        $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://canabicultarr.local/access-profiles/'.$key);
        $profile = PermissionRole::find($id) ?? PermissionRole::where('name_key', Str::lower(self::PROFILES[$key]['name']))->first();
        if ($profile) {
            return $profile;
        }
        $profile = new PermissionRole(self::PROFILES[$key]);
        $profile->id = $id;
        $profile->save();

        return $profile;
    }

    public function run(): void
    {
        foreach (self::PROFILES as $key => $profile) {
            $permissions = $profile['permissions'];
            sort($permissions);
            // Re-running installation must preserve edits, archives and account assignments.
            $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://canabicultarr.local/access-profiles/'.$key);
            if (! PermissionRole::whereKey($id)->exists() && ! PermissionRole::where('name_key', Str::lower($profile['name']))->exists()) {
                $role = new PermissionRole([...$profile, 'permissions' => $permissions]);
                $role->id = $id;
                $role->save();
            }
        }
    }
}
