<?php

namespace Tests\Feature;

use App\Models\ApplicationSetup;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Services\AdministratorSetup;
use App\Services\OidcClient;
use Database\Seeders\AccessProfileSeeder;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OidcAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://identity.example.test/application/o/canabicultarr/';

    private MockHandler $http;

    private static $key;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        config(['oidc' => [
            'enabled' => true, 'issuer' => self::ISSUER,
            'client_id' => 'test-client', 'client_secret' => 'fictional-client-secret',
            'redirect' => 'http://localhost/api/v1/auth/oidc/callback',
        ]]);
        self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->http = new MockHandler;
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->requests));
        $this->app->instance(OidcClient::class, new OidcClient(new Client(['handler' => $stack])));
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'oidc@example.test', 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id]]);
    }

    private function begin(bool $link = false, ?string $setupToken = null, array $metadata = []): array
    {
        Cache::flush();
        $this->http->reset();
        $this->http->append(new Response(200, [], json_encode(array_replace([
            'issuer' => config('oidc.issuer'),
            'authorization_endpoint' => 'https://identity.example.test/application/o/authorize/',
            'token_endpoint' => 'https://identity.example.test/application/o/token/',
            'jwks_uri' => 'https://identity.example.test/application/o/canabicultarr/jwks/',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ], $metadata))));
        if ($setupToken !== null) {
            $url = $this->postJson('/api/v1/setup/oidc', ['token' => $setupToken])->assertOk()->json('data.url');
        } elseif ($link) {
            $url = $this->postJson('/api/v1/auth/oidc/link')->assertOk()->json('data.url');
        } else {
            $url = $this->get('/api/v1/auth/oidc/redirect')->assertRedirect()->headers->get('Location');
        }
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function token(array $flow, array $overrides = [], bool $badSignature = false, string $algorithm = 'RS256'): void
    {
        $claims = array_replace([
            'iss' => config('oidc.issuer'), 'sub' => 'stable-subject', 'aud' => 'test-client',
            'iat' => time() - 5, 'exp' => time() + 300, 'nonce' => $flow['nonce'],
            'email' => 'oidc@example.test', 'email_verified' => true,
            'groups' => ['admin'], 'permission_role_ids' => [AccessProfileSeeder::defaultProfile('administrator')->id],
        ], $overrides);
        $signingKey = $algorithm === 'ES256' ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) : self::$key;
        $key = $badSignature ? openssl_pkey_new(['private_key_bits' => 2048]) : $signingKey;
        openssl_pkey_export($key, $pem);
        $jwt = JWT::encode($claims, $pem, $algorithm, 'test-key');
        $rsa = openssl_pkey_get_details(self::$key)['rsa'];
        $jwk = [
            'kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
        ];
        if ($algorithm === 'ES256') {
            $ec = openssl_pkey_get_details($signingKey)['ec'];
            // OpenSSL can omit leading zero bytes, but P-256 JWK coordinates must be 32 bytes.
            $jwk = [
                'kty' => 'EC', 'kid' => 'test-key', 'alg' => 'ES256', 'use' => 'sig', 'crv' => 'P-256',
                'x' => JWT::urlsafeB64Encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
                'y' => JWT::urlsafeB64Encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            ];
        }
        $jwks = json_encode(['keys' => [$jwk]]);
        $this->http->append(new Response(200, [], json_encode([
            'access_token' => 'fictional-access-token', 'id_token' => $jwt, 'token_type' => 'Bearer',
        ])), new Response(200, [], $jwks), new Response(200, [], $jwks));
    }

    private function finishCallback(array $flow): TestResponse
    {
        return $this->get('/api/v1/auth/oidc/callback?'.http_build_query(['state' => $flow['state'], 'code' => 'fictional-code']))->assertRedirect('/')->assertHeader('Location', '/');
    }

    private function linked(User $user): void
    {
        OidcIdentity::create(['user_id' => $user->id, 'issuer' => config('oidc.issuer'), 'subject' => 'stable-subject']);
    }

    #[DataProvider('genericProviders')]
    public function test_generic_provider_issuers_and_basic_client_authentication(string $issuer, string $algorithm): void
    {
        config(['oidc.issuer' => $issuer]);
        $user = $this->user();
        $this->linked($user);
        $flow = $this->begin(metadata: ['token_endpoint_auth_methods_supported' => ['client_secret_basic'], 'id_token_signing_alg_values_supported' => [$algorithm]]);
        $this->token($flow, algorithm: $algorithm);
        $this->finishCallback($flow);
        $this->assertAuthenticatedAs($user);
        $tokenRequest = collect($this->requests)->first(fn ($entry) => $entry['request']->getMethod() === 'POST')['request'];
        $this->assertSame('Basic '.base64_encode('test-client:fictional-client-secret'), $tokenRequest->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('client_secret', (string) $tokenRequest->getBody());
    }

    public static function genericProviders(): array
    {
        return [
            'authentik' => ['https://identity.example.test/application/o/canabicultarr/', 'RS256'],
            'authelia' => ['https://sso.example.test', 'RS256'],
            'pocket-id' => ['https://identity.example.test', 'ES256'],
            'keycloak' => ['https://identity.example.test/realms/canabicultarr', 'RS256'],
        ];
    }

    public function test_return_redirect_does_not_expose_the_internal_proxy_host(): void
    {
        $this->withHeaders(['Host' => 'backend:8000'])
            ->get('/api/v1/auth/oidc/callback?error=access_denied')
            ->assertStatus(302)->assertHeader('Location', '/')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        config(['oidc.enabled' => false]);
        $this->get('/api/v1/auth/oidc/redirect')
            ->assertStatus(302)->assertHeader('Location', '/');
    }

    public function test_disabled_and_incomplete_configuration_do_not_offer_login(): void
    {
        config(['oidc.enabled' => false]);
        $this->getJson('/api/v1/auth/oidc')->assertOk()->assertJsonPath('data.enabled', false);
        config(['oidc.enabled' => true, 'oidc.client_secret' => '']);
        $this->getJson('/api/v1/auth/oidc')->assertJsonPath('data.enabled', false);
        config(['oidc.client_secret' => 'test', 'oidc.issuer' => 'http://remote.example.test/']);
        $this->getJson('/api/v1/auth/oidc')->assertJsonPath('data.enabled', false);
        $this->assertCount(0, $this->requests);
    }

    public function test_oidc_status_requests_do_not_exhaust_the_setup_rate_limit(): void
    {
        Mail::fake();
        config(['app.url' => 'https://culture.example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'accounts@example.test']);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->getJson('/api/v1/auth/oidc')->assertOk();
        }
        $token = app(AdministratorSetup::class)->issueToken();
        $this->postJson('/api/v1/setup', [
            'token' => $token, 'name' => 'Test Administrator', 'email' => 'admin@example.test',
            'password' => 'A safe fictional password 123!',
            'password_confirmation' => 'A safe fictional password 123!',
        ])->assertCreated();
    }

    public function test_authorization_uses_state_nonce_and_pkce(): void
    {
        $flow = $this->begin();
        $this->assertNotEmpty($flow['state']);
        $this->assertNotEmpty($flow['nonce']);
        $this->assertSame('S256', $flow['code_challenge_method']);
        $this->assertSame('openid email profile', $flow['scope']);
        $this->assertArrayNotHasKey('client_secret', $flow);
        $this->assertSame(43, strlen($flow['code_challenge']));
    }

    public function test_existing_account_can_explicitly_link_then_login_without_role_changes(): void
    {
        Log::spy();
        $user = $this->user();
        $user->permission_role_ids = [AccessProfileSeeder::defaultProfile()->id];
        $user->save();
        $hash = $user->password;
        $this->actingAs($user)->withSession(['auth_version' => 0]);
        $flow = $this->begin(true);
        $this->token($flow);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'linked');
        $this->assertDatabaseHas('oidc_identities', ['user_id' => $user->id, 'subject' => 'stable-subject']);
        $this->getJson('/api/v1/auth/user')->assertJsonPath('data.oidcLinked', true);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $flow = $this->begin();
        $this->token($flow);
        $this->finishCallback($flow)->assertSessionHas('auth_version', 0);
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->isAdministrator());
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
        $this->assertNotEmpty($this->requests[1]['request']->getBody()->__toString());
        parse_str((string) $this->requests[1]['request']->getBody(), $tokenFields);
        $this->assertNotEmpty($tokenFields['code_verifier']);
        $this->assertSame($flow['code_challenge_method'], 'S256');
        Log::shouldHaveReceived('notice')->with('authentication.oidc.linked', ['user_uuid' => $user->uuid])->once();
    }

    public function test_email_match_does_not_auto_link_or_create_an_account(): void
    {
        $user = $this->user();
        $flow = $this->begin();
        $this->token($flow);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcNotLinked');
        $this->assertGuest();
        $this->assertDatabaseCount('oidc_identities', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'https://attacker.example.test/']],
            'audience' => [['aud' => 'other-client']],
            'authorized party' => [['azp' => 'other-client']],
            'expired' => [['exp' => 1]],
            'missing expiry' => [['exp' => null]],
            'nonce' => [['nonce' => 'wrong']],
            'unverified' => [['email_verified' => false]],
            'string verification' => [['email_verified' => 'true']],
            'missing subject' => [['sub' => '']],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_do_not_authenticate(array $claims): void
    {
        $this->linked($this->user());
        $flow = $this->begin();
        $this->token($flow, $claims);
        $this->finishCallback($flow)->assertSessionMissing('state')->assertSessionMissing('code_verifier')->assertSessionMissing('openidconnect_nonce');
        $this->assertGuest();
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->linked($this->user());
        $flow = $this->begin();
        $this->token($flow, [], true);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcFailed');
        $this->assertGuest();
    }

    public function test_missing_state_expired_intent_and_replay_are_rejected(): void
    {
        $this->linked($this->user());
        $flow = $this->begin();
        $this->finishCallback(['state' => 'wrong'])->assertSessionHas('oidc_result', 'auth.errors.oidcFailed');
        $this->assertCount(1, $this->requests);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcFailed');
        $flow = $this->begin();
        $this->travel(11)->minutes();
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcFailed');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $user = $this->user();
        $this->linked($user);
        $user->forceFill(['is_active' => false])->save();
        $flow = $this->begin();
        $this->token($flow);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcNotLinked');
        $this->assertGuest();
    }

    public function test_link_requires_matching_verified_email_and_current_session(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['auth_version' => 0]);
        $flow = $this->begin(true);
        $this->token($flow, ['email' => 'different@example.test']);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcEmailMismatch');
        $flow = $this->begin(true);
        $this->token($flow);
        $user->session_version = 1;
        $user->save();
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcFailed');
        $this->assertDatabaseCount('oidc_identities', 0);
    }

    public function test_link_cannot_reassign_identity_and_guest_cannot_start_link(): void
    {
        $this->postJson('/api/v1/auth/oidc/link')->assertUnauthorized();
        $first = $this->user();
        $second = User::factory()->create(['email' => 'oidc-other@example.test']);
        $this->linked($second);
        $this->actingAs($first)->withSession(['auth_version' => 0]);
        $flow = $this->begin(true);
        $this->token($flow);
        $this->finishCallback($flow)->assertSessionHas('oidc_result', 'auth.errors.oidcLinked');
        $this->assertDatabaseHas('oidc_identities', ['user_id' => $second->id]);
        $this->assertDatabaseCount('oidc_identities', 1);
    }

    public function test_provider_error_is_sanitized_and_local_password_login_still_works(): void
    {
        $user = $this->user();
        $flow = $this->begin();
        $this->get('/api/v1/auth/oidc/callback?error=access_denied&error_description=do-not-expose&state='.$flow['state'])
            ->assertRedirect('/')->assertSessionHas('oidc_result', 'auth.errors.oidcFailed');
        $status = $this->getJson('/api/v1/auth/oidc')->assertOk()->assertDontSee('do-not-expose');
        $this->getJson('/api/v1/auth/oidc')->assertJsonPath('data.result', null);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_ordinary_oidc_login_cannot_bootstrap_without_the_setup_grant(): void
    {
        $flow = $this->begin();
        $this->token($flow);
        $this->finishCallback($flow);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->getJson('/api/v1/auth/oidc')->assertJsonPath('data.result', 'auth.errors.oidcNotLinked');
        $this->assertTrue(app(AdministratorSetup::class)->isRequired());
    }

    private function setupFlow(?string $token = null): array
    {
        // Reuse the protocol fixture, replacing only the initiation endpoint.
        return $this->begin(setupToken: $token ?? app(AdministratorSetup::class)->issueToken());
    }

    public function test_verified_oidc_bootstraps_once_without_a_local_password_or_smtp(): void
    {
        config(['auth.local_enabled' => false, 'mail.default' => 'log']);
        $flow = $this->setupFlow();
        $this->assertArrayHasKey('code_challenge', $flow);
        $this->assertNotEmpty($flow['nonce']);
        $this->token($flow, ['name' => 'OIDC Administrator', 'groups' => [], 'permission_role_ids' => ['ignored']]);
        $previous = session()->getId();
        $this->finishCallback($flow);
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isAdministrator());
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->email_activation_required);
        $this->assertSame('OIDC Administrator', $user->name);
        $this->assertSame($user->id, OidcIdentity::sole()->user_id);
        $this->assertNull(ApplicationSetup::findOrFail(1)->token_hash);
        $this->assertFalse(app(AdministratorSetup::class)->isRequired());
        $this->assertNotSame($previous, session()->getId());
        $this->assertDatabaseCount('jobs', 0);
        $this->getJson('/api/v1/auth/user')->assertOk();
        Auth::logout();
        config(['auth.local_enabled' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Any fictional password 123!'])->assertUnprocessable();
        $this->finishCallback($flow);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('invalidVerifiedClaims')]
    public function test_setup_rejects_unverified_identity_without_consuming_token(mixed $claim): void
    {
        $flow = $this->setupFlow();
        $hash = ApplicationSetup::findOrFail(1)->token_hash;
        $this->token($flow, ['email_verified' => $claim]);
        $this->finishCallback($flow);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame($hash, ApplicationSetup::findOrFail(1)->token_hash);
    }

    public static function invalidVerifiedClaims(): array
    {
        return [[false], [null], ['true'], [1]];
    }

    public function test_rotated_setup_token_is_rechecked_at_callback(): void
    {
        $flow = $this->setupFlow();
        $this->token($flow);
        app(AdministratorSetup::class)->issueToken();
        $this->finishCallback($flow);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->getJson('/api/v1/auth/oidc')->assertJsonPath('data.result', 'auth.errors.invalidSetupToken');
    }

    public function test_setup_expiry_and_an_existing_account_prevent_callback_claiming(): void
    {
        $flow = $this->setupFlow();
        $this->token($flow);
        ApplicationSetup::findOrFail(1)->forceFill(['token_expires_at' => now()])->save();
        $this->finishCallback($flow);
        $this->assertDatabaseCount('users', 0);
        $flow = $this->setupFlow();
        $this->token($flow);
        $existing = User::factory()->create();
        $this->finishCallback($flow);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($existing->id, User::sole()->id);
        $this->assertDatabaseCount('oidc_identities', 0);
    }

    public function test_wrong_token_never_starts_a_provider_request_and_raw_token_is_not_in_session(): void
    {
        $token = app(AdministratorSetup::class)->issueToken();
        $this->postJson('/api/v1/setup/oidc', ['token' => str_repeat('x', 64)])->assertForbidden();
        $this->postJson('/api/v1/setup/oidc', ['token' => ''])->assertUnprocessable();
        $this->assertCount(0, $this->requests);
        $this->begin(setupToken: $token);
        $this->assertSame(hash('sha256', $token), session('oidc_intent.setup_token_hash'));
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
    }
}
