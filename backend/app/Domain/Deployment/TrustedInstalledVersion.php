<?php

namespace App\Domain\Deployment;

/**
 * Both owner update entrypoints must derive the installed release from the
 * same held, private regular VERSION inode. A configuration value or an
 * inspected pathname alone is never installed-code provenance.
 *
 * This is a read-only identity check, NOT an authorization to apply updates.
 */
final class TrustedInstalledVersion
{
    public function read(string $path): ?string
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        // Reject unsafe types before fopen: opening a named pipe could block.
        if (! is_array($named) || is_link($path)
            || realpath($path) !== $path
            || ($named['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1
            || ($named['mode'] & 0022) !== 0
            || $named['size'] < 1 || $named['size'] > 32) {
            return null;
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }

        try {
            $opened = @fstat($stream);
            if (! is_array($opened)
                || ! $this->stableIdentity($stream, $path, $named)) {
                return null;
            }

            // Exactly one bounded read of the opened inode, not a reopened
            // pathname that might have been exchanged after preflight.
            $bytes = @stream_get_contents($stream, 33);
            if (! is_string($bytes) || strlen($bytes) !== $named['size']
                || ! $this->stableIdentity($stream, $path, $named)) {
                return null;
            }

            $version = trim($bytes);
            return preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version) === 1
                ? $version : null;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param array<string|int,mixed> $baseline */
    private function stableIdentity($stream, string $path, array $baseline): bool
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || is_link($path) || realpath($path) !== $path
            || ($named['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1
            || ($opened['nlink'] ?? 0) !== 1
            || ($named['mode'] & 0022) !== 0
            || ($opened['mode'] & 0022) !== 0) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'] as $field) {
            if (! isset($baseline[$field], $opened[$field], $named[$field])
                || $named[$field] !== $baseline[$field]
                || $opened[$field] !== $baseline[$field]) {
                return false;
            }
        }

        return true;
    }
}
