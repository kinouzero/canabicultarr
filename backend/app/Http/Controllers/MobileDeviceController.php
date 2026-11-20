<?php

namespace App\Http\Controllers;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Http\Requests\RecordPlantMaintenanceRequest;
use App\Http\Requests\RecordPlantWateringRequest;
use App\Http\Requests\SavePlantRequest;
use App\Models\Document;
use App\Models\EventEntry;
use App\Models\Plant;
use App\Models\Site;
use App\Models\User;
use App\Rules\ZonedTimestamp;
use App\Services\DocumentLibrary;
use App\Services\EventJournal;
use App\Services\LocationAccess;
use App\Services\LocationOptions;
use App\Services\MobileAttachmentUpload;
use App\Services\MobileJournalFeed;
use App\Services\MobileOfflineFiles;
use App\Services\MobilePlantCatalog;
use App\Services\PlantTracking;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->session()->get('mobile_device_id');
        $devices = $this->owned($request)->whereNotNull('paired_at')->orderByDesc('paired_at')
            ->get(['id', 'label', 'paired_at'])
            ->map(fn ($device) => [
                'id' => $device->id,
                'label' => $device->label,
                'paired_at' => Carbon::parse($device->paired_at)->toIso8601String(),
                'current' => $device->id === $current,
            ]);

        return response()->json(['data' => $devices]);
    }

    public function offlineFiles(Request $request, MobileOfflineFiles $files): JsonResponse
    {
        $this->offlineFileBinding($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $files->list(trim($input['search'] ?? ''))]);
    }

    public function offlineFileSelection(Request $request, MobileOfflineFiles $files): JsonResponse
    {
        $binding = $this->offlineFileBinding($request);
        $input = $request->validate([
            'ids' => ['required_without:ticket', 'prohibits:ticket', 'array', 'list', 'min:1', 'max:'.MobileOfflineFiles::MAX_FILES],
            'ids.*' => ['uuid', 'distinct:ignore_case'],
            'ticket' => ['required_without:ids', 'string', 'max:2048'],
        ]);
        if (isset($input['ticket'])) {
            $files->validateTicket($input['ticket'], $binding);

            return response()->json(['data' => ['valid' => true]]);
        }

        return response()->json(['data' => $files->select(array_map('strtolower', $input['ids']), $binding)]);
    }

    public function downloadOfflineFile(Request $request, string $file, MobileOfflineFiles $files, DocumentLibrary $library)
    {
        $this->offlineFileBinding($request);

        return $library->download($files->file($file));
    }

    private function offlineFileBinding(Request $request): string
    {
        $device = $this->sessionDeviceId($request);
        abort_unless($this->paired($request, $device)->exists() && $request->user()->can('viewAny', Document::class), 403);

        return hash('sha256', $request->user()->uuid.':'.$device.':'.$request->user()->session_version);
    }

    public function offlineSites(Request $request): JsonResponse
    {
        $device = $this->sessionDeviceId($request);
        abort_unless($this->paired($request, $device)->exists(), 403);
        $directory = $this->offlineDirectory($request);
        $now = now();

        return response()->json(['data' => [
            ...$directory,
            'accountId' => $request->user()->uuid,
            'deviceId' => $device,
            'savedAt' => $now->toIso8601String(),
            'expiresAt' => $now->copy()->addHours(24)->toIso8601String(),
        ]]);
    }

    public function offlineJournal(Request $request, MobileJournalFeed $feed): JsonResponse
    {
        $device = $this->sessionDeviceId($request);
        abort_unless($this->paired($request, $device)->exists(), 403);
        abort_unless($request->user()->can('viewAny', EventEntry::class), 403);
        $input = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);
        $directory = $this->offlineDirectory($request);
        abort_unless(hash_equals($directory['version'], $input['version']), 409);

        $scope = hash('sha256', $request->user()->uuid.$device.$directory['version']);

        return response()->json(['data' => $feed->read($directory['journalSiteIds'], $scope, $input['cursor'] ?? null)]);
    }

    public function offlinePlants(Request $request, MobilePlantCatalog $catalog, LocationOptions $locationOptions): JsonResponse
    {
        $device = $this->sessionDeviceId($request);
        abort_unless($this->paired($request, $device)->exists(), 403);
        abort_unless($request->user()->can('viewAny', Plant::class), 403);
        $input = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'check' => ['sometimes', 'boolean'],
        ]);
        $directory = $this->offlineDirectory($request);
        abort_unless(hash_equals($directory['version'], $input['version']), 409);

        return response()->json(['data' => $catalog->read($request->user(), array_column($directory['sites'], 'id'), $request->boolean('check'), $locationOptions)]);
    }

    private function offlineDirectory(Request $request): array
    {
        abort_unless($request->user()->can('viewAny', Site::class), 403);
        $sites = Site::query()->orderBy('name_key')->orderBy('id')->limit(51)->get(['id', 'name']);
        $visible = $sites->take(50);
        $locations = app(LocationAccess::class);
        $subjects = $visible->map(fn (Site $site) => ['type' => 'site', 'id' => $site->id])->all();
        $locations->prepare($request->user(), $visible);
        $directory = [
            'sites' => $visible->map(fn (Site $site) => ['id' => $site->id, 'name' => $site->name])->values()->all(),
            'truncated' => $sites->count() > 50,
            'journalSiteIds' => collect($subjects)->filter(fn (array $site) => $locations->allows($request->user(), $site))->pluck('id')->all(),
        ];

        return [...$directory, 'version' => hash('sha256', json_encode($directory, JSON_THROW_ON_ERROR))];
    }

    public function uploadAttachment(Request $request, Plant $plant, DocumentLibrary $library): JsonResponse
    {
        $device = $this->boundDeviceId($request);
        $input = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'gallery' => ['sometimes', 'boolean'],
            'file' => ['required', 'file', 'max:'.MobileAttachmentUpload::MAX_KIB, ...($request->boolean('gallery') ? ['mimes:jpg,jpeg,png,webp'] : [])],
        ]);
        $actor = $request->user();
        $upload = new MobileAttachmentUpload($device, $actor->session_version, $plant->id, Str::lower($input['upload_id']), (int) $input['version'], $request->boolean('gallery'));
        $document = $library->upload($actor, [], $request->file('file'), $upload);

        return response()->json(['data' => ['id' => $document->id, 'gallery' => $request->boolean('gallery')]]);
    }

    public function uploadMaintenance(RecordPlantMaintenanceRequest $request, Plant $plant, PlantTracking $tracking): JsonResponse
    {
        $input = $request->validate(['document_ids' => ['prohibited'], 'attachment_id' => ['nullable', 'uuid'], 'occurred_at' => ['required']]);

        return $this->withPairedActor($request, function (User $actor) use ($request, $plant, $tracking, $input) {
            $documents = MobileAttachmentUpload::documents($actor, $plant, $input['attachment_id'] ?? null);
            $record = $tracking->maintain($actor, $plant, [...$request->validated(), 'document_ids' => $documents]);

            return response()->json(['data' => ['id' => $record->id]]);
        });
    }

    public function uploadWatering(RecordPlantWateringRequest $request, Plant $plant, PlantTracking $tracking): JsonResponse
    {
        $input = $request->validate([
            'document_ids' => ['prohibited'],
            'attachment_id' => ['nullable', 'uuid'],
            'fertilizers' => $request->boolean('with_products') ? ['required', 'array', 'min:1'] : ['prohibited'],
            'water_liters' => $request->boolean('with_products') ? ['required'] : ['nullable'],
            'occurred_at' => ['required'],
        ]);

        return $this->withPairedActor($request, function (User $actor) use ($request, $plant, $tracking, $input) {
            $documents = MobileAttachmentUpload::documents($actor, $plant, $input['attachment_id'] ?? null);
            $record = $tracking->water($actor, $plant, [...$request->validated(), 'document_ids' => $documents], offline: true);

            return response()->json(['data' => ['id' => $record->id]]);
        });
    }

    public function uploadPlannedPlant(SavePlantRequest $request, PlantTracking $tracking): JsonResponse
    {
        $request->validate([
            'planned' => ['required', 'accepted'],
            'device_ids' => ['prohibited'],
            'summary_sensor_ids' => ['prohibited'],
        ]);

        return $this->withPairedActor($request, function (User $actor) use ($request, $tracking) {
            $record = $tracking->create($actor, $request->validated());

            return response()->json(['data' => ['id' => $record->id]]);
        });
    }

    public function uploadJournalNote(Request $request, EventJournal $journal, LocationAccess $locations): JsonResponse
    {
        $this->sessionDeviceId($request);
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'site_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
            'occurred_at' => ['required', new ZonedTimestamp],
        ]);
        $payload = [
            'title' => $input['title'],
            'body' => $input['body'],
            'occurred_at' => $input['occurred_at'],
            'category' => EventCategory::Note->value,
            'severity' => EventSeverity::Info->value,
            'subject' => ['type' => 'site', 'id' => Str::lower($input['site_id'])],
        ];
        $operationId = Str::lower($input['operation_id']);
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->withPairedActor($request, function (User $current) use ($locations, $journal, $payload, $operationId, $digest) {
            if (! $locations->allows($current, $payload['subject']) || ! Site::whereKey($payload['subject']['id'])->exists()) {
                return response()->json(['error' => ['code' => 'offlineSites.siteUnavailable']], 422);
            }
            $receipt = DB::table('mobile_journal_receipts')->where('user_id', $current->id)
                ->where('operation_id', $operationId)->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_digest, $digest)) {
                    return response()->json(['error' => ['code' => 'offlineSites.operationConflict']], 409);
                }

                return response()->json(['data' => ['id' => $receipt->event_id]]);
            }

            $event = $journal->save($current, $payload);
            DB::table('mobile_journal_receipts')->insert([
                'user_id' => $current->id,
                'operation_id' => $operationId,
                'payload_digest' => $digest,
                'event_id' => $event->id,
            ]);

            return response()->json(['data' => ['id' => $event->id]], 201);
        });
    }

    private function boundDeviceId(Request $request): string
    {
        $device = $this->sessionDeviceId($request);
        $scope = $request->validate(['account_id' => ['required', 'uuid'], 'device_id' => ['required', 'uuid']]);
        abort_unless(Str::lower($scope['account_id']) === $request->user()->uuid && Str::lower($scope['device_id']) === $device, 403);

        return $device;
    }

    private function withPairedActor(Request $request, Closure $action): JsonResponse
    {
        $device = $this->boundDeviceId($request);

        return DB::transaction(function () use ($request, $device, $action) {
            $current = User::whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($current->is_active && (! $current->email_activation_required || $current->email_verified_at)
                && $current->session_version === $request->user()->session_version
                && $this->paired($request, $device)->lockForUpdate()->first(['id']), 403);

            return app(LocationAccess::class)->run($current, fn () => $action($current));
        }, 3);
    }

    public function issue(Request $request): JsonResponse
    {
        abort_unless($request->isSecure(), 403);
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = now()->addMinutes(5);

        return DB::transaction(function () use ($request, $code, $expires) {
            $user = $request->user();
            DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first(['id']);
            $this->owned($request)->whereNull('paired_at')->delete();
            if ($this->owned($request)->count() >= 25) {
                return response()->json(['error' => ['code' => 'mobilePairing.limit']], 409);
            }

            DB::table('mobile_devices')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'code_digest' => hash('sha256', $code),
                'session_version' => $user->session_version,
                'expires_at' => $expires,
            ]);

            return response()->json(['data' => ['code' => $code, 'expires_at' => $expires->toIso8601String()]], 201);
        }, 3);
    }

    public function redeem(Request $request): JsonResponse
    {
        abort_unless($request->isSecure(), 403);
        $input = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'label' => ['required', 'string', 'max:80'],
        ]);
        $match = $this->owned($request)->where('code_digest', hash('sha256', $input['code']))
            ->where('session_version', $request->user()->session_version)
            ->where('expires_at', '>', now())->whereNull('paired_at');

        $id = DB::transaction(function () use ($match, $input) {
            $id = (clone $match)->value('id');
            if ($id === null || $match->where('id', $id)->update([
                'label' => $input['label'], 'paired_at' => now(), 'code_digest' => null, 'expires_at' => null,
            ]) !== 1) {
                return null;
            }

            return $id;
        }, 3);
        if ($id === null) {
            return response()->json(['error' => ['code' => 'mobilePairing.invalidCode']], 422);
        }

        $request->session()->put('mobile_device_id', $id);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function destroy(Request $request, string $device): Response
    {
        abort_unless($this->owned($request)->whereNotNull('paired_at')->where('id', $device)->delete(), 404);
        if ($request->session()->get('mobile_device_id') === $device) {
            $request->session()->forget('mobile_device_id');
        }

        return response()->noContent();
    }

    private function sessionDeviceId(Request $request): string
    {
        $device = $request->session()->get('mobile_device_id');
        abort_unless($request->isSecure() && is_string($device) && Str::isUuid($device), 403);

        return $device;
    }

    private function paired(Request $request, string $device): Builder
    {
        return $this->owned($request)->where('id', $device)->whereNotNull('paired_at');
    }

    private function owned(Request $request): Builder
    {
        return DB::table('mobile_devices')->where('user_id', $request->user()->getKey());
    }
}
