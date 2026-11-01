<?php

namespace App\AI;

use App\Models\AiProvider;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Asset;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Server-only bounded inference for already-leased T011 jobs.
 * No inference or image generation can bypass a running provider lease,
 * admin provider health, or the budget reservation/usage receipt.
 *
 * Remote-generated artwork is quarantined in PRIVATE storage, never exposed
 * as a public hero until a separate copyright/provenance rights decision.
 */
final class EditorialAiGenerator
{
    public function __construct(
        private readonly DurableAiQueue $queue,
        private readonly ResearchSourceIntake $sourceIntake,
        private readonly ResearchDraftComposer $composer,
    ) {
    }

    /**
     * Sources are unverified candidates. This operation can only SAVE A DRAFT.
     * @param list<string> $sources
     */
    public function draft(
        Article $article, AiProvider $provider, string $model, array $sources,
        string $instructions, string $jobId, string $owner, int $estimatedCostMinor = 0
    ): ArticleRevision {
        if (! $article->exists || mb_strlen($instructions) > 6000
            || trim($instructions) === '' || $article->status === 'published') {
            throw new InvalidArgumentException('Invalid unpublished research request.');
        }
        // Reject private/signed URLs BEFORE any external model request.
        $candidates = $this->sourceIntake->candidateCitations($sources);
        $this->authorizeLeasedCall($provider, $model, $jobId, $owner, $estimatedCostMinor);

        $prompt = "Write a cautious, source-backed technical Markdown draft. Do NOT claim sources are verified, fabricate results, or assert personal experience. Mark gaps for human review.\nTopic: "
            .$article->title_en."\nCandidate public sources (unverified):\n"
            .implode("\n", array_column($candidates, 'source_url'))
            ."\nEditorial instructions:\n".$instructions;

        $base = ProviderEndpoint::normalize($provider->base_url);
        try {
            if ($provider->provider_type === 'openai-compatible') {
                $response = Http::acceptJson()->withToken($provider->secret_ciphertext)
                    ->connectTimeout(5)->timeout($this->timeout($provider))
                    ->withOptions(['allow_redirects' => false])
                    ->post($base.'/chat/completions', [
                        'model' => $model,
                        'messages' => [['role' => 'user', 'content' => $prompt]],
                        'max_tokens' => 2500,
                    ]);
                $body = $response->json('choices.0.message.content');
            } elseif ($provider->provider_type === 'ollama-compatible') {
                $response = Http::acceptJson()->connectTimeout(5)
                    ->timeout($this->timeout($provider))
                    ->withOptions(['allow_redirects' => false])
                    ->post($base.'/api/generate', [
                        'model' => $model, 'prompt' => $prompt, 'stream' => false,
                    ]);
                $body = $response->json('response');
            } else {
                throw new DomainException('Unsupported model provider.');
            }
            if (! $response->successful() || ! is_string($body)
                || trim($body) === '' || mb_strlen($body) > 40000) {
                throw new RuntimeException('Provider returned no bounded text.');
            }
        } catch (Throwable) {
            // Provider messages frequently include private payloads and tokens.
            throw new RuntimeException('AI draft generation failed safely.');
        }

        $draft = $this->composer->saveDraft($article, $body, array_column($candidates, 'source_url'));
        $this->recordEstimatedUsage($provider, $jobId, $owner,
            'ai-draft:'.$draft->id, $estimatedCostMinor, $model);
        return $draft;
    }

