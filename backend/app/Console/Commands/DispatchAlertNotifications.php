<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DispatchAlertNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Dispatch opted-in alert notifications with bounded retries';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        if (! Schema::hasTable('notification_deliveries')) {
            return self::SUCCESS;
        }
        $lock = Cache::lock('alert-notifications.dispatch', 300);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $ids = NotificationDelivery::where(fn ($q) => $q->where(fn ($p) => $p->where('status', 'pending')->where('next_attempt_at', '<=', now()))->orWhere(fn ($p) => $p->where('status', 'sending')->where('lease_until', '<=', now())))->orderBy('created_at')->orderBy('id')->limit(10)->pluck('id');
            $counts = [];
            foreach ($ids as $id) {
                $status = $dispatcher->dispatch($id);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $this->line(json_encode($counts));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
