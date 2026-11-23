<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuthenticatedUser;
use App\Models\Passkey;
use App\Services\AuthenticationSession;
use App\Services\PasskeyAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PasskeyController extends Controller
{
    public function index(Request $request, PasskeyAuthentication $authentication)
    {
        $authentication->assertOrigin($request);
        Gate::authorize('account.security');

        return response()->json(['data' => [
            'keys' => $authentication->keys($request->user(), $request)->oldest()->get()->map($this->summary(...)),
            'recentlyVerified' => AuthenticationSession::recentlyVerified($request),
        ]]);
    }

    public function registrationOptions(Request $request, PasskeyAuthentication $authentication)
    {
        return response()->json(['data' => $authentication->registrationOptions($request)]);
    }

    public function register(Request $request, PasskeyAuthentication $authentication)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'credential' => ['required', 'string', 'max:16384']]);
        $key = $authentication->register($request, $data['name'], $data['credential']);

        return response()->json(['data' => $this->summary($key)], 201);
    }

    public function loginOptions(Request $request, PasskeyAuthentication $authentication)
    {
        return response()->json(['data' => $authentication->loginOptions($request)]);
    }

    public function login(Request $request, PasskeyAuthentication $authentication)
    {
        $data = $request->validate(['credential' => ['required', 'string', 'max:16384']]);

        return new AuthenticatedUser($authentication->login($request, $data['credential']));
    }

    public function destroy(Request $request, PasskeyAuthentication $authentication, string $passkey)
    {
        $authentication->remove($request, $passkey);

        return response()->noContent();
    }

    private function summary(Passkey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'createdAt' => $key->created_at->toISOString(),
            'lastUsedAt' => $key->last_used_at?->toISOString(),
        ];
    }
}
