<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;
use Throwable;

/**
 * Signed, READ-ONLY Cron discovery. This deliberately has no code-switch,
 * migration, downloader or host-adapter capability.
 *
 * A protected first install / host-specific recovery+update integration must
 * independently authorize all writes. No value from this receipt is trusted by
 * the installer, and "BLOCKED" must never be presented as "DEPLOYED".
 */
final class CpanelReleaseCronPoller
{
    /**
     * @return array{schema:int,state:string,checked_at:int,installed_version:string,available_version:?string,source_sha:?string,reason:string,code_applied:bool}
     */
    public function poll(
        string $privateDirectory,
        string $publicDocumentRoot,
        string $publisherOrigin,
        string $publisherPublicKey,
        string $installedVersion,
        ?TrustedReleaseCatalog $catalog = null,
    ): array {
        if (preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $installedVersion) !== 1) {
            throw new DomainException('Trusted installed version is missing.');
        }
        TrustedReleaseMetadata::assertPinnedHttpsOrigin($publisherOrigin);
        if (strlen($publisherPublicKey) < 40 || strlen($publisherPublicKey) > 100) {
            throw new DomainException('Publisher verification key is unavailable.');
        }

        $directory = $this->assertPrivateDirectory($privateDirectory, $publicDocumentRoot);
        $lock = $this->openLock($directory.'/release-poll.lock');
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                // A competing process owns the tick. It alone writes the
                // next receipt; never overwrite it with a spurious success.
                throw new DomainException('Another release check is running.');
            }
            $this->assertLockIdentity($lock, $directory.'/release-poll.lock');
            $record = [
                'schema' => 1,
                'state' => 'BLOCKED',
                'checked_at' => time(),
                'installed_version' => $installedVersion,
                'available_version' => null,
                'source_sha' => null,
                'reason' => 'PUBLISHER_CHECK_UNVERIFIED',
                'code_applied' => false,
            ];
            try {
                $release = ($catalog ?? new TrustedReleaseCatalog())->check(
                    $publisherOrigin, $publisherPublicKey, $installedVersion,
                    true, // ONLY this read-only path may accept signed equality.
                )['release'];
                $record['available_version'] = $release['version'];
                $record['source_sha'] = $release['source_sha'];
                if (version_compare($release['version'], $installedVersion, '==')) {
                    // This attests a signed catalog version, not that the
                    // running PHP tree or actual live HTTPS site was audited.
                    $record['state'] = 'UP_TO_DATE';
                    $record['reason'] = 'NO_NEWER_SIGNED_VERSION';
                } else {
                    // An approved ZIP is still NOT an update authorization.
                    // No host adapter / backup / recovery in a Cron tick.
                    $record['reason'] = 'VERIFIED_HOST_UPDATE_GATE_REQUIRED';
                }
            } catch (Throwable) {
                // Never record cURL errors, untrusted JSON, secrets or hosts.
                $record['state'] = 'BLOCKED';
            }
            $this->writeReceipt($directory, $record, $lock);
            return $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertPrivateDirectory(string $directory, string $publicDocumentRoot): string
    {
        $parent = realpath(dirname($directory));
        $public = realpath($publicDocumentRoot);
        if ($parent === false || $public === false
            || is_link($directory) || is_link(dirname($directory))
            || ! is_dir($parent) || is_link($publicDocumentRoot)) {
            throw new DomainException('Private Cron directory not available.');
        }
        $parentStat = @lstat($parent);
        if (! is_array($parentStat) || ($parentStat['mode'] & 0170000) !== 0040000
            || ($parentStat['mode'] & 0022) !== 0) {
            throw new DomainException('Private Cron parent is unsafe.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700)) {
            throw new DomainException('Private Cron directory cannot be created.');
        }
        clearstatcache(true, $directory);
        $resolved = realpath($directory);
        $stat = @lstat($directory);
        if ($resolved === false || $resolved !== $directory || ! is_array($stat)
            || is_link($directory) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0077) !== 0
            || $resolved === $public || str_starts_with($resolved.'/', $public.'/')
            || str_starts_with($public.'/', $resolved.'/')) {
            throw new DomainException('Cron receipt storage must be isolated and owner-only.');
        }
        return $resolved;
    }

    /** @return resource */
    private function openLock(string $path)
    {
        if (is_link($path)) {
            throw new DomainException('Cron lock must not be a symlink.');
        }
        $existing = @lstat($path);
        if ($existing === false) {
            $lock = @fopen($path, 'x+');
            if ($lock !== false && ! @chmod($path, 0600)) {
                fclose($lock);
                throw new DomainException('Private Cron lock cannot be protected.');
            }
            if ($lock === false) {
                // A simultaneous Cron tick may have just created it.
                $lock = @fopen($path, 'c+');
            }
        } else {
            $lock = @fopen($path, 'c+');
        }
        if ($lock === false) {
            throw new RuntimeException('Private Cron lock unavailable.');
        }
        try {
            $this->assertLockIdentity($lock, $path);
        } catch (Throwable $error) {
            fclose($lock);
            throw $error;
        }
        return $lock;
    }

    /** @param resource $stream */
    private function assertLockIdentity($stream, string $path): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named) || is_link($path)
            || ($named['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0777) !== 0600
            || ($named['mode'] & 0777) !== 0600
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1) {
            throw new DomainException('Private Cron lock identity unsafe.');
        }
        foreach (['dev', 'ino', 'mode', 'nlink'] as $field) {
            if ($opened[$field] !== $named[$field]) {
                throw new DomainException('Private Cron lock was replaced.');
            }
        }
    }

    /** @param array<string,int|string|bool|null> $record @param resource $lock */
    private function writeReceipt(string $directory, array $record, $lock): void
    {
        $path = $directory.'/release-poll-status.json';
        // Never replace a symlink, hardlink, weakly protected or unexpected
        // nonregular file, even though receipts never grant update authority.
        clearstatcache(true, $path);
        $prior = @lstat($path);
        if ($prior !== false && (is_link($path)
            || ($prior['mode'] & 0170000) !== 0100000
            || ($prior['mode'] & 0777) !== 0600
            || $prior['nlink'] !== 1 || $prior['size'] > 2048)) {
            throw new DomainException('Existing private Cron receipt is unsafe.');
        }
        $raw = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! is_string($raw) || strlen($raw) > 2048) {
            throw new DomainException('Cron receipt exceeds bounded size.');
        }
        $temp = $directory.'/.release-poll-'.bin2hex(random_bytes(16)).'.tmp';
        $stream = @fopen($temp, 'x');
        if ($stream === false) {
            throw new DomainException('Private Cron receipt cannot be written.');
        }
        try {
            if (! @chmod($temp, 0600)
                || @fwrite($stream, $raw."\n") !== strlen($raw) + 1
                || ! @fflush($stream)
                || (function_exists('fsync') && ! @fsync($stream))) {
                throw new DomainException('Private Cron receipt cannot be persisted.');
            }
        } finally {
            fclose($stream);
        }
        try {
            $this->assertLockIdentity($lock, $directory.'/release-poll.lock');
            clearstatcache(true, $path);
            $now = @lstat($path);
            if (($prior === false) !== ($now === false)
                || ($prior !== false && $now !== false
                    && ($prior['dev'] !== $now['dev'] || $prior['ino'] !== $now['ino']))) {
                throw new DomainException('Cron receipt was replaced during poll.');
            }
            if (! @rename($temp, $path)) {
                throw new DomainException('Private Cron receipt cannot be committed.');
            }
        } finally {
            if (file_exists($temp) && ! is_link($temp)) {
                @unlink($temp);
            }
        }
    }
}
