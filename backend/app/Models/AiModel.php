<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
