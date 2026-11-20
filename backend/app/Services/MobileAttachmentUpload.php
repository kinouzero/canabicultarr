<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Exceptions\PlantException;
use App\Models\Document;
use App\Models\Plant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class MobileAttachmentUpload
{
    public const MAX_KIB = 2048;

    public function __construct(
        private string $deviceId,
        private int $sessionVersion,
        private string $plantId,
        private string $uploadId,
        private int $plantVersion,
        private bool $gallery,
    ) {}

    public function prepare(User $actor, string $digest, string $name): array|Document
    {
        abort_unless($actor->is_active && (! $actor->email_activation_required || $actor->email_verified_at)
            && $actor->session_version === $this->sessionVersion
            && DB::table('mobile_devices')->where('id', $this->deviceId)->where('user_id', $actor->id)
                ->whereNotNull('paired_at')->lockForUpdate()->first(['id']), 403);
        $plant = Plant::whereKey($this->plantId)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $plant);

        $receipt = self::receipt($actor, $this->uploadId);
        if ($receipt) {
            if ($receipt->plant_id !== $this->plantId || ! hash_equals($receipt->digest, $this->fingerprint($digest, $name))) {
                throw new DocumentException('offlinePlants.attachmentConflict', 409);
            }
            $document = Document::findOrFail($receipt->document_id);
            Gate::forUser($actor)->authorize('view', $document);
            abort_if($document->is_archived, 409);

            return $document;
        }

        if ($plant->version !== $this->plantVersion) {
            throw new PlantException('plants.errors.conflict');
        }

        $location = $plant->locationDetails();
        abort_unless($location, 422);

        return ['title' => mb_substr($name, 0, 160), 'subject' => ['type' => $location['type'], 'id' => $location['id']]];
    }

    public function record(User $actor, Document $document, string $digest, string $name): void
    {
        if ($this->gallery) {
            Plant::whereKey($this->plantId)->firstOrFail()->photos()->attach($document->id);
        }

        DB::table('mobile_attachment_receipts')->insert([
            'user_id' => $actor->id,
            'upload_id' => $this->uploadId,
            'plant_id' => $this->plantId,
            'document_id' => $document->id,
            'digest' => $this->fingerprint($digest, $name),
        ]);
    }

    public static function documents(User $actor, Plant $plant, ?string $uploadId): array
    {
        if ($uploadId === null) {
            return [];
        }

        $receipt = self::receipt($actor, strtolower($uploadId));
        if (! $receipt || $receipt->plant_id !== $plant->id) {
            throw new PlantException('plants.errors.attachmentUnavailable', 422);
        }

        return [$receipt->document_id];
    }

    private static function receipt(User $actor, string $uploadId): ?object
    {
        return DB::table('mobile_attachment_receipts')->where('user_id', $actor->id)->where('upload_id', $uploadId)->first();
    }

    private function fingerprint(string $digest, string $name): string
    {
        return hash('sha256', $digest."\n".$name.($this->gallery ? "\ngallery" : ''));
    }
}
