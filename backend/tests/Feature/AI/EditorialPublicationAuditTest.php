<?php

namespace Tests\Feature\AI;

use App\AI\EditorialPublicationAudit;
use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class EditorialPublicationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    private function article(bool $valid): Article
    {
        $article = Article::create([
            'slug' => 'audit-'.Str::lower(Str::random(16)),
            'title_en' => 'Source-backed technical reading',
            'title_ar' => 'مقال تقني موثق بمصادر',
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Original technical explanation with a public source.',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://example.org/public-evidence',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();
        if (! $valid) {
            // Simulate a previously verified citation withdrawn by review.
            $revision->update(['validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ]]);
        }

        return $article;
    }

    public function test_bounded_pages_revoke_only_invalid_content_and_rotate_cursor(): void
    {
        $articles = [$this->article(false), $this->article(true), $this->article(false)];
        $audit = app(EditorialPublicationAudit::class);

        $first = $audit->run(2);
        $this->assertSame(2, $first['checked']);
        $this->assertTrue($first['has_more']);
        $this->assertFalse($first['locked']);

        $second = $audit->run(2);
        $this->assertSame(1, $second['checked']);
        $this->assertFalse($second['has_more']);

        $this->assertSame(2, $first['revoked'] + $second['revoked']);
        $this->assertDatabaseCount('editorial_events', 2);
        $this->assertDatabaseHas('editorial_events', [
            'action' => 'evidence_revoked',
            'reason' => 'citation_or_rights_invalid',
        ]);
        $this->assertSame(1, Article::query()->where('status', 'published')->count());
        $this->assertSame(2, Article::query()->where('status', 'draft')->count());
        $this->assertNotNull($articles[1]->fresh()->publicRevision());

        // After the end of the scan, the cursor wraps, but no second
        // revocation is possible for an already withdrawn article.
        $third = $audit->run(2);
        $this->assertSame(1, $third['checked']);
        $this->assertSame(0, $third['revoked']);
    }

    public function test_competing_runner_skips_work_while_lock_is_held(): void
    {
        $article = $this->article(false);
        $lock = Cache::lock('editorial:published-audit:lock:v1', 120);
        $this->assertTrue($lock->get());
        try {
            $result = app(EditorialPublicationAudit::class)->run(20);
            $this->assertTrue($result['locked']);
            $this->assertSame(0, $result['checked']);
            $this->assertSame('published', $article->fresh()->status);
        } finally {
            $lock->release();
        }
    }

    public function test_invalid_limits_cannot_start_an_unbounded_scan(): void
    {
        $article = $this->article(false);
        foreach ([0, 51, -1] as $limit) {
            try {
                app(EditorialPublicationAudit::class)->run($limit);
                $this->fail('Invalid limit was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('between 1 and 50', $exception->getMessage());
            }
        }
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_empty_page_is_bounded_and_successful(): void
    {
        $result = app(EditorialPublicationAudit::class)->run();
        $this->assertSame([
            'checked' => 0, 'revoked' => 0, 'has_more' => false, 'locked' => false,
        ], $result);
    }
}
