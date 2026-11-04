<?php

namespace Tests\Feature\Integration;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class FirstRunRateLimitingTest extends TestCase
{
    private function rule(Request $request): Limit
    {
        $callback = RateLimiter::limiter('site-setup-actions');
        $this->assertIsCallable($callback);

        return $callback($request);
    }

    public function test_both_install_entrypoints_share_five_attempt_private_token_budget(): void
    {
        $legacyRequest = Request::create('/setup/unlock', 'POST');
        $newRequest = Request::create('/install/step/1', 'POST');

        $legacy = RateLimiter::limiter('site-setup-unlock');
        $this->assertIsCallable($legacy);
        $legacyLimit = $legacy($legacyRequest);
        $newLimit = $this->rule($newRequest);

        $this->assertSame(5, $legacyLimit->maxAttempts);
        $this->assertSame(5, $newLimit->maxAttempts);
        $this->assertSame($legacyLimit->key, $newLimit->key);
    }

    public function test_verified_owner_can_complete_fifteen_stages_without_anonymous_budget(): void
    {
        $unverified = Request::create('/install/step/2', 'POST');
        $unverifiedLimit = $this->rule($unverified);
        $this->assertSame(12, $unverifiedLimit->maxAttempts);

        $session = app('session')->driver();
        $session->put('site_setup_authorized', true);
        $verified = Request::create('/install/step/14', 'POST');
        $verified->setLaravelSession($session);
        $verifiedLimit = $this->rule($verified);

        $this->assertSame(30, $verifiedLimit->maxAttempts);
        $this->assertNotSame($unverifiedLimit->key, $verifiedLimit->key);
    }
}
