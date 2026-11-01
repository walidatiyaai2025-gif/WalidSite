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
        $this->get('/admin')->assertOk()->assertSee('Administration overview');
        $this->get('/admin/ai/providers')->assertOk()
            ->assertSee('AI providers')
            ->assertSee('name="_token"', false)
            ->assertSee('No providers configured yet.');
        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مزودو الذكاء الاصطناعي');
    }

    public function test_provider_console_links_to_real_usage_audit_in_both_locales(): void
    {
        $this->get('/admin/ai/usage')->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/ai/usage')->assertForbidden();

        $this->actingAs($this->account(), 'web');

        $this->get('/admin/ai/providers?lang=en')->assertOk()
            ->assertSee('AI usage audit')
            ->assertSee('View persisted usage audit')
            ->assertSee('admin/ai/usage?lang=en', false)
            ->assertSee('do not assume runtime budget enforcement')
            ->assertDontSee('usage reports are pending');

        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('سجل استخدام AI')
            ->assertSee('عرض سجل الاستخدام الموثق')
            ->assertSee('admin/ai/usage?lang=ar', false);
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

    public function test_admin_can_change_routing_priority_but_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->account(), 'web');

        $form = $this->providerForm();
        $form['routing_priority'] = 12;
        $this->post('/admin/ai/providers', $form)
            ->assertRedirect('/admin/ai/providers');

        $provider = AiProvider::firstOrFail();
        $this->assertSame(12, $provider->routing_priority);
        $this->get('/admin/ai/providers')->assertOk()
            ->assertSee('Routing priority (lower first)')
            ->assertSee('name="routing_priority"', false)
            ->assertSee('value="12"', false)
            ->assertDontSee('super-private-test-credential');

        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('أولوية التوجيه');

        $this->patch('/admin/ai/providers/'.$provider->id, [
            'routing_priority' => 5,
        ])->assertRedirect('/admin/ai/providers');
        $this->assertSame(5, $provider->fresh()->routing_priority);

        $this->patch('/admin/ai/providers/'.$provider->id, [
            'routing_priority' => 1000,
        ])->assertSessionHasErrors('routing_priority');
        $this->assertSame(5, $provider->fresh()->routing_priority);
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

    public function test_arabic_provider_workflow_preserves_locale_and_secures_credentials(): void
    {
        $this->actingAs($this->account(), 'web');

        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('action="'.route('admin.providers.create', ['lang' => 'ar']).'"', false);

        $this->post('/admin/ai/providers?lang=ar', $this->providerForm())
            ->assertRedirect(route('admin.providers.index', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم حفظ مزود الذكاء الاصطناعي.');
        $provider = AiProvider::firstOrFail();
        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('action="'.route('admin.providers.update', [
                'provider' => $provider->id, 'lang' => 'ar',
            ]).'"', false)
            ->assertSee('action="'.route('admin.providers.test', [
                'provider' => $provider->id, 'lang' => 'ar',
            ]).'"', false)
            ->assertDontSee('super-private-test-credential');

        $this->patch('/admin/ai/providers/'.$provider->id.'?lang=ar', [
            'daily_budget_minor' => 250, 'secret' => '',
        ])->assertRedirect(route('admin.providers.index', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم تحديث مزود الذكاء الاصطناعي.');
        $this->assertSame(250, $provider->fresh()->daily_budget_minor);
        $this->assertSame('super-private-test-credential', $provider->fresh()->secret_ciphertext);

        Http::fake(['https://api.openai.com/v1/models' =>
            Http::response(['data' => []], 200)]);
        $this->post('/admin/ai/providers/'.$provider->id.'/test?lang=ar')
            ->assertRedirect(route('admin.providers.index', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم تسجيل نتيجة فحص الاتصال: healthy.');
        $this->assertSame('healthy', $provider->fresh()->health_status);
        Http::assertSentCount(1);
    }

    public function test_provider_pagination_keeps_arabic_without_echoing_arbitrary_query_parameters(): void
    {
        $this->actingAs($this->account(), 'web');
        for ($number = 1; $number <= 22; $number++) {
            AiProvider::create([
                'display_name' => 'Provider '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'provider_type' => 'ollama-compatible',
                'base_url' => 'http://127.0.0.1:11434',
                'enabled' => false, 'health_status' => 'unverified',
                'daily_budget_minor' => 0, 'currency' => 'USD',
            ]);
        }
        $this->get('/admin/ai/providers?lang=ar&ignored=do-not-echo')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang=ar&amp;page=2', false)
            ->assertDontSee('do-not-echo');
        $this->get('/admin/ai/providers?lang=ar&page=2')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('Provider 21')
            ->assertDontSee('Provider 01');
    }

    public function test_provider_archive_requires_admin_session_and_exact_name_confirmation(): void
    {
        Http::fake();
        $this->delete('/admin/ai/providers/00000000-0000-4000-8000-000000000000')
            ->assertRedirect('/admin/login');

        $this->actingAs($this->account(), 'web');
        $this->post('/admin/ai/providers', $this->providerForm())
            ->assertRedirect('/admin/ai/providers');
        $provider = AiProvider::firstOrFail();

        $this->get('/admin/ai/providers?lang=en')->assertOk()
            ->assertSee('Archive provider and revoke credential')
            ->assertSee('name="confirm_name"', false)
            ->assertDontSee('super-private-test-credential');
        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('أرشفة المزود نهائيًا');

        $this->actingAs($this->account('reader'), 'web');
        $this->delete('/admin/ai/providers/'.$provider->id, [
            'confirm_name' => $provider->display_name,
        ])->assertForbidden();
        $this->assertNotNull(AiProvider::find($provider->id));

        $this->actingAs($this->account(), 'web');
        $this->delete('/admin/ai/providers/'.$provider->id, [
            'confirm_name' => 'Incorrect name',
        ])->assertSessionHasErrors('confirm_name');
        $this->assertNotNull(AiProvider::find($provider->id));
        $this->assertSame('super-private-test-credential', $provider->fresh()->secret_ciphertext);

        $this->delete('/admin/ai/providers/'.$provider->id.'?lang=ar', [
            'confirm_name' => $provider->display_name,
        ])->assertRedirect(route('admin.providers.index', ['lang' => 'ar']));
        $this->get('/admin/ai/providers?lang=en')->assertDontSee('Production-like Fixture');

        $archived = AiProvider::withTrashed()->findOrFail($provider->id);
        $this->assertTrue($archived->trashed());
        $this->assertFalse($archived->enabled);
        $this->assertNull($archived->secret_ciphertext);
        $this->assertSame(1, DB::table('ai_provider_audit_events')
            ->where('provider_id', $provider->id)->where('action', 'deleted')->count());
        Http::assertNothingSent();
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
