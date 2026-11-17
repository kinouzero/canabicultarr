<?php

namespace App\Services;

use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\Document;
use App\Models\EventEntry;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\InventoryItem;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Plant;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\Strain;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;

class InstallationOverview
{
    private function authorize(User $actor): void
    {
        $current = User::find($actor->id);
        if (! $current || ! $current->is_active || $current->session_version !== $actor->session_version) {
            throw new AuthenticationException;
        }
        Gate::forUser($current)->authorize('dashboard.view');
    }

    public function read(User $actor): array
    {
        return app(LocationAccess::class)->run($actor, fn () => $this->build($actor));
    }

    private function build(User $actor): array
    {
        $this->authorize($actor);
        $assets = Asset::selectRaw("COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance,
            SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END) AS retired")->first();
        // EXISTS checks stop at the first matching sample: never count the hypertable.
        $measurementQuery = Measurement::usable()->where('observed_at', '<=', now()->format('Y-m-d H:i:s.u'))->whereColumn('measurements.sensor_id', 'sensors.id')->selectRaw('1');
        $measurementSql = $measurementQuery->toSql();
        $sensors = Sensor::selectRaw('COUNT(*) AS total,
            SUM(CASE WHEN is_enabled THEN 1 ELSE 0 END) AS enabled,
            SUM(CASE WHEN EXISTS ('.$measurementSql.') THEN 1 ELSE 0 END) AS with_readings,
            SUM(CASE WHEN EXISTS (SELECT 1 FROM home_assistant_links WHERE home_assistant_links.sensor_id = sensors.id AND home_assistant_links.deleted_at IS NULL) OR EXISTS (SELECT 1 FROM homebridge_links WHERE homebridge_links.sensor_id = sensors.id AND homebridge_links.deleted_at IS NULL) OR EXISTS (SELECT 1 FROM mqtt_measurement_sources WHERE mqtt_measurement_sources.sensor_id = sensors.id) OR EXISTS (SELECT 1 FROM bluetooth_links WHERE bluetooth_links.sensor_id = sensors.id AND bluetooth_links.deleted_at IS NULL) OR EXISTS (SELECT 1 FROM http_measurement_sources WHERE http_measurement_sources.sensor_id = sensors.id) THEN 1 ELSE 0 END) AS linked', $measurementQuery->getBindings())->first();
        $links = HomeAssistantLink::selectRaw('COUNT(*) AS total,
            SUM(CASE WHEN is_enabled THEN 1 ELSE 0 END) AS enabled,
            MAX(last_attempt_at) AS last_attempt_at, MAX(last_success_at) AS last_success_at')->first();
        $alerts = AlertEpisode::whereNull('resolved_at')->selectRaw("COUNT(*) AS active,
            SUM(CASE WHEN acknowledged_at IS NULL THEN 1 ELSE 0 END) AS unacknowledged,
            SUM(CASE WHEN severity = 'info' THEN 1 ELSE 0 END) AS info,
            SUM(CASE WHEN severity = 'warning' THEN 1 ELSE 0 END) AS warning,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical")->first();
        $inventory = InventoryItem::where('is_archived', false)->selectRaw('COUNT(*) AS active,
            SUM(CASE WHEN threshold_units IS NOT NULL AND total_units <= threshold_units THEN 1 ELSE 0 END) AS low_stock')->first();
        $result = [
            'plants' => $this->plants(),
            'strains' => [
                'active' => Strain::where('is_archived', false)->count(),
                'archived' => Strain::where('is_archived', true)->count(),
                'inUse' => Plant::whereNotIn('phase', ['planned', 'archived'])->distinct()->count('strain_id'),
            ],
            'records' => [
                'documents' => Document::where('is_archived', false)->count(),
                'archivedDocuments' => Document::where('is_archived', true)->count(),
                'journalEntries' => EventEntry::count(),
                'recentJournalEntries' => EventEntry::where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays(7)->format('Y-m-d H:i:s.u'))->where('occurred_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))->count(),
            ],
            'sources' => [
                'homebridge' => $this->sourceSummary(HomebridgeLink::class, 'last_success_at'),
                'homeAssistant' => ['total' => (int) $links->total, 'enabled' => (int) $links->enabled, 'lastReceivedAt' => $links->last_success_at?->toISOString()],
                'mqtt' => $this->sourceSummary(MqttMeasurementSource::class, 'last_received_at'),
                'http' => $this->sourceSummary(HttpMeasurementSource::class, 'last_received_at'),
            ],
            'locations' => ['sites' => Site::count(), 'rooms' => Room::count(), 'zones' => Zone::count()],
            'assets' => ['total' => (int) $assets->total, 'active' => (int) $assets->active, 'maintenance' => (int) $assets->maintenance, 'retired' => (int) $assets->retired],
            'sensors' => ['total' => (int) $sensors->total, 'enabled' => (int) $sensors->enabled, 'withReadings' => (int) $sensors->with_readings, 'withoutReadings' => (int) $sensors->total - (int) $sensors->with_readings, 'linked' => (int) $sensors->linked, 'unlinked' => (int) $sensors->total - (int) $sensors->linked],
            'collection' => ['enabledLinks' => (int) $links->enabled, 'pausedLinks' => (int) $links->total - (int) $links->enabled, 'lastAttemptAt' => $links->last_attempt_at?->toISOString(), 'lastSuccessAt' => $links->last_success_at?->toISOString()],
            'alerts' => ['active' => (int) $alerts->active, 'unacknowledged' => (int) $alerts->unacknowledged, 'info' => (int) $alerts->info, 'warning' => (int) $alerts->warning, 'critical' => (int) $alerts->critical, 'enabledRules' => AlertRule::where('is_enabled', true)->count()],
            'inventory' => ['activeItems' => (int) $inventory->active, 'lowStockItems' => app(InventoryLocations::class)->restricted($actor) ? null : (int) $inventory->low_stock],
        ];
        $this->authorize($actor);

        return ['generatedAt' => CarbonImmutable::now()->utc()->toISOString(), ...$result];
    }

    private function sourceSummary(string $model, string $receivedColumn): array
    {
        $summary = $model::selectRaw("COUNT(*) AS total, SUM(CASE WHEN is_enabled THEN 1 ELSE 0 END) AS enabled, MAX($receivedColumn) AS last_received_at")->first();

        return ['total' => (int) $summary->total, 'enabled' => (int) $summary->enabled,
            'lastReceivedAt' => $summary->last_received_at ? CarbonImmutable::parse($summary->last_received_at, 'UTC')->toISOString() : null];
    }

    private function plants(): array
    {
        $counts = Plant::select('phase')->selectRaw('COUNT(*) AS total')->groupBy('phase')->pluck('total', 'phase');
        $byPhase = array_fill_keys(LifecycleDefinition::ids(), 0);
        foreach ($counts as $phase => $count) {
            $byPhase[$phase] = (int) $count;
        }
        $lifecycleDue = Plant::whereNotIn('phase', ['planned', 'archived'])->where('phase_due_at', '<=', now())->count();
        $wateringDue = 0;
        $overlap = 0;
        $watering = app(PlantWateringNeeds::class);
        // Reuse authoritative moisture/timer rules, loading bounded batches rather than all plant graphs.
        Plant::whereIn('phase', LifecycleDefinition::allowing('watering'))->chunkById(250, function ($plants) use ($watering, &$wateringDue, &$overlap) {
            $watering->prepare($plants);
            foreach ($plants as $plant) {
                if ($watering->state($plant)['needed']) {
                    $wateringDue++;
                    if ($plant->phase_due_at?->lessThanOrEqualTo(now())) {
                        $overlap++;
                    }
                }
            }
        });
        $total = array_sum($byPhase);

        return [
            'total' => $total, 'active' => $total - $byPhase['planned'] - $byPhase['archived'],
            'planned' => $byPhase['planned'], 'archived' => $byPhase['archived'], 'byPhase' => $byPhase,
            'lifecycleDue' => $lifecycleDue, 'wateringDue' => $wateringDue, 'due' => $lifecycleDue + $wateringDue - $overlap,
            'harvested' => Plant::whereNotNull('harvest_weight_grams')->count(),
            'harvestWeightGrams' => number_format((float) Plant::sum('harvest_weight_grams'), 2, '.', ''),
        ];
    }
}
