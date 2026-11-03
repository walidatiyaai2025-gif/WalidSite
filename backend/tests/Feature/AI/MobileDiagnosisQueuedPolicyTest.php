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


    /** @return array{AiProvider,AiModel,AiRoute,AiDiagnosisRequest,object} */
    private function queuedLocalDiagnosis(): array
    {
        $provider = AiProvider::create([
            'display_name' => 'Pending local diagnosis',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => null,
            'enabled' => true,
            'health_status' => 'healthy',
            'routing_priority' => 10,
            'daily_budget_minor' => 0,
            'currency' => 'USD',
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'local-reviewed-selector',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => [],
        ]);
        $route = AiRoute::create([
            'task_type' => 'mobile-ai-diagnose',
            'primary_model_id' => $model->id,
            'fallback_model_ids_json' => [],
            'budget_json' => ['reservation_minor' => 0],
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
            ['classify'], 0, 1
        );
        $diagnosis->forceFill(['job_id' => $jobId])->save();

        return [$provider, $model, $route, $diagnosis, (object) [
            'id' => $jobId,
            'provider_id' => $provider->id,
            'reserved_budget_minor' => 0,
        ]];
    }

    private function replacementLocalModel(AiProvider $provider): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'newly-approved-local-selector',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => [],
        ]);
    }

    private function reviewedCatalog(): string
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
        return $id;
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

    public function test_account_disabled_during_paid_provider_reply_cannot_publish_selection_but_cost_is_recorded(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $runbookId = $this->reviewedCatalog();
        $queue = app(DurableAiQueue::class);
        $this->assertSame($job->id, $queue->claimNext('test-worker')?->id);
        $sent = 0;
        Http::fake(function ($request) use ($diagnosis, $runbookId, &$sent) {
            $sent++;
            $this->assertSame('https://provider.example.test/v1/chat/completions',
                $request->url());
            $this->assertStringContainsString('Private database question',
                $request['messages'][0]['content']);

            // The account is valid at the last outbound boundary but is
            // deactivated while the external model is generating a response.
            User::query()->findOrFail($diagnosis->user_id)->update([
                'status' => 'pending_activation',
            ]);

            return Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'runbook_id' => $runbookId,
                ], JSON_THROW_ON_ERROR)]]],
                'usage' => ['prompt_tokens' => 23, 'completion_tokens' => 5],
            ], 200);
        });

        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('A disabled requester must not receive a late provider result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
            $this->assertStringNotContainsString('Private database question',
                $exception->getMessage());
        }
        $this->assertSame(1, $sent);
        Http::assertSentCount(1);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertNull($diagnosis->fresh()->request_blob);
        // The provider call DID occur: suppressing the paid receipt would
        // release its budget and permit another unaccounted external call.
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $job->id,
            'cost_minor' => 25,
            'cost_currency' => 'USD',
        ]);
    }

    public function test_account_disabled_during_no_match_reply_does_not_finalize_as_success(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $this->reviewedCatalog();
        $this->assertSame($job->id,
            app(DurableAiQueue::class)->claimNext('test-worker')?->id);
        Http::fake(function () use ($diagnosis) {
            User::query()->findOrFail($diagnosis->user_id)->update([
                'status' => 'pending_activation',
            ]);
            return Http::response([
                'choices' => [['message' => ['content' => '{"runbook_id":null}']]],
                'usage' => ['prompt_tokens' => 17, 'completion_tokens' => 4],
            ], 200);
        });

        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('A deactivated requester must not finalize even no-match output.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertDatabaseHas('ai_usage', ['job_id' => $job->id, 'cost_minor' => 25]);
    }

    public function test_active_requester_still_receives_verified_provider_selection_with_paid_receipt(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $runbookId = $this->reviewedCatalog();
        $this->assertSame($job->id,
            app(DurableAiQueue::class)->claimNext('test-worker')?->id);
        Http::fake(['https://provider.example.test/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'runbook_id' => $runbookId,
            ], JSON_THROW_ON_ERROR)]]],
            'usage' => ['prompt_tokens' => 23, 'completion_tokens' => 5],
        ], 200)]);

        $ref = app(MobileDiagnosisDispatcher::class)->execute(
            $job, 'classify', 'test-worker'
        );
        $this->assertSame('mobile/solutions/'.$runbookId, $ref);
        $this->assertSame($runbookId, $diagnosis->fresh()->selected_runbook_id);
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertDatabaseHas('ai_usage', ['job_id' => $job->id, 'cost_minor' => 25]);
        Http::assertSentCount(1);
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

    public function test_expiry_during_paid_inference_preserves_receipt_but_discards_private_result(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $runbookId = $this->reviewedCatalog();
        $diagnosis->forceFill(['expires_at' => now()->addSeconds(30)])->save();
        $this->assertSame($job->id,
            app(DurableAiQueue::class)->claimNext('test-worker')?->id);

        Http::fake(function () use ($runbookId) {
            // Allow setup and dispatch ample time before expiry: a one-second
            // window flakes on slower CI runners before inference starts.
            // Expire only during the simulated outbound provider call;
            // the 60-second worker lease remains valid for the receipt.
            $this->travel(31)->seconds();
            return Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'runbook_id' => $runbookId,
                ], JSON_THROW_ON_ERROR)]]],
                'usage' => ['prompt_tokens' => 19, 'completion_tokens' => 5],
            ], 200);
        });

        try {
            try {
                app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
                $this->fail('Expired private diagnosis must never publish a late selection.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
            }
        } finally {
            $this->travelBack();
        }

        Http::assertSentCount(1);
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $job->id, 'cost_minor' => 25, 'cost_currency' => 'USD',
        ]);
        $this->assertSame(
            ['input_tokens' => 19, 'output_tokens' => 5],
            json_decode((string) DB::table('ai_usage')->where('job_id', $job->id)
                ->value('usage_json'), true)
        );
    }


    public function test_queued_local_model_removed_from_admin_route_cannot_transmit_private_input(): void
    {
        [$provider, , $route, $diagnosis, $job] = $this->queuedLocalDiagnosis();
        $replacement = $this->replacementLocalModel($provider);
        $route->forceFill(['primary_model_id' => $replacement->id])->save();

        // Prior to #671, the Ollama-specific path skipped the route membership
        // guard and sent the stored question to a no-longer-approved model.
        $this->rejectWithoutProviderCall($diagnosis, $job);
    }

    public function test_queued_local_model_revoked_during_catalog_read_cannot_call_ollama(): void
    {
        [$provider, , $route, $diagnosis, $job] = $this->queuedLocalDiagnosis();
        $replacement = $this->replacementLocalModel($provider);
        $this->reviewedCatalog();
        Http::fake();
        $changed = false;

        DB::listen(function ($query) use (&$changed, $route, $replacement): void {
            if (! $changed && str_contains(strtolower($query->sql), 'runbooks')) {
                $changed = true;
                $route->forceFill(['primary_model_id' => $replacement->id])->save();
            }
        });

        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('Admin route revocation during catalog lookup must block inference.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }

        $this->assertTrue($changed, 'The reviewed catalog must have been read.');
        Http::assertNothingSent();
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertDatabaseMissing('ai_usage', ['job_id' => $job->id]);
    }

    public function test_still_authorized_local_model_can_select_reviewed_runbook_without_paid_charge(): void
    {
        [, , , $diagnosis, $job] = $this->queuedLocalDiagnosis();
        $runbookId = $this->reviewedCatalog();
        $this->assertSame($job->id,
            app(DurableAiQueue::class)->claimNext('test-worker')?->id);
        Http::fake(['http://127.0.0.1:11434/api/generate' => Http::response([
            'response' => json_encode(['runbook_id' => $runbookId], JSON_THROW_ON_ERROR),
            'prompt_eval_count' => 9,
            'eval_count' => 4,
        ], 200)]);

        $ref = app(MobileDiagnosisDispatcher::class)->execute(
            $job, 'classify', 'test-worker'
        );
        $this->assertSame('mobile/solutions/'.$runbookId, $ref);
        $this->assertSame($runbookId, $diagnosis->fresh()->selected_runbook_id);
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $job->id, 'cost_minor' => 0, 'cost_currency' => 'USD',
        ]);
        $this->assertSame(
            ['input_tokens' => 9, 'output_tokens' => 4],
            json_decode((string) DB::table('ai_usage')
                ->where('job_id', $job->id)->value('usage_json'), true)
        );
        Http::assertSentCount(1);
    }


    public function test_malformed_paid_provider_usage_keeps_estimate_but_never_publishes_diagnosis(): void
    {
        [, , , $diagnosis, $job] = $this->queuedPaidDiagnosis();
        $runbookId = $this->reviewedCatalog();
        $this->assertSame($job->id,
            app(DurableAiQueue::class)->claimNext('test-worker')?->id);

        Http::fake(['https://provider.example.test/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'runbook_id' => $runbookId,
            ], JSON_THROW_ON_ERROR)]]],
            // Provider billed the request but sent untrusted usage telemetry.
            'usage' => ['prompt_tokens' => '19', 'completion_tokens' => 5],
        ], 200)]);

        try {
            app(MobileDiagnosisDispatcher::class)->execute($job, 'classify', 'test-worker');
            $this->fail('Malformed usage must never finalize a provider selection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertNull($diagnosis->fresh()->request_blob);
        $this->assertNull($diagnosis->fresh()->selected_runbook_id);
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $job->id,
            'cost_minor' => 25,
            'cost_currency' => 'USD',
        ]);
        $this->assertSame([], json_decode((string) DB::table('ai_usage')
            ->where('job_id', $job->id)->value('usage_json'), true));
    }


}
