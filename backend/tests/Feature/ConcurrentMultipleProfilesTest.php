<?php

namespace Tests\Feature;

use App\Exceptions\UserAdministrationException;
use App\Models\PermissionRole;
use App\Models\User;
use App\Services\PermissionRoleAdministration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentMultipleProfilesTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_edits_to_two_profiles_cannot_remove_the_only_administrators_access(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent administrator row locks require PostgreSQL.');
        }
        $profiles = collect(['First admin', 'Second admin'])->map(fn ($name) => PermissionRole::create(['name' => $name, 'permissions' => ['administration.view']]));
        $user = User::factory()->create(['permission_role_ids' => $profiles->pluck('id')->all()]);
        $database = DB::connection()->getDatabaseName();
        $userId = $user->id;
        $operations = [];
        foreach ($profiles as $profile) {
            $profileId = $profile->id;
            $operations[] = static function () use ($profileId, $userId, $database) {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database) {
                    return 'wrong_test_environment';
                }
                try {
                    $profile = PermissionRole::findOrFail($profileId);
                    app(PermissionRoleAdministration::class)->save(User::findOrFail($userId), ['version' => $profile->version, 'name' => $profile->name, 'permissions' => []], $profile);

                    return 'updated';
                } catch (UserAdministrationException $exception) {
                    return $exception->errorCode;
                }
            };
        }
        $results = Concurrency::driver('process')->run($operations, timeout: 20);
        sort($results);
        $this->assertSame(['updated', 'users.errors.lastAdministrator'], $results);
        $this->assertTrue($user->fresh()->isAdministrator());
        $this->assertSame(1, $user->fresh()->session_version);
        // Clean only this isolated multi-profile fixture before schema rollback.
        $user->delete();
    }
}
