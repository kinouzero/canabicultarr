<?php

namespace App\Providers;

use App\Enums\DelegatedPermission;
use App\Models\AlertEpisode;
use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Document;
use App\Models\EventEntry;
use App\Models\EventRevision;
use App\Models\HomeAssistantLink;
use App\Models\HomebridgeLink;
use App\Models\HttpMeasurementSource;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Measurement;
use App\Models\MqttMeasurementSource;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Room;
use App\Models\Sensor;
use App\Models\Site;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Zone;
use App\Policies\AccountSecurityPolicy;
use App\Policies\DashboardPolicy;
use App\Policies\LocationPolicy;
use App\Policies\NotificationPolicy;
use App\Services\ConnectionConfiguration;
use App\Services\HomeAssistantClient;
use App\Services\InventoryLocations;
use App\Services\LocationAccess;
use App\Services\RemoteSensorClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(LocationAccess::class);
        $this->app->singleton(ConnectionConfiguration::class);
        $this->app->bind(RemoteSensorClient::class, HomeAssistantClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(ConnectionConfiguration::class)->apply();
        Queue::before(function () {
            app(ConnectionConfiguration::class)->apply();
            Mail::forgetMailers();
        });
        StorageLocation::addGlobalScope('inventory_access', fn ($query) => app(InventoryLocations::class)->scope($query));
        foreach ([Plant::class, PlantEvent::class, Site::class, Room::class, Zone::class,
            Asset::class, AssetAssignment::class, Sensor::class,
            HomebridgeLink::class, HomeAssistantLink::class, HttpMeasurementSource::class, MqttMeasurementSource::class,
            Measurement::class, AlertRule::class, AlertEpisode::class,
            EventEntry::class, EventRevision::class, Document::class,
            InventoryBalance::class, InventoryMovement::class] as $model) {
            $model::addGlobalScope('location_access', fn ($query) => app(LocationAccess::class)->scope($query));
        }
        RateLimiter::for('http-ingestion-ip', fn (Request $request) => Limit::perMinute(120)->by('http-ingestion-ip:'.$request->ip()));
        Gate::define('instance-settings.manage', fn (User $user) => $user->is_active && $user->isAdministrator());
        Gate::define('lifecycle.manage', fn (User $user) => $user->is_active && $user->isAdministrator());
        Gate::define('integrations.manage', fn (User $user) => $user->hasOperationalPermission(DelegatedPermission::HomeAssistant));
        Gate::define('account.profile', [AccountSecurityPolicy::class, 'manage']);
        Gate::define('account.security', [AccountSecurityPolicy::class, 'manage']);
        Gate::define('notifications.view', [NotificationPolicy::class, 'view']);
        Gate::define('notifications.manage', [NotificationPolicy::class, 'manage']);
        Gate::define('dashboard.view', [DashboardPolicy::class, 'view']);
        foreach ([Site::class, Room::class, Zone::class] as $model) {
            Gate::policy($model, LocationPolicy::class);
        }
    }
}
