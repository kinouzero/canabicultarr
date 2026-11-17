<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Plant;
use App\Models\Room;
use App\Models\Site;
use App\Models\Strain;
use App\Models\Zone;
use App\Services\LocationAccess;
use App\Services\RecordPhotoGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordPhotoGalleryController extends Controller
{
    public function plant(Request $request, Plant $plant, RecordPhotoGallery $gallery)
    {
        Gate::authorize('view', $plant);

        $ownPhoto = $gallery->primaryPhoto($plant, $request->user());
        $cover = $ownPhoto ?? $gallery->inheritedCover($plant);

        return $this->response($request, $gallery, $plant, $cover?->id);
    }

    public function strain(Request $request, Strain $strain, RecordPhotoGallery $gallery)
    {
        Gate::authorize('view', $strain);

        return $this->response($request, $gallery, $strain);
    }

    public function location(Request $request, string $kind, string $id, RecordPhotoGallery $gallery)
    {
        $model = match ($kind) {
            'site' => Site::class, 'room' => Room::class, 'zone' => Zone::class, default => abort(404)
        };
        $location = $model::findOrFail($id);
        abort_unless(app(LocationAccess::class)->record($request->user(), $location), 404);
        Gate::authorize('view', $location);

        return $this->response($request, $gallery, $location, $location->photo_document_id);
    }

    private function response(Request $request, RecordPhotoGallery $gallery, Plant|Strain|Location $record, ?string $coverId = null)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'between:1,100000']]);
        $photos = $gallery->photos($record, $data['page'] ?? 1, $coverId);

        return response()->json([
            'data' => $photos->getCollection()->map(fn ($photo) => [
                'id' => $photo->id, 'name' => $photo->original_name,
                'url' => '/api/v1/documents/'.$photo->id.'/preview?version='.$photo->version,
                'createdAt' => $photo->created_at->toISOString(), 'isCover' => $photo->id === $coverId,
            ])->values(),
            'meta' => ['current_page' => $photos->currentPage(), 'last_page' => $photos->lastPage(), 'per_page' => 20, 'total' => $photos->total()],
        ]);
    }
}
