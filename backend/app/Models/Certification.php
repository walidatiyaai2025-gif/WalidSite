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

    public function evidenceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'evidence_asset_id');
    }

    public function scopePubliclyVerified($query)
    {
        // A manually checked verified flag and a non-null UUID do not prove
        // the referenced asset is still licensed for public display. Align
        // this query with Asset::isPubliclyUsable() so both /about and /resume
        // immediately stop showing a credential after evidence revocation.
        $driver = $this->getConnection()->getDriverName();

        return $query->where('verified', true)
            ->whereHas('profile', fn ($q) => $q->where('publish_status', 'published'))
            ->whereHas('evidenceAsset', static function ($assets) use ($driver): void {
                $assets->where('publishable', true)
                    ->where('rights_status', 'cleared')
                    ->whereIn('origin', ['owner-supplied', 'licensed', 'ai-generated'])
                    ->whereIn('mime_type', [
                        'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif',
                    ])
                    ->whereNotNull('storage_key')
                    ->whereRaw("TRIM(storage_key) <> ''")
                    ->whereRaw('LENGTH(sha256) = 64');

                // SQLite supports GLOB; MySQL/MariaDB support REGEXP. Check
                // all hex digits, not just the SHA's fixed database length.
                if ($driver === 'sqlite') {
                    $assets->whereRaw("sha256 NOT GLOB '*[^0-9A-Fa-f]*'");
                } else {
                    $assets->whereRaw("sha256 REGEXP '^[0-9A-Fa-f]{64}$'");
                }
            });
    }
}
