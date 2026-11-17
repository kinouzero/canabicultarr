<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'parentId' => $this->parent_id, 'version' => $this->version, 'hasChildren' => (bool) ($this->children_exists ?? false), 'canEdit' => $request->user()->can('update', $this->resource)];
    }
}
