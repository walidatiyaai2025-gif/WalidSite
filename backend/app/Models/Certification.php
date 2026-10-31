<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certification extends Model
{
    use HasUuids;

    protected $attributes = ['verified' => false];

    protected $fillable = ['profile_id', 'title', 'issuer', 'issued_on', 'verified', 'evidence_asset_id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'verified' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopePubliclyVerified($query)
    {
        return $query->where('verified', true)->whereNotNull('evidence_asset_id')
            ->whereHas('profile', fn ($q) => $q->where('publish_status', 'published'));
    }
}
