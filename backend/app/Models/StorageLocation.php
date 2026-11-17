<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StorageLocation extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'is_archived'];

    protected $hidden = ['migration_origin'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean'];
    }
}
