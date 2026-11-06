<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GithubRepositoryCandidate extends Model
{
    use HasUuids;

    protected $fillable = [
        'repository_slug', 'normalized_slug', 'status',
        'submitted_by_user_id', 'reviewed_by_user_id',
        'reviewed_at', 'verified_at', 'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GithubRepositoryCandidateEvent::class, 'candidate_id');
    }
}
