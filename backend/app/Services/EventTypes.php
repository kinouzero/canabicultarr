<?php

namespace App\Services;

use App\Exceptions\EventJournalException;
use App\Models\EventEntry;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventTypes
{
    public const ICONS = ['journal', 'eye', 'scissors', 'water', 'warning', 'message', 'pot', 'leaf', 'info'];

    public const CONTEXTS = ['none', 'site', 'room', 'zone', 'asset', 'sensor'];

    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at'];

    public function search(array $criteria)
    {
        Gate::authorize('viewAny', EventEntry::class);
        $query = EventType::query();
        if (($criteria['state'] ?? 'all') !== 'all') {
            $query->where('is_archived', $criteria['state'] === 'archived');
        }
        if (($search = trim($criteria['search'] ?? '')) !== '') {
            $query->whereRaw("name_key LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%']);
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?EventType $target = null): EventType
    {
        try {
            return DB::transaction(function () use ($actor, $data, $target) {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if ($current->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                Gate::forUser($current)->authorize('instance-settings.manage');
                $type = $target ? EventType::whereKey($target->id)->lockForUpdate()->firstOrFail() : new EventType;
                if ($target && $type->version !== $data['version']) {
                    throw new EventJournalException('eventTypes.errors.conflict');
                }
                $type->fill($data);
                $type->name = trim($type->name);
                $type->name_key = Str::lower($type->name);
                if ($type->isDirty()) {
                    $type->version = $target ? $type->version + 1 : 1;
                    $type->save();
                    Log::notice('event.type_saved', ['actor_uuid' => $current->uuid, 'type_uuid' => $type->id, 'version' => $type->version]);
                }

                return $type;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'eventTypes.errors.nameTaken']);
        }
    }
}
