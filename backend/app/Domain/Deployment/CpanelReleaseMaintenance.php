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
        $home = realpath($homeDirectory);
        if ($home === false || is_link($homeDirectory) || ! is_writable($home)
            || $seconds < 30 || $seconds > 300
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
        } catch (\Throwable $error) {
            fclose($stream);
            @unlink($path);
            throw $error;
        }
        fclose($stream);
        return ['token' => $token, 'expires_at' => $expires];
    }

    public function assertActive(string $homeDirectory, string $token, string $previousRoot): void
    {
        $home = realpath($homeDirectory);
        $path = $home.'/.walidsite-update-maintenance.json';
        if ($home === false || ! is_file($path) || is_link($path)
            || filesize($path) === false || filesize($path) > 1024) {
            throw new DomainException('Private maintenance barrier is unavailable.');
        }
        $marker = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($marker) || array_keys($marker) !== ['schema', 'expires_at', 'previous_root', 'previous_version', 'probe_sha256']
            || $marker['schema'] !== 1
            || ! is_int($marker['expires_at']) || $marker['expires_at'] <= time()
            || $marker['previous_root'] !== $previousRoot
            || ! is_string($marker['probe_sha256'])
            || ! hash_equals($marker['probe_sha256'], hash('sha256', $token))) {
            throw new DomainException('Maintenance lease changed or expired.');
        }
    }

    public function clear(string $homeDirectory, string $token): void
    {
        $home = realpath($homeDirectory);
        if ($home === false) {
            throw new DomainException('Private maintenance root unavailable.');
        }
        $path = $home.'/.walidsite-update-maintenance.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 1024) {
            throw new DomainException('Private maintenance lease unavailable.');
        }
        $marker = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($marker) || ! is_string($marker['probe_sha256'] ?? null)
            || ! hash_equals($marker['probe_sha256'], hash('sha256', $token))) {
            throw new DomainException('Maintenance ownership is invalid.');
        }
        if (! @unlink($path)) {
            throw new RuntimeException('Cannot release private maintenance barrier.');
        }
    }
}
