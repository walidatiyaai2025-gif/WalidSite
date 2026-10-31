<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certification extends Model
{
    use HasUuids;

    protected $fillable = ['profile_id', 'title', 'issuer', 'issued_on', 'verified', 'evidence_asset_id', 'source_refs_json'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'verified' => 'boolean', 'source_refs_json' => 'array'];
    }

    public function profile(): BelongsTo { return $this->belongsTo(Profile::class); }
}
