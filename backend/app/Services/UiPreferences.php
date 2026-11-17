<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

class UiPreferences
{
    public const MAX_FIELDS = 4096;

    public const MAX_BYTES = 1048576;

    public function read(User $user): array
    {
        return array_map(fn (string $value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR), Redis::hgetall($this->key($user)));
    }

    public function update(User $user, array $entries): void
    {
        // Each field is independent: updating a zone cannot overwrite other sections.
        $values = [];
        foreach ($entries as $entry) {
            $values[$entry['key']] = json_encode($entry['value'] ?? '', JSON_THROW_ON_ERROR);
        }
        // Enforce the account budget atomically across concurrent independent field updates.
        $arguments = [];
        foreach ($values as $key => $value) {
            array_push($arguments, $key, $value);
        }
        $saved = Redis::eval(<<<'LUA'
local fields = redis.call('HLEN', KEYS[1])
local bytes = fields == 0 and 0 or tonumber(redis.call('GET', KEYS[2]))
-- Initialize accounting once after a metadata eviction, preserving existing preferences.
if not bytes then
    local rows = redis.call('HGETALL', KEYS[1])
    bytes = 0
    for i = 1, #rows, 2 do bytes = bytes + #rows[i] + #rows[i + 1] end
end
for i = 3, #ARGV, 2 do
    local key, value = ARGV[i], ARGV[i + 1]
    if redis.call('HEXISTS', KEYS[1], key) == 1 then
        bytes = bytes - redis.call('HSTRLEN', KEYS[1], key) + #value
    else
        fields = fields + 1
        bytes = bytes + #key + #value
    end
end
if fields > tonumber(ARGV[1]) or bytes > tonumber(ARGV[2]) then return 0 end
redis.call('HSET', KEYS[1], unpack(ARGV, 3))
redis.call('SET', KEYS[2], bytes)
return 1
LUA, 2, $this->key($user), $this->key($user).':bytes', self::MAX_FIELDS, self::MAX_BYTES, ...$arguments);
        if (! $saved) {
            throw ValidationException::withMessages(['entries' => ['collection.preferenceLimit']]);
        }
    }

    public function key(User $user): string
    {
        return 'ui-preferences:'.$user->uuid;
    }
}
