<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SetupAdministratorRequest;
use App\Http\Resources\AuthenticatedUser;
use App\Models\User;
use App\Services\AdministratorSetup;
use App\Services\LocalAuthentication;
use App\Services\NotificationAdministration;
use App\Services\PasskeyAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuthenticationController extends Controller
{
    public function status(Request $request, AdministratorSetup $setup)
    {
        return response()->json(['data' => ['requiresSetup' => $setup->isRequired(), 'localEnabled' => (bool) config('auth.local_enabled'), 'registrationEnabled' => (bool) config('auth.local_enabled') && (bool) config('auth.registration_enabled'), 'passkeysAvailable' => PasskeyAuthentication::available($request)]]);
    }

    public function setup(SetupAdministratorRequest $request, AdministratorSetup $setup)
    {
        $setup->complete($request->validated());

        return response()->json(['data' => ['activationRequired' => true]], 201);
    }

    public function login(LoginRequest $request, LocalAuthentication $authentication)
    {
        $authentication->login($request->validated(), $request);

        return new AuthenticatedUser($request->user());
    }

    public function register(RegisterRequest $request, LocalAuthentication $authentication)
    {
        $authentication->register($request->validated(), $request);

        return response()->json(['data' => ['activationRequired' => true]], 201);
    }

    public function user(Request $request)
    {
        return (new AuthenticatedUser($request->user()))->response()->setStatusCode(200);
    }

    public function logout(Request $request, LocalAuthentication $authentication)
    {
        app(NotificationAdministration::class)->logoutDevice($request->user(), $request->input('push_endpoint'), $request->session()->getId());
        $authentication->logout($request);

        return response()->noContent();
    }

    public function administration()
    {
        Gate::authorize('viewAdministration', User::class);

        return response()->json(['data' => ['authorized' => true]]);
    }
}
