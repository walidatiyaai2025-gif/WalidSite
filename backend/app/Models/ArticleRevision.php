<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleRevision extends Model
{
    use HasUuids;

    protected $fillable = [
        'article_id', 'revision_no', 'body_md', 'citations_json',
        'validation_json', 'status',
    ];

    protected function casts(): array
    {
        return [
            'citations_json' => 'array',
            'validation_json' => 'array',
            'revision_no' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    protected static function booted(): void
    {
        // Editorial sign-off belongs to the exact reviewed body and sources.
        // Updating either in place must never inherit earlier approval,
        // including when a caller submits "validated" in the same update.
        static::updating(static function (self $revision): void {
            if ($revision->isDirty(['body_md', 'citations_json'])) {
                $revision->status = 'draft';
                $revision->validation_json = [
                    'citations_passed' => false,
                    'technical_passed' => false,
                ];
            }
        });
    }

    public function hasVerifiedEvidence(): bool
    {
        $citations = $this->citations_json;
        $validation = $this->validation_json;

        if ($this->status !== 'validated'
            || trim($this->body_md ?? '') === ''
            || ! is_array($citations) || count($citations) === 0
            || ! is_array($validation)
            || ($validation['citations_passed'] ?? false) !== true
            || ($validation['technical_passed'] ?? false) !== true) {
            return false;
        }

        foreach ($citations as $citation) {
            if (! is_array($citation)
                || ($citation['verified'] ?? false) !== true
                || ! is_string($citation['source_url'] ?? null)
                || ! self::isSafePublicSourceUrl($citation['source_url'])) {
                return false;
            }
        }

        return true;
    }
    /**
     * "verified" is a review state, never permission to link to internal
     * infrastructure or leak signed/private evidence URLs in public output.
     * This check does not fetch a URL or assert that an external source exists.
     */
    private static function isSafePublicSourceUrl(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        foreach (['localhost', '.localhost', '.local', '.internal', '.test', '.invalid', '.onion'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, $suffix)) {
                return false;
            }
        }

        // Signed documentation URLs and OAuth fragments can contain
        // credentials even when an editor has marked the source reviewed.
        // Keep ordinary public docs query strings, but never publish common
        // API keys, capability tokens or cloud signing parameters.
        $parameters = urldecode((string) ($parts['query'] ?? ''))
            .'&'.urldecode((string) ($parts['fragment'] ?? ''));
        return preg_match(
            '/(?:^|[?&;])(?:access[_-]?token|token|api[_-]?key|key|auth(?:orization)?|password|secret|signature|sig|session|jwt|client[_-]?secret|private[_-]?key|code|ticket|awsaccesskeyid|x[-_]amz[-_][a-z0-9_-]+|x[-_]goog[-_][a-z0-9_-]+)=/i',
            $parameters
        ) !== 1;
    }
}
