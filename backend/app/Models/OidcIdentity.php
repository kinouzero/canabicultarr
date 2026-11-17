<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OidcIdentity extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'issuer', 'subject'];
}
