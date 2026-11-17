<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CommentTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_members_cannot_manage_shared_templates(): void
    {
        Cache::flush();
        $this->getJson('/api/v1/comment-templates')->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/v1/comment-templates')->assertOk()->assertJsonPath('canManage', false);
        $this->postJson('/api/v1/comment-templates', ['name' => 'Denied', 'body' => 'Text'])->assertForbidden();
    }

    public function test_templates_preserve_literal_text_validate_and_require_current_version(): void
    {
        Cache::flush();
        $admin = User::factory()->create(['permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
        $body = 'Watered {{ plant.name }} on {{ date.today }}. <script>literal</script>';
        $created = $this->actingAs($admin)->postJson('/api/v1/comment-templates', ['name' => 'Watering', 'body' => $body])->assertCreated()->assertJsonPath('data.body', $body)->assertJsonPath('data.version', 1);
        $id = $created->json('data.id');
        $this->postJson('/api/v1/comment-templates', ['name' => 'watering', 'body' => 'Duplicate'])->assertUnprocessable();
        $this->patchJson('/api/v1/comment-templates/'.$id, ['name' => 'Watering', 'body' => 'Updated', 'version' => 1, 'is_archived' => true])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/comment-templates/'.$id, ['name' => 'Watering', 'body' => 'Stale', 'version' => 1])->assertConflict();
        $this->getJson('/api/v1/comment-templates')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/comment-templates?status=archived&search=water')->assertJsonCount(1, 'data');
        foreach ([['name' => ' ', 'body' => 'Text'], ['name' => 'Empty', 'body' => ' '], ['name' => 'Long', 'body' => str_repeat('x', 2001)]] as $input) {
            $this->postJson('/api/v1/comment-templates', $input)->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/comment-templates/'.$id, ['name' => 'Denied', 'body' => 'Text', 'version' => 2])->assertForbidden();
    }
}
