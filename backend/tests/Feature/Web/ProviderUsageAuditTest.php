<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderUsageAuditTest extends TestCase
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

    public function test_audit_requires_admin_session_and_hides_data_from_readers(): void
    {
        $this->get('/admin/ai/usage')->assertRedirect('/admin/login');
        $this->actingAs($this->userWithRole('reader'), 'web')
            ->get('/admin/ai/usage')->assertForbidden();
    }

    public function test_audit_separates_utc_billing_currencies_and_never_shows_secrets(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');

        $this->get('/admin/ai/usage')->assertOk()
            ->assertSee('No providers configured.');

        $provider = AiProvider::create([
            'display_name' => 'Audit Fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://example.test/v1',
            'secret_ciphertext' => 'should-not-be-visible',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 500,
            'currency' => 'USD',
        ]);
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $jobId,
            'provider_id' => $provider->id,
            'job_type' => 'draft',
            'idempotency_key' => 'usage-audit:test:1',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        foreach ([
            ['key' => 'audit-usd', 'cost' => 123, 'currency' => 'USD', 'at' => now('UTC')],
            ['key' => 'audit-kwd', 'cost' => 9, 'currency' => 'KWD', 'at' => now('UTC')],
            ['key' => 'audit-old', 'cost' => 900, 'currency' => 'USD', 'at' => now('UTC')->subDays(2)],
        ] as $receipt) {
            DB::table('ai_usage')->insert([
                'id' => (string) Str::uuid(),
                'usage_key' => $receipt['key'],
                'job_id' => $jobId,
                'provider_id' => $provider->id,
                'cost_minor' => $receipt['cost'],
                'cost_currency' => $receipt['currency'],
                'created_at' => $receipt['at'],
            ]);
        }

        $this->get('/admin/ai/usage')->assertOk()
            ->assertSee('Audit Fixture')
            ->assertSee('Configured daily budget')
            ->assertSee('500 USD')
            ->assertSee('KWD')->assertSee('USD')
            ->assertSee('>123</td>', false)
            ->assertSee('>9</td>', false)
            ->assertDontSee('>900</td>', false)
            ->assertDontSee('should-not-be-visible')
            ->assertDontSee('example.test/v1');

        $this->get('/admin/ai/usage?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('استخدام مزودي AI')
            ->assertSee('Audit Fixture');
    }

    public function test_zero_usage_provider_has_zero_receipts_without_imaginary_costs(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        AiProvider::create([
            'display_name' => 'Idle Fixture',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'enabled' => false,
            'health_status' => 'unverified',
            'daily_budget_minor' => 0,
            'currency' => 'KWD',
        ]);

        $this->get('/admin/ai/usage')->assertOk()
            ->assertSee('Idle Fixture')
            ->assertSee('0 KWD')
            ->assertSee('>0</td>', false);
    }
}
