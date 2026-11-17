<?php

use App\Models\ConnectionSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAMES = ['ENABLED', 'ISSUER', 'CLIENT_ID', 'CLIENT_SECRET', 'REDIRECT_URI'];

    public function up(): void
    {
        $this->rename('AUTHENTIK_', 'OIDC_');
    }

    public function down(): void
    {
        $this->rename('OIDC_', 'AUTHENTIK_');
    }

    private function rename(string $from, string $to): void
    {
        DB::transaction(function () use ($from, $to) {
            $row = ConnectionSetting::whereKey('oidc')->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $values = $row->values ?? [];
            $changed = false;
            foreach (self::NAMES as $name) {
                if (! array_key_exists($from.$name, $values)) {
                    continue;
                }
                if (array_key_exists($to.$name, $values) && $values[$to.$name] !== $values[$from.$name]) {
                    throw new RuntimeException('Conflicting OIDC connection keys; resolve duplicate names before migrating.');
                }
                $values[$to.$name] = $values[$from.$name];
                unset($values[$from.$name]);
                $changed = true;
            }
            if ($changed) {
                $row->values = $values;
                $row->version++;
                $row->save();
            }
        });
    }
};
