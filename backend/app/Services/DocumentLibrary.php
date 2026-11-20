<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Models\Document;
use App\Models\User;
use App\Rules\DocumentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentLibrary
{
    public const TEXT_PREVIEW_LIMIT = 1024 * 1024;

    public function __construct(private DocumentContext $context) {}

    public function search(array $criteria): LengthAwarePaginator
    {
        $query = Document::with('uploader:id,uuid,name');
        if (array_key_exists('folder_id', $criteria)) {
            $query->where('folder_id', $criteria['folder_id']);
        }
        $state = $criteria['state'] ?? 'active';
        if ($state !== 'all') {
            $query->where('is_archived', $state === 'archived');
        }
        foreach (['format', 'site_id', 'room_id', 'zone_id', 'asset_id', 'sensor_id', 'event_entry_id'] as $filter) {
            if (! empty($criteria[$filter])) {
                $query->where($filter, $criteria[$filter]);
            }
        }
        if (($search = trim($criteria['search'] ?? '')) !== '') {
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($search)).'%']);
        }

        match ($criteria['sort'] ?? 'newest') {
            'name' => $query->orderByRaw('LOWER(title)'),
            'oldest' => $query->orderBy('created_at'),
            'size' => $query->orderByDesc('size_bytes'),
            default => $query->orderByDesc('created_at'),
        };

        return $query->orderByDesc('id')->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function upload(User $actor, array $data, UploadedFile $file, ?MobileAttachmentUpload $mobile = null): Document
    {
        // Service callers must enforce the same boundary as HTTP uploads.
        [$format, $extension, $mime] = DocumentFile::validatedMetadata($file);
        $disk = $data['disk'] ?? config('documents.disk');
        if (! in_array($disk, app(DocumentStorage::class)->disks(), true)) {
            throw new DocumentException('documents.errors.storageUnavailable', 503);
        }
        $id = (string) Str::uuid();
        $path = $id.'/original.'.$extension;
        $attempted = false;
        try {
            $document = DB::transaction(function () use ($actor, $data, $file, $format, $mime, $disk, $id, $path, $mobile, &$attempted) {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($current)->authorize('create', Document::class);
                $sha = hash_file('sha256', $file->getRealPath());
                if ($sha === false) {
                    throw new DocumentException('documents.errors.storageUnavailable', 503);
                }
                $name = $this->filename($file->getClientOriginalName());
                if ($mobile) {
                    $prepared = app(LocationAccess::class)->run($current, fn () => $mobile->prepare($current, $sha, $name));
                    if ($prepared instanceof Document) {
                        return $prepared;
                    }
                    $data = $prepared;
                }
                $context = app(LocationAccess::class)->run($current, fn () => $this->context->resolve($data['subject'] ?? null));
                $this->context->authorize($current, $context['subject_snapshot']);
                $document = new Document(Arr::only($data, ['title', 'description']));
                $document->folder_id = app(DocumentFolders::class)->resolve($data['folder_id'] ?? null);
                $document->forceFill(['id' => $id, 'disk' => $disk, 'path' => $path, 'original_name' => $name, 'format' => $format, 'media_type' => $mime, 'size_bytes' => $file->getSize(), 'sha256' => $sha,
                    'uploaded_by' => $current->id, 'uploader_uuid' => $current->uuid, ...$context]);
                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream === false) {
                    throw new DocumentException('documents.errors.storageUnavailable', 503);
                }
                try {
                    $attempted = true;
                    if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'private', 'ContentType' => $mime])) {
                        throw new DocumentException('documents.errors.storageUnavailable', 503);
                    }
                } catch (Throwable) {
                    throw new DocumentException('documents.errors.storageUnavailable', 503);
                } finally {
                    fclose($stream);
                }
                $document->save();
                $mobile?->record($current, $document, $sha, $name);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($attempted) {
                try {
                    if (! Storage::disk($disk)->delete($path)) {
                        Log::warning('document.cleanup_failed', ['document_uuid' => $id]);
                    }
                } catch (Throwable) {
                    Log::warning('document.cleanup_failed', ['document_uuid' => $id]);
                }
            }
            throw $exception;
        }
        if ($attempted) {
            Log::notice('document.uploaded', ['actor_uuid' => $actor->uuid, 'document_uuid' => $id]);
        }

        return $document->load('uploader:id,uuid,name');
    }

    private function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f<>:"|?*]/u', '_', $name) ?? 'document';

        return Str::limit($name, 180, '');
    }

    public function update(User $actor, Document $target, array $data): Document
    {
        [$document,$fields] = DB::transaction(function () use ($actor, $target, $data) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $document = Document::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($current)->authorize('update', $document);
            if ((int) $data['version'] !== $document->version) {
                throw new DocumentException('documents.errors.conflict');
            }
            if (array_key_exists('folder_id', $data)) {
                $document->folder_id = app(DocumentFolders::class)->resolve($data['folder_id']);
            }
            $document->fill(Arr::only($data, ['title', 'description', 'is_archived']));
            $contextChanged = false;
            if (array_key_exists('subject', $data)) {
                $old = $document->subject_snapshot;
                $next = $data['subject'];
                $contextChanged = ($old['type'] ?? null) !== ($next['type'] ?? null) || ($old['id'] ?? null) !== ($next['id'] ?? null);
                if ($contextChanged) {
                    $document->forceFill($this->context->resolve($next));
                }
            }
            $this->context->authorize($current, $document->subject_snapshot);
            $fields = array_values(array_intersect(array_keys($document->getDirty()), ['title', 'description', 'is_archived', 'folder_id']));
            if ($contextChanged) {
                $fields[] = 'subject';
            }
            if ($fields) {
                $document->version++;
                $document->save();
            }

            return [$document, $fields];
        });
        if ($fields) {
            Log::notice('document.updated', ['actor_uuid' => $actor->uuid, 'document_uuid' => $document->id, 'fields' => $fields, 'version' => $document->version]);
        }

        return $document->load('uploader:id,uuid,name');
    }

    public function preview(Document $document)
    {
        $limit = $document->format === 'text' ? self::TEXT_PREVIEW_LIMIT : null;
        $response = $this->stream($document, $limit);
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('X-Preview-Truncated', $limit !== null && $document->size_bytes > $limit ? 'true' : 'false');

        return $response;
    }

    public function download(Document $document)
    {
        return $this->stream($document);
    }

    private function stream(Document $document, ?int $limit = null)
    {
        Gate::authorize('view', $document);
        try {
            $disk = Storage::disk($document->disk);
            if (! $disk->exists($document->path)) {
                throw new DocumentException('documents.errors.fileUnavailable', 404);
            }
            $stream = $disk->readStream($document->path);
            if (! is_resource($stream)) {
                throw new DocumentException('documents.errors.storageUnavailable', 503);
            }
        } catch (DocumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DocumentException('documents.errors.storageUnavailable', 503);
        }

        return response()->streamDownload(function () use ($stream, $limit) {
            $output = fopen('php://output', 'wb');
            try {
                stream_copy_to_stream($stream, $output, $limit ?? -1);
            } finally {
                fclose($stream);
                fclose($output);
            }
        }, $document->original_name, [
            'Content-Type' => $document->media_type, 'Content-Length' => (string) min($document->size_bytes, $limit ?? $document->size_bytes), 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
