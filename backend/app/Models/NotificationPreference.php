<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'purpose',
        'channel',
        'enabled',
        'consented_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
