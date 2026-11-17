<?php

use App\Services\AssetPowerControl;
use App\Services\InstanceBackup;
use App\Services\PumpControl;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('measurements:collect-homebridge')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('measurements:collect')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('measurements:events ha')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('measurements:events hb')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping(5)->runInBackground();

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping(5)->runInBackground();

Artisan::command('pumps:stop-due', function () {
    app(PumpControl::class)->stopDue();
})->purpose('Stop pumps whose timers have elapsed');

Schedule::command('measurements:retain')->dailyAt('03:00')->timezone('UTC')->withoutOverlapping(5)->runInBackground();

Schedule::command('reports:cleanup-exports')->everyMinute()->withoutOverlapping(2)->runInBackground();

Artisan::command('devices:schedule', function () {
    app(AssetPowerControl::class)->enqueueDue();
})->purpose('Queue current hardware power schedule events');
Schedule::command('devices:schedule')->everyMinute()->withoutOverlapping(2)->runInBackground();

if (config('backup.enabled')) {
    if (InstanceBackup::scheduleIsValid()) {
        $backup = Schedule::command('backup:create')->timezone(config('backup.timezone'))->runInBackground();
        if (config('backup.frequency') === 'weekly') {
            $backup->weeklyOn((int) config('backup.day'), config('backup.time'));
        } else {
            $backup->dailyAt(config('backup.time'));
        }
    } else {
        Log::warning('backup.schedule_invalid');
    }
}
