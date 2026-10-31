<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
