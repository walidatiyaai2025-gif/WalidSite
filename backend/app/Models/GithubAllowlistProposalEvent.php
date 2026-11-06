<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class GithubAllowlistProposalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'proposal_id', 'actor_user_id', 'action', 'reason', 'created_at',
    ];
}
