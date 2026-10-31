<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasUuids;

    protected $fillable = [
        'asset_key', 'mime_type', 'storage_key', 'sha256',
        'origin', 'rights_status', 'publishable',
    ];

    protected function casts(): array
    {
        return ['publishable' => 'boolean'];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AssetVariant::class);
    }

    public function isPubliclyUsable(): bool
    {
        return $this->publishable === true
            && $this->rights_status === 'cleared'
            && in_array($this->origin, ['owner-supplied', 'licensed', 'ai-generated'], true)
            && str_starts_with($this->mime_type ?? '', 'image/')
            && preg_match('/^[a-f0-9]{64}$/i', $this->sha256 ?? '') === 1
            && trim($this->storage_key ?? '') !== '';
    }
}
