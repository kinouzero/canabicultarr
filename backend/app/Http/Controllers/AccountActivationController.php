<?php

namespace App\Http\Controllers;

use App\Services\AccountActivation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountActivationController extends Controller
{
    public function resend(Request $request, AccountActivation $activation)
    {
        $data = $this->input($request);
        $activation->resend($data['email'], $data['locale'] ?? 'en');

        return response()->noContent();
    }

    public function confirm(Request $request, AccountActivation $activation)
    {
        $data = $this->input($request, true);
        $activation->confirm($data['email'], $data['token']);

        return response()->noContent();
    }

    private function input(Request $request, bool $confirm = false): array
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        return $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'locale' => ['sometimes', 'in:en,fr'],
            ...($confirm ? ['token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/D']] : []),
        ], ['email.*' => 'auth.validation.emailInvalid', 'token.*' => 'accountActivation.invalidLink', 'locale.*' => 'auth.errors.requestFailed']);
    }
}
