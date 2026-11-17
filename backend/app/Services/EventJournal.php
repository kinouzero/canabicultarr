<?php

namespace App\Services;

use App\Enums\EventCategory;
use App\Exceptions\EventJournalException;
use App\Models\EventEntry;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventJournal
{
    public const SORT_COLUMNS = ['occurred' => 'occurred_at', 'created' => 'created_at', 'name' => 'title'];

    public function __construct(private EventContext $context) {}

    public function search(array $criteria): LengthAwarePaginator
    {
        $query = EventEntry::with('author:id,uuid,name');
        foreach (['category', 'event_type_id', 'severity', 'site_id', 'room_id', 'zone_id', 'asset_id', 'sensor_id'] as $filter) {
            if (! empty($criteria[$filter])) {
                $query->where($filter, $filter === 'event_type_id' ? Str::lower($criteria[$filter]) : $criteria[$filter]);
            }
        }
        $from = empty($criteria['date_from']) ? null : CarbonImmutable::parse($criteria['date_from'])->utc();
        $to = empty($criteria['date_to']) ? null : CarbonImmutable::parse($criteria['date_to'])->utc();
        if ($from && $to && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['date_to' => ['events.validation.rangeInvalid']]);
        }
        if ($from) {
            $query->where('occurred_at', '>=', $from->format('Y-m-d H:i:s.u'));
        }
        if ($to) {
            $query->where('occurred_at', '<=', $to->format('Y-m-d H:i:s.u'));
        }
        $search = trim($criteria['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]);
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS, 'occurred_desc')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function revisions(EventEntry $entry, array $criteria): LengthAwarePaginator
    {
        return $entry->revisions()->with('editor:id,uuid,name')->orderByDesc('version')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?EventEntry $target = null): EventEntry
    {
        $creating = $target === null;
        [$entry, $fields, $changed] = DB::transaction(function () use ($actor, $data, $target, $creating) {
            $current = User::whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $entry = $creating ? new EventEntry : EventEntry::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize($creating ? 'create' : 'update', $creating ? EventEntry::class : $entry);
            if (! $creating && (int) $data['version'] !== $entry->version) {
                throw new EventJournalException('events.errors.conflict');
            }
            $entry->fill(Arr::only($data, ['title', 'body', 'category', 'severity']));
            if (isset($data['occurred_at'])) {
                $entry->occurred_at = CarbonImmutable::parse($data['occurred_at'])->utc();
            }
            if ($creating) {
                $entry->created_by = $current->id;
                $entry->author_uuid = $current->uuid;
            }
            $contextChanged = false;
            if (array_key_exists('subject', $data)) {
                $old = $entry->subject_snapshot;
                $next = $data['subject'];
                $contextChanged = ($old['type'] ?? null) !== ($next['type'] ?? null) || ($old['id'] ?? null) !== ($next['id'] ?? null);
                if ($contextChanged) {
                    $entry->forceFill($this->context->resolve($next));
                }
            }
            $custom = $entry->category === EventCategory::Custom;
            $typeId = $custom ? (array_key_exists('event_type_id', $data) ? $data['event_type_id'] : $entry->event_type_id) : null;
            if (! $custom && ! empty($data['event_type_id'])) {
                throw ValidationException::withMessages(['event_type_id' => 'eventTypes.validation.type']);
            }
            $typeId = $typeId === null ? null : Str::lower($typeId);
            $typeChanged = $entry->event_type_id !== $typeId;
            if ($custom) {
                $type = $typeId ? EventType::whereKey($typeId)->lockForUpdate()->first() : null;
                if (! $type || (($creating || $typeChanged || $contextChanged) && $type->is_archived)) {
                    throw ValidationException::withMessages(['event_type_id' => 'eventTypes.validation.type']);
                }
                if (($creating || $typeChanged || $contextChanged) && ! in_array($entry->subject_snapshot['type'] ?? 'none', $type->contexts, true)) {
                    throw ValidationException::withMessages(['subject' => 'eventTypes.validation.scope']);
                }
                if ($creating || $typeChanged) {
                    $entry->event_type_snapshot = $type->snapshot();
                }
            } elseif ($typeChanged) {
                $entry->event_type_snapshot = null;
            }
            $entry->event_type_id = $typeId;
            app(LocationAccess::class)->assertSubject($current, $entry->subject_snapshot);
            $fields = array_values(array_intersect(array_keys($entry->getDirty()), ['title', 'body', 'category', 'severity', 'occurred_at']));
            if ($typeChanged) {
                $fields[] = 'event_type_id';
            }
            if ($contextChanged) {
                $fields[] = 'subject';
            }
            $changed = $creating || $fields !== [];
            if ($changed) {
                $entry->version = $creating ? 1 : $entry->version + 1;
                $entry->save();
                $entry->revisions()->create(['version' => $entry->version, 'changed_by' => $current->id,
                    'editor_uuid' => $current->uuid, 'snapshot' => $entry->snapshot(), 'recorded_at' => now()]);
            }

            return [$entry->load('author:id,uuid,name'), $fields, $changed];
        });
        if ($changed) {
            Log::notice($creating ? 'event.created' : 'event.corrected', ['actor_uuid' => $actor->uuid, 'event_uuid' => $entry->id, 'version' => $entry->version, 'fields' => $fields]);
        }

        return $entry;
    }
}
