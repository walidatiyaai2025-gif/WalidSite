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

final class AdminAiProviderEditorTest extends TestCase
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
            'display_name' => 'Editor provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.test/v1',
            'secret_ciphertext' => 'private-editor-secret',
            'enabled' => false,
            'health_status' => 'unverified',
            'routing_priority' => 30,
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 500,
            'currency' => 'USD',
        ]);
    }

    public function test_editor_is_admin_only_and_scoped_to_active_provider(): void
    {
        $provider = $this->provider();
        $url = '/admin/ai/providers/'.$provider->id;
        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->get($url)->assertForbidden();

        $this->actingAs($this->account(), 'web');
        $this->get($url.'?lang=en')->assertOk()
            ->assertHeader('Cache-Control', 'no-store')
            ->assertSee('Edit AI provider')
            ->assertSee('Editor provider')
            ->assertSee('Save provider settings')
            ->assertSee('Test connection now')
            ->assertSee('Archive provider and revoke credential')
            ->assertSee('href="#content"', false)
            ->assertSee('id="content" tabindex="-1"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('return=editor', false)
            ->assertSee('Stored and hidden.')
            ->assertDontSee('private-editor-secret');

        $this->get($url.'?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تحرير مزود AI')
            ->assertSee('اختبار الاتصال الآن')
            ->assertSee('مفتاح مخزن ومخفي.')
            ->assertDontSee('private-editor-secret');

        $this->get($url.'?lang[]=ar')->assertRedirect()->assertSessionHasErrors('lang');
        $this->get('/admin/ai/providers/00000000-0000-4000-8000-000000000000')
            ->assertNotFound();

        $provider->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_editor_updates_provider_via_existing_csrf_writer_and_retains_secret(): void
    {
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider();
        $url = '/admin/ai/providers/'.$provider->id;

        $this->patch($url.'?lang=ar&return=editor', [
            'display_name' => 'Updated editor provider',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret' => '',
            'routing_priority' => 4,
            'daily_budget_minor' => 600,
            'enabled' => '1',
        ])->assertRedirect(route('admin.providers.show', [
            'provider' => $provider->id, 'lang' => 'ar',
        ]))->assertSessionHas('status', 'تم تحديث مزود الذكاء الاصطناعي.');

        $this->assertSame('Updated editor provider', $provider->fresh()->display_name);
        $this->assertTrue($provider->fresh()->enabled);
        $this->assertSame(4, $provider->fresh()->routing_priority);
        $this->assertSame(600, $provider->fresh()->daily_budget_minor);
        $this->assertSame('private-editor-secret', $provider->fresh()->secret_ciphertext);
        $this->assertSame('unverified', $provider->fresh()->health_status);
        $this->assertSame(1, DB::table('ai_provider_audit_events')
            ->where('provider_id', $provider->id)->where('action', 'updated')->count());

        $this->get($url.'?lang=ar')->assertOk()
            ->assertSee('Updated editor provider')
            ->assertDontSee('private-editor-secret');

        $this->patch($url.'?return=editor', [
            'clear_secret' => '1',
        ])->assertRedirect(route('admin.providers.show', ['provider' => $provider->id]));
        $this->assertNull($provider->fresh()->secret_ciphertext);
    }

    public function test_editor_health_check_runs_only_on_explicit_action_and_returns_to_editor(): void
    {
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider();
        $url = '/admin/ai/providers/'.$provider->id;

        Http::fake(['https://api.example.test/v1/models' =>
            Http::response(['data' => []], 200)]);
        $this->get($url)->assertOk();
        Http::assertNothingSent();

        $this->post($url.'/test?lang=ar&return=editor')
            ->assertRedirect(route('admin.providers.show', [
                'provider' => $provider->id, 'lang' => 'ar',
            ]))->assertSessionHas('status', 'تم تسجيل نتيجة فحص الاتصال: healthy.');
        Http::assertSentCount(1);
        $this->assertSame('healthy', $provider->fresh()->health_status);

        $this->get($url.'?lang=ar')->assertOk()
            ->assertSee('سليم')->assertDontSee('private-editor-secret');
    }

    public function test_editor_archive_reuses_name_confirmation_and_revokes_credential(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider();
        $url = '/admin/ai/providers/'.$provider->id;

        $this->delete($url.'?lang=ar', [
            'confirm_name' => 'Not correct',
        ])->assertSessionHasErrors('confirm_name');
        $this->assertNotNull(AiProvider::find($provider->id));

        $this->delete($url.'?lang=ar', [
            'confirm_name' => 'Editor provider',
        ])->assertRedirect(route('admin.providers.index', ['lang' => 'ar']));

        $this->get($url)->assertNotFound();
        $archived = AiProvider::withTrashed()->findOrFail($provider->id);
        $this->assertTrue($archived->trashed());
        $this->assertNull($archived->secret_ciphertext);
        $this->assertFalse($archived->enabled);
        Http::assertNothingSent();
    }
}
