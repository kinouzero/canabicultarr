<?php

namespace App\Services;

use App\Exceptions\InstanceSettingsException;
use App\Models\ConnectionSetting;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ConnectionConfiguration
{
    private array $defaults = [];

    public function __construct()
    {
        foreach (config('connection_settings') as $fields) {
            foreach ($fields as $field) {
                $this->defaults[$field['path']] = config($field['path']);
            }
        }
    }

    public function apply(): void
    {
        // Never bake database overrides or their secrets into Laravel's config cache.
        if (app()->runningInConsole() && in_array($_SERVER['argv'][1] ?? '', ['config:cache', 'optimize', 'package:discover'], true)) {
            return;
        }
        // Package discovery, initial setup and migrations must work before the DB exists.
        try {
            if (! Schema::hasTable('connection_settings')) {
                return;
            }
            $rows = ConnectionSetting::all();
        } catch (QueryException) {
            return;
        }
        foreach ($rows as $row) {
            $this->applyRow($row);
        }
    }

    private function applyRow(ConnectionSetting $row): void
    {
        $values = $row->values ?? [];
        $changedDisks = [];
        foreach (config('connection_settings.'.$row->id, []) as $name => $field) {
            $value = $field['locked'] ? $this->defaults[$field['path']] : ($values[$name] ?? $this->defaults[$field['path']]);
            if (str_starts_with($field['path'], 'filesystems.disks.') && config($field['path']) !== $value) {
                $changedDisks[] = explode('.', $field['path'])[2];
            }
            config([$field['path'] => $value]);
        }
        if ($row->id === 'mail') {
            config(['mail.mailers.smtp.scheme' => (int) config('mail.mailers.smtp.port') === 465 ? 'smtps' : 'smtp']);
        }
        // Queue workers keep resolved adapters between jobs; changed credentials must take effect.
        if ($changedDisks !== []) {
            Storage::forgetDisk(array_values(array_unique($changedDisks)));
        }
    }

    public function payload(string $group): array
    {
        $row = ConnectionSetting::findOrFail($group);
        $fields = [];
        $values = $row->values ?? [];
        foreach (config('connection_settings.'.$group) as $name => $field) {
            $value = config($field['path']);
            $fields[] = ['name' => $name, 'type' => $field['type'], 'options' => $field['options'],
                'locked' => $field['locked'], 'hasValue' => $value !== null && $value !== '',
                'value' => $field['type'] === 'secret' ? null : $value,
                'overridden' => ! $field['locked'] && array_key_exists($name, $values),
                ...($field['type'] === 'interval' ? ['min' => RemoteSyncSchedule::MIN_INTERVAL, 'max' => RemoteSyncSchedule::MAX_INTERVAL, 'step' => RemoteSyncSchedule::MIN_INTERVAL] : [])];
        }

        return ['version' => $row->version, 'fields' => $fields];
    }

    public function save(User $actor, string $group, array $data): void
    {
        DB::transaction(function () use ($actor, $group, $data) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($current->session_version !== $actor->session_version) {
                throw new AuthenticationException;
            }
            Gate::forUser($current)->authorize('instance-settings.manage');
            $row = ConnectionSetting::whereKey($group)->lockForUpdate()->firstOrFail();
            if ($row->version !== $data['version']) {
                throw new InstanceSettingsException('instanceSettings.conflict');
            }
            $values = $row->values ?? [];
            foreach ($data['values'] ?? [] as $name => $value) {
                if (config('connection_settings.'.$group.'.'.$name.'.locked', true)) {
                    throw ValidationException::withMessages(['values.'.$name => ['connectionSettings.locked']]);
                }
                if ($value === null || $value === '') {
                    unset($values[$name]);
                } else {
                    $type = config('connection_settings.'.$group.'.'.$name.'.type');
                    $values[$name] = match ($type) {
                        'port', 'interval' => (int) $value,
                        'boolean' => (bool) $value,
                        default => $value,
                    };
                }
            }
            if ($data['reset'] ?? false) {
                $values = [];
            }
            $row->values = $values;
            $row->version++;
            $row->save();
            $this->applyRow($row);
        });
        // Configuration values, including non-secret URLs, never enter logs.
        Log::notice('connection_settings.updated', ['actor_uuid' => $actor->uuid, 'group' => $group]);
    }
}
