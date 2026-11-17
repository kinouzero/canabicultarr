<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DocumentResource extends LocationAwareResource
{
    public function toArray(Request $request): array
    {
        return ['disk' => $this->disk, 'folderId' => $this->folder_id, 'id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'originalName' => $this->original_name, 'mediaType' => $this->media_type, 'format' => $this->format,
            'sizeBytes' => $this->size_bytes, 'sha256' => $this->sha256, 'version' => $this->version, 'isArchived' => $this->is_archived, 'subject' => $this->subject_snapshot,
            'uploader' => ['id' => $this->uploader_uuid, 'name' => $this->uploader?->name], 'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString(), 'canEdit' => $request->user()->can('update', $this->resource)];
    }
}
