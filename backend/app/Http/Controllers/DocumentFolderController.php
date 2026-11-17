<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDocumentFolderRequest;
use App\Http\Resources\DocumentFolderResource;
use App\Models\DocumentFolder;
use App\Services\CollectionPagination;
use App\Services\DocumentFolders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentFolderController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', DocumentFolder::class);

        $criteria = $request->validate([
            'parent_id' => ['nullable', 'uuid', 'exists:document_folders,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => CollectionPagination::rules(),
        ]);

        return DocumentFolderResource::collection(DocumentFolder::withChildren()
            ->where('parent_key', $criteria['parent_id'] ?? 'root')->orderBy('name_key')->orderBy('id')
            ->paginate(CollectionPagination::size($criteria, 50)));
    }

    public function paths(Request $request, DocumentFolders $folders)
    {
        Gate::authorize('viewAny', DocumentFolder::class);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        $parents = $folders->ancestors($data['ids']);

        return DocumentFolderResource::collection(DocumentFolder::whereKey(array_keys($parents))->withChildren()->get());
    }

    public function show(DocumentFolder $documentFolder, DocumentFolders $folders)
    {
        Gate::authorize('viewAny', DocumentFolder::class);
        $parents = $folders->ancestors([$documentFolder->id]);
        $ancestors = DocumentFolder::whereKey(array_keys($parents))->withChildren()->get()->keyBy('id');
        $path = [];
        $id = $documentFolder->id;
        $seen = [];
        while ($id && isset($ancestors[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($path, $ancestors[$id]);
            $id = $parents[$id];
        }

        return (new DocumentFolderResource(DocumentFolder::withChildren()->findOrFail($documentFolder->id)))
            ->additional(['ancestors' => DocumentFolderResource::collection(collect($path))]);
    }

    public function store(SaveDocumentFolderRequest $request, DocumentFolders $folders)
    {
        $folder = $folders->save($request->user(), null, $request->validated());

        return (new DocumentFolderResource(DocumentFolder::withChildren()->findOrFail($folder->id)))->response()->setStatusCode(201);
    }

    public function update(SaveDocumentFolderRequest $request, DocumentFolder $documentFolder, DocumentFolders $folders)
    {
        $folder = $folders->save($request->user(), $documentFolder, $request->validated());

        return new DocumentFolderResource(DocumentFolder::withChildren()->findOrFail($folder->id));
    }

    public function destroy(SaveDocumentFolderRequest $request, DocumentFolder $documentFolder, DocumentFolders $folders)
    {
        $folders->delete($request->user(), $documentFolder, (int) $request->validated('version'));

        return response()->noContent();
    }
}
