<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiDiagnosisRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'job_id',
        'model_id',
        'selected_runbook_id',
        'request_blob',
        'expires_at',
    ];

    protected $hidden = ['request_blob'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
