<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BluetoothDevice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['device_key', 'address'];

    protected $attributes = ['is_enabled' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['address' => 'encrypted', 'is_enabled' => 'boolean', 'version' => 'integer'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function links()
    {
        return $this->hasMany(BluetoothLink::class);
    }
}
