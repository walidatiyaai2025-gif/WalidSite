<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerifiedAdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    public function test_guest_cannot_see_admin_metrics_and_reader_is_forbidden(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($this->userWithRole('reader'), 'web')
            ->get('/admin')->assertForbidden();
    }

    public function test_overview_counts_are_real_and_secrets_do_not_appear(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');

        $this->get('/admin')->assertOk()
            ->assertSee('Administration overview')
            ->assertSee('Configured providers')
            ->assertSee('AI providers');

        AiProvider::create([
            'display_name' => 'Healthy Fixture',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'private-fixture-token',
            'enabled' => true,
            'health_status' => 'healthy',
        ]);
        AiProvider::create([
            'display_name' => 'Unverified Fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://example.test/v1',
            'enabled' => false,
            'health_status' => 'unverified',
        ]);

        $this->get('/admin')->assertOk()
            ->assertSee('Configured providers')
            ->assertSee('>2</strong>', false)
            ->assertSee('Enabled')
            ->assertSee('Healthy')
            ->assertSee('Unverified')
            ->assertSee('>1</strong>', false)
            ->assertDontSee('private-fixture-token')
            ->assertDontSee('Healthy Fixture')
            ->assertDontSee('Unverified Fixture')
            ->assertSee('href="'.route('admin.providers.index', ['lang' => 'en']).'"', false);

        $this->get('/admin?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('نظرة عامة');
    }

    public function test_admin_role_also_has_access_without_api_token(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web')
            ->get('/admin')->assertOk();
    }
    public function test_ai_activity_shows_only_persisted_utc_receipts_separated_by_currency(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');

        $this->get('/admin')->assertOk()
            ->assertSee('Persisted AI jobs')
            ->assertSee('No AI jobs recorded yet.')
            ->assertSee('No usage receipts recorded today.');

        $provider = AiProvider::create([
            'display_name' => 'Private billing fixture',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'never-leak-this-secret',
            'enabled' => true,
            'health_status' => 'healthy',
        ]);
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $jobId,
            'provider_id' => $provider->id,
            'job_type' => 'draft',
            'idempotency_key' => 'overview:test:1',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        foreach ([
            ['key' => 'usd-current', 'cost' => 123, 'currency' => 'USD', 'at' => now('UTC')],
            ['key' => 'kwd-current', 'cost' => 9, 'currency' => 'KWD', 'at' => now('UTC')],
            ['key' => 'usd-yesterday', 'cost' => 999, 'currency' => 'USD', 'at' => now('UTC')->subDays(2)],
        ] as $row) {
            DB::table('ai_usage')->insert([
                'id' => (string) Str::uuid(),
                'usage_key' => $row['key'],
                'job_id' => $jobId,
                'provider_id' => $provider->id,
                'cost_minor' => $row['cost'],
                'cost_currency' => $row['currency'],
                'created_at' => $row['at'],
            ]);
        }

        $this->get('/admin')->assertOk()
            ->assertSee('Persisted AI jobs')
            ->assertSee('completed')
            ->assertSee('Today’s usage (UTC)')
            ->assertSee('USD')->assertSee('KWD')
            ->assertSee('>123</td>', false)->assertSee('>9</td>', false)
            ->assertDontSee('>999</td>', false)
            ->assertDontSee('>1131</td>', false)
            ->assertDontSee('never-leak-this-secret')
            ->assertDontSee('Private billing fixture');

        $this->get('/admin?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('استخدام اليوم (UTC)')
            ->assertSee('USD')->assertSee('KWD');
    }

}
