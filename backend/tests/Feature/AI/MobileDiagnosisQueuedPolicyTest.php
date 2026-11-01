<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\AI\MobileDiagnosisDispatcher;
use App\Models\AiDiagnosisRequest;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class MobileDiagnosisQueuedPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{AiProvider,AiModel,AiRoute,AiDiagnosisRequest,object} */
    private function queuedPaidDiagnosis(): array
    {
        $provider = AiProvider::create([
            'display_name' => 'Pending paid diagnosis',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://provider.example.test/v1',
            'secret_ciphertext' => 'private-fixture-key',
            'enabled' => true,
            'health_status' => 'healthy',
            'routing_priority' => 10,
            'daily_budget_minor' => 100,
            'currency' => 'USD',
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'reviewed-selector',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
        $route = AiRoute::create([
            'task_type' => 'mobile-ai-diagnose',
            'primary_model_id' => $model->id,
            'fallback_model_ids_json' => [],
            'budget_json' => ['currency' => 'USD', 'reservation_minor' => 25],
            'rate_limits_json' => ['per_minute' => 4, 'per_day' => 40],
        ]);
        $user = User::create([
            'email' => Str::random(12).'@example.test',
            'status' => 'active',
        ]);
        $diagnosis = AiDiagnosisRequest::create([
            'user_id' => $user->id,
            'model_id' => $model->id,
            'request_blob' => Crypt::encryptString(json_encode([
                'question' => 'Private database question',
                'technology' => 'SQL Server',
                'code_snippet' => 'private-code-not-to-leak',
            ], JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addHour(),
        ]);
        $jobId = app(DurableAiQueue::class)->enqueue(
            $provider, 'mobile-diagnosis', 'mobile-diagnosis:'.$diagnosis->id,
            ['classify'], 25, 1
        );
        $diagnosis->forceFill(['job_id' => $jobId])->save();

        return [$provider, $model, $route, $diagnosis, (object) [
            'id' => $jobId,
            'provider_id' => $provider->id,
            'reserved_budget_minor' => 25,
        ]];
    }

    private function reviewedCatalog(): void
    {
        $id = (string) Str::uuid();
        $reviewed = now()->subMinutes(10);
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => 'queued-reviewed-solution',
            'title' => 'Approved troubleshooting guide',
            'body_md' => '# Recovery\nUse the reviewed procedure.',
            'version' => 1,
            'validated_at' => $reviewed,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => '# Recovery\nUse the reviewed procedure.',
            'reviewed_title' => 'Approved troubleshooting guide',
            'checksum' => hash('sha256', '# Recovery\nUse the reviewed procedure.'),
            'created_at' => $reviewed->copy()->subMinute(),
        ]);
    }

    private function rejectWithoutProviderCall(AiDiagnosisRequest $diagnosis, object $job): void
    {
        Http::fake();
        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('Changed pricing policy must block queued inference.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $job->id]);
    }

    public function test_inactive_account_cannot_transmit_already_queued_private_diagnosis(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        User::query()->findOrFail($diagnosis->user_id)->update([
            'status' => 'pending_activation',
        ]);

        $this->rejectWithoutProviderCall($diagnosis, $job);
    }

    public function test_account_disabled_while_loading_reviewed_catalog_never_reaches_provider(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $this->reviewedCatalog();
        Http::fake();
        $changed = false;

        DB::listen(function ($query) use (&$changed, $diagnosis): void {
            if (! $changed && str_contains(strtolower($query->sql), 'runbooks')) {
                $changed = true;
                User::query()->findOrFail($diagnosis->user_id)->update([
                    'status' => 'pending_activation',
                ]);
            }
        });

        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('Account deactivation during catalog load must block private inference.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }

        $this->assertTrue($changed, 'The reviewed catalog must have been read.');
        Http::assertNothingSent();
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $job->id]);
    }

    public function test_paid_model_currency_changed_after_queueing_fails_before_private_input_leaves(): void
    {
        [, $model, , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $model->forceFill(['cost_policy_json' => ['currency' => 'KWD']])->save();

        $this->rejectWithoutProviderCall($diagnosis, $job);
    }

    public function test_changed_route_currency_or_reservation_rejects_queued_paid_job(): void
    {
        [, , $route, $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $route->forceFill([
            'budget_json' => ['currency' => 'EUR', 'reservation_minor' => 25],
        ])->save();
        $this->rejectWithoutProviderCall($diagnosis, $job);

        $diagnosis->forceFill(['request_blob' => Crypt::encryptString(
            json_encode(['question' => 'Private retry', 'technology' => 'SQL Server',
                'code_snippet' => 'private'], JSON_THROW_ON_ERROR)
        )])->save();
        $route->forceFill([
            'budget_json' => ['currency' => 'USD', 'reservation_minor' => 50],
        ])->save();
        $this->rejectWithoutProviderCall($diagnosis, $job);
    }

    public function test_model_disabled_during_reviewed_catalog_lookup_cannot_call_provider(): void
    {
        [, $model, , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $this->reviewedCatalog();
        Http::fake();

        $changed = false;
        DB::listen(function ($query) use (&$changed, $model): void {
            if (! $changed && str_contains(strtolower($query->sql), 'runbooks')) {
                $changed = true;
                $model->forceFill(['enabled' => false])->save();
            }
        });
        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('A model disabled while reading the catalog must not infer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }

        $this->assertTrue($changed, 'Reviewed catalog must actually be consulted.');
        Http::assertNothingSent();
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $job->id]);
    }

    public function test_paid_price_currency_changed_during_catalog_lookup_cannot_call_provider(): void
    {
        [, $model, , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $this->reviewedCatalog();
        Http::fake();

        $changed = false;
        DB::listen(function ($query) use (&$changed, $model): void {
            if (! $changed && str_contains(strtolower($query->sql), 'runbooks')) {
                $changed = true;
                $model->forceFill(['cost_policy_json' => ['currency' => 'KWD']])->save();
            }
        });
        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('Changed paid pricing during catalog lookup must not infer.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }

        $this->assertTrue($changed, 'Reviewed catalog must actually be consulted.');
        Http::assertNothingSent();
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $job->id]);
    }
}
