<?php

namespace Tests\Feature\Api;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCanonicalProductApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:string} */
    private function account(string $role = 'owner', string $ability = 'admin'): array
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return [
            $user,
            $user->createToken('admin-contract-test', [$ability], now()->addHour())->plainTextToken,
        ];
    }

    private function provider(string $name = 'Canonical Image Provider'): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.test/v1',
            'secret_ciphertext' => 'PRIVATE-ADMIN-PROVIDER-SECRET',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 1000,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    private function imageModel(AiProvider $provider): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'gpt-image-safe',
            'capabilities_json' => ['image-generation'],
            'enabled' => true,
            'cost_policy_json' => [
                'currency' => 'USD',
                'image_reservation_minor' => 25,
            ],
        ]);
    }

    private function readyArticle(
        string $slug,
        string $risk = 'low',
        bool $professionalClaims = false
    ): Article {
        // Give the historical positive fixtures a genuine distinct author,
        // rather than weakening the new fail-closed legacy reviewer gate.
        [$author] = $this->account('admin');
        $this->actingAs($author, 'web');
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Reviewed '.$slug,
            'title_ar' => 'مراجعة '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Reviewed technical body.',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/'.$slug,
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
                'risk_level' => $risk,
                'contains_professional_claims' => $professionalClaims,
                'auto_publish_approved' => $risk === 'low' && ! $professionalClaims,
            ],
            'status' => 'validated',
        ]);
        Auth::forgetGuards();

        return $article;
    }

    private function createJob(AiProvider $provider, string $status = 'failed'): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'editorial-art',
            'idempotency_key' => 'test-job-'.$id,
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'reserved_budget_minor' => 25,
            'next_attempt_at' => $status === 'retry' ? now()->addHour() : null,
            'result_ref' => 'PRIVATE/result/ref',
            'last_error_code' => 'safe_failure_code',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_all_canonical_admin_contract_methods_are_registered(): void
    {
        $required = [
            'api/v1/admin/articles/{id}/publish' => ['POST'],
            'api/v1/admin/ai/images' => ['GET', 'POST'],
            'api/v1/admin/quality' => ['GET'],
            'api/v1/admin/github' => ['GET'],
            'api/v1/admin/github/sync' => ['POST'],
            'api/v1/admin/projects' => ['GET'],
            'api/v1/admin/audience' => ['GET'],
            'api/v1/admin/analytics' => ['GET'],
            'api/v1/admin/approvals' => ['GET'],
            'api/v1/admin/approvals/{id}/decision' => ['POST'],
            'api/v1/admin/audit' => ['GET'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($required as $uri => $methods) {
            // Laravel registers separate Route instances for GET and POST on
            // the same URI. Verify the union rather than only the first match.
            $matches = $routes->filter(static fn ($candidate) => $candidate->uri() === $uri);
            $this->assertNotEmpty($matches->all(), 'Missing canonical route '.$uri);
            $registered = $matches->flatMap(static fn ($candidate) => $candidate->methods())
                ->unique()->values()->all();
            foreach ($methods as $method) {
                $this->assertContains($method, $registered, $uri.' missing '.$method);
            }
        }
    }

    public function test_all_new_admin_reads_require_real_admin_bearer_scope(): void
    {
        $paths = [
            '/api/v1/admin/ai/images',
            '/api/v1/admin/quality',
            '/api/v1/admin/github',
            '/api/v1/admin/projects',
            '/api/v1/admin/audience',
            '/api/v1/admin/analytics',
            '/api/v1/admin/approvals',
            '/api/v1/admin/audit',
        ];

        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }

        [, $reader] = $this->account('reader');
        foreach ($paths as $path) {
            Auth::forgetGuards();
            $this->withToken($reader)->getJson($path)->assertForbidden();
        }

        [, $mobileOnly] = $this->account('owner', 'mobile');
        foreach ($paths as $path) {
            Auth::forgetGuards();
            $this->withToken($mobileOnly)->getJson($path)->assertForbidden();
        }
    }

    public function test_image_contract_creates_real_bounded_draft_job_from_admin_config(): void
    {
        [$owner, $token] = $this->account();
        $provider = $this->provider();
        $model = $this->imageModel($provider);
        $this->withToken($token);

        $created = $this->postJson('/api/v1/admin/ai/images', [
            'title' => 'Database failover diagram',
            'topic' => 'Illustrate a generic database failover topology without people or text.',
            'language' => 'en',
            'publication_mode' => 'draft',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('meta.draft_only', true)
            ->assertJsonPath('meta.image_requires_separate_rights_review', true);

        $jobId = $created->json('data.id');
        $articleId = $created->json('data.draft_article_id');
        $this->assertNotNull($jobId);
        $this->assertNotNull($articleId);
        $this->assertDatabaseHas('ai_jobs', [
            'id' => $jobId,
            'provider_id' => $provider->id,
            'job_type' => 'editorial-art',
            'reserved_budget_minor' => 25,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('articles', [
            'id' => $articleId,
            'status' => 'draft',
            'canonical_lang' => 'en',
        ]);
        $this->assertDatabaseHas('article_revisions', [
            'article_id' => $articleId,
            'status' => 'draft',
        ]);
        $this->assertStringNotContainsString('PRIVATE-ADMIN-PROVIDER-SECRET', $created->getContent());
        $this->assertStringNotContainsString($provider->base_url, $created->getContent());

        $list = $this->getJson('/api/v1/admin/ai/images')->assertOk()
            ->assertJsonPath('data.eligible_models.0.id', $model->id)
            ->assertJsonPath('data.eligible_models.0.configured_reservation_minor', 25);
        $this->assertStringNotContainsString('PRIVATE-ADMIN-PROVIDER-SECRET', $list->getContent());
        $this->assertStringNotContainsString($provider->base_url, $list->getContent());
        $this->assertStringNotContainsString('PRIVATE/result/ref', $list->getContent());

        $this->postJson('/api/v1/admin/ai/images', [
            'title' => 'Invalid extra field',
            'topic' => 'Safe technical topic.',
            'language' => 'en',
            'publication_mode' => 'draft',
            'model_id' => $model->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertSame($owner->id, Article::findOrFail($articleId)
            ->revisions()->firstOrFail()->validation_json['requested_by']);
    }

    public function test_image_model_registration_can_configure_capability_and_cost_without_code_change(): void
    {
        [, $token] = $this->account();
        $provider = $this->provider('API Configurable Image Provider');
        $this->withToken($token);

        $response = $this->postJson('/api/v1/admin/ai/models', [
            'provider_id' => $provider->id,
            'provider_model_id' => 'image-model-v2',
            'enabled' => true,
            'supports_text' => false,
            'supports_image' => true,
            'image_reservation_minor' => 31,
        ])->assertCreated()
            ->assertJsonPath('data.image_reservation_minor', 31);

        $id = $response->json('data.id');
        $model = AiModel::findOrFail($id);
        $this->assertSame(['image-generation'], $model->capabilities_json);
        $this->assertSame(31, $model->cost_policy_json['image_reservation_minor']);

        $this->postJson('/api/v1/admin/ai/models', [
            'provider_id' => $provider->id,
            'provider_model_id' => 'invalid-image-cost',
            'enabled' => true,
            'supports_text' => false,
            'supports_image' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('image_reservation_minor');
    }

    public function test_generic_publish_reuses_evidence_gates_and_high_risk_uses_owner_approval(): void
    {
        [, $adminToken] = $this->account('admin');
        $low = $this->readyArticle('low-risk-api');
        $high = $this->readyArticle('high-risk-api', 'high', true);

        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/articles/'.$low->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $low->id,
            'action' => 'manual_published',
            'reason' => 'content_reviewed',
        ]);

        Auth::forgetGuards();
        [, $secondAdmin] = $this->account('admin');
        $this->withToken($secondAdmin)
            ->postJson('/api/v1/admin/articles/'.$high->id.'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('article');
        $this->assertSame('draft', $high->fresh()->status);

        Auth::forgetGuards();
        [, $ownerToken] = $this->account('owner');
        $this->withToken($ownerToken)
            ->postJson('/api/v1/admin/approvals/'.$high->id.'/decision', [
                'decision' => 'approve',
                'reason' => 'legal_reviewed',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'published');
        $this->assertSame('published', $high->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $high->id,
            'action' => 'manual_published',
            'reason' => 'legal_reviewed',
        ]);
    }

    public function test_rejection_is_persisted_without_destroying_or_publishing_revision(): void
    {
        [, $token] = $this->account('admin');
        $article = $this->readyArticle('reject-current-revision');
        $this->withToken($token);

        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'reject',
            'reason' => 'technical_reviewed',
        ])->assertOk()->assertJsonPath('data.state', 'rejected');

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'revision_no' => 1,
            'action' => 'manual_rejected',
            'reason' => 'technical_reviewed',
        ]);

        $this->getJson('/api/v1/admin/approvals')->assertOk()
            ->assertJsonFragment([
                'article_id' => $article->id,
                'state' => 'rejected',
            ]);
    }

    public function test_quality_projects_analytics_audience_and_audit_are_real_redacted_projections(): void
    {
        [, $token] = $this->account();
        $provider = $this->provider('Redacted Provider');
        $job = $this->createJob($provider, 'failed');

        $asset = Asset::create([
            'asset_key' => 'private-unreviewed-art',
            'mime_type' => 'image/png',
            'storage_key' => 'PRIVATE/storage/path.png',
            'sha256' => str_repeat('b', 64),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);
        $draft = Article::create([
            'slug' => 'quality-redaction',
            'title_en' => 'Quality redaction',
            'title_ar' => 'مراجعة الجودة',
            'canonical_lang' => 'en',
            'hero_asset_id' => $asset->id,
        ]);
        $draft->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'PRIVATE DRAFT BODY',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/PRIVATE-CITATION',
                'verified' => false,
            ]],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => false,
            ],
            'status' => 'draft',
        ]);

        $project = Project::create([
            'slug' => 'verified-project',
            'title' => 'Verified project',
            'summary_en' => 'Reviewed summary.',
            'summary_ar' => 'ملخص تمت مراجعته.',
            'role_en' => 'Developer',
            'role_ar' => 'مطور',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subMinute(),
        ]);
        $project->evidence()->create([
            'evidence_type' => 'public',
            'url' => 'https://evidence.example.org/project',
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);

        DB::table('ai_provider_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'provider_id' => $provider->id,
            'actor_id' => User::query()->firstOrFail()->id,
            'action' => 'updated',
            'changed_fields_json' => json_encode([
                'enabled', 'secret_rotated', 'secret_ciphertext',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => 'analytics-'.$job,
            'job_id' => $job,
            'provider_id' => $provider->id,
            'model_key' => 'safe-model',
            'cost_minor' => 11,
            'cost_currency' => 'USD',
            'usage_json' => null,
            'created_at' => now(),
        ]);

        $this->withToken($token);

        $quality = $this->getJson('/api/v1/admin/quality')->assertOk()
            ->assertJsonFragment(['article_id' => $draft->id])
            ->assertJsonFragment(['id' => $job, 'status' => 'failed']);
        foreach ([
            'PRIVATE DRAFT BODY',
            'PRIVATE-CITATION',
            'PRIVATE/storage/path.png',
            'PRIVATE-ADMIN-PROVIDER-SECRET',
            $provider->base_url,
            'PRIVATE/result/ref',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $quality->getContent());
        }

        $this->getJson('/api/v1/admin/projects')->assertOk()
            ->assertJsonFragment([
                'id' => $project->id,
                'verified_public_evidence_count' => 1,
            ]);

        $analytics = $this->getJson('/api/v1/admin/analytics')->assertOk()
            ->assertJsonPath('data.content.public_projects', 1)
            ->assertJsonFragment([
                'currency' => 'USD',
                'cost_minor' => 11,
            ]);
        $this->assertStringNotContainsString('PRIVATE', $analytics->getContent());

        $this->getJson('/api/v1/admin/audience')->assertOk()
            ->assertJsonPath('data.notifications.consent_storage_available', true)
            ->assertJsonPath('data.notifications.consented_recipients', 0)
            ->assertJsonPath('data.notifications.delivery_enabled', false)
            ->assertJsonMissing(['email' => true]);

        $audit = $this->getJson('/api/v1/admin/audit')->assertOk()
            ->assertJsonFragment([
                'provider_id' => $provider->id,
                'action' => 'updated',
            ]);
        $this->assertStringContainsString('secret_rotated', $audit->getContent());
        $this->assertStringNotContainsString('secret_ciphertext', $audit->getContent());
        $this->assertStringNotContainsString('PRIVATE-ADMIN-PROVIDER-SECRET', $audit->getContent());
        $this->assertStringNotContainsString($provider->base_url, $audit->getContent());
    }

    public function test_github_settings_and_sync_use_only_configured_public_repository_metadata(): void
    {
        [, $token] = $this->account();
        config()->set('portfolio.github_public_repos', [
            'walidatiyaai2025-gif/WalidSite',
            'private-owner/HiddenRepo',
        ]);

        Http::fake([
            'https://api.github.com/repos/walidatiyaai2025-gif/WalidSite' => Http::response([
                'private' => false,
                'visibility' => 'public',
                'full_name' => 'walidatiyaai2025-gif/WalidSite',
                'html_url' => 'https://github.com/walidatiyaai2025-gif/WalidSite',
                'description' => 'Public repository',
                'language' => 'PHP',
            ], 200),
            'https://api.github.com/repos/private-owner/HiddenRepo' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $this->withToken($token);
        $settings = $this->getJson('/api/v1/admin/github')->assertOk()
            ->assertJsonPath('meta.configured_count', 2)
            ->assertJsonPath('meta.verified_count', 1)
            ->assertJsonPath('data.0.full_name', 'walidatiyaai2025-gif/WalidSite');
        $this->assertStringNotContainsString('private-owner/HiddenRepo', $settings->getContent());

        $sync = $this->postJson('/api/v1/admin/github/sync', [])->assertOk()
            ->assertJsonPath('meta.external_available', true)
            ->assertJsonPath('meta.verified_count', 1);
        $this->assertStringNotContainsString('private-owner/HiddenRepo', $sync->getContent());
        Http::assertSentCount(4);
    }

    public function test_github_sync_fails_closed_when_all_configured_metadata_is_unavailable(): void
    {
        [, $token] = $this->account();
        config()->set('portfolio.github_public_repos', ['private-owner/HiddenRepo']);
        Http::fake([
            'https://api.github.com/repos/private-owner/HiddenRepo' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/github/sync', [])
            ->assertStatus(503)
            ->assertJsonPath('meta.external_available', false)
            ->assertJsonPath('meta.verified_count', 0);

        Http::assertSentCount(1);
    }

    public function test_github_sync_with_empty_or_invalid_allowlist_never_claims_provider_availability(): void
    {
        [, $token] = $this->account();
        Http::fake();
        $this->withToken($token);

        foreach ([[], ['invalid', 'owner/../private', 'https://github.com/owner/repo']] as $configured) {
            config()->set('portfolio.github_public_repos', $configured);
            $this->getJson('/api/v1/admin/github')->assertOk()
                ->assertJsonPath('meta.configured_count', 0)
                ->assertJsonPath('meta.verified_count', 0);
            $response = $this->postJson('/api/v1/admin/github/sync', [])->assertOk()
                ->assertJsonPath('meta.configured_count', 0)
                ->assertJsonPath('meta.verified_count', 0)
                ->assertJsonPath('meta.external_available', false)
                ->assertJsonPath('meta.configuration_status', 'not_configured')
                ->assertHeader('Cache-Control', 'no-store');
            $this->assertArrayNotHasKey('synced_at', $response->json('meta'));
            $this->assertStringNotContainsString('owner/../private', $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_github_api_counts_valid_unique_allowlist_slots_after_filtering(): void
    {
        [, $token] = $this->account();
        config()->set('portfolio.github_public_repos', array_merge(
            ['owner/verified', 'OWNER/VERIFIED'],
            array_fill(0, 21, 'invalid!'),
            ['owner/second', 'owner/secret']
        ));
        Http::fake(function ($request) {
            if ($request->url() === 'https://api.github.com/repos/owner/verified') {
                return Http::response([
                    'full_name' => 'owner/verified',
                    'html_url' => 'https://github.com/owner/verified',
                    'private' => false, 'visibility' => 'public',
                ]);
            }
            return Http::response([], 404);
        });
        $this->withToken($token);

        $list = $this->getJson('/api/v1/admin/github')->assertOk()
            ->assertJsonPath('meta.configured_count', 3)
            ->assertJsonPath('meta.verified_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'owner/verified');
        $this->assertStringNotContainsString('owner/secret', $list->getContent());
        $this->assertStringNotContainsString('invalid!', $list->getContent());

        $refresh = $this->postJson('/api/v1/admin/github/sync', [])->assertOk()
            ->assertJsonPath('meta.configured_count', 3)
            ->assertJsonPath('meta.verified_count', 1)
            ->assertJsonPath('meta.external_available', true);
        $this->assertNotEmpty($refresh->json('meta.synced_at'));
        $this->assertStringNotContainsString('owner/secret', $refresh->getContent());
        Http::assertSentCount(6); // Three unique, validated sources per API call.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'invalid!'));
    }

    public function test_invalid_filters_and_unknown_approval_reason_fail_validation(): void
    {
        [, $token] = $this->account();
        $article = $this->readyArticle('invalid-approval-reason');
        $this->withToken($token);

        $this->getJson('/api/v1/admin/quality?page=0')
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/v1/admin/projects?visibility=secret')
            ->assertUnprocessable()->assertJsonValidationErrors('visibility');
        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'approve',
            'reason' => 'free_form_unreviewed_reason',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }
}
