<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountSecurityRequest;
use App\Services\AccountSecurity;
use App\Services\LocalAuthentication;

class AccountSecurityController extends Controller
{
    public function status(AccountSecurityRequest $request, AccountSecurity $security)
    {
        return ['data' => $security->status($request->user(), $request->session()->getId())];
    }

    public function password(AccountSecurityRequest $request, AccountSecurity $security, LocalAuthentication $authentication)
    {
        $data = $request->validated();
        $security->apply($request->user(), $data['current_password'], $data['password']);
        $request->user()->setRememberToken(null);
        $authentication->logout($request);

        return response()->noContent();
    }

    public function revoke(AccountSecurityRequest $request, AccountSecurity $security, LocalAuthentication $authentication)
    {
        $security->apply($request->user(), $request->validated('current_password'));
        $request->user()->setRememberToken(null);
        $authentication->logout($request);

        return response()->noContent();
    }
}
