<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingEmailChange extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'queued_at' => 'immutable_datetime', 'user_version' => 'integer', 'requester_version' => 'integer'];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
