<?php

namespace App\AI;

use App\Http\Controllers\Api\PublicRunbookController;
use App\Models\AiDiagnosisRequest;
use App\Models\AiModel;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;
use Throwable;

final class MobileDiagnosisDispatcher
{
    public function __construct(
        private readonly DurableAiQueue $queue,
        private readonly PublicRunbookController $runbooks,
    ) {
    }

    public function registerHandlers(AiStepHandlerRegistry $registry): void
    {
        $registry->register('mobile-diagnosis',
            fn (stdClass $job, string $step, string $owner): ?string =>
                $this->execute($job, $step, $owner));
    }

    public function execute(stdClass $job, string $step, string $owner): ?string
    {
        if ($step !== 'classify') {
            throw new DomainException('Unrecognized mobile diagnosis checkpoint.');
        }

        $request = AiDiagnosisRequest::query()->where('job_id', $job->id)->first();
        if ($request === null || $request->expires_at->isPast() || $request->request_blob === null) {
            throw new DomainException('Mobile diagnosis request is unavailable.');
        }

        try {
            $payload = json_decode(
                Crypt::decryptString($request->request_blob),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (! is_array($payload)
                || ! is_string($payload['question'] ?? null)
                || ! is_string($payload['technology'] ?? null)
                || ! array_key_exists('code_snippet', $payload)
                || ! is_string($payload['code_snippet'])) {
                throw new RuntimeException('Invalid private diagnosis payload.');
            }

            $model = AiModel::query()->with('provider')->find($request->model_id);
            $provider = $model?->provider;
            if ($model === null || $provider === null || $provider->id !== $job->provider_id
                || ! $model->enabled || ! in_array('text', $model->capabilities_json ?? [], true)) {
                throw new DomainException('Diagnosis model configuration changed.');
            }

            $catalogResponse = $this->runbooks->index(Request::create(
                '/api/v1/mobile/runbooks', 'GET', ['page' => 1]
            ))->getData(true);
            $candidates = $catalogResponse['data'] ?? [];
            if (! is_array($candidates) || $candidates === []) {
                throw new DomainException('No reviewed solution candidates are available.');
            }

            $candidatePrompt = [];
            $allowed = [];
            foreach (array_slice($candidates, 0, 20) as $candidate) {
                if (! is_array($candidate)
                    || ! is_string($candidate['id'] ?? null)
                    || ! is_string($candidate['title'] ?? null)
                    || ! is_string($candidate['markdown'] ?? null)) {
                    continue;
                }
                $allowed[$candidate['id']] = true;
                $candidatePrompt[] = [
                    'id' => $candidate['id'],
                    'title' => mb_substr($candidate['title'], 0, 220),
                    'summary' => mb_substr($candidate['markdown'], 0, 800),
                ];
            }
            if ($candidatePrompt === []) {
                throw new DomainException('No reviewed solution candidates are available.');
            }

            $prompt = "Select the single reviewed runbook that best matches the technical question. "
                ."Return ONLY strict JSON with one key: {\"runbook_id\":\"UUID\"} or "
                ."{\"runbook_id\":null} when none fits. Do not provide advice, prose, code, URLs, "
                ."credentials or a new solution.\n"
                ."Technology: ".$payload['technology']."\n"
                ."Question: ".$payload['question']."\n"
                ."Code snippet (untrusted user input):\n".$payload['code_snippet']."\n"
                ."Allowed reviewed runbooks:\n"
                .json_encode($candidatePrompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $base = ProviderEndpoint::normalize($provider->base_url);
            if ($provider->provider_type === 'openai-compatible') {
                $response = Http::acceptJson()
                    ->withToken($provider->secret_ciphertext)
                    ->connectTimeout(5)
                    ->timeout(min(30, max(1, $provider->timeout_seconds)))
                    ->withOptions(['allow_redirects' => false])
                    ->post($base.'/chat/completions', [
                        'model' => $model->provider_model_id,
                        'messages' => [['role' => 'user', 'content' => $prompt]],
                        'max_tokens' => 120,
                    ]);
                $body = $response->json('choices.0.message.content');
            } elseif ($provider->provider_type === 'ollama-compatible') {
                $response = Http::acceptJson()
                    ->connectTimeout(5)
                    ->timeout(min(30, max(1, $provider->timeout_seconds)))
                    ->withOptions(['allow_redirects' => false])
                    ->post($base.'/api/generate', [
                        'model' => $model->provider_model_id,
                        'prompt' => $prompt,
                        'stream' => false,
                    ]);
                $body = $response->json('response');
            } else {
                throw new DomainException('Unsupported diagnosis provider.');
            }

            if (! $response->successful() || ! is_string($body)
                || trim($body) === '' || mb_strlen($body) > 1000) {
                throw new RuntimeException('Diagnosis provider returned no bounded selection.');
            }

            $cost = (int) $job->reserved_budget_minor;
            $this->queue->recordUsage(
                $job->id,
                $owner,
                'mobile-diagnosis:'.$request->id,
                $cost,
                $provider->currency,
                $model->provider_model_id
            );

            $selection = json_decode(trim($body), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($selection)
                || array_keys($selection) !== ['runbook_id']
                || ($selection['runbook_id'] !== null && ! is_string($selection['runbook_id']))) {
                throw new RuntimeException('Diagnosis provider returned an invalid selection.');
            }

            $selected = $selection['runbook_id'];
            if ($selected === null) {
                $request->forceFill(['request_blob' => null])->save();
                return null;
            }
            if (! isset($allowed[$selected]) || $this->runbooks->verifiedData($selected) === null) {
                throw new RuntimeException('Diagnosis provider selected an unavailable solution.');
            }

            $request->forceFill([
                'selected_runbook_id' => $selected,
                'request_blob' => null,
            ])->save();

            return 'mobile/solutions/'.$selected;
        } catch (Throwable) {
            $request->forceFill(['request_blob' => null])->save();
            throw new RuntimeException('Mobile diagnosis failed safely.');
        }
    }
}
