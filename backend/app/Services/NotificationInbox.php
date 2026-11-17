<?php

namespace App\Services;

use App\Models\AlertEpisode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationInbox
{
    public function __construct(private NotificationAdministration $administration) {}

    public function feed(User $actor, int $page = 1, bool $summary = false): array
    {
        $current = $this->administration->actor($actor);
        $through = CarbonImmutable::now('UTC');
        $cursor = DB::table('notification_preferences')->where('user_id', $current->id)->value('inbox_read_at');
        $query = AlertEpisode::query()->whereRaw('COALESCE(resolved_at, opened_at) <= ?', [$through->format('Y-m-d H:i:s.u')]);
        $unread = clone $query;
        if ($cursor !== null) {
            $unread->whereRaw('COALESCE(resolved_at, opened_at) > ?', [$cursor]);
        }
        $result = ['unreadCount' => $unread->count(), 'readThrough' => $through->toISOString(), 'items' => [], 'page' => 1, 'lastPage' => 1];
        if ($summary) {
            return $result;
        }
        $items = $query->orderByRaw('COALESCE(resolved_at, opened_at) DESC')->orderByDesc('id')->paginate(20, ['*'], 'page', $page);
        $result['items'] = $items->map(function ($episode) use ($cursor) {
            $time = $episode->resolved_at ?? $episode->opened_at;

            return ['id' => $episode->id, 'name' => $episode->rule_snapshot['name'], 'sensorName' => $episode->rule_snapshot['sensor']['name'] ?? null,
                'severity' => $episode->severity, 'event' => $episode->resolved_at ? 'recovered' : 'opened', 'occurredAt' => $time->toISOString(),
                'unread' => $cursor === null || $time->greaterThan(CarbonImmutable::parse($cursor))];
        })->all();
        $result['page'] = $items->currentPage();
        $result['lastPage'] = $items->lastPage();

        return $result;
    }

    public function markRead(User $actor, string $through): void
    {
        $time = CarbonImmutable::parse($through)->utc();
        if ($time->isFuture()) {
            throw ValidationException::withMessages(['read_through' => 'notifications.errors.inputInvalid']);
        }
        DB::transaction(function () use ($actor, $time) {
            $current = $this->administration->actor($actor, lock: true);
            $query = DB::table('notification_preferences')->where('user_id', $current->id);
            $cursor = $query->value('inbox_read_at');
            if ($cursor === null || $time->greaterThan(CarbonImmutable::parse($cursor))) {
                if ($query->exists()) {
                    $query->update(['inbox_read_at' => $time->format('Y-m-d H:i:s.u')]);
                } else {
                    DB::table('notification_preferences')->insert(['user_id' => $current->id, 'inbox_read_at' => $time->format('Y-m-d H:i:s.u'), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
