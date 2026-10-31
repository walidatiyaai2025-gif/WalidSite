<?php

namespace App\Models;

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
                || ! filter_var($citation['source_url'], FILTER_VALIDATE_URL)
                || strtolower(parse_url($citation['source_url'], PHP_URL_SCHEME) ?: '') !== 'https') {
                return false;
            }
        }

        return true;
    }
}
