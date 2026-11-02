<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AccountDeletionEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'subject_hash',
        'reason_code',
        'cancelled_ai_jobs',
        'deleted_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_ai_jobs' => 'integer',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
