<?php

namespace App\Services;

use App\Exceptions\AuthFlowException;
use App\Models\ApplicationSetup;
use App\Models\OidcIdentity;
use App\Models\User;
use Database\Seeders\AccessProfileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdministratorSetup
{
    public function isRequired(): bool
    {
        return ApplicationSetup::findOrFail(1)->completed_at === null && ! User::exists();
    }

    public function issueToken(): string
    {
        return DB::transaction(function () {
            $setup = ApplicationSetup::whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($setup);
            $token = Str::random(64);
            $setup->forceFill([
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addHour(),
            ])->save();

            Log::notice('administrator.setup_token.issued');

            return $token;
        });
    }

    public function complete(array $data): User
    {
        if (! config('auth.local_enabled')) {
            throw new AuthFlowException('auth.errors.localDisabled', 403);
        }
        $user = DB::transaction(function () use ($data) {
            $setup = ApplicationSetup::whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($setup);

            $this->ensureToken($setup, hash('sha256', $data['token']));

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->permission_role_ids = [AccessProfileSeeder::defaultProfile('administrator')->id];
            $user->email_activation_required = true;
            $user->save();
            app(AccountActivation::class)->begin($user, $data['locale'] ?? 'en');

            $this->close($setup);

            return $user;
        });

        Log::notice('administrator.setup.completed', ['user_uuid' => $user->uuid]);

        return $user;
    }

    public function authorizeOidc(string $token): string
    {
        return DB::transaction(function () use ($token) {
            $setup = ApplicationSetup::whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($setup);
            $hash = hash('sha256', $token);
            $this->ensureToken($setup, $hash);

            return $hash;
        });
    }

    public function completeOidc(string $tokenHash, string $issuer, string $subject, string $email, ?string $name): User
    {
        $user = DB::transaction(function () use ($tokenHash, $issuer, $subject, $email, $name) {
            // Same lock as local setup: only one bootstrap may commit.
            $setup = ApplicationSetup::whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($setup);
            $this->ensureToken($setup, $tokenHash);
            $user = new User(['name' => mb_substr(trim($name ?? '') ?: $email, 0, 120), 'email' => Str::lower($email)]);
            $user->permission_role_ids = [AccessProfileSeeder::defaultProfile('administrator')->id];
            $user->email_verified_at = now();
            $user->save();
            OidcIdentity::create(['user_id' => $user->id, 'issuer' => $issuer, 'subject' => $subject]);
            $this->close($setup);

            return $user;
        });
        Log::notice('administrator.setup.oidc_completed', ['user_uuid' => $user->uuid]);

        return $user;
    }

    private function ensureToken(ApplicationSetup $setup, string $tokenHash): void
    {
        if ($setup->token_hash === null || $setup->token_expires_at === null
            || $setup->token_expires_at->lessThanOrEqualTo(now())
            || ! hash_equals($setup->token_hash, $tokenHash)) {
            Log::notice('administrator.setup.rejected');
            throw new AuthFlowException('auth.errors.invalidSetupToken', 403);
        }
    }

    private function close(ApplicationSetup $setup): void
    {
        $setup->forceFill(['token_hash' => null, 'token_expires_at' => null, 'completed_at' => now()])->save();
    }

    private function ensureOpen(ApplicationSetup $setup): void
    {
        if ($setup->completed_at !== null || User::exists()) {
            throw new AuthFlowException('auth.errors.setupClosed', 409);
        }
    }
}
