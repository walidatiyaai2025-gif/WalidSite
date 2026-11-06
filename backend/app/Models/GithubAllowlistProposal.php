<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class GithubAllowlistProposal extends Model
{
    use HasUuids;

    protected $fillable = ['repository_slug', 'status', 'submitted_by_user_id'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
