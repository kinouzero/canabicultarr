<?php

namespace App\Http\Controllers;

use App\Exceptions\AuthFlowException;
use App\Services\OidcAuthentication;
use App\Services\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OidcAuthenticationController extends Controller
{
    public function status(Request $request, OidcClient $client)
    {
        return response()->json(['data' => [
            'enabled' => $client->enabled(),
            'result' => $request->session()->pull('oidc_result'),
        ]]);
    }

    public function setup(Request $request, OidcAuthentication $authentication)
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/D']], ['token.*' => 'auth.validation.tokenRequired']);
        try {
            $response = $authentication->beginSetup($request, $data['token']);

            return response()->json(['data' => ['url' => $response->getTargetUrl()]]);
        } catch (Throwable $exception) {
            $request->session()->forget(OidcAuthentication::SESSION_KEYS);
            throw $exception instanceof AuthFlowException ? $exception : new AuthFlowException('auth.errors.oidcFailed', 422);
        }
    }

    public function redirect(Request $request, OidcAuthentication $authentication)
    {
        return $this->begin($request, $authentication, false);
    }

    public function link(Request $request, OidcAuthentication $authentication)
    {
        return $this->begin($request, $authentication, true);
    }

    private function begin(Request $request, OidcAuthentication $authentication, bool $link)
    {
        try {
            $response = $authentication->begin($request, $link);

            return $link ? response()->json(['data' => ['url' => $response->getTargetUrl()]]) : $response;
        } catch (Throwable $exception) {
            $request->session()->forget(OidcAuthentication::SESSION_KEYS);
            if ($link) {
                throw new AuthFlowException('auth.errors.oidcFailed', 422);
            }
            $request->session()->put('oidc_result', 'auth.errors.oidcFailed');

            return new RedirectResponse('/');
        }
    }

    public function callback(Request $request, OidcAuthentication $authentication)
    {
        try {
            $authentication->complete($request);
        } catch (Throwable $exception) {
            // Provider exceptions may contain tokens, client secrets, or email addresses.
            Log::notice('authentication.oidc.rejected');
            $request->session()->put('oidc_result', $exception instanceof AuthFlowException
                ? $exception->errorCode : 'auth.errors.oidcFailed');
        } finally {
            $request->session()->forget(OidcAuthentication::SESSION_KEYS);
        }

        return (new RedirectResponse('/'))->header('Referrer-Policy', 'no-referrer');
    }
}
