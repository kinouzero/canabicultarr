<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class DashboardProfile
{
    public static function capture(callable $request): void
    {
        if (! getenv('DASHBOARD_SQL_PROFILE') || DB::getDriverName() !== 'pgsql') {
            return;
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with(strtolower($query->sql), 'select')) {
                $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'milliseconds' => $query->time];
            }
        });
        $result = [];
        $original = DB::selectOne('SHOW jit')->jit;
        try {
            foreach ([false, true] as $analyzed) {
                if ($analyzed) {
                    DB::statement('ANALYZE');
                }
                foreach (['on', 'off'] as $jit) {
                    DB::statement($jit === 'on' ? 'SET LOCAL jit = on' : 'SET LOCAL jit = off');
                    foreach ([true, false] as $metadata) {
                        $samples = [];
                        for ($index = 0; $index < 3; $index++) {
                            $queries = [];
                            $start = hrtime(true);
                            $request($metadata);
                            $samples[] = ['milliseconds' => (hrtime(true) - $start) / 1e6, 'query_count' => count($queries), 'sql_milliseconds' => array_sum(array_column($queries, 'milliseconds'))];
                        }
                        usort($queries, fn ($a, $b) => $b['milliseconds'] <=> $a['milliseconds']);
                        $slowest = $queries[0];
                        $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$slowest['sql'], $slowest['bindings']);
                        $result[] = ['metadata' => $metadata, 'analyzed' => $analyzed, 'jit' => $jit, 'samples' => $samples, 'slowest' => $slowest, 'plan' => $plan];
                    }
                }
            }
            file_put_contents('/tmp/cana-dashboard-profile.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } finally {
            DB::statement($original === 'on' ? 'SET LOCAL jit = on' : 'SET LOCAL jit = off');
        }
    }
}
