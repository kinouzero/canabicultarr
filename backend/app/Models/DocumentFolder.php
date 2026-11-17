<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentFolder extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'parent_id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function scopeWithChildren(Builder $query): void
    {
        // parent_key is indexed text and also represents root; IDs are UUIDs on PostgreSQL.
        $query->select('document_folders.*')->selectSub(
            DB::table('document_folders as children')->selectRaw('1')
                ->whereRaw('children.parent_key = CAST(document_folders.id AS VARCHAR)')->limit(1),
            'children_exists'
        );
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentFolder $folder) {
            $folder->name = trim($folder->name);
            $folder->name_key = Str::lower($folder->name);
            $folder->parent_key = $folder->parent_id ?? 'root';
        });
    }
}
