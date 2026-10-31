<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetVariant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['asset_id', 'size_key', 'storage_key', 'width', 'height', 'format'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
