<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://culture.example.test';

    private const RP = 'culture.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::ORIGIN, 'auth.passkeys_enabled' => true, 'auth.local_enabled' => true]);
        Cache::flush();
        $this->withHeader('Origin', self::ORIGIN);
    }

    public function test_register_sign_in_without_password_then_remove_a_verified_passkey(): void
    {
        $user = User::factory()->create(['session_version' => 3]);
        [$key, $id, $created] = $this->enroll($user);
        $this->assertDatabaseCount('passkeys', 1);
        $this->getJson(self::ORIGIN.'/api/v1/account/passkeys')->assertOk()
            ->assertJsonPath('data.keys.0.name', 'Test authenticator')
            ->assertJsonMissingPath('data.keys.0.credential');
        $this->logoutFromApp();
        config(['auth.local_enabled' => false]);

        $challenge = $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/options')->assertOk()
            ->assertJsonPath('data.userVerification', 'required')->json('data.challenge');
        $guestSession = session()->getId();
        $assertion = $this->assertion($challenge, $id, $key, $user->uuid);
        $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/login', ['credential' => $assertion])
            ->assertOk()->assertJsonPath('data.id', $user->uuid);
        $this->assertNotSame($guestSession, session()->getId());
        $this->assertEquals(3, session('auth_version'));
        $this->getJson(self::ORIGIN.'/api/v1/auth/user')->assertOk();
        $this->assertNotNull(Passkey::findOrFail($created)->last_used_at);
        $this->deleteJson(self::ORIGIN.'/api/v1/account/passkeys/'.$created)->assertNoContent();
        $this->assertDatabaseEmpty('passkeys');
        $this->logoutFromApp();
        $options = $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/options')->assertOk()->json('data.challenge');
        $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/login', [
            'credential' => $this->assertion($options, $id, $key, $user->uuid),
        ])->assertUnprocessable();
    }

    public function test_login_rejects_bad_origin_absent_verification_and_replayed_or_expired_challenges(): void
    {
        $user = User::factory()->create();
        [$key, $id] = $this->enroll($user);
        $this->logoutFromApp();
        $optionsEndpoint = self::ORIGIN.'/api/v1/auth/passkeys/options';
        $login = self::ORIGIN.'/api/v1/auth/passkeys/login';

        $this->postJson($optionsEndpoint, [], ['Origin' => 'https://other.example.test'])->assertForbidden();
        $this->postJson('http://culture.example.test/api/v1/auth/passkeys/options', [], ['Origin' => 'http://culture.example.test'])->assertForbidden();
        $options = $this->postJson($optionsEndpoint)->assertOk()->json('data.challenge');
        $this->postJson($login, ['credential' => $this->assertion($options, $id, $key, $user->uuid, origin: 'https://culture.example.test:444')])->assertUnprocessable();
        $this->postJson($login, ['credential' => $this->assertion($options, $id, $key, $user->uuid)])->assertUnprocessable();
        $options = $this->postJson($optionsEndpoint)->assertOk()->json('data.challenge');
        $this->postJson($login, ['credential' => $this->assertion($options, $id, $key, $user->uuid, flags: 0x01)])->assertUnprocessable();
        $options = $this->postJson($optionsEndpoint)->assertOk()->json('data.challenge');
        $this->travel(6)->minutes();
        $this->postJson($login, ['credential' => $this->assertion($options, $id, $key, $user->uuid)])->assertUnprocessable();

        $this->assertGuest();
        $this->assertNull(Passkey::firstOrFail()->last_used_at);
    }

    public function test_passkeys_cannot_authenticate_activation_pending_accounts_or_manage_other_accounts(): void
    {
        $user = User::factory()->create();
        [$key, $id, $created] = $this->enroll($user);
        $this->logoutFromApp();
        $other = User::factory()->create();
        $this->actingAs($other)->withSession(['auth_verified_at' => now()->subMinutes(11)->timestamp]);
        $this->getJson(self::ORIGIN.'/api/v1/account/passkeys')->assertOk()->assertJsonPath('data.keys', []);
        $this->postJson(self::ORIGIN.'/api/v1/account/passkeys/options')->assertConflict()->assertJsonPath('error.code', 'auth.errors.passkeyReauthenticate');
        $this->withSession(['auth_verified_at' => now()->timestamp]);
        $this->deleteJson(self::ORIGIN.'/api/v1/account/passkeys/'.$created)->assertNotFound();
        $this->logoutFromApp();

        foreach ([['email_activation_required' => true, 'email_verified_at' => null], ['is_active' => false]] as $state) {
            $user->forceFill(['email_activation_required' => false, ...$state])->save();
            $challenge = $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/options')->assertOk()->json('data.challenge');
            $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/login', ['credential' => $this->assertion($challenge, $id, $key, $user->uuid)])->assertUnprocessable();
        }
        $this->assertGuest();
    }

    public function test_passkey_availability_uses_the_configured_origin_and_supports_localhost_for_desktop(): void
    {
        $this->getJson(self::ORIGIN.'/api/v1/setup')->assertOk()->assertJsonPath('data.passkeysAvailable', true);
        $this->getJson('https://unconfigured.example.test/api/v1/setup')->assertOk()->assertJsonPath('data.passkeysAvailable', false);
        $this->postJson('http://culture.example.test/api/v1/auth/passkeys/options')->assertOk()->assertJsonPath('data.rpId', self::RP);
        config(['auth.passkeys_enabled' => false]);
        $this->postJson(self::ORIGIN.'/api/v1/auth/passkeys/options')->assertForbidden();

        config(['auth.passkeys_enabled' => true, 'app.url' => 'http://127.0.0.1:8420']);
        $this->getJson('http://127.0.0.1:8420/api/v1/setup')->assertOk()->assertJsonPath('data.passkeysAvailable', false);
        $this->postJson('http://localhost:8420/api/v1/auth/passkeys/options', [], ['Origin' => 'http://localhost:8420'])
            ->assertOk()->assertJsonPath('data.rpId', 'localhost');
    }

    public function test_invalid_registration_data_does_not_create_a_key(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth_verified_at' => now()->timestamp]);
        $options = $this->postJson(self::ORIGIN.'/api/v1/account/passkeys/options')->assertOk()->json('data');
        $badCredential = $this->credential(random_bytes(32), [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($this->clientData('webauthn.create', $options['challenge'], self::ORIGIN)),
            'attestationObject' => Base64UrlSafe::encodeUnpadded("\x9f"),
        ]);
        $this->postJson(self::ORIGIN.'/api/v1/account/passkeys', ['name' => 'Invalid key', 'credential' => $badCredential])
            ->assertUnprocessable()->assertJsonPath('error.code', 'auth.errors.passkeyFailed');
        $this->assertDatabaseEmpty('passkeys');
    }

    private function enroll(User $user): array
    {
        $this->actingAs($user)->withSession(['auth_version' => $user->session_version, 'auth_verified_at' => now()->timestamp]);
        $options = $this->postJson(self::ORIGIN.'/api/v1/account/passkeys/options')->assertOk()->json('data');
        $this->assertSame(self::RP, $options['rp']['id']);
        $this->assertSame($user->uuid, Base64UrlSafe::decodeNoPadding($options['user']['id']));
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $public = openssl_pkey_get_details($key)['ec'];
        $id = random_bytes(32);
        // COSE EC2 public key and an authenticator registration with no attestation.
        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($public['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($public['y'], 32, "\0", STR_PAD_LEFT)));
        $authData = hash('sha256', self::RP, true).chr(0x45).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($id)).$id.(string) $cose;
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData))
            ->add(TextStringObject::create('attStmt'), MapObject::create());
        $credential = $this->credential($id, [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($this->clientData('webauthn.create', $options['challenge'], self::ORIGIN)),
            'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestation),
        ]);
        $created = $this->postJson(self::ORIGIN.'/api/v1/account/passkeys', ['name' => 'Test authenticator', 'credential' => $credential])
            ->assertCreated()->json('data.id');

        return [$key, $id, $created];
    }

    private function assertion(string $challenge, string $id, \OpenSSLAsymmetricKey $key, string $user, string $origin = self::ORIGIN, int $flags = 0x05): string
    {
        $client = $this->clientData('webauthn.get', $challenge, $origin);
        $auth = hash('sha256', self::RP, true).chr($flags).pack('N', 1);
        $this->assertTrue(openssl_sign($auth.hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256));

        return $this->credential($id, [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($client),
            'authenticatorData' => Base64UrlSafe::encodeUnpadded($auth),
            'signature' => Base64UrlSafe::encodeUnpadded($signature),
            'userHandle' => Base64UrlSafe::encodeUnpadded($user),
        ]);
    }

    private function credential(string $id, array $response): string
    {
        return json_encode(['id' => Base64UrlSafe::encodeUnpadded($id), 'rawId' => Base64UrlSafe::encodeUnpadded($id),
            'type' => 'public-key', 'response' => $response, 'clientExtensionResults' => (object) []], JSON_THROW_ON_ERROR);
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(compact('type', 'challenge', 'origin') + ['crossOrigin' => false], JSON_THROW_ON_ERROR);
    }

    private function logoutFromApp(): void
    {
        $this->postJson(self::ORIGIN.'/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
    }
}
