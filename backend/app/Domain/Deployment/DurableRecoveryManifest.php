<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;

/**
 * Exclusively writes a private recovery manifest to one verified inode.
 *
 * A manifest is a restore authority, not a disposable cache entry: do not
 * accept a preexisting name, symlink, hardlink, partial write or unsynced
 * bytes as proof that the snapshot can be recovered.
 */
final class DurableRecoveryManifest
{
    public function write(string $path, string $json): void
    {
        if ($json === '') {
            throw new DomainException('Empty recovery manifest cannot be committed.');
        }

        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot create exclusive recovery manifest.');
        }

        $committed = false;
        try {
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('Cannot protect private recovery manifest.');
            }
            $length = strlen($json);
            $offset = 0;
            while ($offset < $length) {
                $count = fwrite($stream, substr($json, $offset));
                if (! is_int($count) || $count <= 0) {
                    throw new RuntimeException('Private recovery manifest write was interrupted.');
                }
                $offset += $count;
            }
            if (! fflush($stream) || ! @fsync($stream)) {
                throw new RuntimeException('Cannot durably commit private recovery manifest.');
            }

            clearstatcache(true, $path);
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
                || $opened['size'] !== $length || $named['size'] !== $length) {
                throw new DomainException('Private recovery manifest identity changed before commit.');
            }
            $committed = true;
        } finally {
            if (! $committed) {
                clearstatcache(true, $path);
                $opened = @fstat($stream);
                $named = @lstat($path);
                if (is_array($opened) && is_array($named) && ! is_link($path)
                    && ($opened['mode'] & 0170000) === 0100000
                    && ($named['mode'] & 0170000) === 0100000
                    && $opened['dev'] === $named['dev']
                    && $opened['ino'] === $named['ino']
                    && $opened['nlink'] === 1 && $named['nlink'] === 1) {
                    @unlink($path);
                }
            }
            fclose($stream);
        }
    }
}
