<?php

namespace App\AI;

use App\Models\Asset;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Independent human approval for AI artwork after provenance and bytes
 * verification. Generated assets always start private/unverified.
 */
final class EditorialArtworkRights
{
    public function approve(
        Asset $asset, User $reviewer, bool $licensedForPublication,
        bool $noOwnerImpersonation
    ): bool {
        if (! $reviewer->exists || ! $reviewer->canAccessAdmin()) {
            throw new DomainException('Active rights reviewer required.');
        }
        if (! $licensedForPublication || ! $noOwnerImpersonation) {
            return false;
        }
        return DB::transaction(function () use ($asset, $reviewer): bool {
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            // Generated assets are written ONLY as ai-artwork/<UUID>.<raster>.
            // Prefix checks alone permit arbitrary local filenames, nested
            // paths, traversal and mismatched MIME extensions to masquerade
            // as generator-owned artwork if an asset row is corrupted.
            $extension = match ($asset->mime_type) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => null,
            };
            if ($asset->origin !== 'ai-generated' || $asset->rights_status !== 'unverified'
                || $extension === null || ! is_string($asset->storage_key)
                || preg_match(
                    '/\Aai-artwork\/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\.'
                        .$extension.'\z/D',
                    $asset->storage_key
                ) !== 1) {
                throw new DomainException('Unreviewed generated artwork required.');
            }
            $disk = Storage::disk('local');
            $manifestPath = $asset->storage_key.'.provenance.json';
            if (! $disk->exists($asset->storage_key) || ! $disk->exists($manifestPath)) {
                throw new RuntimeException('Private artwork evidence missing.');
            }
            // The generator writes at most 4 MiB of raster data and a tiny
            // JSON manifest. Quarantined files can be replaced independently
            // of the DB; inspect sizes BEFORE get() to bound cPanel memory
            // during owner review. An oversized file is never publishable.
            $imageBytes = $disk->size($asset->storage_key);
            $manifestBytes = $disk->size($manifestPath);
            if ($imageBytes < 1 || $imageBytes > 4 * 1024 * 1024
                || $manifestBytes < 1 || $manifestBytes > 16 * 1024) {
                throw new DomainException('Artwork provenance or content verification failed.');
            }
            $manifest = json_decode($disk->get($manifestPath), true);
            if (! is_array($manifest) || ($manifest['asset_id'] ?? null) !== $asset->id
                || ($manifest['sha256'] ?? null) !== $asset->sha256
                || ! is_string($manifest['provider_id'] ?? null)
                || ! is_string($manifest['model'] ?? null)
                || ! is_string($manifest['prompt_sha256'] ?? null)
                || hash('sha256', $disk->get($asset->storage_key)) !== $asset->sha256) {
                throw new DomainException('Artwork provenance or content verification failed.');
            }

            // The review attestation is private and references the exact
            // original bytes; never show owner identity or prompts in public.
            $rightsPath = $asset->storage_key.'.rights.json';
            if (! $disk->put($rightsPath, json_encode([
                'asset_id' => $asset->id,
                'sha256' => $asset->sha256,
                'reviewer_id' => $reviewer->id,
                'rights_status' => 'cleared',
                'no_owner_impersonation' => true,
                'reviewed_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not persist artwork rights attestation.');
            }
            $asset->update(['rights_status' => 'cleared', 'publishable' => true]);
            return $asset->fresh()->isPubliclyUsable();
        }, 3);
    }
}
