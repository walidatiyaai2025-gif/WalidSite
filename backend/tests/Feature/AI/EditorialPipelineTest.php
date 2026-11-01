<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\AI\EditorialAiGenerator;
use App\AI\EditorialTechnicalReview;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class EditorialPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function article(): Article
    {
        return Article::create([
            'slug' => 'reviewed-ai-research', 'title_en' => 'Technical backups',
            'title_ar' => 'النسخ الاحتياطي', 'canonical_lang' => 'en',
        ]);
    }

    private function provider(string $type = 'openai-compatible'): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Pipeline '.Str::random(9),
            'provider_type' => $type,
            'base_url' => $type === 'openai-compatible'
                ? 'https://models.example.org/v1' : 'http://127.0.0.1:11434',
            'secret_ciphertext' => $type === 'openai-compatible' ? 'test-secret-never-log' : null,
            'enabled' => true, 'health_status' => 'healthy',
            'daily_budget_minor' => 1000, 'concurrent_limit' => 1,
            'timeout_seconds' => 5, 'currency' => 'USD',
        ]);
    }

    private function owner(): User
    {
        $user = User::create([
            'email' => 'reviewer@example.org',
            'password_hash' => 'not-an-authentication-secret',
            'status' => 'active',
        ]);
        $role = Role::firstOrCreate(['name' => 'owner']);
        $user->roles()->attach($role->id);
        return $user;
    }

    private function claimedJob(AiProvider $provider, string $kind, int $reserve = 0): string
    {
        $queue = app(DurableAiQueue::class);
        $id = $queue->enqueue($provider, $kind, 'research:'.Str::uuid(),
            ['generate'], $reserve);
        $this->assertSame($id, $queue->claimNext('review-worker')?->id);
        return $id;
    }

    public function test_managed_provider_generates_unverified_draft_under_valid_lease_and_budget(): void
    {
        Http::fake(['https://models.example.org/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '# A draft needing source review']]],
        ], 200)]);
        $provider = $this->provider();
        $article = $this->article();
        $jobId = $this->claimedJob($provider, 'editorial-draft', 80);
        $draft = app(EditorialAiGenerator::class)->draft($article, $provider,
            'chosen-model', ['https://docs.example.org/backup'], 'Write backup steps',
            $jobId, 'review-worker', 80);

        $this->assertSame('draft', $draft->status);
        $this->assertFalse($draft->hasVerifiedEvidence());
        $this->assertFalse($article->fresh()->isReadyToPublish());
        $this->assertSame(false, $draft->citations_json[0]['verified']);
        $this->assertSame(80, (int) DB::table('ai_usage')->where('job_id', $jobId)->sum('cost_minor'));
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://models.example.org/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-secret-never-log')
        );
    }

    public function test_private_dns_research_candidates_never_reach_a_leased_paid_model(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'Should not be generated']]],
        ], 200)]);
        $provider = $this->provider();
        $article = $this->article();
        $jobId = $this->claimedJob($provider, 'editorial-draft', 50);

        foreach ([
            'https://cluster.default.svc/private-runbook',
            'https://router.lan/internal-diagram',
            'https://gateway.home.arpa/metadata',
            'https://node.localdomain/service',
            'https://intranet.home/owner-notes',
        ] as $source) {
            try {
                app(EditorialAiGenerator::class)->draft($article, $provider,
                    'chosen-model', [$source], 'Research safe database recovery',
                    $jobId, 'review-worker', 50);
                $this->fail('Private hostname reached a leased AI draft call.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Research sources must use a safe public HTTPS URL.',
                    $exception->getMessage()
                );
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage', 0);
        $this->assertSame(0, $article->revisions()->count());
        $this->assertDatabaseHas('ai_jobs', [
            'id' => $jobId, 'reserved_budget_minor' => 50, 'status' => 'running',
        ]);
    }

    public function test_independent_admin_review_approves_only_exact_current_draft_and_valid_sources(): void
    {
        $article = $this->article();
        $draft = app(\App\AI\ResearchDraftComposer::class)->saveDraft(
            $article, '# Backup procedure', ['https://docs.example.org/backup']
        );
        $owner = $this->owner();
        $review = app(EditorialTechnicalReview::class);
        $this->assertTrue($review->decide($draft, $owner,
            ['https://docs.example.org/backup'], true, true, false, false));
        $this->assertTrue($draft->fresh()->hasVerifiedEvidence());
        $this->assertSame('validated', $draft->fresh()->status);
        $this->assertFalse($draft->fresh()->validation_json['auto_publish_approved']);
        $article->publishVerified();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);
    }

    public function test_unprivileged_reviewer_and_false_technical_review_never_publish(): void
    {
        $article = $this->article();
        $draft = app(\App\AI\ResearchDraftComposer::class)->saveDraft(
            $article, '# Unknown claim', ['https://docs.example.org/unknown']
        );
        $reader = User::create([
            'email' => 'reader@example.org', 'password_hash' => 'unused', 'status' => 'active',
        ]);
        try {
            app(EditorialTechnicalReview::class)->decide($draft, $reader,
                ['https://docs.example.org/unknown'], true, true);
            $this->fail('An unprivileged reader could validate an article.');
        } catch (DomainException) {
            $this->assertFalse($draft->fresh()->hasVerifiedEvidence());
        }
        $this->assertFalse(app(EditorialTechnicalReview::class)->decide(
            $draft, $this->owner(), ['https://docs.example.org/unknown'], false, true
        ));
        $this->assertFalse($draft->fresh()->hasVerifiedEvidence());
        $this->assertNull($article->fresh()->publicRevision());
    }

    public function test_unreviewed_source_swap_is_rejected_without_mutation(): void
    {
        $article = $this->article();
        $draft = app(\App\AI\ResearchDraftComposer::class)->saveDraft(
            $article, '# Safety steps', ['https://docs.example.org/runbook']
        );
        $this->expectException(DomainException::class);
        try {
            app(EditorialTechnicalReview::class)->decide(
                $draft, $this->owner(), ['https://docs.example.org/other'], true, true
            );
        } finally {
            $this->assertFalse($draft->fresh()->hasVerifiedEvidence());
        }
    }

    public function test_unleased_paid_provider_and_private_source_rejected_without_any_network_call(): void
    {
        Http::fake();
        $provider = $this->provider();
        $article = $this->article();
        $jobId = app(DurableAiQueue::class)->enqueue(
            $provider, 'draft', 'not-claimed:'.Str::uuid(), ['generate'], 50
        );
        try {
            app(EditorialAiGenerator::class)->draft($article, $provider, 'model',
                ['https://docs.example.org/backup'], 'Research backup', $jobId, 'review-worker', 50);
            $this->fail('Unleased inference permitted.');
        } catch (DomainException) {
            Http::assertNothingSent();
        }

        try {
            app(EditorialAiGenerator::class)->draft($article, $provider, 'model',
                ['http://127.0.0.1/private'], 'Research backup', $jobId, 'review-worker', 50);
            $this->fail('Unsafe source should be rejected before inference.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_generated_art_is_private_quarantined_and_has_model_provenance(): void
    {
        Storage::fake('local');
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGMUSdnCwMDAxMDAwMDAAAANmgEwmATK1AAAAABJRU5ErkJggg==';
        Http::fake(['https://models.example.org/v1/images/generations' =>
            Http::response(['data' => [['b64_json' => $png]]], 200)]);
        $provider = $this->provider();
        $jobId = $this->claimedJob($provider, 'illustration', 100);

        $asset = app(EditorialAiGenerator::class)->illustration($provider,
            'image-model', 'Abstract diagram of server backup topology',
            $jobId, 'review-worker', 100);
        $this->assertSame('ai-generated', $asset->origin);
        $this->assertSame('unverified', $asset->rights_status);
        $this->assertFalse($asset->isPubliclyUsable());
        $this->assertSame('image/png', $asset->mime_type);
        Storage::disk('local')->assertExists($asset->storage_key);
        Storage::disk('local')->assertExists($asset->storage_key.'.provenance.json');
        $meta = json_decode(Storage::disk('local')->get(
            $asset->storage_key.'.provenance.json'), true);
        $this->assertSame($provider->id, $meta['provider_id']);
        $this->assertSame($asset->sha256, $meta['sha256']);
        $this->assertArrayNotHasKey('prompt', $meta);
        $this->assertSame(100, (int) DB::table('ai_usage')->where('job_id', $jobId)
            ->sum('cost_minor'));
        $article = $this->article();
        $article->update(['hero_asset_id' => $asset->id]);
        $this->assertFalse($article->fresh()->isReadyToPublish());

        // A second independent rights reviewer can approve only the exact
        // private generated bytes with persisted provenance.
        $this->assertFalse(app(\App\AI\EditorialArtworkRights::class)->approve(
            $asset, $this->owner(), false, true
        ));
        $this->assertFalse($asset->fresh()->isPubliclyUsable());
        $this->assertTrue(app(\App\AI\EditorialArtworkRights::class)->approve(
            $asset, User::where('email', 'reviewer@example.org')->firstOrFail(), true, true
        ));
        $this->assertTrue($asset->fresh()->isPubliclyUsable());
        Storage::disk('local')->assertExists($asset->storage_key.'.rights.json');
    }

    public function test_image_approval_rejects_tampered_content_and_unprivileged_reviewers(): void
    {
        Storage::fake('local');
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGMUSdnCwMDAxMDAwMDAAAANmgEwmATK1AAAAABJRU5ErkJggg==';
        Http::fake(['https://models.example.org/v1/images/generations' =>
            Http::response(['data' => [['b64_json' => $png]]], 200)]);
        $provider = $this->provider();
        $jobId = $this->claimedJob($provider, 'illustration', 80);
        $asset = app(EditorialAiGenerator::class)->illustration(
            $provider, 'image-model', 'Detailed abstract storage architecture',
            $jobId, 'review-worker', 80
        );
        $reader = User::create([
            'email' => 'untrusted@example.org', 'password_hash' => 'unused', 'status' => 'active',
        ]);
        try {
            app(\App\AI\EditorialArtworkRights::class)->approve($asset, $reader, true, true);
            $this->fail('Reader granted image publishing rights.');
        } catch (DomainException) {
            $this->assertFalse($asset->fresh()->isPubliclyUsable());
        }

        Storage::disk('local')->put($asset->storage_key, 'tampered-content');
        $this->expectException(DomainException::class);
        app(\App\AI\EditorialArtworkRights::class)->approve($asset, $this->owner(), true, true);
    }

    public function test_image_generation_rejects_untrusted_urls_and_owner_portrait_requests(): void
    {
        Http::fake(['*' => Http::response(['data' => [['url' => 'https://private.example/secret']]], 200)]);
        $provider = $this->provider();
        $jobId = $this->claimedJob($provider, 'illustration', 50);
        $generator = app(EditorialAiGenerator::class);
        try {
            $generator->illustration($provider, 'image-model', 'Portrait of Walid Atiya in a studio',
                $jobId, 'review-worker', 50);
            $this->fail('Owner likeness generation was allowed.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
        $this->expectException(RuntimeException::class);
        $generator->illustration($provider, 'image-model', 'Abstract network troubleshooting diagram',
            $jobId, 'review-worker', 50);
    }

    public function test_arabic_and_romanized_owner_image_prompts_are_blocked_before_provider_request(): void
    {
        Http::fake(['*' => Http::response(['error' => 'should not be called'], 503)]);
        $provider = $this->provider();
        $jobId = $this->claimedJob($provider, 'illustration', 50);
        $generator = app(EditorialAiGenerator::class);

        foreach ([
            'ارسم صورة وليد عطية الحقيقية',
            'صورة شخصية لوليد عطية بالذكاء الاصطناعي',
            'أنشئ رسماً لــوَلِيد عطية',
            'اعمل سيلفي واقعي لصاحب الموقع',
            'ارسم صورتي وملامحي بشكل واقعي',
            'Create an illustration of Waleed',
            'Draw Walid as a realistic engineer',
            'Generate a headshot of the owner',
        ] as $prompt) {
            try {
                $generator->illustration($provider, 'image-model', $prompt,
                    $jobId, 'review-worker', 50);
                $this->fail('Owner or personal likeness prompt was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Only bounded original technical artwork is allowed.',
                    $exception->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('ai_usage', 0);

        // Arabic non-person technical artwork remains allowed up to the
        // provider boundary. A failed provider creates no public asset.
        $this->expectException(RuntimeException::class);
        try {
            $generator->illustration($provider, 'image-model',
                'مخطط تقني تجريدي لاتصالات قواعد البيانات', $jobId,
                'review-worker', 50);
        } finally {
            Http::assertSentCount(1);
            $this->assertDatabaseCount('assets', 0);
        }
    }

    public function test_registered_durable_editorial_job_runs_one_safe_step_and_preserves_private_review(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/generate' => Http::response([
            'response' => '# Model-generated unreviewed technical draft',
        ], 200)]);
        $article = $this->article();
        $source = app(\App\AI\ResearchDraftComposer::class)->saveDraft(
            $article, 'Produce a cautious technical backup guide',
            ['https://docs.example.org/backup']
        );
        $provider = $this->provider('ollama-compatible');
        $dispatcher = app(\App\AI\EditorialJobDispatcher::class);
        $jobId = $dispatcher->enqueue($source, $provider, 'local-model');
        // Normal Laravel boot must register the allowlist without manual
        // wiring in an admin request, unit test, or cPanel scheduler.
        $registry = app(\App\AI\AiStepHandlerRegistry::class);
        $this->assertSame(['editorial-draft', 'editorial-art'],
            array_keys($registry->handlers()));
        $this->assertSame($registry, app(\App\AI\AiStepHandlerRegistry::class));
        $this->assertSame(0, Artisan::call('ai:tick'));
        $this->assertStringContainsString('completed', Artisan::output());
        $this->assertSame('completed', DB::table('ai_jobs')->where('id', $jobId)->value('status'));
        $this->assertSame(2, $article->revisions()->count());
        $this->assertFalse($article->fresh()->isReadyToPublish());
        $this->assertSame('draft', $article->revisions()->orderByDesc('revision_no')->first()->status);
        $this->assertSame(1, DB::table('ai_job_steps')->where('job_id', $jobId)
            ->where('status', 'completed')->count());
    }

    public function test_fresh_boot_registry_runs_only_two_explicit_editorial_job_kinds(): void
    {
        $registry = app(\App\AI\AiStepHandlerRegistry::class);
        $this->assertSame(['editorial-draft', 'editorial-art'],
            array_keys($registry->handlers()));
        $this->assertSame($registry, app(\App\AI\AiStepHandlerRegistry::class));
        $this->assertTrue($registry->hasHandlers());
    }

    public function test_real_artisan_tick_does_not_consume_unknown_job_kinds(): void
    {
        Http::fake();
        $provider = $this->provider('ollama-compatible');
        $queue = app(DurableAiQueue::class);
        $id = $queue->enqueue($provider, 'user-supplied-kind',
            'untrusted-kind:'.Str::uuid(), ['generate']);

        $this->assertSame(0, Artisan::call('ai:tick'));
        $this->assertStringContainsString('idle', Artisan::output());
        $this->assertSame('queued',
            DB::table('ai_jobs')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) DB::table('ai_jobs')
            ->where('id', $id)->value('attempt_count'));
        $this->assertDatabaseCount('job_leases', 0);
        Http::assertNothingSent();
    }

    public function test_registered_artwork_job_fails_closed_without_compatible_generator(): void
    {
        $article = $this->article();
        $source = app(\App\AI\ResearchDraftComposer::class)->saveDraft(
            $article, 'A documented technical backup diagram',
            ['https://docs.example.org/backup']
        );
        $this->expectException(DomainException::class);
        app(\App\AI\EditorialJobDispatcher::class)->enqueue(
            $source, $this->provider('ollama-compatible'), 'local-model', true
        );
    }

    public function test_local_ollama_text_draft_stays_unverified_without_paid_receipt(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/generate' => Http::response([
            'response' => '# Locally generated draft',
        ], 200)]);
        $provider = $this->provider('ollama-compatible');
        $jobId = $this->claimedJob($provider, 'draft');
        $draft = app(EditorialAiGenerator::class)->draft($this->article(), $provider,
            'local-model', ['https://docs.example.org/reference'],
            'Technical instructions', $jobId, 'review-worker');
        $this->assertSame('draft', $draft->status);
        $this->assertFalse($draft->hasVerifiedEvidence());
        $this->assertSame(0, (int) DB::table('ai_usage')->where('job_id', $jobId)->sum('cost_minor'));
    }
}
