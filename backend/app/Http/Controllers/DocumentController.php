<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListDocumentsRequest;
use App\Http\Requests\SaveDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentLibrary;
use App\Services\DocumentPdfPreview;
use App\Services\DocumentStorage;
use App\Services\DocumentThumbnail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function index(ListDocumentsRequest $request, DocumentLibrary $library)
    {
        return DocumentResource::collection($library->search($request->validated()));
    }

    public function storageOptions(DocumentStorage $storage)
    {
        Gate::authorize('create', Document::class);

        return response()->json(['data' => ['default' => config('documents.disk'), 'disks' => $storage->disks()]]);
    }

    public function store(SaveDocumentRequest $request, DocumentLibrary $library)
    {
        return (new DocumentResource($library->upload($request->user(), $request->validated(), $request->file('file'))))->response()->setStatusCode(201);
    }

    public function show(Document $document)
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load('uploader:id,uuid,name'));
    }

    public function update(SaveDocumentRequest $request, Document $document, DocumentLibrary $library)
    {
        return new DocumentResource($library->update($request->user(), $document, $request->validated()));
    }

    public function download(Document $document, DocumentLibrary $library)
    {
        return $library->download($document);
    }

    public function thumbnail(Document $document, DocumentThumbnail $thumbnail, DocumentLibrary $library)
    {
        return $thumbnail->response($document, $library);
    }

    public function imagePreview(Document $document, DocumentThumbnail $thumbnail, DocumentLibrary $library)
    {
        return $thumbnail->response($document, $library, screen: true);
    }

    public function preview(Request $request, Document $document, DocumentLibrary $library, DocumentPdfPreview $pdf)
    {
        Gate::authorize('view', $document);
        $types = [
            'image' => ['image/jpeg', 'image/png', 'image/webp'],
            'pdf' => ['application/pdf'],
            'text' => ['text/plain', 'text/csv', 'application/csv'],
        ];
        abort_unless(in_array($document->media_type, $types[$document->format] ?? [], true), 404);

        return $document->format === 'pdf' ? $pdf->response($request, $document) : $library->preview($document);
    }
}
