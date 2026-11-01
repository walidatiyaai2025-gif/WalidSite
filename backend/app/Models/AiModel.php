<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AiModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'provider_id',
        'provider_model_id',
        'capabilities_json',
        'enabled',
        'cost_policy_json',
    ];

    protected function casts(): array
    {
        return [
            'capabilities_json' => 'array',
            'enabled' => 'boolean',
            'cost_policy_json' => 'array',
        ];
    }

    /** @return HasMany<AiRoute, $this> */
    public function primaryRoutes(): HasMany
    {
        return $this->hasMany(AiRoute::class, 'primary_model_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
