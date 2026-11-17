<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommentTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'body', 'is_archived'];

    protected $attributes = ['version' => 1, 'is_archived' => false];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_archived' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (CommentTemplate $template) {
            $template->name = trim($template->name);
            $template->name_key = Str::lower($template->name);
        });
    }
}
