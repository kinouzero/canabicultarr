<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DocumentFolders
{
    public function resolve(?string $id): ?string
    {
        if ($id && ! DocumentFolder::whereKey($id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['folder_id' => ['documents.validation.folderInvalid']]);
        }

        return $id;
    }

    /** Read only the requested ancestor chains; UNION also terminates malformed cycles. */
    public function ancestors(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        if (! $ids) {
            return [];
        }
        $bindings = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("WITH RECURSIVE ancestors AS (
            SELECT id, parent_id FROM document_folders WHERE id IN ($bindings)
            UNION SELECT f.id, f.parent_id FROM document_folders f JOIN ancestors a ON f.id = a.parent_id
        ) SELECT id, parent_id FROM ancestors", $ids);
        $parents = [];
        foreach ($rows as $row) {
            $parents[$row->id] = $row->parent_id;
        }
        ksort($parents);

        return $parents;
    }

    private function lockBranches(array $ids): void
    {
        $before = $this->ancestors($ids);
        if (! $before) {
            return;
        }
        // Overlapping moves share ordered ancestor locks; independent roots remain independent.
        DocumentFolder::whereKey(array_keys($before))->orderBy('id')->lockForUpdate()->get(['id']);
        if ($before !== $this->ancestors($ids)) {
            throw new DocumentException('documents.errors.folderConflict');
        }
    }

    public function save(User $actor, ?DocumentFolder $target, array $data): DocumentFolder
    {
        try {
            $folder = DB::transaction(function () use ($actor, $target, $data) {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if ($target && $target->parent_id !== $data['parent_id']) {
                    $this->lockBranches([$target->id, $data['parent_id']]);
                } elseif (! $target && $data['parent_id'] && ! DocumentFolder::whereKey($data['parent_id'])->lockForUpdate()->first(['id'])) {
                    throw ValidationException::withMessages(['parent_id' => ['documents.validation.folderInvalid']]);
                }
                // New leaves/renames cannot create cycles; only moves need ancestor locks.
                $folder = $target ? DocumentFolder::whereKey($target->id)->lockForUpdate()->first() : new DocumentFolder;
                abort_if(! $folder, 404);
                Gate::forUser($current)->authorize($target ? 'update' : 'create', $target ? $folder : DocumentFolder::class);
                if ($target && $folder->version !== (int) $data['version']) {
                    throw new DocumentException('documents.errors.folderConflict');
                }
                $parent = $data['parent_id'];
                $tree = $this->ancestors([$parent]);
                $seen = $target ? [$target->id] : [];
                while ($parent) {
                    if (in_array($parent, $seen, true) || ! array_key_exists($parent, $tree)) {
                        throw ValidationException::withMessages(['parent_id' => ['documents.validation.folderInvalid']]);
                    }
                    $seen[] = $parent;
                    $parent = $tree[$parent];
                }
                $folder->fill(['name' => trim($data['name']), 'parent_id' => $data['parent_id']]);
                if (! $target) {
                    $folder->created_by = $current->id;
                }
                if ($folder->isDirty()) {
                    if ($target) {
                        $folder->version++;
                    }
                    $folder->save();
                }

                return $folder;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['documents.errors.folderNameTaken']]);
        }
        Log::notice('document.folder_saved', ['actor_uuid' => $actor->uuid, 'folder_uuid' => $folder->id, 'version' => $folder->version]);

        return $folder;
    }

    public function delete(User $actor, DocumentFolder $target, int $version): void
    {
        DB::transaction(function () use ($actor, $target, $version) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $folder = DocumentFolder::whereKey($target->id)->lockForUpdate()->first();
            abort_if(! $folder, 404);
            Gate::forUser($current)->authorize('delete', $folder);
            if ($folder->version !== $version) {
                throw new DocumentException('documents.errors.folderConflict');
            }
            // Include archived and geographically hidden files; deletion never removes content.
            if (DocumentFolder::where('parent_key', $folder->id)->exists() || Document::withoutGlobalScopes()->where('folder_id', $folder->id)->exists()) {
                throw new DocumentException('documents.errors.folderNotEmpty');
            }
            $folder->delete();
        });
        Log::notice('document.folder_deleted', ['actor_uuid' => $actor->uuid, 'folder_uuid' => $target->id]);
    }
}
