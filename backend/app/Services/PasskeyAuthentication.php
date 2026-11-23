<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyAuthentication
{
    private const MAX_KEYS = 10;

    private const CHALLENGE_SECONDS = 300;

    private readonly NormalizerInterface&DenormalizerInterface $serializer;

    public function __construct()
    {
        $this->serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport])
        ))->create();
    }

    public static function available(Request $request): bool
    {
        return self::origin($request) !== null;
    }

    private static function origin(Request $request): ?string
    {
        if (! config('auth.passkeys_enabled')) {
            return null;
        }
        $url = parse_url((string) config('app.url'));
        $host = strtolower($url['host'] ?? '');
        $scheme = $url['scheme'] ?? '';
        if ($scheme === 'http' && $host === '127.0.0.1') {
            $host = 'localhost';
        }
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            || ! ($scheme === 'https' || ($scheme === 'http' && $host === 'localhost'))) {
            return null;
        }
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = $url['port'] ?? $defaultPort;
        $authority = $host.($port === $defaultPort ? '' : ':'.$port);
        // A reverse proxy can terminate TLS before forwarding the request.
        if (! in_array($request->getHttpHost(), [$authority, $host.':'.$port], true)) {
            return null;
        }

        return $scheme.'://'.$authority;
    }

    public function registrationOptions(Request $request): array
    {
        $this->assertOrigin($request);
        $user = $this->assertRecentAccount($request);
        $keys = $this->keys($user, $request)->limit(self::MAX_KEYS)->get();
        $this->assertLimit($keys->count());
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(config('app.name'), $request->getHost()),
            PublicKeyCredentialUserEntity::create($user->email, $user->uuid, $user->name),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(userVerification: 'required', residentKey: 'required'),
            attestation: 'none',
            excludeCredentials: $keys->map(fn (Passkey $key) => $this->record($key)->getPublicKeyCredentialDescriptor())->all(),
            timeout: 60000,
        );

        return $this->remember($request, 'registration', $options, $user);
    }

    public function register(Request $request, string $name, string $json): Passkey
    {
        $this->assertOrigin($request);
        $user = $this->assertRecentAccount($request);
        $options = $this->consume($request, 'registration', PublicKeyCredentialCreationOptions::class, $user);
        $credential = $this->decode($json);
        if (! $credential->response instanceof AuthenticatorAttestationResponse) {
            throw $this->invalid();
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremony($request)->creationCeremony())
                ->check($credential->response, $options, $request->getHost());
        } catch (WebauthnException|\InvalidArgumentException|\RuntimeException) {
            throw $this->invalid();
        }
        if (! hash_equals($record->publicKeyCredentialId, $credential->rawId)) {
            throw $this->invalid();
        }

        try {
            $key = DB::transaction(function () use ($request, $user, $name, $record) {
                $current = $this->lockAccount($user);
                $this->assertLimit($this->keys($current, $request)->count());

                return Passkey::create([
                    'user_id' => $current->id,
                    'rp_id' => $request->getHost(),
                    'credential_hash' => hash('sha256', $record->publicKeyCredentialId),
                    'name' => $name,
                    'credential' => $this->serializer->normalize($record),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new AuthFlowException('auth.errors.passkeyDuplicate', 409);
        }
        Log::notice('authentication.passkey.registered', ['user_uuid' => $user->uuid]);

        return $key;
    }

    public function loginOptions(Request $request): array
    {
        $this->assertGuest($request);
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32), $request->getHost(), userVerification: 'required', timeout: 60000);

        return $this->remember($request, 'login', $options);
    }

    public function login(Request $request, string $json): User
    {
        $this->assertGuest($request);
        $options = $this->consume($request, 'login', PublicKeyCredentialRequestOptions::class);
        $credential = $this->decode($json);
        if (! $credential->response instanceof AuthenticatorAssertionResponse) {
            throw $this->invalid();
        }
        $query = Passkey::where('rp_id', $request->getHost())->where('credential_hash', hash('sha256', $credential->rawId));
        $userId = $query->value('user_id');
        if ($userId === null) {
            throw $this->invalid();
        }
        $user = DB::transaction(function () use ($request, $userId, $query, $credential, $options) {
            $current = User::whereKey($userId)->lockForUpdate()->first();
            $key = $query->lockForUpdate()->first();
            if (! $current || ! $key || $key->user_id !== $current->id || ! $this->isActive($current)) {
                throw $this->invalid();
            }
            $record = $this->record($key);
            if (! hash_equals($current->uuid, $record->userHandle)) {
                throw $this->invalid();
            }
            try {
                $updated = AuthenticatorAssertionResponseValidator::create($this->ceremony($request)->requestCeremony())
                    ->check($record, $credential->response, $options, $request->getHost(), null);
            } catch (WebauthnException|\InvalidArgumentException|\RuntimeException) {
                throw $this->invalid();
            }
            $key->update(['credential' => $this->serializer->normalize($updated), 'last_used_at' => now()]);

            return $current;
        });
        Auth::login($user);
        AuthenticationSession::refresh($request, $user);
        Log::notice('authentication.passkey.succeeded', ['user_uuid' => $user->uuid]);

        return $user;
    }

    public function keys(User $user, Request $request): Builder
    {
        return Passkey::where('user_id', $user->id)->where('rp_id', $request->getHost());
    }

    public function remove(Request $request, string $id): void
    {
        $this->assertOrigin($request);
        $user = $this->assertRecentAccount($request);
        DB::transaction(function () use ($request, $id, $user) {
            $current = $this->lockAccount($user);
            $this->keys($current, $request)->whereKey($id)->firstOrFail()->delete();
        });
        Log::notice('authentication.passkey.removed', ['user_uuid' => $user->uuid]);
    }

    public function assertOrigin(Request $request): void
    {
        $origin = self::origin($request);
        if ($origin === null || (! $request->isMethod('GET') && $request->header('Origin') !== $origin)) {
            throw new AuthFlowException('auth.errors.passkeyUnavailable', 403);
        }
    }

    private function assertGuest(Request $request): void
    {
        $this->assertOrigin($request);
        if ($request->user()) {
            throw new AuthFlowException('auth.errors.passkeyAlreadySignedIn', 409);
        }
    }

    private function assertRecentAccount(Request $request): User
    {
        Gate::authorize('account.security');
        if (! AuthenticationSession::recentlyVerified($request)) {
            throw new AuthFlowException('auth.errors.passkeyReauthenticate', 409);
        }

        return $request->user();
    }

    private function lockAccount(User $user): User
    {
        $current = User::whereKey($user->id)->lockForUpdate()->first();
        if (! $current || ! $this->isActive($current) || $current->session_version !== $user->session_version) {
            throw new AuthenticationException;
        }

        return $current;
    }

    private function isActive(User $user): bool
    {
        return $user->is_active && (! $user->email_activation_required || $user->email_verified_at);
    }

    private function assertLimit(int $count): void
    {
        if ($count >= self::MAX_KEYS) {
            throw new AuthFlowException('auth.errors.passkeyLimit', 409);
        }
    }

    private function ceremony(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([self::origin($request)]);

        return $factory;
    }

    private function record(Passkey $key): CredentialRecord
    {
        return $this->serializer->denormalize($key->credential, CredentialRecord::class);
    }

    private function decode(string $json): PublicKeyCredential
    {
        try {
            $credential = $this->serializer->denormalize(json_decode($json, true, 32, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (WebauthnException|SerializerException|\JsonException|\InvalidArgumentException|\RuntimeException|\TypeError|\ValueError) {
            throw $this->invalid();
        }
        if ($credential->response->clientDataJSON->crossOrigin) {
            throw $this->invalid();
        }

        return $credential;
    }

    private function remember(Request $request, string $kind, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options, ?User $user = null): array
    {
        $data = $this->serializer->normalize($options);
        $request->session()->put('passkey_'.$kind, [
            'options' => $data,
            'origin' => self::origin($request),
            'user' => $user?->uuid,
            'expires' => now()->timestamp + self::CHALLENGE_SECONDS,
        ]);

        return $data;
    }

    private function consume(Request $request, string $kind, string $type, ?User $user = null): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        $ceremony = $request->session()->pull('passkey_'.$kind);
        if (! $ceremony || $ceremony['origin'] !== self::origin($request) || $ceremony['user'] !== $user?->uuid
            || $ceremony['expires'] <= now()->timestamp
            || ! Cache::add('passkey-used:'.hash('sha256', $ceremony['options']['challenge']), true, self::CHALLENGE_SECONDS)) {
            throw $this->invalid();
        }

        return $this->serializer->denormalize($ceremony['options'], $type);
    }

    private function invalid(): AuthFlowException
    {
        return new AuthFlowException('auth.errors.passkeyFailed', 422);
    }
}
