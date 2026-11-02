<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, string $status = 'active'): User
    {
        return User::create([
            'email' => $email,
            'password_hash' => Hash::make('VeryLongTestPassword.123!'),
            'status' => $status,
        ]);
    }

    public function test_profile_update_requires_active_mobile_token_and_current_password(): void
    {
        $user = $this->user('reader@example.test');

        $this->patchJson('/api/v1/mobile/me', [
            'email' => 'new@example.test',
            'current_password' => 'VeryLongTestPassword.123!',
        ])->assertUnauthorized();

        $adminOnly = $user->createToken('admin-only', ['admin']);
        $this->withToken($adminOnly->plainTextToken)->patchJson('/api/v1/mobile/me', [
            'email' => 'new@example.test',
            'current_password' => 'VeryLongTestPassword.123!',
        ])->assertForbidden();

        Auth::forgetGuards();
        $mobile = $user->createToken('phone', ['mobile']);
        $this->withToken($mobile->plainTextToken)->patchJson('/api/v1/mobile/me', [
            'email' => 'new@example.test',
            'current_password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'The current password is invalid.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'reader@example.test',
        ]);

        $user->update(['status' => 'inactive']);
        Auth::forgetGuards();
        $this->withToken($mobile->plainTextToken)->patchJson('/api/v1/mobile/me', [
            'email' => 'new@example.test',
            'current_password' => 'VeryLongTestPassword.123!',
        ])->assertForbidden();
    }

    public function test_reauthenticated_email_change_is_unique_and_revokes_other_bearers_only(): void
    {
        $user = $this->user('reader@example.test');
        $this->user('already@example.test');

        $current = $user->createToken('current-phone', ['mobile']);
        $otherMobile = $user->createToken('other-phone', ['mobile']);
        $otherAdmin = $user->createToken('other-admin', ['admin']);

        $this->withToken($current->plainTextToken)->patchJson('/api/v1/mobile/me', [
            'email' => 'already@example.test',
            'current_password' => 'VeryLongTestPassword.123!',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This email address is already in use.');

        $this->assertSame(3, $user->tokens()->count());

        Auth::forgetGuards();
        $this->withToken($current->plainTextToken)->patchJson('/api/v1/mobile/me', [
            'email' => 'updated@example.test',
            'current_password' => 'VeryLongTestPassword.123!',
        ])->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'updated@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'updated@example.test',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherMobile->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherAdmin->accessToken->id]);

        Auth::forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/mobile/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'updated@example.test');

        Auth::forgetGuards();
        $this->withToken($otherMobile->plainTextToken)->getJson('/api/v1/mobile/me')
            ->assertUnauthorized();
    }
}
