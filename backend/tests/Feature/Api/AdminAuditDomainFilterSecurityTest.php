<?php

namespace Tests\Feature\Api;

use App\Models\AiProvider;
use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAuditDomainFilterSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function bearer(User $actor): void
    {
        Auth::forgetGuards();
        $token = $actor->createToken('audit-read', ['admin'], now()->addHour());
        $this->withToken($token->plainTextToken);
    }

    private function fixture(): array
    {
        $actor = $this->actor();
        $provider = AiProvider::create([
            'display_name' => 'Fixture AI provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://provider.example.test/v1',
            'secret_ciphertext' => 'PRIVATE-SERVICE-KEY-DO-NOT-LEAK',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 200,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
        $article = Article::create([
            'slug' => 'audit-'.Str::lower(Str::random(12)),
            'title_en' => 'Reviewable item',
            'title_ar' => 'محتوى قابل للمراجعة',
            'canonical_lang' => 'en',
        ]);

        return [$actor, $provider, $article];
    }

    private function providerEvent(AiProvider $provider, User $actor, string $action,
        int $minutes = 0): void
    {
        DB::table('ai_provider_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'provider_id' => $provider->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'changed_fields_json' => json_encode([
                'enabled', 'secret_ciphertext', 'api_key',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now('UTC')->subMinutes($minutes),
        ]);
    }

    private function editorialEvent(Article $article, User $actor, string $action,
        int $minutes = 0, string $reason = 'source_reviewed'): void
    {
        $id = (string) Str::uuid();
        DB::table('editorial_events')->insert([
            'id' => $id,
            'event_key' => 'audit-filter-'.$id,
            'article_id' => $article->id,
            'revision_no' => 1,
            'actor_id' => $actor->id,
            'action' => $action,
            'reason' => $reason,
            'created_at' => now('UTC')->subMinutes($minutes),
        ]);
    }

    public function test_filtered_editorial_action_never_includes_provider_events_and_vice_versa(): void
    {
        [$actor, $provider, $article] = $this->fixture();
        $this->providerEvent($provider, $actor, 'updated');
        $this->providerEvent($provider, $actor, 'created');
        $this->editorialEvent($article, $actor, 'manual_rejected');
        $this->editorialEvent($article, $actor, 'requires_review');
        $this->bearer($actor);

        $editorial = $this->getJson('/api/v1/admin/audit?action=manual_rejected')
            ->assertOk()->assertJsonCount(0, 'data.provider_events')
            ->assertJsonCount(1, 'data.editorial_events')
            ->assertJsonPath('data.editorial_events.0.action', 'manual_rejected')
            ->assertJsonPath('meta.provider_total', 0);
        $this->assertStringContainsString('no-store', $editorial->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $editorial->headers->get('Cache-Control'));
        $editorial->assertDontSee('PRIVATE-SERVICE-KEY-DO-NOT-LEAK')
            ->assertDontSee('secret_ciphertext')->assertDontSee($actor->email);

        $providers = $this->getJson('/api/v1/admin/audit?action=updated')
            ->assertOk()->assertJsonCount(1, 'data.provider_events')
            ->assertJsonCount(0, 'data.editorial_events')
            ->assertJsonPath('data.provider_events.0.action', 'updated');
        $this->assertSame(['enabled'], $providers->json('data.provider_events.0.changed_fields'));
    }

    public function test_known_domains_actions_and_invalid_combinations_fail_closed(): void
    {
        [$actor, $provider, $article] = $this->fixture();
        $this->providerEvent($provider, $actor, 'tested');
        $this->editorialEvent($article, $actor, 'media_rights_approved',
            reason: 'asset:'.(string) Str::uuid());
        $this->editorialEvent($article, $actor, 'manual_rejected', 1,
            'private password example@test.test token');
        $this->editorialEvent($article, $actor, 'unrecognized_legacy_action');
        $this->bearer($actor);

        $this->getJson('/api/v1/admin/audit?domain=provider')
            ->assertOk()->assertJsonCount(1, 'data.provider_events')
            ->assertJsonCount(0, 'data.editorial_events');
        $this->getJson('/api/v1/admin/audit?domain=editorial')
            ->assertOk()->assertJsonCount(0, 'data.provider_events')
            ->assertJsonCount(2, 'data.editorial_events')
            ->assertDontSee('private password')
            ->assertDontSee('unrecognized_legacy_action');
        $this->getJson('/api/v1/admin/audit?action=media_rights_approved')
            ->assertOk()->assertJsonCount(1, 'data.editorial_events')
            ->assertJsonCount(0, 'data.provider_events');

        foreach ([
            'domain=invalid', 'action=unknown_action', 'action[]=updated',
            'domain[]=provider', 'domain=provider&action=manual_rejected',
            'domain=editorial&action=updated', 'page=-1',
            'editorial_page[]=1', 'lang=xx', 'debug=1',
        ] as $query) {
            $this->getJson('/api/v1/admin/audit?'.$query)->assertUnprocessable();
        }
    }

    public function test_independent_provider_and_editorial_pagination_preserves_utc_order(): void
    {
        [$actor, $provider, $article] = $this->fixture();
        for ($i = 0; $i < 25; $i++) {
            $this->providerEvent($provider, $actor, 'created', $i);
        }
        for ($i = 0; $i < 53; $i++) {
            $this->editorialEvent($article, $actor, 'manual_rejected', $i);
        }
        $this->bearer($actor);
        $first = $this->getJson('/api/v1/admin/audit?action=manual_rejected')
            ->assertOk()->assertJsonCount(0, 'data.provider_events')
            ->assertJsonCount(50, 'data.editorial_events')
            ->assertJsonPath('meta.editorial_total', 53)
            ->assertJsonPath('meta.editorial_last_page', 2);
        $second = $this->getJson('/api/v1/admin/audit?action=manual_rejected&editorial_page=2')
            ->assertOk()->assertJsonCount(3, 'data.editorial_events');
        $this->assertGreaterThan(
            strtotime($second->json('data.editorial_events.0.created_at')),
            strtotime($first->json('data.editorial_events.0.created_at'))
        );

        $providerFirst = $this->getJson('/api/v1/admin/audit?domain=provider&page=1')
            ->assertOk()->assertJsonCount(20, 'data.provider_events')
            ->assertJsonPath('meta.provider_total', 25)
            ->assertJsonPath('meta.last_page', 2);
        $providerSecond = $this->getJson('/api/v1/admin/audit?domain=provider&page=2')
            ->assertOk()->assertJsonCount(5, 'data.provider_events');
        $this->assertGreaterThan(
            strtotime($providerSecond->json('data.provider_events.0.created_at')),
            strtotime($providerFirst->json('data.provider_events.0.created_at'))
        );
    }

    public function test_guest_reader_and_session_only_actors_cannot_read_private_audit(): void
    {
        $this->getJson('/api/v1/admin/audit')->assertUnauthorized();
        $reader = $this->actor('reader');
        $this->bearer($reader);
        $this->getJson('/api/v1/admin/audit')->assertForbidden();

        Auth::forgetGuards();
        $owner = $this->actor('owner');
        $this->actingAs($owner, 'web');
        $this->getJson('/api/v1/admin/audit')->assertForbidden();

        Auth::forgetGuards();
        $this->bearer($owner);
        $this->getJson('/api/v1/admin/audit?lang=ar')->assertOk()
            ->assertJsonPath('meta.domain', 'all');
    }
}
