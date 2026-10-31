<?php

namespace Tests\Feature\Api;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiProviderSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $role, string $ability = 'admin', string $status = 'active'): string
    {
        $user = User::create([
            'email' => Str::random(15).'@example.test',
            'password_hash' => null,
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user->createToken('test', [$ability], now()->addHour())->plainTextToken;
    }

    private function input(): array
    {
        return [
            'display_name' => 'OpenAI Managed',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.openai.com/v1',
            'enabled' => false,
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 1000,
            'secret' => 'private-test-credential-123',
        ];
    }

    public function test_only_real_admin_token_with_active_role_can_access(): void
    {
        $this->getJson('/api/v1/admin/ai/providers')->assertUnauthorized();
        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'inactive'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->tokenFor($role, $ability, $status))
                ->getJson('/api/v1/admin/ai/providers')->assertForbidden();
        }
        Auth::forgetGuards();
        $this->withToken($this->tokenFor('admin'))->getJson('/api/v1/admin/ai/providers')->assertOk();
    }

    public function test_secrets_are_encrypted_hidden_and_audited_without_values(): void
    {
        Http::fake();
        $this->withToken($this->tokenFor('owner'));
        $created = $this->postJson('/api/v1/admin/ai/providers', $this->input())
            ->assertCreated()->assertJsonPath('data.has_saved_secret', true)
            ->assertJsonPath('data.health_status', 'unverified');
        $id = $created->json('data.id');
        $this->assertNotNull($id);
        $this->assertStringNotContainsString('private-test-credential-123', $created->getContent());
        $stored = DB::table('ai_providers')->where('id', $id)->first();
        $this->assertNotSame('private-test-credential-123', $stored->secret_ciphertext);
        $this->assertSame('private-test-credential-123', AiProvider::findOrFail($id)->secret_ciphertext);
        $this->getJson('/api/v1/admin/ai/providers')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/ai/providers/'.$id)->assertOk()->assertJsonMissing(['secret' => 'private-test-credential-123']);

        $this->patchJson('/api/v1/admin/ai/providers/'.$id, [
            'enabled' => true, 'daily_budget_minor' => 200,
        ])->assertOk()->assertJsonPath('data.daily_budget_minor', 200);
        $this->assertSame('private-test-credential-123', AiProvider::findOrFail($id)->secret_ciphertext);
        $logs = DB::table('ai_provider_audit_events')->pluck('changed_fields_json')->implode(' ');
        $this->assertStringNotContainsString('private-test-credential-123', $logs);
        $this->assertSame(2, DB::table('ai_provider_audit_events')->count());
        Http::assertNothingSent();
    }

    public function test_endpoint_validation_and_explicit_health_test_are_safe(): void
    {
        $this->withToken($this->tokenFor('owner'));
        foreach (['http://10.0.0.1:11434', 'https://user:pass@example.com', 'http://example.com/api', 'http://localhost/?secret=1'] as $url) {
            $this->postJson('/api/v1/admin/ai/providers', array_merge($this->input(), ['base_url' => $url]))
                ->assertUnprocessable()->assertJsonValidationErrors('base_url');
        }
        // A new admin token keeps negative-validation rate-limit counters separate
        // from the explicit health probe without disabling production throttling.
        Auth::forgetGuards();
        $this->withToken($this->tokenFor('owner'));
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => []], 200)]);
        $created = $this->postJson('/api/v1/admin/ai/providers', $this->input())->assertCreated();
        $id = $created->json('data.id');
        Http::assertNothingSent();
        $this->postJson('/api/v1/admin/ai/providers/'.$id.'/test')
            ->assertOk()->assertJsonPath('data.health_status', 'healthy');
        Http::assertSentCount(1);
        $this->patchJson('/api/v1/admin/ai/providers/'.$id, ['enabled' => false])
            ->assertOk()->assertJsonPath('data.health_status', 'unverified');
    }

    public function test_delete_requires_admin_and_erases_secret_while_preserving_audit(): void
    {
        $this->deleteJson('/api/v1/admin/ai/providers/00000000-0000-4000-8000-000000000000')
            ->assertUnauthorized();

        Auth::forgetGuards();
        $this->withToken($this->tokenFor('owner'));
        $created = $this->postJson('/api/v1/admin/ai/providers', $this->input())->assertCreated();
        $id = $created->json('data.id');

        Auth::forgetGuards();
        $this->withToken($this->tokenFor('reader'))
            ->deleteJson('/api/v1/admin/ai/providers/'.$id)
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->tokenFor('owner'));
        $this->deleteJson('/api/v1/admin/ai/providers/'.$id)->assertNoContent();
        $this->getJson('/api/v1/admin/ai/providers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/ai/providers/'.$id)->assertNotFound();

        $deleted = AiProvider::withTrashed()->findOrFail($id);
        $this->assertTrue($deleted->trashed());
        $this->assertFalse($deleted->enabled);
        $this->assertNull($deleted->secret_ciphertext);

        $audit = DB::table('ai_provider_audit_events')
            ->where('provider_id', $id)
            ->where('action', 'deleted')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(['enabled', 'secret_removed'], json_decode($audit->changed_fields_json, true));
        $this->assertStringNotContainsString('private-test-credential-123', $audit->changed_fields_json);
        Http::assertNothingSent();
    }
}
