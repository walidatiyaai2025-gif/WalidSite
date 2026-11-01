<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiRoute extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'task_type',
        'primary_model_id',
        'fallback_model_ids_json',
        'budget_json',
        'rate_limits_json',
    ];

    protected function casts(): array
    {
        return [
            'fallback_model_ids_json' => 'array',
            'budget_json' => 'array',
            'rate_limits_json' => 'array',
        ];
    }

    public function primaryModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'primary_model_id');
    }
}
