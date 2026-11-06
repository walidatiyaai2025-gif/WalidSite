<?php

namespace App\Domain\Deployment;

/**
 * Trusted operator-installed cPanel capability for a full owner code update.
 *
 * Backup isolation alone is insufficient: an update requires an independent
 * HTTPS request routed through the stable physical dispatcher, carrying only
 * the ephemeral private maintenance token. The implementation must verify a
 * live new-release response, matching version AND source SHA, and fail on
 * TLS, network, timeout, stale response or version mismatch.
 *
 * No default implementation is registered. Never accept host proof or an
 * HTTP target URL from a form, session, or environment boolean.
 */
interface CpanelOwnerCodeUpdateHost extends CpanelOwnerBackupHost
{
    public function probeNewRelease(string $maintenanceToken, string $version, string $sourceSha): bool;
}
