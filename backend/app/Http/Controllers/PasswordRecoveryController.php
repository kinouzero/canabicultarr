<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordRecoveryRequest;
use App\Services\PasswordRecovery;
use Illuminate\Support\Facades\Auth;

class PasswordRecoveryController extends Controller
{
    public function status(PasswordRecovery $recovery)
    {
        return response()->json(['data' => ['enabled' => $recovery->ready()]]);
    }

    public function request(PasswordRecoveryRequest $request, PasswordRecovery $recovery)
    {
        $data = $request->validated();
        $recovery->request($data['email'], $data['locale']);

        return response()->noContent();
    }

    public function reset(PasswordRecoveryRequest $request, PasswordRecovery $recovery)
    {
        $data = $request->validated();
        $recovery->reset($data['email'], $data['token'], $data['password']);
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
