<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiRoutingConsoleTest extends TestCase
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

    private function provider(string $name, int $priority, bool $enabled = false): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'private-routing-fixture-secret',
            'enabled' => $enabled,
            'health_status' => 'healthy',
            'routing_priority' => $priority,
            'daily_budget_minor' => 250,
            'currency' => 'USD',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
        ]);
    }

    public function test_routing_console_requires_admin_and_offers_honest_empty_state(): void
    {
        $this->get('/admin/ai/routing')->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/ai/routing')->assertForbidden();

        $this->actingAs($this->account(), 'web');
        $this->get('/admin/ai/routing?lang=en')->assertOk()
            ->assertSee('AI provider routing')
            ->assertSee('No providers configured.')
            ->assertSee('noindex,nofollow', false)
            ->assertSee('href="#content"', false)
            ->assertSee('id="content" tabindex="-1"', false);

        $this->get('/admin/ai/routing?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('توجيه مزودي الذكاء الاصطناعي')
            ->assertSee('لا يوجد مزودون مسجلون بعد.');
    }

    public function test_routing_list_orders_real_providers_without_disclosing_endpoint_or_secret(): void
    {
        $this->actingAs($this->account(), 'web');
        $this->provider('Slow edge', 80, false);
        $fast = $this->provider('Fast edge', 10, true);

        $this->get('/admin/ai/routing?lang=en')->assertOk()
            ->assertSeeInOrder(['Fast edge', 'Slow edge'])
            ->assertSee('Provider routing order (2)')
            ->assertSee('Last recorded health')
            ->assertSee('configured settings, not verified remaining spend')
            ->assertSee('name="routing_priority"', false)
            ->assertSee('name="daily_budget_minor"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('return=routing', false)
            ->assertDontSee('private-routing-fixture-secret')
            ->assertDontSee('http://127.0.0.1:11434')
            ->assertSee('route-'.$fast->id, false);

        $this->get('/admin/ai/routing?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الأولوية (الأقل أولًا)')
            ->assertSee('توجيه AI')
            ->assertDontSee('private-routing-fixture-secret');
    }

    public function test_routing_form_saves_canonical_provider_settings_and_returns_to_routing_page(): void
    {
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider('Saved settings', 80, false);
        $secret = $provider->secret_ciphertext;

        $this->patch('/admin/ai/providers/'.$provider->id.'?lang=ar&return=routing&page=1', [
            'routing_priority' => 4,
            'concurrent_limit' => 2,
            'timeout_seconds' => 7,
            'daily_budget_minor' => 700,
            'currency' => 'KWD',
            'enabled' => '1',
        ])->assertRedirect(route('admin.routing.index', ['lang' => 'ar', 'page' => 1]))
            ->assertSessionHas('status', 'تم تحديث مزود الذكاء الاصطناعي.');

        $provider->refresh();
        $this->assertSame(4, $provider->routing_priority);
        $this->assertSame(2, $provider->concurrent_limit);
        $this->assertSame(7, $provider->timeout_seconds);
        $this->assertSame(700, $provider->daily_budget_minor);
        $this->assertSame('KWD', $provider->currency);
        $this->assertTrue($provider->enabled);
        $this->assertSame($secret, $provider->secret_ciphertext);
        $this->assertSame('unverified', $provider->health_status);
        $this->assertSame(1, DB::table('ai_provider_audit_events')
            ->where('provider_id', $provider->id)->where('action', 'updated')->count());

        $this->get('/admin/ai/routing?lang=ar')->assertOk()
            ->assertSee('700')
            ->assertSee('KWD')
            ->assertSee('مفعّل')
            ->assertDontSee('private-routing-fixture-secret');
    }

    public function test_routing_edits_reject_invalid_values_and_external_redirects(): void
    {
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider('Restricted settings', 20);

        $this->patch('/admin/ai/providers/'.$provider->id.'?return=routing&page=1', [
            'routing_priority' => 1000,
        ])->assertSessionHasErrors('routing_priority');
        $this->assertSame(20, $provider->fresh()->routing_priority);

        $this->patch('/admin/ai/providers/'.$provider->id.'?return=routing&page[]=2', [
            'routing_priority' => 10,
        ])->assertSessionHasErrors('page');
        $this->assertSame(20, $provider->fresh()->routing_priority);

        // A client-controlled return value is never treated as a URL.
        $this->patch('/admin/ai/providers/'.$provider->id.'?return=https://example.test/steal', [
            'routing_priority' => 10,
        ])->assertRedirect(route('admin.providers.index'));
        $this->assertSame(10, $provider->fresh()->routing_priority);

        $this->actingAs($this->account('reader'), 'web')
            ->patch('/admin/ai/providers/'.$provider->id.'?return=routing', [
                'routing_priority' => 11,
            ])->assertForbidden();
        $this->assertSame(10, $provider->fresh()->routing_priority);
    }

    public function test_routing_pagination_preserves_locale_and_validates_page_inputs(): void
    {
        $this->actingAs($this->account(), 'web');
        for ($number = 1; $number <= 22; $number++) {
            $this->provider('Routing '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), $number);
        }

        $this->get('/admin/ai/routing?lang=ar&ignore=discard')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang=ar&amp;page=2', false)
            ->assertSee('Routing 01')
            ->assertDontSee('Routing 21')
            ->assertDontSee('discard');
        $this->get('/admin/ai/routing?lang=ar&page=2')->assertOk()
            ->assertSee('Routing 21')
            ->assertSee('Routing 22')
            ->assertDontSee('Routing 01');

        foreach (['lang=fr', 'lang[]=ar', 'page[]=2', 'page=0', 'page=10001'] as $query) {
            $this->get('/admin/ai/routing?'.$query)
                ->assertRedirect()->assertSessionHasErrors();
        }
    }
}
