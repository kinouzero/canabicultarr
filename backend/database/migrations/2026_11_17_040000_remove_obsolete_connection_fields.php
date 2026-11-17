<?php

use App\Models\ConnectionSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (['homebridge' => ['HOMEBRIDGE_TOKEN'], 'mail' => ['MAIL_URL', 'MAIL_SCHEME']] as $group => $removed) {
                $row = ConnectionSetting::whereKey($group)->lockForUpdate()->first();
                if (! $row) {
                    continue;
                }
                $values = $row->values ?? [];
                $updated = array_diff_key($values, array_flip($removed));
                if ($updated !== $values) {
                    $row->values = $updated;
                    $row->version++;
                    $row->save();
                }
            }
        });
    }

    public function down(): void
    {
        // Removed credentials are deliberately not recoverable by rolling back.
    }
};
