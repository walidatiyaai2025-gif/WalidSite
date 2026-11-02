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
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || $user->status !== 'active' ||
            ! $user->password_hash || ! Hash::check($data['password'], $user->password_hash)) {
            // Deliberately identical message for unknown, inactive and bad-password accounts.
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        // Mobile bearer tokens have no administrative capability.
        $token = $user->createToken($data['device_name'], ['mobile'], now()->addDays(7));

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

        if (! $user->password_hash || ! Hash::check($data['current_password'], $user->password_hash)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['current_password' => ['The current password is invalid.']],
            ], 422);
        }

        $email = trim($data['email']);
        $duplicate = User::query()
            ->whereKeyNot($user->getKey())
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['email' => ['This email address is already in use.']],
            ], 422);
        }

        DB::transaction(function () use ($user, $token, $email): void {
            $user->forceFill(['email' => $email])->save();
            // A successful identity edit invalidates every other bearer device.
            // The exact token that performed the reauthenticated change remains
            // usable so the current screen can confirm the updated identity.
            $user->tokens()->whereKeyNot($token->getKey())->delete();
        });

        return response()->json(['data' => [
            'id' => $user->getKey(),
            'email' => $user->fresh()->email,
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
