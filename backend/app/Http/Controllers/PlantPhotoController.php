<?php

namespace App\Http\Controllers;

use App\Models\Plant;
use App\Services\DocumentLibrary;
use Illuminate\Support\Facades\Gate;

class PlantPhotoController extends Controller
{
    public function show(Plant $plant, DocumentLibrary $library)
    {
        Gate::authorize('view', $plant);
        $photo = $plant->photoDocument;
        abort_unless($photo && ! $photo->is_archived && $photo->format === 'image', 404);
        $response = $library->download($photo);
        $response->headers->set('Content-Disposition', 'inline');

        return $response;
    }
}
