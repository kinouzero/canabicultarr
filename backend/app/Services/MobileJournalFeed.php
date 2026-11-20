<?php

namespace App\Services;

use App\Enums\EventCategory;
use App\Models\EventEntry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class MobileJournalFeed
{
    private const LIMIT = 50;

    public function record(EventEntry $event, ?string $previousSite): void
    {
        $site = self::siteOf($event);
        if ($previousSite === null && $site === null) {
            return;
        }

        // The counter's row lock is held until the journal write commits.
        DB::table('mobile_journal_sequence')->where('id', 1)->increment('position');
        DB::table('mobile_journal_changes')->insert([
            'position' => $this->position(),
            'event_id' => $event->id,
            'previous_site_id' => $previousSite,
            'site_id' => $site,
        ]);
    }

    public static function siteOf(EventEntry $event): ?string
    {
        return $event->category === EventCategory::Note && $event->subject_kind === 'site' ? $event->site_id : null;
    }

    public function read(array $sites, string $scope, ?string $cursor): array
    {
        $position = $this->position();
        $hasMore = false;
        if ($cursor === null) {
            // Read the watermark first. Any newer note may also be replayed on the next request.
            $changes = $this->notes($sites)->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::LIMIT)->get()
                ->map(fn (EventEntry $entry) => $this->upsert($entry))->all();
        } else {
            $after = $this->decode($cursor, $scope);
            abort_if($after === null || $after > $position, 409);

            $rows = DB::table('mobile_journal_changes')->where('position', '>', $after)->where('position', '<=', $position)
                ->where(fn ($query) => $query->whereIn('site_id', $sites)->orWhereIn('previous_site_id', $sites))
                ->orderBy('position')->limit(self::LIMIT + 1)->get();
            $hasMore = $rows->count() > self::LIMIT;
            $rows = $rows->take(self::LIMIT);
            $current = $this->notes($sites)->whereIn('id', $rows->pluck('event_id'))->get()->keyBy('id');
            $changes = $rows->unique('event_id')->map(function ($row) use ($current) {
                $entry = $current->get($row->event_id);

                return $entry ? $this->upsert($entry) : ['id' => $row->event_id, 'type' => 'remove'];
            })->values()->all();
            if ($hasMore) {
                $position = (int) $rows->last()->position;
            }
        }

        return [
            'changes' => $changes,
            'cursor' => Crypt::encryptString($scope.':'.$position),
            'hasMore' => $hasMore,
        ];
    }

    private function notes(array $sites): Builder
    {
        return EventEntry::query()->where('category', EventCategory::Note->value)->where('subject_kind', 'site')->whereIn('site_id', $sites)
            ->select(['id', 'site_id', 'title', 'body', 'occurred_at']);
    }

    private function upsert(EventEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => 'upsert',
            'site_id' => $entry->site_id,
            'title' => $entry->title,
            'body' => $entry->body,
            'occurred_at' => $entry->occurred_at->toISOString(),
        ];
    }

    private function position(): int
    {
        return (int) DB::table('mobile_journal_sequence')->where('id', 1)->value('position');
    }

    private function decode(string $cursor, string $scope): ?int
    {
        try {
            [$binding, $position] = array_pad(explode(':', Crypt::decryptString($cursor), 2), 2, '');
        } catch (DecryptException) {
            return null;
        }

        return hash_equals($scope, $binding) && ctype_digit($position) ? (int) $position : null;
    }
}
