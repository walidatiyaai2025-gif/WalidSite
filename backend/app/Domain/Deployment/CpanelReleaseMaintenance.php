<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;

/**
 * Private short-lived HTTP barrier for cPanel updates.
 * A trusted orchestrator creates the lease after stopping background writers
 * and proving backups; only the random probe token may reach the new code
 * while the release pointer is being verified. No token is logged or served.
 *
 * After expiration the stable dispatcher serves the PREVIOUS code, even if
 * PHP crashed after switching the pointer. It never runs an unverified PHP
 * release just because a maintenance request died mid-flight.
 */
final class CpanelReleaseMaintenance
{
    /**
     * @return array{token:string,expires_at:int}
     */
    public function begin(string $homeDirectory, string $previousRoot, string $previousVersion, int $seconds = 180): array
    {
        $homeIdentity = $this->privateHome($homeDirectory);
        $home = $homeIdentity['path'];
        if ($seconds < 30 || $seconds > 300
            || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $previousVersion)
            || ($previousRoot !== 'legacy'
                && ! preg_match('#\A\.walidsite-releases/v'.preg_quote($previousVersion, '#').'-[a-f0-9]{40}\z#D', $previousRoot))) {
            throw new DomainException('Cannot enter safe cPanel release maintenance.');
        }