    /**
     * A GENERATED IMAGE IS NOT AN APPROVED PUBLIC ASSET.
     * Reject owner likenesses and arbitrary binary/remote URLs. Store only
     * validated raster bytes; reviewer must separately clear rights.
     */
    public function illustration(
        AiProvider $provider, string $model, string $prompt,
        string $jobId, string $owner, int $estimatedCostMinor = 0
    ): Asset {
        if (mb_strlen($prompt) < 12 || mb_strlen($prompt) > 1000
            || preg_match('/\b(?:portrait|headshot|selfie|photo of walid|photo of waleed|walid atiya|waleed atiya|face of walid|face of waleed)\b/i', $prompt)) {
            throw new InvalidArgumentException('Only bounded original technical artwork is allowed.');
        }
        $this->authorizeLeasedCall($provider, $model, $jobId, $owner, $estimatedCostMinor);
        if ($provider->provider_type !== 'openai-compatible') {
            throw new DomainException('Selected provider does not support image generation.');
        }
        $base = ProviderEndpoint::normalize($provider->base_url);
        try {
            $response = Http::acceptJson()->withToken($provider->secret_ciphertext)
                ->connectTimeout(5)->timeout($this->timeout($provider))
                ->withOptions(['allow_redirects' => false])
                ->post($base.'/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                    'response_format' => 'b64_json',
                ]);
            $encoded = $response->json('data.0.b64_json');
            if (! $response->successful() || ! is_string($encoded)
                || strlen($encoded) > 6 * 1024 * 1024) {
                throw new RuntimeException('No safe inline image.');
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false || strlen($bytes) > 4 * 1024 * 1024 || strlen($bytes) < 30) {
                throw new RuntimeException('Invalid image payload.');
            }
            $details = @getimagesizefromstring($bytes);
            if (! is_array($details) || ! in_array($details['mime'] ?? null,
                    ['image/png', 'image/jpeg', 'image/webp'], true)
                || $details[0] < 1 || $details[1] < 1
                || $details[0] > 4096 || $details[1] > 4096) {
                throw new RuntimeException('Unsafe image format or dimensions.');
            }
        } catch (Throwable) {
            throw new RuntimeException('AI artwork generation failed safely.');
        }

        $sha = hash('sha256', $bytes);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$details['mime']];
        $key = 'ai-artwork/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($key, $bytes)) {
            throw new RuntimeException('Private artwork storage unavailable.');
        }

        try {
            $asset = Asset::create([
                'asset_key' => 'ai-'.Str::uuid(),
                'mime_type' => $details['mime'],
                'storage_key' => $key,
                'sha256' => $sha,
                'origin' => 'ai-generated',
                'rights_status' => 'unverified',
                'publishable' => false,
            ]);
            $manifest = [
                'asset_id' => $asset->id,
                'sha256' => $sha,
                'provider_id' => $provider->id,
                'model' => $model,
                'prompt_sha256' => hash('sha256', $prompt),
                'generated_at' => now()->toIso8601String(),
                'rights_status' => 'unverified',
            ];
            if (! Storage::disk('local')->put($key.'.provenance.json',
                json_encode($manifest, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Private provenance storage unavailable.');
            }
            $this->recordEstimatedUsage($provider, $jobId, $owner,
                'ai-image:'.$asset->id, $estimatedCostMinor, $model);
            return $asset;
        } catch (Throwable) {
            Storage::disk('local')->delete([$key, $key.'.provenance.json']);
            if (isset($asset)) {
                $asset->delete();
            }
            throw new RuntimeException('Artwork was not safely retained.');
        }
    }

    private function authorizeLeasedCall(
        AiProvider $provider, string $model, string $jobId, string $owner, int $cost
    ): void {
        if (! preg_match('/^[A-Za-z0-9_.:\-]{1,120}$/D', $model)
            || $cost < 0 || $cost > 100000000) {
            throw new InvalidArgumentException('Invalid model or cost estimate.');
        }
        $provider->refresh();
        if ($provider->trashed() || ! $provider->enabled
            || $provider->health_status !== 'healthy'
            || ! in_array($provider->provider_type, ['openai-compatible', 'ollama-compatible'], true)
            || ($provider->provider_type === 'openai-compatible'
                && ($cost < 1 || ! is_string($provider->secret_ciphertext)
                    || $provider->secret_ciphertext === ''))) {
            throw new DomainException('AI provider is disabled, unverified or unfunded.');
        }
        $job = DB::table('ai_jobs')->where('id', $jobId)->first();
        if (! $job || $job->provider_id !== $provider->id
            || $cost > (int) $job->reserved_budget_minor) {
            throw new DomainException('AI job/provider reservation mismatch.');
        }
        // Checks actual lease owner and expiry before contacting any provider.
        $this->queue->heartbeat($jobId, $owner, 60);
    }

    private function recordEstimatedUsage(
        AiProvider $provider, string $jobId, string $owner,
        string $receiptId, int $estimate, string $model
    ): void {
        // This is a conservative configured estimate, NOT verified provider
        // invoice data. Strict daily and per-job spending gates still apply.
        $this->queue->recordUsage($jobId, $owner, $receiptId, $estimate,
            $provider->currency, $model);
    }

    private function timeout(AiProvider $provider): int
    {
        return min(30, max(1, (int) $provider->timeout_seconds));
    }
}
