<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasUuids;

    // Mirror the DB default even before refreshing a newly inserted model.
    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['slug', 'title_en', 'title_ar', 'canonical_lang', 'hero_asset_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'published_revision_no' => 'integer'];
    }

    /**
     * A reviewed body is not approval for a different public title, canonical
     * language or slug. Hero asset rights are checked independently on every
     * public read; attaching a new rights-cleared image does not change text
     * citation approval (and preserves the existing editorial workflow).
     */
    private const REVIEWED_PUBLIC_FIELDS = [
        'slug', 'title_en', 'title_ar', 'canonical_lang',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $article): void {
            if ($article->isDirty(self::REVIEWED_PUBLIC_FIELDS)) {
                // The metadata edit cannot smuggle a published state through
                // the same save operation or retain its old published pin.
                $article->forceFill([
                    'status' => 'draft',
                    'published_at' => null,
                    'published_revision_no' => null,
                ]);
            }
        });

        static::updated(static function (self $article): void {
            if (! $article->wasChanged(self::REVIEWED_PUBLIC_FIELDS)) {
                return;
            }

            // Revisions validated against the old article metadata must be
            // reviewed again. This also covers edits made before first publish.
            // A subsequent separate review may restore their validated state.
            $article->revisions()->where('status', 'validated')->get()
                ->each(static function (ArticleRevision $revision): void {
                    $revision->update([
                        'status' => 'draft',
                        'validation_json' => [
                            'citations_passed' => false,
                            'technical_passed' => false,
                        ],
                    ]);
                });
        });
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'article_categories');
    }

    public function heroAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'hero_asset_id');
    }

    public function isReadyToPublish(): bool
    {
        // A reviewed revision is tied to the persisted article identity and
        // bilingual titles. Do not claim publication succeeded while unsaved
        // metadata would immediately invalidate that approval in updating().
        if ($this->isDirty(self::REVIEWED_PUBLIC_FIELDS)) {
            return false;
        }

        if (trim($this->title_en ?? '') === '' || trim($this->title_ar ?? '') === ''
            || ! in_array($this->canonical_lang, ['ar', 'en'], true)) {
            return false;
        }
        if ($this->hero_asset_id && ! $this->heroAsset()->first()?->isPubliclyUsable()) {
            return false;
        }
        $latest = $this->revisions()->orderByDesc('revision_no')->first();
        return $latest?->hasVerifiedEvidence() === true;
    }

    public function publishVerified(): void
    {
        if (! $this->isReadyToPublish()) {
            throw new DomainException('Article evidence, editorial validation, or media rights are incomplete.');
        }
        // Freeze the exact validated revision at publication time. A newer draft
        // must never silently replace the already reviewed public content.
        $revision = $this->revisions()->orderByDesc('revision_no')->firstOrFail();
        $this->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'published_revision_no' => $revision->revision_no,
        ])->save();
    }

    public function publicRevision(): ?ArticleRevision
    {
        if ($this->status !== 'published'
            || $this->published_at === null
            || $this->published_at->isFuture()
            || $this->published_revision_no === null
            || ($this->hero_asset_id !== null && ! $this->heroAsset()->first()?->isPubliclyUsable())) {
            return null;
        }

        $revision = $this->revisions()->where('revision_no', $this->published_revision_no)->first();

        // Revalidate the persisted snapshot on every public read: rights,
        // citations or an editor's validation may have been revoked.
        return $revision?->hasVerifiedEvidence() === true ? $revision : null;
    }

    public function scopePubliclyVisible($query)
    {
        return $query->where('articles.status', 'published')
            ->whereNotNull('articles.published_revision_no')
            ->whereNotNull('articles.published_at')
            ->where('articles.published_at', '<=', now())
            ->whereHas('revisions', fn ($q) => $q->where('status', 'validated')
                ->whereColumn('revision_no', 'articles.published_revision_no'))
            ->where(function ($q) {
                $q->whereNull('articles.hero_asset_id')
                    ->orWhereHas('heroAsset', fn ($asset) => $asset
                        ->where('publishable', true)
                        ->where('rights_status', 'cleared'));
            });
    }
}
