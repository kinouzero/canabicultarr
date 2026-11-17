<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\ManagedUser;
use App\Models\User;
use App\Services\UserAdministration;

class UserAdministrationController extends Controller
{
    public function index(ListUsersRequest $request, UserAdministration $users)
    {
        return ManagedUser::collection($users->search($request->validated()))->additional(['summary' => $users->summary()]);
    }

    public function store(CreateUserRequest $request, UserAdministration $users)
    {
        return (new ManagedUser($users->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user, UserAdministration $users)
    {
        return new ManagedUser($users->update($request->user(), $user, $request->validated()));
    }
}
