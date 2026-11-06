<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GithubRepositoryCandidateEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['candidate_id', 'actor_user_id', 'action', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(GithubRepositoryCandidate::class, 'candidate_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
