<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationRequest;
use App\Services\NotificationAdministration;
use App\Services\NotificationInbox;

class NotificationController extends Controller
{
    public function inbox(NotificationRequest $request, NotificationInbox $inbox)
    {
        return ['data' => $inbox->feed($request->user(), (int) $request->validated('page', 1), $request->boolean('summary'))];
    }

    public function readInbox(NotificationRequest $request, NotificationInbox $inbox)
    {
        $inbox->markRead($request->user(), $request->validated('read_through'));

        return response()->noContent();
    }

    public function status(NotificationRequest $r, NotificationAdministration $s)
    {
        return ['data' => $s->status($r->user())];
    }

    public function updateSettings(NotificationRequest $r, NotificationAdministration $s)
    {
        return ['data' => $s->saveSettings($r->user(), $r->validated())];
    }

    public function preferences(NotificationRequest $r, NotificationAdministration $s)
    {
        return ['data' => $s->preferences($r->user())];
    }

    public function updatePreferences(NotificationRequest $r, NotificationAdministration $s)
    {
        return ['data' => $s->savePreferences($r->user(), $r->validated())];
    }

    public function subscriptions(NotificationRequest $r, NotificationAdministration $s)
    {
        return ['data' => $s->subscriptions($r->user())];
    }

    public function subscribe(NotificationRequest $r, NotificationAdministration $s)
    {
        return response()->json(['data' => $s->subscribe($r->user(), $r->validated(), $r->session()->getId())], 201);
    }

    public function unsubscribe(NotificationRequest $r, string $subscription, NotificationAdministration $s)
    {
        $s->unsubscribe($r->user(), $subscription);

        return response()->noContent();
    }

    public function removeBrowser(NotificationRequest $r, NotificationAdministration $s)
    {
        $s->actor($r->user());
        $s->logoutDevice($r->user(), $r->validated('endpoint'), $r->session()->getId());

        return response()->noContent();
    }

    public function deliveries(NotificationRequest $r, NotificationAdministration $s)
    {
        $page = $s->deliveries($r->user(), (int) $r->validated('page', 1), (int) $r->validated('per_page', 20));

        return ['data' => $page->map(fn ($d) => ['id' => $d->id, 'episodeId' => $d->alert_episode_id, 'event' => $d->event, 'channel' => $d->channel, 'status' => $d->status, 'attempts' => $d->attempts, 'errorCode' => $d->error_code, 'createdAt' => $d->created_at->toISOString(), 'sentAt' => $d->sent_at?->toISOString(), 'nextAttemptAt' => $d->next_attempt_at?->toISOString()]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]];
    }
}
