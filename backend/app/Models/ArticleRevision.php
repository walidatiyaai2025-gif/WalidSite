<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleRevision extends Model
{
    use HasUuids;

    private const SENSITIVE_PARAMETER_PATTERN =
        '/(?:^|[?#&;])(?:access[_-]?token|refresh[_-]?token|id[_-]?token|token|api[_-]?key|key|auth(?:orization)?|bearer|password|passwd|secret|signature|sig|session(?:id)?|jwt|client[_-]?secret|private[_-]?key|code|ticket|awsaccesskeyid|x[-_]amz[-_][a-z0-9_-]+|x[-_]goog[-_][a-z0-9_-]+)=/i';

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
            // The article association and sequence number identify the exact
            // reviewed snapshot. Neither may be rewritten after creation:
            // reparenting or renumbering would transfer editorial approval to
            // another title or publication pin without a new review.
            if ($revision->isDirty(['article_id', 'revision_no'])) {
                throw new DomainException('Article revision identity is immutable after creation.');
            }

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

        // A URL marked "verified" is never permission to leak bearer
        // credentials, signed storage links or OAuth fragments. Inspect both
        // raw and repeatedly decoded layers: nested redirects may encode '?'
        // and '&' as well as the sensitive key name (even more than once).
        // Never fetch, print or persist decoded source parameters.
        return ! self::hasSensitiveParameters((string) ($parts['query'] ?? ''))
            && ! self::hasSensitiveParameters((string) ($parts['fragment'] ?? ''));
    }

    private static function hasSensitiveParameters(string $parameters): bool
    {
        // Five bounded rounds cover common multi-encoded redirects. If a
        // sixth decode would still change the content, deny rather than
        // silently accept a deeply encoded credential-bearing URL.
        for ($depth = 0; $depth < 5; ++$depth) {
            if (preg_match(
                self::SENSITIVE_PARAMETER_PATTERN,
                $parameters
            ) === 1) {
                return true;
            }

            $decoded = rawurldecode($parameters);
            if ($decoded === $parameters) {
                return false;
            }
            $parameters = $decoded;
        }

        // The final decoded layer also needs examination (the last decode
        // can reveal the dangerous key). An unresolved percent encoding after
        // the bounded scan is conservatively rejected.
        return preg_match(
            self::SENSITIVE_PARAMETER_PATTERN,
            $parameters
        ) === 1 || rawurldecode($parameters) !== $parameters;
    }
}
