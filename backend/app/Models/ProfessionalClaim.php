<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalClaim extends Model
{
    use HasUuids;

    protected $fillable = [
        'profile_id', 'claim_en', 'claim_ar', 'evidence_url',
        'status', 'is_public', 'source_refs_json',
    ];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'source_refs_json' => 'array'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopePubliclyApproved($query)
    {
        return $query->where('is_public', true)->where('status', 'approved')
            ->whereNotNull('evidence_url')
            ->whereHas('profile', fn ($q) => $q->where('publish_status', 'published'));
    }
}
