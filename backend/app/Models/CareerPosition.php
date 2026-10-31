<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerPosition extends Model
{
    use HasUuids;

    protected $fillable = ['profile_id', 'org_name', 'role_en', 'role_ar', 'started_on', 'ended_on', 'date_precision', 'publishable', 'verified', 'source_refs_json'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date', 'publishable' => 'boolean', 'verified' => 'boolean', 'source_refs_json' => 'array'];
    }

    public function profile(): BelongsTo { return $this->belongsTo(Profile::class); }
}