        $path = $home.'/.walidsite-update-maintenance.json';
        if (file_exists($path) || is_link($path)) {
            throw new DomainException('Another recovery/maintenance lease already exists.');
        }
        $token = bin2hex(random_bytes(32));
        $expires = time() + $seconds;
        $marker = [
            'schema' => 1,
            'expires_at' => $expires,
            'previous_root' => $previousRoot,
            'previous_version' => $previousVersion,
            'probe_sha256' => hash('sha256', $token),
        ];
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Unable to create private update barrier.');
        }
        try {
            $json = json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! @chmod($path, 0600) || fwrite($stream, $json) !== strlen($json)
                || ! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new RuntimeException('Cannot durably create update barrier.');
            }
            // A private lease is valid only when its actual open inode and
            // named inode are identical, single-linked and mode 0600. Never
            // issue a probe token if another account principal could replace
            // its parent directory while the marker was being written.
            $this->assertHomeUnchanged($homeDirectory, $homeIdentity);
            $opened = @fstat($stream);
            $named = @lstat($path);
            if (! is_array($opened) || ! is_array($named) || is_link($path)
                || ($opened['mode'] & 0170000) !== 0100000
                || ($named['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0777) !== 0600
                || ($named['mode'] & 0777) !== 0600
                || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || $opened['dev'] !== $named['dev']
                || $opened['ino'] !== $named['ino']
                || $opened['size'] !== strlen($json)
                || $named['size'] !== strlen($json)) {
                throw new DomainException('Private maintenance lease changed during creation.');
            }
            $this->assertHomeUnchanged($homeDirectory, $homeIdentity);
        } catch (\Throwable $error) {
            // Do not unlink an unrelated file swapped into the pathname by
            // a concurrent actor. Keep the fail-closed marker for recovery.
            $opened = @fstat($stream);
            $named = @lstat($path);
            fclose($stream);
            if (is_array($opened) && is_array($named) && ! is_link($path)
                && $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino']
                && $named['nlink'] === 1) {
                @unlink($path);
            }
            throw $error;
        }
        fclose($stream);
        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * Resolve a cPanel account home only when other hosting principals
     * cannot write its root. Mode 0711/0750 is supported; group/other write
     * is never an acceptable boundary for a secret maintenance lease.
     *
     * @return array{path:string,dev:int,ino:int,mode:int,uid:int}
     */
    private function privateHome(string $homeDirectory): array
    {
        clearstatcache(true, $homeDirectory);
        $details = @lstat($homeDirectory);
        $resolved = realpath($homeDirectory);
        if (! is_array($details) || $resolved === false
            || $resolved !== $homeDirectory || is_link($homeDirectory)
            || ! is_dir($resolved) || ! is_writable($resolved)
            || ($details['mode'] & 0170000) !== 0040000
            || ($details['mode'] & 0022) !== 0) {
            throw new DomainException('Private cPanel account home permissions or identity are unsafe.');
        }
        return [
            'path' => $resolved,
            'dev' => (int) $details['dev'],
            'ino' => (int) $details['ino'],
            'mode' => (int) $details['mode'],
            'uid' => (int) $details['uid'],
        ];
    }

    /** @param array{path:string,dev:int,ino:int,mode:int,uid:int} $baseline */
    private function assertHomeUnchanged(string $homeDirectory, array $baseline): void
    {
        if ($this->privateHome($homeDirectory) !== $baseline) {
            throw new DomainException('Private cPanel account home changed during maintenance.');
        }
    }

    /**
     * Read the maintenance marker through one pinned regular-file descriptor.
     * A hard link, loosened permissions or changed pathname must never prove
     * that the updater still owns the private release barrier.
     *
     * @return array{string, mixed, array<string, int>, array{path:string,dev:int,ino:int,mode:int,uid:int}}
     */
    private function readPrivateMarker(string $homeDirectory): array
    {
        $homeIdentity = $this->privateHome($homeDirectory);
        $home = $homeIdentity['path'];
        $path = $home.'/.walidsite-update-maintenance.json';
        if (is_link($path)) {
            throw new DomainException('Private maintenance lease unavailable.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Private maintenance lease unavailable.');
        }

        try {
            clearstatcache(true, $path);
            $opened = fstat($stream);
            $named = @lstat($path);
            if ($opened === false || $named === false
                || ($opened['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0777) !== 0600
                || $opened['nlink'] !== 1
                || $opened['size'] < 1 || $opened['size'] > 1024) {
                throw new DomainException('Unsafe private maintenance lease.');
            }
            $fields = ['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'];
            foreach ($fields as $field) {
                if ($opened[$field] !== $named[$field]) {
                    throw new DomainException('Private maintenance lease pathname changed.');
                }
            }
            $raw = stream_get_contents($stream, 1025);
            if ($raw === false || strlen($raw) !== $opened['size']) {
                throw new DomainException('Private maintenance lease contents changed.');
            }
            clearstatcache(true, $path);
            $after = fstat($stream);
            $namedAfter = @lstat($path);
            if ($after === false || $namedAfter === false) {
                throw new DomainException('Private maintenance lease disappeared.');
            }
            foreach ($fields as $field) {
                if ($opened[$field] !== $after[$field]
                    || $opened[$field] !== $namedAfter[$field]) {
                    throw new DomainException('Private maintenance lease changed during read.');
                }
            }
            $this->assertHomeUnchanged($homeDirectory, $homeIdentity);
            return [$path, json_decode($raw, true, 8, JSON_THROW_ON_ERROR), $opened, $homeIdentity];
        } finally {
            fclose($stream);
        }
    }

    public function assertActive(string $homeDirectory, string $token, string $previousRoot): void
    {
        [, $marker] = $this->readPrivateMarker($homeDirectory);
        if (! is_array($marker) || array_keys($marker) !== ['schema', 'expires_at', 'previous_root', 'previous_version', 'probe_sha256']
            || $marker['schema'] !== 1
            || ! is_int($marker['expires_at']) || $marker['expires_at'] <= time()
            || $marker['previous_root'] !== $previousRoot
            || ! is_string($marker['probe_sha256'])
            || ! hash_equals($marker['probe_sha256'], hash('sha256', $token))) {
            throw new DomainException('Maintenance lease changed or expired.');
        }
    }

    /**
     * Finalize a successful cutover only while its private lease is LIVE.
     * Expired markers stay in place as a fail-closed recovery barrier; never
     * clear one just because a token still matches: that could expose a
     * switched but no-longer-verified release in the stable web dispatcher.
     * Recovery of an expired marker requires independently proven rollback.
     */
    public function clear(string $homeDirectory, string $token): void
    {
        [$path, $marker, $identity, $homeIdentity] = $this->readPrivateMarker($homeDirectory);
        if (! is_array($marker)
            || array_keys($marker) !== ['schema', 'expires_at', 'previous_root', 'previous_version', 'probe_sha256']
            || $marker['schema'] !== 1
            || ! is_int($marker['expires_at']) || $marker['expires_at'] <= time()
            || ! is_string($marker['previous_root'])
            || ! is_string($marker['previous_version'])
            || ! is_string($marker['probe_sha256'])
            || ! hash_equals($marker['probe_sha256'], hash('sha256', $token))) {
            throw new DomainException('Maintenance ownership is invalid or lease expired; recovery required.');
        }
        // Fail closed if the named marker was replaced after descriptor read.
        // The cPanel account directory must remain private to the PHP owner:
        // PHP has no portable unlink-by-descriptor primitive for a hostile
        // account-level writer concurrently replacing this pathname.
        $this->assertHomeUnchanged($homeDirectory, $homeIdentity);
        clearstatcache(true, $path);
        $named = @lstat($path);
        if ($named === false
            || $named['dev'] !== $identity['dev']
            || $named['ino'] !== $identity['ino']
            || $named['nlink'] !== 1
            || ($named['mode'] & 0777) !== 0600) {
            throw new DomainException('Maintenance barrier identity changed; recovery required.');
        }
        if (! @unlink($path)) {
            throw new RuntimeException('Cannot release private maintenance barrier.');
        }
    }
}
