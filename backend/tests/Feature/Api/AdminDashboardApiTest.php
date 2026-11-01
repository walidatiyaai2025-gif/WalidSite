<?php

namespace Tests\Feature\Api;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(18).'@example.test',
            'password_hash' => null,
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function token(string $role = 'owner', string $ability = 'admin', string $status = 'active'): string
    {
        return $this->user($role, $status)->createToken(
            'dashboard-fixture',
            [$ability],
            now()->addHour()
        )->plainTextToken;
    }

    private function provider(string $name, bool $enabled, string $health, ?string $secret = null): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.org/v1',
            'enabled' => $enabled,
            'health_status' => $health,
            'secret_ciphertext' => $secret,
        ]);
    }

    private function job(AiProvider $provider, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'fixture',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        return $id;
    }

    private function receipt(AiProvider $provider, string $jobId, string $currency, int $minor, string $time): void
    {
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => (string) Str::uuid(),
            'job_id' => $jobId,
            'provider_id' => $provider->id,
            'cost_currency' => $currency,
            'cost_minor' => $minor,
            'created_at' => $time,
        ]);
    }

    public function test_requires_real_admin_bearer_role_and_rejects_session_only_access(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'disabled'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status))
                ->getJson('/api/v1/admin/dashboard')->assertForbidden();
        }

        Auth::forgetGuards();
        $this->withToken($this->token('admin'))
            ->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_admin_web_session_without_personal_admin_token_cannot_access_dashboard_api(): void
    {
        $owner = $this->user();
        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_empty_and_bilingual_snapshots_are_honest_and_validate_locale(): void
    {
        Http::fake();
        $this->withToken($this->token());

        foreach (['en', 'ar'] as $lang) {
            $this->getJson('/api/v1/admin/dashboard?lang='.$lang)->assertOk()
                ->assertJsonPath('data.providers.configured', 0)
                ->assertJsonPath('data.providers.enabled', 0)
                ->assertJsonPath('data.providers.healthy', 0)
                ->assertJsonPath('data.providers.unverified', 0)
                ->assertJsonCount(0, 'data.jobs')
                ->assertJsonCount(0, 'data.usage_today_utc')
                ->assertJsonPath('meta.lang', $lang)
                ->assertJsonPath('meta.jobs_are_persisted_not_live', true)
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        foreach (['lang=de', 'lang[]=ar'] as $invalid) {
            $this->getJson('/api/v1/admin/dashboard?'.$invalid)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_dashboard_reads_true_provider_jobs_and_utc_usage_separately_by_currency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01T12:00:00+00:00'));
        try {
            Http::fake();
            $first = $this->provider('First paid provider', true, 'healthy', 'secret-never-export-123');
            $second = $this->provider('Local provider', false, 'unverified');
            $archived = $this->provider('Archived provider', true, 'healthy');
            $archived->delete();

            $queued = $this->job($first, 'queued');
            $this->job($first, 'queued');
            $this->job($second, 'completed');
            $this->receipt($first, $queued, 'USD', 120, '2026-11-01 08:30:00');
            $this->receipt($first, $queued, 'USD', 80, '2026-11-01 11:15:00');
            $this->receipt($first, $queued, 'KWD', 35, '2026-11-01 03:00:00');
            $this->receipt($first, $queued, 'USD', 99999, '2026-10-31 23:59:59');
            $this->receipt($first, $queued, 'USD', 99999, '2026-11-02 00:00:00');

            $this->withToken($this->token('owner'));
            $response = $this->getJson('/api/v1/admin/dashboard?lang=ar')->assertOk()
                ->assertJsonPath('data.providers.configured', 2)
                ->assertJsonPath('data.providers.enabled', 1)
                ->assertJsonPath('data.providers.healthy', 1)
                ->assertJsonPath('data.providers.unverified', 1)
                ->assertJsonCount(2, 'data.jobs')
                ->assertJsonPath('data.jobs.0.status', 'completed')
                ->assertJsonPath('data.jobs.0.count', 1)
                ->assertJsonPath('data.jobs.1.status', 'queued')
                ->assertJsonPath('data.jobs.1.count', 2)
                ->assertJsonCount(2, 'data.usage_today_utc')
                ->assertJsonPath('data.usage_today_utc.0.currency', 'KWD')
                ->assertJsonPath('data.usage_today_utc.0.receipt_count', 1)
                ->assertJsonPath('data.usage_today_utc.0.cost_minor', 35)
                ->assertJsonPath('data.usage_today_utc.1.currency', 'USD')
                ->assertJsonPath('data.usage_today_utc.1.receipt_count', 2)
                ->assertJsonPath('data.usage_today_utc.1.cost_minor', 200)
                ->assertJsonPath('meta.usage_window_start_utc', '2026-11-01T00:00:00+00:00')
                ->assertJsonPath('meta.usage_window_end_utc', '2026-11-02T00:00:00+00:00');

            $this->assertStringNotContainsString('secret-never-export-123', $response->getContent());
            $this->assertStringNotContainsString('api.example.org', $response->getContent());
            $this->assertStringNotContainsString($queued, $response->getContent());
            $this->assertStringNotContainsString($first->id, $response->getContent());
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_production_plaintext_denied_before_admin_token_is_inspected(): void
    {
        $env = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->withToken($this->token('owner'))
                ->getJson('http://localhost/api/v1/admin/dashboard')->assertForbidden();
            $this->getJson('https://localhost/api/v1/admin/dashboard')
                ->assertOk()->assertJsonPath('data.providers.configured', 0);
        } finally {
            $this->app->instance('env', $env);
        }
    }
}
