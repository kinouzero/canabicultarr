<?php

namespace App\Models;

use App\Models\Concerns\HasAssetLocation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssetLocation extends Model
{
    use HasAssetLocation, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];
}
