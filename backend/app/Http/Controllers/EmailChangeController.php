<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuthenticatedUser;
use App\Http\Resources\ManagedUser;
use App\Models\User;
use App\Services\EmailChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EmailChangeController extends Controller
{
    public function confirm(Request $request, EmailChange $changes)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/D']],
            ['email.*' => 'auth.validation.emailInvalid', 'token.*' => 'emailChange.invalidLink']);
        $changes->confirm($data['email'], $data['token']);

        return response()->noContent();
    }

    public function resend(Request $request, EmailChange $changes)
    {
        $locale = $request->validate(['locale' => ['sometimes', 'in:en,fr']]);

        return new AuthenticatedUser($changes->manage($request->user(), $request->user(), true, $locale['locale'] ?? 'en'));
    }

    public function cancel(Request $request, EmailChange $changes)
    {
        return new AuthenticatedUser($changes->manage($request->user(), $request->user(), false));
    }

    public function resendManaged(Request $request, User $user, EmailChange $changes)
    {
        Gate::authorize('update', $user);
        $locale = $request->validate(['locale' => ['sometimes', 'in:en,fr']]);

        return new ManagedUser($changes->manage($request->user(), $user, true, $locale['locale'] ?? 'en'));
    }

    public function cancelManaged(Request $request, User $user, EmailChange $changes)
    {
        Gate::authorize('update', $user);

        return new ManagedUser($changes->manage($request->user(), $user, false));
    }
}
