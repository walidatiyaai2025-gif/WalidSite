<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest-only safe configuration surface for MOBILE-01.
 *
 * Never serialize config('app'), config('site'), provider credentials, a
 * guessed publisher key, private profile data or unpublished capabilities.
 * The mobile application remains the owner of local language selection.
 */
final class MobileBootstrapController extends Controller
{
    private const LOCALES = ['en', 'ar'];

    private function locales(Request $request): array
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['prohibited'],
        ]);

        // A misconfigured APP_LOCALE or array input must not propagate into
        // public navigation URLs or turn into an invented supported locale.
        $configured = config('app.locale');
        $default = is_string($configured) && in_array($configured, self::LOCALES, true)
            ? $configured : 'en';

        return [$default, $input['lang'] ?? $default];
    }

    public function bootstrap(Request $request): JsonResponse
    {
        [$default, $locale] = $this->locales($request);

        return response()->json([
            'data' => [
                'app_name' => $locale === 'ar' ? 'رفيق وليد التقني' : 'Waleed Tech Companion',
                'locale' => $locale,
                'default_locale' => $default,
                'supported_locales' => self::LOCALES,
                'guest_entry_allowed' => true,
                // The application currently ships a light theme. Do not
                // promise features that aren't backed by implemented routes.
                'available_themes' => ['light'],
                'routes' => [
                    'home' => route('api.mobile.home', [], false),
                    'articles' => route('api.mobile.articles', [], false),
                    'runbooks' => route('api.mobile.runbooks', [], false),
                    'learning_paths' => route('api.mobile.learn', [], false),
                    // This is an authenticated endpoint, NOT a guest feed.
                    'saved_resources' => route('api.mobile.saved', [], false),
                ],
                'availability' => [
                    'guest_reading' => true,
                    'guest_runbooks' => true,
                    'guest_learning_paths' => true,
                    'authenticated_ai_diagnosis' => false,
                    // The token-scoped saved CRUD is implemented; advertise
                    // its availability without exposing saved user data.
                    'server_saved_resources' => true,
                    'saved_resources_requires_auth' => true,
                ],
                // There is no public privacy-policy endpoint yet; never
                // generate an official-looking URL for a non-existent page.
                'privacy_policy_url' => null,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function preferences(Request $request): JsonResponse
    {
        [$default, $locale] = $this->locales($request);

        return response()->json([
            'data' => [
                'locale' => $locale,
                'default_locale' => $default,
                'supported_locales' => self::LOCALES,
                'available_themes' => ['light'],
                'theme' => 'light',
                // Flutter currently switches language in memory on device.
                // This endpoint must never imply account/server persistence.
                'locale_saved_on_server' => false,
                'requires_authentication' => false,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
