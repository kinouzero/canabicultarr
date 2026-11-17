<?php

namespace Tests;

use App\Models\Asset;
use App\Services\AssetContexts;
use App\Services\AssetLocations;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function configureHomebridge(string $url = 'https://hb.example.test', string $token = 'fictional-hb-token'): void
    {
        config(['homebridge.url' => $url, 'homebridge.username' => 'test-user', 'homebridge.password' => 'test-password']);
        $key = 'homebridge:session:'.hash_hmac('sha256', json_encode([$url, 'test-user', 'test-password']), config('app.key'));
        Cache::put($key, ['token' => Crypt::encryptString($token)], 3600);
    }

    protected function placeAsset(Asset $asset, array $attributes): Asset
    {
        $row = array_intersect_key($attributes, array_flip(['site_id', 'room_id', 'zone_id']));
        $asset->deferContext = true;
        $asset->forceFill(array_diff_key($attributes, $row))->save();
        $rows = array_filter($row) ? [array_replace(['site_id' => null, 'room_id' => null, 'zone_id' => null], $row)] : [];
        app(AssetLocations::class)->replace($asset, $rows);
        $asset->deferContext = false;
        app(AssetContexts::class)->capture($asset->id);

        return $asset;
    }

    protected function reapplySchemaFrom(string $firstMigration): void
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files, SORT_STRING);
        $migrations = array_map(fn ($file) => require $file, array_values(array_filter($files, fn ($file) => basename($file) >= $firstMigration)));
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        foreach ($migrations as $migration) {
            $migration->up();
        }
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        $database = $connection->getDatabaseName();
        $isolated = $connection->getDriverName() === 'sqlite'
            ? $database === ':memory:'
            : preg_match('/(^|[_-])test([_-]|$)/i', $database) === 1;

        if (! $app->environment('testing') || ! $isolated) {
            throw new RuntimeException('Feature tests require APP_ENV=testing and an isolated test database.');
        }

        return $app;
    }
}
