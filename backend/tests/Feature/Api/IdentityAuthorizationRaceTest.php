<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentityAuthorizationRaceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Strong-Mobile-Account-Password.123!';

    private function account(string $label): User
    {
        return User::create([
            'email' => $label.'-'.Str::lower(Str::random(8)).'@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
        ]);
    }

    private function credentials(User $user): array
    {
        return [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'phone-A',
        ];
    }

    public function test_login_cannot_issue_token_after_account_is_deleted_during_password_verification(): void
    {
        $user = $this->account('login-erased');
        $originalEmail = $user->email;
        $tombstone = 'deleted+'.Str::lower(Str::random(16)).'@invalid.local';

        Hash::shouldReceive('check')->once()->andReturnUsing(
            function ($password, $hash) use ($user, $tombstone): bool {
                // Deterministic transition after the user model was fetched
                // and before the second, locked account-state verification.
                DB::table('users')->where('id', $user->id)->update([
                    'status' => 'deleted',
                    'email' => $tombstone,
                    'password_hash' => null,
                ]);
                return password_verify($password, $hash);
            }
        );

        $this->postJson('/api/v1/auth/login', $this->credentials($user))
            ->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.')
            ->assertDontSee($originalEmail)->assertJsonMissingPath('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'deleted',
            'email' => $tombstone,
            'password_hash' => null,
        ]);
    }

    public function test_login_cannot_use_old_password_or_old_email_after_a_change(): void
    {
        foreach (['password', 'email'] as $change) {
            $user = $this->account('login-rotated-'.$change);
            $input = $this->credentials($user);
            $rotatedHash = password_hash('Rotated.OtherPassword.987!', PASSWORD_BCRYPT);

            Hash::shouldReceive('check')->once()->andReturnUsing(
                function ($password, $hash) use ($user, $change, $rotatedHash): bool {
                    $fields = $change === 'password'
                        ? ['password_hash' => $rotatedHash]
                        : ['email' => 'rotated-'.Str::lower(Str::random(8)).'@example.test'];
                    DB::table('users')->where('id', $user->id)->update($fields);
                    return password_verify($password, $hash);
                }
            );

            $this->postJson('/api/v1/auth/login', $input)
                ->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
            $this->assertDatabaseCount('personal_access_tokens', 0);
            // Drop the facade mock before the next iteration.
            Hash::clearResolvedInstance('hash');
        }
    }

    public function test_profile_email_patch_rejects_post_auth_account_erasure_without_restoring_pii(): void
    {
        $user = $this->account('profile-erased');
        $token = $user->createToken('current-phone', ['mobile'], now()->addHour())
            ->plainTextToken;
        $tombstone = 'deleted+'.Str::lower(Str::random(16)).'@invalid.local';

        Hash::shouldReceive('check')->once()->andReturnUsing(
            function ($password, $hash) use ($user, $tombstone): bool {
                DB::table('users')->where('id', $user->id)->update([
                    'status' => 'deleted',
                    'email' => $tombstone,
                    'password_hash' => null,
                ]);
                return password_verify($password, $hash);
            }
        );

        $this->withToken($token)->patchJson('/api/v1/mobile/me', [
            'email' => 'resurrected@example.test',
            'current_password' => self::PASSWORD,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'deleted',
            'email' => $tombstone,
            'password_hash' => null,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'resurrected@example.test']);
    }

    public function test_profile_email_patch_rejects_password_rotation_during_reauthentication(): void
    {
        $user = $this->account('profile-rotated');
        $token = $user->createToken('current-phone', ['mobile'], now()->addHour())
            ->plainTextToken;
        $originalEmail = $user->email;
        $rotatedHash = password_hash('NewPassword.456!', PASSWORD_BCRYPT);

        Hash::shouldReceive('check')->once()->andReturnUsing(
            function ($password, $hash) use ($user, $rotatedHash): bool {
                DB::table('users')->where('id', $user->id)
                    ->update(['password_hash' => $rotatedHash]);
                return password_verify($password, $hash);
            }
        );

        $this->withToken($token)->patchJson('/api/v1/mobile/me', [
            'email' => 'unauthorized@example.test',
            'current_password' => self::PASSWORD,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $originalEmail,
            'password_hash' => $rotatedHash,
        ]);
    }

    public function test_normal_login_and_verified_profile_edit_revoke_only_other_devices(): void
    {
        $user = $this->account('current');
        $other = $this->account('unrelated');
        $otherToken = $other->createToken('other-account', ['mobile'], now()->addHour());

        $login = $this->postJson('/api/v1/auth/login', $this->credentials($user))
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');
        $token = $login->json('data.token');

        $secondDevice = $user->createToken('phone-B', ['mobile'], now()->addHour());
        $this->assertSame(2, $user->tokens()->count());

        Auth::forgetGuards();
        $this->withToken($token)->patchJson('/api/v1/mobile/me', [
            'email' => 'new-owner-address@example.test',
            'current_password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.email', 'new-owner-address@example.test');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondDevice->accessToken->id]);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->assertSame('active', $user->fresh()->status);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/mobile/me')
            ->assertOk()->assertJsonPath('data.email', 'new-owner-address@example.test');

        $this->withToken($token)->patchJson('/api/v1/mobile/me', [
            'email' => strtoupper($other->email),
            'current_password' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'This email address is already in use.');
        $this->assertSame('new-owner-address@example.test', $user->fresh()->email);
    }
}
