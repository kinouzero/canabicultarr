<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use JsonException;

final class MobileOfflineFiles
{
    public const MAX_FILES = 4;

    private const MAX_BYTES = 2 * 1024 * 1024;

    private const MEDIA = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'application/csv'];

    public function list(string $search): array
    {
        $query = $this->available();
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%';
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get()
            ->map(fn (Document $file) => $this->metadata($file))->all();
    }

    public function select(array $ids, string $binding): array
    {
        $files = $this->selection($ids);
        abort_unless($files !== null, 409);
        $expiresAt = now()->addHours(24);

        return [
            'files' => $files,
            'expiresAt' => $expiresAt->toIso8601String(),
            'ticket' => Crypt::encryptString(json_encode([$binding, $ids, $this->fingerprint($files), $expiresAt->timestamp], JSON_THROW_ON_ERROR)),
        ];
    }

    public function validateTicket(string $ticket, string $binding): void
    {
        try {
            $scope = json_decode(Crypt::decryptString($ticket), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(409);
        }
        abort_unless(is_array($scope) && count($scope) === 4 && ($scope[0] ?? null) === $binding
            && is_array($scope[1] ?? null) && is_string($scope[2] ?? null)
            && is_int($scope[3] ?? null) && $scope[3] > now()->timestamp, 409);
        $files = $this->selection($scope[1]);
        abort_unless($files !== null && hash_equals($scope[2], $this->fingerprint($files)), 409);
    }

    public function file(string $id): Document
    {
        return $this->available()->findOrFail($id);
    }

    private function selection(array $ids): ?array
    {
        $files = $this->available()->whereKey($ids)->get()->keyBy('id');
        if ($files->count() !== count($ids)) {
            return null;
        }
        foreach ($files as $file) {
            if (! Gate::allows('view', $file)) {
                return null;
            }
        }

        return array_map(fn (string $id) => $this->metadata($files[$id]), $ids);
    }

    private function available(): Builder
    {
        return Document::query()->where('is_archived', false)->whereIn('media_type', self::MEDIA)
            ->whereBetween('size_bytes', [1, self::MAX_BYTES]);
    }

    private function metadata(Document $file): array
    {
        return [
            'id' => $file->id, 'title' => $file->title, 'name' => $file->original_name,
            'size' => $file->size_bytes, 'mediaType' => $file->media_type, 'version' => $file->version, 'sha256' => $file->sha256,
        ];
    }

    private function fingerprint(array $files): string
    {
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
