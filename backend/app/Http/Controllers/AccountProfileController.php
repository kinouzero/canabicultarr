<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountProfileRequest;
use App\Http\Resources\AuthenticatedUser;
use App\Services\AccountProfile;

class AccountProfileController extends Controller
{
    public function update(UpdateAccountProfileRequest $request, AccountProfile $profile)
    {
        return new AuthenticatedUser($profile->update($request->user(), $request->validated()));
    }
}
