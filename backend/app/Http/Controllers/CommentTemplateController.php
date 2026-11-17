<?php

namespace App\Http\Controllers;

use App\Http\Resources\CommentTemplateResource;
use App\Models\CommentTemplate;
use App\Services\CollectionPagination;
use App\Services\CollectionSorting;
use App\Services\CommentTemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CommentTemplateController extends Controller
{
    public function index(Request $request, CommentTemplateCatalog $catalog)
    {
        $criteria = $request->validate(['sort' => CollectionSorting::rules(CommentTemplateCatalog::SORT_COLUMNS), 'search' => ['sometimes', 'nullable', 'string', 'max:120'], 'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])], 'per_page' => CollectionPagination::rules(), 'page' => ['sometimes', 'integer', 'between:1,100000']]);

        return CommentTemplateResource::collection($catalog->search($criteria))->additional(['canManage' => $request->user()->can('lifecycle.manage')]);
    }

    private function input(Request $request, bool $editing): array
    {
        Gate::authorize('lifecycle.manage');

        return $request->validate(['name' => ['required', 'string', 'max:120', 'regex:/\S/u'], 'body' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'version' => [$editing ? 'required' : 'prohibited', 'integer', 'min:1'], 'is_archived' => ['sometimes', 'boolean']]);
    }

    public function store(Request $request, CommentTemplateCatalog $catalog)
    {
        return (new CommentTemplateResource($catalog->save($request->user(), $this->input($request, false))))->response()->setStatusCode(201);
    }

    public function update(Request $request, CommentTemplate $commentTemplate, CommentTemplateCatalog $catalog)
    {
        return new CommentTemplateResource($catalog->save($request->user(), $this->input($request, true), $commentTemplate));
    }
}
