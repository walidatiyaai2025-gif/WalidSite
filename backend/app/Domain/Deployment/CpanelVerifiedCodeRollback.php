<?php

namespace App\Domain\Deployment;

use RuntimeException;

/**
 * A code-only chained release was rejected by its post-switch health check,
 * and the original immutable release pointer was restored and re-attested.
 *
 * This is not a successful update receipt. It is a narrow recovery capability
 * that permits the still-owning HTTP writer lease to clear maintenance only
 * after an independent second verification of the previous running release.
 */
final class CpanelVerifiedCodeRollback extends RuntimeException
{
    /**
     * @param array{schema:int,version:string,root:string} $previousPointer
     */
    public function __construct(
        public readonly array $previousPointer,
        public readonly string $previousRoot,
        public readonly string $previousVersion,
        public readonly string $previousVersionFingerprint,
        public readonly string $previousReleaseFingerprint,
    ) {
        parent::__construct('New release health failed; old code pointer restored.');
    }
}
