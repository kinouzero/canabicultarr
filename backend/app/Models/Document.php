<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Document extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'description', 'is_archived'];

    protected $hidden = ['disk', 'path', 'search_text', 'uploaded_by'];

    protected $attributes = ['version' => 1, 'is_archived' => false];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_archived' => 'boolean', 'size_bytes' => 'integer', 'subject_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Document $document) {
            $document->title = trim($document->title);
            $document->search_text = Str::lower($document->title."\n".$document->description."\n".$document->original_name);
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
