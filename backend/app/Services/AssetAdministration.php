<?php

namespace App\Services;

use App\Enums\AssetCategory;
use App\Enums\DelegatedPermission;
use App\Exceptions\AssetException;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetContext;
use App\Models\DeviceCommand;
use App\Models\PumpActivation;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssetAdministration
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at'];

    private const FIELDS = ['name', 'category', 'status', 'asset_tag', 'manufacturer', 'model', 'serial_number', 'description', 'pump_control', 'power_control'];

    public function search(array $criteria): LengthAwarePaginator
    {
        $query = Asset::with(Asset::assignmentRelations())->withExists(Asset::INTEGRATION_RELATIONS)->withCount(['plants', 'sensors', 'sensors as enabled_channels_count' => fn ($q) => $q->where('is_enabled', true)]);
        foreach (['category', 'status'] as $filter) {
            if (! empty($criteria[$filter])) {
                $query->where($filter, $criteria[$filter]);
            }
        }
        $search = trim($criteria['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("asset_tag_key LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(serial_number) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('sensors', fn ($sensor) => $sensor->where(fn ($channel) => $channel->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("channel_key LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('measurementType', fn ($type) => $type->where(fn ($match) => $match->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("code LIKE ? ESCAPE '!'", [$pattern]))))));
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function assignments(Asset $asset, array $criteria): LengthAwarePaginator
    {
        return $asset->assignments()->with([...AssetAssignment::locationRelations(), 'user:id,uuid'])
            ->orderByDesc('assigned_at')->orderByDesc('id')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?Asset $target = null): Asset
    {
        $creating = $target === null;
        try {
            [$asset, $fields, $moved] = DB::transaction(function () use ($actor, $data, $target, $creating) {
                $current = User::whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                if (! $current->is_active || $current->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                $asset = $creating ? new Asset : Asset::whereKey($target->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize($creating ? 'create' : 'update', $creating ? Asset::class : $asset);
                $before = $creating ? [] : $asset->locationList();
                $references = $data['locations'] ?? null;
                if (isset($data['add_location'])) {
                    $addition = $data['add_location'];
                    $references = array_map(fn ($entry) => Arr::only($entry, ['type', 'id']), $before);
                    if (! in_array($addition, $references)) {
                        $references[] = $addition;
                    }
                    if (count($references) > 100) {
                        throw new AssetException('assets.validation.locationInvalid', 422);
                    }
                }
                $placements = $references !== null ? app(AssetLocations::class)->resolve($references, $current) : null;
                $asset->deferContext = true;
                $asset->fill(Arr::only($data, self::FIELDS));
                if (array_key_exists('power_control', $data) && $asset->power_control) {
                    if (! $asset->category->supportsPowerControl()) {
                        throw new AssetException('assets.power.invalid', 422);
                    }
                    $settings = Arr::only($asset->power_control, ['provider', 'entity', 'mode', 'on_at', 'off_at', 'timezone']);
                    $previous = $asset->getOriginal('power_control');
                    if (! $previous || Arr::only($previous, ['provider', 'entity']) != Arr::only($settings, ['provider', 'entity'])) {
                        abort_unless($current->hasOperationalPermission(DelegatedPermission::HomeAssistant), 403);
                        app(LocationAccess::class)->assertGlobal($current);
                    }

                    if (! $creating && ! app(DeviceControlClient::class)->matchesConnection($asset, $settings['provider'])) {
                        throw new AssetException('assets.power.connectionMismatch', 422);
                    }
                    $client = $settings['provider'] === 'home_assistant' ? app(HomeAssistantClient::class) : app(HomebridgeClient::class);
                    $settings = [...$settings, 'instance_key' => $client->instanceKey(), 'owner_uuid' => $current->uuid];
                    $old = $asset->getOriginal('power_control');
                    $revision = $old && Arr::except($old, ['revision']) == $settings ? $old['revision'] : (string) Str::uuid();
                    $asset->power_control = [...$settings, 'revision' => $revision];
                }
                if (! $asset->category->supportsPowerControl()) {
                    $asset->power_control = null;
                }
                if (! $creating && DeviceCommand::where('asset_id', $asset->id)->where('status', 'pending')->where('created_at', '>', now()->subMinutes(2))->exists()) {
                    throw new AssetException('assets.power.busy');
                }

                if (array_key_exists('pump_control', $data) && $asset->pump_control) {
                    if (! $creating && ! app(DeviceControlClient::class)->matchesConnection($asset, $asset->pump_control['provider'])) {
                        throw new AssetException('assets.power.connectionMismatch', 422);
                    }
                    $client = $asset->pump_control['provider'] === 'home_assistant' ? app(HomeAssistantClient::class) : app(HomebridgeClient::class);
                    $asset->pump_control = [...$asset->pump_control, 'instance_key' => $client->instanceKey()];
                }
                if (! $creating && $asset->isDirty(['pump_control', 'status', 'category']) && PumpActivation::where('asset_id', $asset->id)->whereNull('stopped_at')->where('control->mode', '!=', 'auto')->exists()) {
                    throw new AssetException('assets.pump.running');
                }
                if ($asset->pump_control && ($asset->pump_control['mode'] ?? 'auto') !== 'auto' && preg_match('/^(button|input_button)\./', $asset->pump_control['entity'])) {
                    throw new AssetException('assets.pump.notStoppable', 422);
                }
                if ($asset->category !== AssetCategory::Pump && $asset->pump_control !== null) {
                    $asset->pump_control = null;
                }
                $fields = array_values(array_intersect(array_keys($asset->getDirty()), self::FIELDS));
                $asset->save();
                if ($placements !== null) {
                    app(AssetLocations::class)->replace($asset, $placements);
                }
                app(LocationAccess::class)->assertRecord($current, $asset);
                $moved = $before !== $asset->locationList();
                if ($moved) {
                    $fields[] = 'locations';
                }
                $asset->deferContext = false;
                app(AssetContexts::class)->capture($asset->id);
                if ($moved) {
                    $assignment = $asset->assignments()->create([
                        'locations_snapshot' => $asset->locationList(),
                        'asset_context_id' => AssetContext::where('asset_id', $asset->id)->orderByDesc('effective_at')->orderByDesc('id')->value('id'),
                        'user_id' => $current->id, 'assigned_at' => now(),
                    ]);
                    $rows = $asset->locations->map(fn ($location) => ['asset_assignment_id' => $assignment->id, 'site_id' => $location->site_id, 'room_id' => $location->room_id, 'zone_id' => $location->zone_id])->all();
                    foreach (array_chunk($rows, 100) as $batch) {
                        DB::table('asset_assignment_locations')->insert($batch);
                    }
                }

                return [$asset->load(Asset::assignmentRelations())->loadCount(['plants', 'sensors', 'sensors as enabled_channels_count' => fn ($q) => $q->where('is_enabled', true)])->loadExists(Asset::INTEGRATION_RELATIONS), $fields, $moved];
            });
        } catch (UniqueConstraintViolationException) {
            throw new AssetException('assets.errors.tagTaken');
        }
        Log::notice($creating ? 'asset.created' : 'asset.updated', [
            'actor_uuid' => $actor->uuid, 'asset_uuid' => $asset->id,
            'fields' => $fields, 'assignment_changed' => $moved,
        ]);

        return $asset;
    }
}
