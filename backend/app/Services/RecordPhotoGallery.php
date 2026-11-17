<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Location;
use App\Models\Plant;
use App\Models\PlantEvent;
use App\Models\Strain;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RecordPhotoGallery
{
    public function primaryPhoto(Plant $plant, User $user): ?Document
    {
        $photo = $plant->photoDocument;

        return $photo && ! $photo->is_archived && $photo->format === 'image' && $user->can('view', $photo) ? $photo : null;
    }

    public function inheritedCover(Plant $plant): ?Document
    {
        $key = 'strain-photo-cover:'.$plant->strain_id;
        $attributes = request()->attributes;
        if (! $attributes->has($key)) {
            $attributes->set($key, $this->documents($plant->strain)->orderByDesc('created_at')->orderByDesc('id')->first());
        }

        return $attributes->get($key);
    }

    public function photos(Plant|Strain|Location $record, int $page, ?string $coverId = null)
    {
        return $this->documents($record)
            ->when($coverId, fn ($query) => $query->orderByRaw('CASE WHEN documents.id = ? THEN 0 ELSE 1 END', [$coverId]))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['documents.*'], 'page', $page);
    }

    private function documents(Plant|Strain|Location $record): Builder
    {
        if ($record instanceof Location) {
            return Document::where('format', 'image')->where('is_archived', false)
                ->whereIn('media_type', ['image/jpeg', 'image/png', 'image/webp'])
                ->whereIn('documents.id', $record->photos()->select('documents.id'));
        }

        $strainId = $record instanceof Plant ? $record->strain_id : $record->id;

        return Document::where('format', 'image')->where('is_archived', false)
            ->whereIn('media_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->where(function ($query) use ($record, $strainId) {
                $query->whereIn('documents.id', function ($links) use ($strainId) {
                    $links->select('document_id')->from('strain_photos')->where('strain_id', $strainId);
                });
                if ($record instanceof Plant) {
                    $query->orWhere('documents.id', $record->photo_document_id)
                        ->orWhereIn('documents.id', function ($links) use ($record) {
                            $links->select('document_id')->from('plant_photos')->where('plant_id', $record->id);
                        })
                        ->orWhereIn('documents.id', function ($links) use ($record) {
                            $links->select('document_id')->from('plant_event_documents')
                                ->whereIn('plant_event_id', PlantEvent::where('plant_id', $record->id)->select('plant_events.id'));
                        });
                }
            });
    }
}
