<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimescaleDatabaseTest extends TestCase
{
    public function test_can_store_and_query_time_series(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('TimescaleDB integration requires PostgreSQL.');
        }

        DB::beginTransaction();

        try {
            DB::statement('CREATE TABLE ci_timescale_measurements (recorded_at timestamptz NOT NULL, value double precision NOT NULL)');
            DB::select("SELECT create_hypertable('ci_timescale_measurements', by_range('recorded_at'))");

            DB::table('ci_timescale_measurements')->insert([
                'recorded_at' => '2026-01-01 00:00:00+00',
                'value' => 12.5,
            ]);

            $this->assertNotNull(DB::selectOne(
                'SELECT hypertable_name FROM timescaledb_information.hypertables WHERE hypertable_name = ?',
                ['ci_timescale_measurements'],
            ));
            $this->assertSame(12.5, (float) DB::table('ci_timescale_measurements')->value('value'));
        } finally {
            DB::rollBack();
        }
    }
}
