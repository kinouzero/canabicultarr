<?php

namespace App\Services;

use App\Models\CommentTemplate;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommentTemplateCatalog
{
    public const SORT_COLUMNS = ['name' => 'name_key', 'created' => 'created_at', 'updated' => 'updated_at'];

    public function search(array $criteria)
    {
        $query = CommentTemplate::query();
        $status = $criteria['status'] ?? 'active';
        if ($status !== 'all') {
            $query->where('is_archived', $status === 'archived');
        }
        if (filled($criteria['search'] ?? null)) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(trim($criteria['search']))).'%';
            $query->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern]);
        }

        return CollectionSorting::apply($query, $criteria['sort'] ?? null, self::SORT_COLUMNS)->paginate(CollectionPagination::size($criteria), ['*'], 'page', $criteria['page'] ?? 1);
    }

    public function save(User $actor, array $data, ?CommentTemplate $target = null): CommentTemplate
    {
        try {
            $record = DB::transaction(function () use ($actor, $data, $target) {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if ($current->session_version !== $actor->session_version) {
                    throw new AuthenticationException;
                }
                Gate::forUser($current)->authorize('lifecycle.manage');
                $record = $target ? CommentTemplate::whereKey($target->id)->lockForUpdate()->firstOrFail() : new CommentTemplate;
                if ($target && $record->version !== (int) $data['version']) {
                    throw new HttpException(409, 'commentTemplates.errors.conflict');
                }
                $record->fill($data);
                if ($target && $record->isDirty()) {
                    $record->version++;
                }
                $record->save();

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['commentTemplates.errors.nameTaken']]);
        }
        Log::notice('comment_template.saved', ['actor_uuid' => $actor->uuid, 'template_uuid' => $record->id]);

        return $record;
    }
}
