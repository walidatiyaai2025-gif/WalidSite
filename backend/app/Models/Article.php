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

    protected $fillable = ['slug', 'title_en', 'title_ar', 'canonical_lang', 'hero_asset_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
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
        $this->forceFill(['status' => 'published', 'published_at' => now()])->save();
    }

    public function scopePubliclyVisible($query)
    {
        return $query->where('status', 'published')->whereNotNull('published_at')
            ->whereHas('revisions', fn ($q) => $q->where('status', 'validated'));
    }
}
