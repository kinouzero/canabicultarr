<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OidcAuthentication
{
    public const SESSION_KEYS = ['oidc_intent', 'state', 'code_verifier', 'openidconnect_nonce'];

    public function __construct(private OidcClient $client) {}

    public function beginSetup(Request $request, string $token)
    {
        abort_unless($this->client->enabled(), 404);
        $hash = app(AdministratorSetup::class)->authorizeOidc($token);

        return $this->begin($request, setupTokenHash: $hash);
    }

    public function begin(Request $request, bool $link = false, ?string $setupTokenHash = null)
    {
        abort_unless($this->client->enabled(), 404);
        if (! $link && $request->user()) {
            throw new AuthFlowException('auth.errors.oidcAlreadySignedIn', 409);
        }
        $request->session()->forget(self::SESSION_KEYS);
        $request->session()->put('oidc_intent', [
            'action' => $setupTokenHash !== null ? 'setup' : ($link ? 'link' : 'login'),
            'setup_token_hash' => $setupTokenHash,
            'user_id' => $link ? $request->user()->getKey() : null,
            'version' => $link ? $request->user()->session_version : null,
            'expires' => now()->addMinutes(10)->timestamp,
            'issuer' => config('oidc.issuer'),
        ]);

        return $this->client->driver($request)->redirect();
    }

    public function complete(Request $request): void
    {
        abort_unless($this->client->enabled(), 404);
        $intent = $request->session()->pull('oidc_intent');
        if (! is_array($intent) || ($intent['expires'] ?? 0) <= now()->timestamp
            || ($intent['issuer'] ?? null) !== config('oidc.issuer')) {
            throw new AuthFlowException('auth.errors.oidcFailed', 422);
        }
        $identity = $this->client->driver($request)->user();
        $claims = $identity->getRaw();
        $subject = $identity->getId();
        $email = $identity->getEmail();
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255
            || ($claims['email_verified'] ?? false) !== true
            || ! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthFlowException('auth.errors.oidcUnverified', 422);
        }
        $issuer = config('oidc.issuer');
        if ($intent['action'] === 'link') {
            try {
                $user = DB::transaction(function () use ($intent, $request, $issuer, $subject, $email) {
                    $user = User::whereKey($intent['user_id'])->lockForUpdate()->firstOrFail();
                    if (! $request->user() || ! $user->is($request->user()) || ! $user->is_active
                        || $user->session_version !== $intent['version']
                        || $request->session()->get('auth_version', 0) !== $user->session_version) {
                        throw new AuthFlowException('auth.errors.oidcFailed', 422);
                    }
                    if (Str::lower($email) !== Str::lower($user->email)) {
                        throw new AuthFlowException('auth.errors.oidcEmailMismatch', 422);
                    }
                    // Never overwrite a link or take an identity from another account.
                    OidcIdentity::create(['user_id' => $user->getKey(), 'issuer' => $issuer, 'subject' => $subject]);

                    return $user;
                });
            } catch (UniqueConstraintViolationException) {
                throw new AuthFlowException('auth.errors.oidcLinked', 409);
            }
            Log::notice('authentication.oidc.linked', ['user_uuid' => $user->uuid]);
            $request->session()->regenerate();
            $request->session()->put('oidc_result', 'linked');

            return;
        }
        if ($request->user()) {
            throw new AuthFlowException('auth.errors.oidcAlreadySignedIn', 409);
        }
        if ($intent['action'] === 'setup') {
            $hash = $intent['setup_token_hash'] ?? null;
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new AuthFlowException('auth.errors.invalidSetupToken', 403);
            }
            $name = $identity->getName();
            $user = app(AdministratorSetup::class)->completeOidc($hash, $issuer, $subject, $email, is_string($name) ? $name : null);
            Auth::login($user);
            AuthenticationSession::refresh($request, $user);

            return;
        }
        DB::transaction(function () use ($issuer, $subject, $request) {
            $link = OidcIdentity::where('issuer', $issuer)->where('subject', $subject)->first();
            $user = $link ? User::whereKey($link->user_id)->lockForUpdate()->first() : null;
            if (! $user || ! $user->is_active) {
                throw new AuthFlowException('auth.errors.oidcNotLinked', 403);
            }
            Auth::login($user);
            AuthenticationSession::refresh($request, $user);
            Log::notice('authentication.oidc.succeeded', ['user_uuid' => $user->uuid]);
        });
    }
}
