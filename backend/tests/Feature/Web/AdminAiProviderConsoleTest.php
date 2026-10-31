<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAiProviderConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::random(14).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function providerForm(): array
    {
        return [
            'display_name' => 'Production-like Fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.openai.com/v1',
            'secret' => 'super-private-test-credential',
            'enabled' => '0',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 500,
            'currency' => 'USD',
        ];
    }

    public function test_unauthenticated_and_unprivileged_sessions_cannot_access_admin_console(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/ai/providers')->assertRedirect('/admin/login');
        $this->post('/admin/ai/providers', $this->providerForm())->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/ai/providers')->assertForbidden();
        $this->post('/admin/ai/providers', $this->providerForm())->assertForbidden();
        $this->assertDatabaseCount('ai_providers', 0);
    }

    public function test_owner_can_open_bilingual_console_with_real_csrf_forms(): void
    {
        $this->actingAs($this->account(), 'web');
        $this->get('/admin')->assertRedirect('/admin/ai/providers');
        $this->get('/admin/ai/providers')->assertOk()
            ->assertSee('AI providers')
            ->assertSee('name="_token"', false)
            ->assertSee('No providers configured yet.');
        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مزودو الذكاء الاصطناعي');
    }

    public function test_web_form_reuses_encryption_and_never_displays_saved_secret(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $this->post('/admin/ai/providers', $this->providerForm())
            ->assertRedirect('/admin/ai/providers');

        $provider = AiProvider::firstOrFail();
        $this->assertSame('super-private-test-credential', $provider->secret_ciphertext);
        $this->assertNotSame('super-private-test-credential', DB::table('ai_providers')->value('secret_ciphertext'));
        $this->assertFalse($provider->enabled);
        $this->assertSame(500, $provider->daily_budget_minor);

        $this->get('/admin/ai/providers')->assertOk()
            ->assertSee('Production-like Fixture')
            ->assertSee('Stored, hidden')
            ->assertDontSee('super-private-test-credential');

        $this->patch('/admin/ai/providers/'.$provider->id, [
            'enabled' => '1',
            'secret' => '',
            'daily_budget_minor' => 200,
        ])->assertRedirect('/admin/ai/providers');

        $this->assertSame('super-private-test-credential', $provider->fresh()->secret_ciphertext);
        $this->assertTrue($provider->fresh()->enabled);
        $this->assertSame(200, $provider->fresh()->daily_budget_minor);
        $this->assertSame(2, DB::table('ai_provider_audit_events')->count());
        Http::assertNothingSent();

        $this->patch('/admin/ai/providers/'.$provider->id, [
            'clear_secret' => '1',
        ])->assertRedirect('/admin/ai/providers');
        $this->assertNull($provider->fresh()->secret_ciphertext);
    }

    public function test_explicit_health_check_uses_provider_adapter_only_on_click(): void
    {
        $this->actingAs($this->account(), 'web');
        $this->post('/admin/ai/providers', $this->providerForm())
            ->assertRedirect('/admin/ai/providers');
        $provider = AiProvider::firstOrFail();

        Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => []], 200)]);
        Http::assertNothingSent();
        $this->post('/admin/ai/providers/'.$provider->id.'/test')
            ->assertRedirect('/admin/ai/providers');
        Http::assertSentCount(1);
        $this->assertSame('healthy', $provider->fresh()->health_status);

        // A save invalidates any stale prior health status.
        $this->patch('/admin/ai/providers/'.$provider->id, [
            'enabled' => '0',
        ])->assertRedirect('/admin/ai/providers');
        $this->assertSame('unverified', $provider->fresh()->health_status);
    }

    public function test_url_validation_and_api_privileges_remain_fail_closed(): void
    {
        $this->actingAs($this->account(), 'web');
        $bad = $this->providerForm();
        $bad['base_url'] = 'http://10.1.2.3:9000/private';
        $this->post('/admin/ai/providers', $bad)
            ->assertSessionHasErrors('base_url');
        $this->assertDatabaseCount('ai_providers', 0);

        // A browser session must not implicitly grant API bearer privileges.
        $this->getJson('/api/v1/admin/ai/providers')->assertForbidden();
    }
}
