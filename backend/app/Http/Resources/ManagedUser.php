<?php

namespace App\Http\Resources;

use App\Models\OidcIdentity;
use App\Services\EmailChange;
use App\Services\InventoryLocations;
use App\Services\LocationAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagedUser extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'pendingEmail' => app(EmailChange::class)->pendingEmail($this->resource),
            'avatarUrl' => $this->resource->avatarUrl(),
            'isActive' => $this->is_active,
            'emailActivationRequired' => $this->email_activation_required && ! $this->email_verified_at,
            'inventoryLocationAccess' => app(InventoryLocations::class)->describe($this->resource),
            'locationAccess' => app(LocationAccess::class)->describe($this->resource),
            'permissionRoles' => $this->permissionRoles->map(fn ($profile) => ['id' => $profile->id, 'name' => $profile->name, 'permissions' => $profile->grants()])->values()->all(),
            'permissionGrants' => $this->delegatedPermissions(),
            'permissionDenials' => $this->deniedPermissions(),
            'permissions' => $this->effectiveOperationalPermissions(),
            'oidcLinked' => OidcIdentity::where('user_id', $this->getKey())->where('issuer', config('oidc.issuer'))->exists(),
        ];
    }
}
