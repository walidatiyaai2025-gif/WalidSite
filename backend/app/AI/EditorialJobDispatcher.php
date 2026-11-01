<?php

namespace App\AI;

use App\Models\AiProvider;
use App\Models\ArticleRevision;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/**
 * Fully persisted editorial job intent: the only user input carried in the
 * idempotency key is an existing revision UUID and selected model ID.
 * Prompts, citations and private media remain server-side in the draft.
 *
 * This service registers explicit T011 callback kinds; no need to persist or
 * deserialize PHP classes, URLs, API keys or executable payloads in jobs.
 */
final class EditorialJobDispatcher
{
    public function __construct(
        private readonly DurableAiQueue $queue,
        private readonly EditorialAiGenerator $generator
    ) {
    }

    public function enqueue(
        ArticleRevision $revision, AiProvider $provider, string $model,
        bool $image = false, int $estimatedCostMinor = 0
    ): string {
        if (! $revision->exists
            || ! Str::isUuid($revision->id)
            || ! preg_match('/^[A-Za-z0-9_.:\-]{1,90}$/D', $model)
            || $estimatedCostMinor < 0) {
            throw new InvalidArgumentException('A current draft and safe model are required.');
        }
        // A reviewer may validate or revoke the revision while the caller
        // still holds an old "draft" model. Refresh before admission; the
        // worker's execution-time check remains a second safety boundary.
        $revision->refresh();
        if ($revision->status !== 'draft') {
            throw new InvalidArgumentException('A current draft and safe model are required.');
        }
        $article = $revision->article;
        if (! $article || $article->revisions()->orderByDesc('revision_no')->value('id') !== $revision->id) {
            throw new DomainException('Only the latest article draft can be queued.');
        }
        if ($image && $provider->provider_type !== 'openai-compatible') {
            throw new DomainException('Selected provider cannot generate images.');
        }
        $kind = $image ? 'editorial-art' : 'editorial-draft';
        $key = 'editorial:'.($image ? 'image' : 'draft').':'.$revision->id.':'.$model;

        return $this->queue->enqueue($provider, $kind, $key, ['generate'],
            $estimatedCostMinor);
    }

    public function registerHandlers(AiStepHandlerRegistry $registry): void
    {
        $registry->register('editorial-draft',
            fn (stdClass $job, string $step, string $owner): string =>
                $this->execute($job, $step, $owner, false));
        $registry->register('editorial-art',
            fn (stdClass $job, string $step, string $owner): string =>
                $this->execute($job, $step, $owner, true));
    }

    private function execute(stdClass $job, string $step, string $owner, bool $image): string
    {
        if ($step !== 'generate'
            || ! preg_match(
                '/^editorial:(draft|image):([0-9a-f-]{36}):([A-Za-z0-9_.:\-]{1,90})$/D',
                $job->idempotency_key, $parts
            ) || ($parts[1] === 'image') !== $image) {
            throw new DomainException('Unrecognized editorial job intent.');
        }

        $revision = ArticleRevision::query()->findOrFail($parts[2]);
        $article = $revision->article;
        if ($revision->status !== 'draft'
            || $article->revisions()->orderByDesc('revision_no')->value('id') !== $revision->id) {
            throw new DomainException('Editorial review or content has changed since queueing.');
        }
        $provider = AiProvider::query()->findOrFail($job->provider_id);
        $estimate = (int) $job->reserved_budget_minor;
        if ($image) {
            $asset = $this->generator->illustration($provider, $parts[3],
                'Original abstract technical illustration without people or text. Topic: '
                    .mb_substr($article->title_en, 0, 200),
                $job->id, $owner, $estimate);
            return 'artwork/assets/'.$asset->id;
        }

        $sources = array_column($revision->citations_json ?? [], 'source_url');
        $draft = $this->generator->draft($article, $provider, $parts[3], $sources,
            mb_substr($revision->body_md, 0, 6000),
            $job->id, $owner, $estimate);
        return 'editorial/revisions/'.$draft->id;
    }
}
