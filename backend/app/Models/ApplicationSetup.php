<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationSetup extends Model
{
    protected $table = 'application_setup';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['token_expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
