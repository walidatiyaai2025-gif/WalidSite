<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class IdentityController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        // Profile email updates already enforce case-insensitive uniqueness.
        // Use the same rule when authenticating on SQLite and MariaDB. A
        // legacy database with two case-equivalent accounts is ambiguous:
        // never choose an arbitrary account to receive a bearer token.
        $matches = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])
            ->limit(2)->get();
        $user = $matches->count() === 1 ? $matches->first() : null;

        if (! $user || $user->status !== 'active' ||
            ! $user->password_hash || ! Hash::check($data['password'], $user->password_hash)) {
            // Deliberately identical message for unknown, inactive and bad-password accounts.
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        // The initial password check can race with suspension, deletion,
        // password rotation or an email change. Account deletion revokes
        // tokens under the same user-row lock, so issuance must use it too.
        $verifiedHash = $user->password_hash;
        $token = DB::transaction(function () use ($user, $data, $verifiedHash) {
            $locked = User::query()->whereKey($user->getKey())
                ->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'active'
                || ! is_string($locked->password_hash)
                || ! hash_equals($verifiedHash, $locked->password_hash)
                || Str::lower($locked->email) !== Str::lower($data['email'])) {
                return null;
            }

            // Mobile bearer tokens have no administrative capability.
            return $locked->createToken($data['device_name'], ['mobile'], now()->addDays(7));
        }, 3);

        if ($token === null) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->status !== 'active' || ! $user->tokenCan('mobile')) {
            abort(403);
        }

        return response()->json(['data' => [
            'id' => $user->getKey(),
            'email' => $user->email,
        ]]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || $user->status !== 'active' || ! $user->tokenCan('mobile') ||
            ! $token instanceof PersonalAccessToken || ! $token->can('mobile')) {
            abort(403);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'current_password' => ['required', 'string', 'max:4096'],
        ]);

        $verifiedHash = $user->password_hash;
        if (! is_string($verifiedHash) || $verifiedHash === ''
            || ! Hash::check($data['current_password'], $verifiedHash)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['current_password' => ['The current password is invalid.']],
            ], 422);
        }

        $email = trim($data['email']);
        $updated = DB::transaction(function () use ($user, $token, $email, $verifiedHash): ?string {
            // Serialize with account deletion and other profile edits. Never
            // write through a User model loaded before an erasure happened.
            $locked = User::query()->whereKey($user->getKey())
                ->lockForUpdate()->first();
            abort_unless(
                $locked !== null
                    && $locked->status === 'active'
                    && is_string($locked->password_hash)
                    && hash_equals($verifiedHash, $locked->password_hash)
                    && $locked->tokens()->whereKey($token->getKey())->exists(),
                403
            );

            $duplicate = User::query()
                ->whereKeyNot($locked->getKey())
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->exists();

            if ($duplicate) {
                return null;
            }

            $locked->forceFill(['email' => $email])->save();
            // The reauthenticated device stays active, all other devices
            // are revoked in the same transaction.
            $locked->tokens()->whereKeyNot($token->getKey())->delete();

            return $locked->email;
        }, 3);

        if ($updated === null) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['email' => ['This email address is already in use.']],
            ], 422);
        }

        return response()->json(['data' => [
            'id' => $user->getKey(),
            'email' => $updated,
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->can('mobile')) {
            abort(403);
        }

        $token->delete();
        return response()->json(['data' => ['revoked' => true]]);
    }

    public function adminCheck(Request $request): JsonResponse
    {
        $user = $request->user();

        // Even an owner role cannot elevate a mobile-only bearer token.
        if (! $user || ! $user->canAccessAdmin() || ! $user->tokenCan('admin')) {
            abort(403);
        }

        return response()->json(['data' => ['authorized' => true]]);
    }
}
