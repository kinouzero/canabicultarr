<?php

namespace App\Models;

use App\Enums\DelegatedPermission;
use App\Services\LocationAccess;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HttpMeasurementSource extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'issuer_id', 'issuer_version'];

    protected $attributes = ['is_enabled' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'version' => 'integer', 'issuer_version' => 'integer', 'expires_at' => 'immutable_datetime', 'last_received_at' => 'immutable_datetime'];
    }

    public function sensor()
    {
        return $this->belongsTo(Sensor::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function credentialCurrent(): bool
    {
        $issuer = $this->issuer;

        return filled($this->token_hash) && $this->expires_at->isFuture() && $issuer && $issuer->session_version === $this->issuer_version && $issuer->hasOperationalPermission(DelegatedPermission::HomeAssistant) && app(LocationAccess::class)->record($issuer, $this->sensor);
    }
}
