<?php

namespace Tests\Feature\AI;

use App\AI\AiStepHandlerRegistry;
use App\AI\BoundedAiWorker;
use App\AI\DurableAiQueue;
use App\AI\EditorialJobDispatcher;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class EditorialImageJobIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGMUSdnCwMDAxMDAwMDAAAANmgEwmATK1AAAAABJRU5ErkJggg==';

    /** @return array{AiProvider,AiModel,Article,User,string} */
    private function queuedImage(bool $attributed = true): array
    {
        $provider = AiProvider::create([
            'display_name' => 'Image job integrity fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://models.example.org/v1',
            'secret_ciphertext' => 'non-production-private-fixture-token',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 1000,
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'currency' => 'USD',
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'image-model-verified',
            'capabilities_json' => ['image-generation'],
            'enabled' => true,
            'cost_policy_json' => [
                'currency' => 'USD',
                'image_reservation_minor' => 100,
            ],
        ]);
        $admin = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);
        $article = Article::create([
            'slug' => 'ai-image-job-'.Str::lower((string) Str::ulid()),
            'title_en' => 'Secure infrastructure diagram',
            'title_ar' => 'مخطط تقني للبنية التحتية',
            'canonical_lang' => 'en',
        ]);
        $checks = [
            'citations_passed' => false,
            'technical_passed' => false,
        ];
        if ($attributed) {
            $checks['requested_by'] = $admin->id;
        }
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'status' => 'draft',
            'body_md' => 'Create an original abstract backup topology.',
            'citations_json' => [],
            'validation_json' => $checks,
        ]);
        $dispatcher = app(EditorialJobDispatcher::class);
        $jobId = $dispatcher->enqueue(
            $revision, $provider, $model->provider_model_id, true, 100
        );
        $this->assertSame($jobId, $dispatcher->enqueue(
            $revision, $provider, $model->provider_model_id, true, 100
        ));

        return [$provider, $model, $article, $admin, $jobId];
    }

    private function tick(): array
    {
        return app(BoundedAiWorker::class)->tick(
            'image-integrity-worker', app(AiStepHandlerRegistry::class)->handlers()
        );
    }

    private function fakeValidImage(): void
    {
        Http::fake(['https://models.example.org/v1/images/generations' =>
            Http::response(['data' => [['b64_json' => self::PNG]]], 200)]);
    }

    public function test_approved_image_job_completes_once_in_private_quarantine_without_publication(): void
    {
        Storage::fake('local');
        [$provider, , $article, , $jobId] = $this->queuedImage();
        $this->fakeValidImage();

        $this->assertSame(['state' => 'completed', 'job_id' => $jobId], $this->tick());
        $this->assertSame(['state' => 'idle', 'job_id' => null], $this->tick());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('assets', 1);
        $asset = Asset::query()->firstOrFail();
        $this->assertSame('unverified', $asset->rights_status);
        $this->assertFalse($asset->publishable);
        $this->assertFalse($asset->isPubliclyUsable());
        Storage::disk('local')->assertExists($asset->storage_key);
        Storage::disk('local')->assertExists($asset->storage_key.'.provenance.json');
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->hero_asset_id);
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $jobId, 'cost_minor' => 100,
            'cost_currency' => $provider->currency,
        ]);
        $this->assertSame(1, DB::table('ai_usage')->where('job_id', $jobId)->count());
    }

    public function test_admin_disabling_queued_image_model_prevents_any_provider_request(): void
    {
        [$provider, $model, $article, , $jobId] = $this->queuedImage();
        $model->forceFill(['enabled' => false])->save();
        $this->fakeValidImage();

        $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $jobId]);
        $this->assertDatabaseCount('assets', 0);
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_reclassifying_or_repricing_queued_image_model_fails_before_inference(): void
    {
        foreach (['capability', 'reservation', 'currency'] as $change) {
            [$provider, $model, , , $jobId] = $this->queuedImage();
            if ($change === 'capability') {
                $model->forceFill(['capabilities_json' => ['text']])->save();
            } elseif ($change === 'reservation') {
                $model->forceFill(['cost_policy_json' => [
                    'currency' => 'USD', 'image_reservation_minor' => 200,
                ]])->save();
            } else {
                $model->forceFill(['cost_policy_json' => [
                    'currency' => 'KWD', 'image_reservation_minor' => 100,
                ]])->save();
            }
            Http::fake();
            $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
            Http::assertNothingSent();
            $this->assertDatabaseMissing('ai_usage', ['job_id' => $jobId]);
        }
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_provider_disabled_while_queued_is_not_claimed_or_charged(): void
    {
        [$provider, , , , $jobId] = $this->queuedImage();
        $provider->forceFill(['enabled' => false])->save();
        Http::fake();
        $this->assertSame(['state' => 'idle', 'job_id' => null], $this->tick());
        $this->assertDatabaseHas('ai_jobs', ['id' => $jobId, 'status' => 'queued']);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $jobId]);
    }

    public function test_revoked_image_requester_cannot_spend_queued_budget(): void
    {
        [, , $article, $admin, $jobId] = $this->queuedImage();
        $admin->forceFill(['status' => 'pending_activation'])->save();
        $this->fakeValidImage();
        $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
        Http::assertNothingSent();
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $jobId]);
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_revocation_during_paid_inference_preserves_estimate_but_never_stores_image(): void
    {
        Storage::fake('local');
        [, , $article, $admin, $jobId] = $this->queuedImage();
        Http::fake(function () use ($admin) {
            $admin->forceFill(['status' => 'pending_activation'])->save();
            return Http::response(['data' => [['b64_json' => self::PNG]]], 200);
        });

        $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('asset_variants', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('ai-artwork'));
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $jobId, 'cost_minor' => 100, 'cost_currency' => 'USD',
        ]);
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_successful_paid_inference_then_storage_failure_cannot_reissue_paid_call_on_retry(): void
    {
        Storage::fake('local');
        [, , $article, , $jobId] = $this->queuedImage();
        $this->fakeValidImage();
        Asset::creating(static function (): void {
            throw new RuntimeException('fixture asset metadata failure after paid inference');
        });

        $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('ai-artwork'));
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $jobId, 'cost_minor' => 100, 'cost_currency' => 'USD',
        ]);

        // The worker is intentionally allowed to retry checkpoint execution;
        // the executor must refuse another paid HTTP call after its receipt.
        $this->travel(30)->seconds();
        try {
            $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
            Http::assertSentCount(1);
            $this->assertSame(1, DB::table('ai_usage')->where('job_id', $jobId)->count());
            $this->assertDatabaseCount('assets', 0);
            $this->assertSame('draft', $article->fresh()->status);
        } finally {
            $this->travelBack();
        }
    }

    public function test_malformed_image_payload_fails_before_cost_receipt_or_asset_storage(): void
    {
        Storage::fake('local');
        [, , , , $jobId] = $this->queuedImage();
        Http::fake(['https://models.example.org/v1/images/generations' =>
            Http::response(['data' => [['b64_json' => base64_encode('not an image')]]], 200)]);

        $this->assertSame(['state' => 'retry', 'job_id' => $jobId], $this->tick());
        Http::assertSentCount(1);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $jobId]);
        $this->assertDatabaseCount('assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('ai-artwork'));
    }
}
