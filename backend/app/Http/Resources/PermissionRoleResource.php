<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'permissions' => $this->grants(), 'isArchived' => $this->is_archived, 'version' => $this->version, 'assignedUsers' => (int) $this->users_count];
    }
}
