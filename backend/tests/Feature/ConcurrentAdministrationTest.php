<?php

namespace Tests\Feature;

use App\Exceptions\UserAdministrationException;
use App\Models\User;
use App\Services\UserAdministration;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentAdministrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_self_demotions_leave_an_active_administrator(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent row locking requires PostgreSQL.');
        }

        $administrators = User::factory()->count(2)->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $database = DB::connection()->getDatabaseName();
        $operations = [];
        foreach ($administrators as $administrator) {
            $id = $administrator->id;
            $data = ['name' => $administrator->name, 'email' => $administrator->email, 'permission_role_ids' => [AccessProfileSeeder::defaultProfile()->id], 'is_active' => true];
            $operations[] = static function () use ($id, $data, $database) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }
                try {
                    $user = User::findOrFail($id);
                    app(UserAdministration::class)->update($user, $user, $data);

                    return 'updated';
                } catch (UserAdministrationException $exception) {
                    return $exception->errorCode;
                }
            };
        }

        $results = Concurrency::driver('process')->run($operations, timeout: 20);
        sort($results);
        $this->assertSame(['updated', 'users.errors.lastAdministrator'], $results);
        $this->assertSame(1, User::withPermission('administration.view')->where('is_active', true)->count());
    }
}
