<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug', 'title', 'title_ar', 'summary_en', 'summary_ar', 'role_en', 'role_ar',
        'status', 'visibility', 'github_repo_id', 'published_at',
        'portfolio_category', 'portfolio_source', 'portfolio_featured',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'portfolio_featured' => 'boolean'];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ProjectEvidence::class);
    }

    public function scopePubliclyVerified(Builder $query): Builder
    {
        return $query->where('visibility', 'public')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('status', ['completed', 'in_progress', 'research', 'archived'])
            ->whereNotNull('role_en')
            ->whereHas('evidence', fn (Builder $evidence) => $evidence
                ->where('visibility', 'public')->whereNotNull('verified_at')
                ->where('verified_at', '<=', now()));
    }
}
