<?php

namespace App\Models;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EventEntry extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['title', 'body', 'category', 'severity', 'occurred_at'];

    protected $hidden = ['search_text', 'created_by'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['category' => EventCategory::class, 'severity' => EventSeverity::class, 'occurred_at' => 'immutable_datetime', 'version' => 'integer', 'subject_snapshot' => 'array', 'event_type_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (EventEntry $entry) {
            $entry->title = trim($entry->title);
            $entry->search_text = Str::lower($entry->title."\n".$entry->body);
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EventRevision::class);
    }

    public function snapshot(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'category' => $this->category->value,
            'severity' => $this->severity->value, 'occurredAt' => $this->occurred_at->toISOString(),
            'subject' => $this->subject_snapshot, 'eventType' => $this->event_type_snapshot];
    }
}
