<?php

namespace App\Console\Commands;

use App\Models\AlertRule;
use App\Services\AlertEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate enabled monitoring rules and update in-app alert episodes';

    public function handle(AlertEvaluator $evaluator): int
    {
        if (! Schema::hasTable('alert_rules')) {
            return self::SUCCESS;
        }
        $lock = Cache::lock('monitoring-alerts.evaluate', 300);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $rules = AlertRule::where('is_enabled', true)->orderByRaw('CASE WHEN last_evaluated_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_evaluated_at')->orderBy('id')->limit(500)->get();
            $counts = [];
            foreach ($rules as $rule) {
                $result = $evaluator->evaluate($rule)->last_result;
                $counts[$result] = ($counts[$result] ?? 0) + 1;
            }
            $this->line(json_encode($counts));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
