<?php

namespace App\Http\Controllers;

use App\Http\Requests\PermissionRoleRequest;
use App\Http\Resources\PermissionRoleResource;
use App\Models\PermissionRole;
use App\Services\PermissionRoleAdministration;

class PermissionRoleController extends Controller
{
    public function index(PermissionRoleRequest $request, PermissionRoleAdministration $roles)
    {
        return PermissionRoleResource::collection($roles->search($request->validated()));
    }

    public function store(PermissionRoleRequest $request, PermissionRoleAdministration $roles)
    {
        return (new PermissionRoleResource($roles->save($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(PermissionRoleRequest $request, PermissionRole $permissionRole, PermissionRoleAdministration $roles)
    {
        return new PermissionRoleResource($roles->save($request->user(), $request->validated(), $permissionRole));
    }
}
