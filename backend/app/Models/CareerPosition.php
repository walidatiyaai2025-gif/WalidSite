<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerPosition extends Model
{
    use HasUuids;

    protected $fillable = ['profile_id', 'org_name', 'role_en', 'role_ar', 'started_on', 'ended_on', 'publishable', 'verified'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date', 'publishable' => 'boolean', 'verified' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopePubliclyVerified($query)
    {
        return $query->where('verified', true)->where('publishable', true)->whereHas('profile', function ($q) {
            $q->where('publish_status', 'published');
        });
    }
}
