<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'icon', 'contexts', 'is_archived'];

    protected $hidden = ['name_key'];

    protected $attributes = ['version' => 1, 'is_archived' => false];

    protected function casts(): array
    {
        return ['contexts' => 'array', 'version' => 'integer', 'is_archived' => 'boolean'];
    }

    public function snapshot(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'icon' => $this->icon, 'version' => $this->version, 'contexts' => $this->contexts];
    }
}
