<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiProvider extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'display_name', 'provider_type', 'base_url', 'secret_ciphertext',
        'enabled', 'health_status', 'concurrent_limit', 'timeout_seconds',
        'daily_budget_minor', 'currency', 'routing_priority', 'last_checked_at',
    ];

    protected $hidden = ['secret_ciphertext'];

    protected function casts(): array
    {
        return [
            'secret_ciphertext' => 'encrypted',
            'enabled' => 'boolean',
            'concurrent_limit' => 'integer',
            'timeout_seconds' => 'integer',
            'daily_budget_minor' => 'integer',
            'routing_priority' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    /** No mass serialization or accidental credential disclosure. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'provider_type' => $this->provider_type,
            'base_url' => $this->base_url,
            'enabled' => $this->enabled,
            'health_status' => $this->health_status,
            'has_saved_secret' => $this->secret_ciphertext !== null,
            'concurrent_limit' => $this->concurrent_limit,
            'timeout_seconds' => $this->timeout_seconds,
            'daily_budget_minor' => $this->daily_budget_minor,
            'currency' => $this->currency,
            'routing_priority' => $this->routing_priority,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
        ];
    }
}
