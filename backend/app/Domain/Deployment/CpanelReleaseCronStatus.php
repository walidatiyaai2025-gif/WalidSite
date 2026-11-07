<?php

namespace App\Domain\Deployment;

use JsonException;

/**
 * Owner dashboard's read-only view of the local Cron poll receipt.
 *
 * This file is NOT an authenticated publisher envelope and MUST NEVER
 * authorize code installation. A modified, stale or unsafe receipt is
 * displayed as unavailable; no update permission is inferred.
 */
final class CpanelReleaseCronStatus
{
    /** @return array{state:string,checked_at:?int,installed_version:?string,available_version:?string,reason:string} */
    public function inspect(string $directory, string $publicDocumentRoot): array
    {
        $absent = [
            'state' => 'UNVERIFIED',
            'checked_at' => null,
            'installed_version' => null,
            'available_version' => null,
            'reason' => 'NO_VERIFIED_RECENT_POLL',
        ];
        $public = realpath($publicDocumentRoot);
        $private = realpath($directory);
        $directoryStat = $private === false ? false : @lstat($directory);
        if ($public === false || $private === false || $private !== $directory
            || ! is_array($directoryStat) || is_link($directory)
            || ($directoryStat['mode'] & 0170000) !== 0040000
            || ($directoryStat['mode'] & 0077) !== 0
            || $public === $private
            || str_starts_with($private.'/', $public.'/')
            || str_starts_with($public.'/', $private.'/')) {
            return $absent;
        }

        $path = $private.'/release-poll-status.json';
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (! is_array($before) || is_link($path)
            || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600
            || ($before['nlink'] ?? 0) !== 1
            || $before['size'] < 1 || $before['size'] > 2048) {
            return $absent;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return $absent;
        }
        try {
            if (! $this->sameInode($stream, $path, $before)) {
                return $absent;
            }
            $raw = @stream_get_contents($stream, 2049);
            if (! is_string($raw) || strlen($raw) !== $before['size']
                || ! $this->sameInode($stream, $path, $before)) {
                return $absent;
            }
        } finally {
            fclose($stream);
        }
        try {
            $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $absent;
        }
        if (! is_array($row)
            || array_keys($row) !== [
                'schema', 'state', 'checked_at', 'installed_version',
                'available_version', 'source_sha', 'reason', 'code_applied',
            ]
            || $row['schema'] !== 1
            || ! in_array($row['state'], ['UP_TO_DATE', 'BLOCKED', 'DEPLOYED'], true)
            || ! is_int($row['checked_at'])
            || $row['checked_at'] > time() || $row['checked_at'] < time() - 3600
            || ! is_string($row['installed_version'])
            || ! $this->version($row['installed_version'])
            || ($row['available_version'] !== null
                && (! is_string($row['available_version']) || ! $this->version($row['available_version'])))
            || ($row['source_sha'] !== null
                && (! is_string($row['source_sha']) || preg_match('/\A[a-f0-9]{40}\z/D', $row['source_sha']) !== 1))
            || ! in_array($row['reason'], [
                'PUBLISHER_CHECK_UNVERIFIED',
                'NO_NEWER_SIGNED_VERSION',
                'VERIFIED_HOST_UPDATE_GATE_REQUIRED',
                'VERIFIED_HOST_CODE_APPLIED',
            ], true)
            || ! is_bool($row['code_applied'])
            || ($row['state'] === 'DEPLOYED') !== ($row['code_applied'] === true)
            || ($row['state'] === 'DEPLOYED'
                && ($row['reason'] !== 'VERIFIED_HOST_CODE_APPLIED'
                    || $row['available_version'] !== $row['installed_version']
                    || $row['source_sha'] === null))
            || ($row['state'] !== 'DEPLOYED'
                && $row['reason'] === 'VERIFIED_HOST_CODE_APPLIED')) {
            return $absent;
        }
        // A "signed" response recorded locally is observation only, not
        // live network attestation at dashboard-render time.
        return [
            'state' => $row['state'],
            'checked_at' => $row['checked_at'],
            'installed_version' => $row['installed_version'],
            'available_version' => $row['available_version'],
            'reason' => $row['reason'],
        ];
    }

    private function version(string $value): bool
    {
        return preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $value) === 1;
    }

    /** @param resource $stream @param array<string|int,mixed> $before */
    private function sameInode($stream, string $path, array $before): bool
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = @fstat($stream);
        if (! is_array($named) || ! is_array($opened) || is_link($path)) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (! isset($before[$key], $opened[$key], $named[$key])
                || $before[$key] !== $named[$key] || $before[$key] !== $opened[$key]) {
                return false;
            }
        }
        return true;
    }
}
