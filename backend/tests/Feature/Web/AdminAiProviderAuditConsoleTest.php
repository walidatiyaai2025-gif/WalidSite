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

final class AdminAiProviderAuditConsoleTest extends TestCase
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

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Audited provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.test/v1',
            'secret_ciphertext' => 'confidential-provider-credential',
            'enabled' => true,
            'health_status' => 'unverified',
            'routing_priority' => 12,
            'daily_budget_minor' => 250,
            'currency' => 'USD',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
        ]);
    }

    private function event(
        AiProvider $provider,
        User $actor,
        array $fields,
        string $action = 'updated',
        ?string $createdAt = null
    ): string {
        $id = (string) Str::uuid();
        DB::table('ai_provider_audit_events')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'changed_fields_json' => json_encode($fields, JSON_THROW_ON_ERROR),
            'created_at' => $createdAt ?? now()->toDateTimeString(),
        ]);
        return $id;
    }

    public function test_history_requires_admin_and_never_exposes_archived_providers(): void
    {
        $provider = $this->provider();
        $url = '/admin/ai/providers/'.$provider->id.'/audit';
        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->get($url)->assertForbidden();

        $this->actingAs($this->account(), 'web');
        $this->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('AI provider change history')
            ->assertSee('No recorded changes for this provider.')
            ->assertSee('href="#content"', false)
            ->assertSee('id="content" tabindex="-1"', false)
            ->assertSee('noindex,nofollow', false);

        $this->get($url.'?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('سجل تغييرات مزود AI')
            ->assertSee('لا توجد أحداث تغيير مسجلة لهذا المزود.');

        $provider->delete();
        $this->get($url)->assertNotFound();
        $this->get('/admin/ai/providers/00000000-0000-4000-8000-000000000000/audit')
            ->assertNotFound();
    }

    public function test_history_redacts_credentials_unknown_fields_actors_and_raw_event_values(): void
    {
        Http::fake();
        $admin = $this->account();
        $provider = $this->provider();
        $this->event($provider, $admin, [
            'routing_priority', 'enabled', 'secret_rotated',
            'secret_ciphertext', 'api_key',
            '<script>alert("leak")</script>',
            ['secret_value' => 'leaked-secret-data'],
        ]);
        $this->event($provider, $admin, [
            'secret_removed', 'base_url', 'display_name',
        ], 'vendor-internal-leak');
        $this->actingAs($admin, 'web');

        $url = '/admin/ai/providers/'.$provider->id.'/audit';
        $this->get('/admin/ai/providers/'.$provider->id)->assertOk()
            ->assertSee('View provider change history');

        $response = $this->get($url)->assertOk()
            ->assertSee('Recorded change events (2)')
            ->assertSee('Routing priority')
            ->assertSee('Enabled state')
            ->assertSee('Credential replaced')
            ->assertSee('Other recorded action')
            ->assertSee('Credential revoked')
            ->assertSee('Endpoint changed')
            ->assertDontSee('confidential-provider-credential')
            ->assertDontSee('leaked-secret-data')
            ->assertDontSee('secret_ciphertext')
            ->assertDontSee('api_key')
            ->assertDontSee('vendor-internal-leak')
            ->assertDontSee('script')
            ->assertDontSee($admin->id)
            ->assertDontSee('https://api.example.test/v1');
        $this->assertSame(2, substr_count($response->getContent(), '<time datetime='));

        $this->get($url.'?lang=ar')->assertOk()
            ->assertSee('تغيير المفتاح السري')
            ->assertSee('أولوية التوجيه')
            ->assertSee('إجراء مسجل آخر')
            ->assertDontSee('confidential-provider-credential');
        Http::assertNothingSent();
    }

    public function test_history_paginates_all_real_events_and_preserves_locale(): void
    {
        $admin = $this->account();
        $provider = $this->provider();
        $newest = now()->startOfMinute();
        for ($i = 0; $i < 23; $i++) {
            $this->event($provider, $admin, ['enabled'], 'updated',
                $newest->copy()->subMinutes($i)->toDateTimeString());
        }
        $this->actingAs($admin, 'web');
        $url = '/admin/ai/providers/'.$provider->id.'/audit';

        $first = $this->get($url.'?lang=ar')->assertOk()
            ->assertSee('أحداث التغيير المسجلة (23)')
            ->assertSee('صفحات سجل التغييرات')
            ->assertSee('lang=ar&amp;page=2', false)
            ->assertSee($newest->toDateTimeString())
            ->assertDontSee($newest->copy()->subMinutes(22)->toDateTimeString());
        $this->assertSame(20, substr_count($first->getContent(), '<time datetime='));

        $second = $this->get($url.'?lang=ar&page=2')->assertOk()
            ->assertSee($newest->copy()->subMinutes(22)->toDateTimeString())
            ->assertDontSee($newest->toDateTimeString());
        $this->assertSame(3, substr_count($second->getContent(), '<time datetime='));

        foreach (['lang=xx', 'lang[]=ar', 'page[]=2', 'page=0',
                  'page=-1', 'page=10001'] as $query) {
            $this->get($url.'?'.$query)
                ->assertRedirect()->assertSessionHasErrors();
        }
    }
}
