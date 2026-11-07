<?php

namespace App\Domain\Deployment;

use DomainException;
use JsonException;

/**
 * Private, permanent cPanel Cron launcher's immutable-code selector.
 *
 * The initial HOME/application/cron/release-poll.php stays at its physical
 * first-install path after code-only releases. The launcher must bootstrap
 * the code actually selected by the stable public_html/index.php dispatcher,
 * not stale original PHP. This selector is installed at first setup and
 * therefore intentionally stays simple, dependency-free and fail closed.
 *
 * An already held SAME-HOME shared CpanelConsoleWriterLease is mandatory to
 * stop an authorized code pointer switch during selection and Laravel boot.
 * External writers / same-account hostile filesystem writes remain hosting
 * prerequisites, not a promise this code can establish independently.
 */
final class CpanelCronReleaseSelector
{
    public function select(
        string $original,
        string $publicDocumentRoot,
        CpanelConsoleWriterLease $sharedLease,
    ): string {
        $sharedLease->assertSharedHeld();

        $home = dirname($original);
        $public = realpath($publicDocumentRoot);
        if (is_link($home) || is_link($original)
            || is_link($publicDocumentRoot)
            || realpath($home) !== $home
            || realpath($original) !== $original
            || $original !== $home.'/application'
            || $public === false || $public !== $publicDocumentRoot
            || $public !== $home.'/public_html'
            || ! is_dir($original) || ! is_dir($public)
            || ! is_file($original.'/.env') || is_link($original.'/.env')
            || ! is_file($original.'/storage/app/site-installed.lock')
            || is_link($original.'/storage/app/site-installed.lock')) {
            throw new DomainException('Cron requires the physical installed cPanel roots.');
        }

        // This is immutable first-install lineage, not the currently selected
        // version. Do not accept env-supplied or HTTP-supplied version strings.
        $firstVersion = (new TrustedInstalledVersion())->read($original.'/VERSION');
        if ($firstVersion === null) {
            throw new DomainException('Original cPanel release identity is unsafe.');
        }
        $maintenance = $home.'/.walidsite-update-maintenance.json';
        if (file_exists($maintenance) || is_link($maintenance)) {
            // Even an expired recovery marker requires supervised recovery.
            throw new DomainException('Cron release selection blocked by maintenance.');
        }

        $pointer = $home.'/.walidsite-active-release.json';
        if (! file_exists($pointer) && ! is_link($pointer)) {
            if ((new TrustedInstalledReleasePin())->read(
                $original, $public, $firstVersion
            ) !== $firstVersion) {
                throw new DomainException('First-installed Cron code is not trusted.');
            }
            $this->assertBootFiles($original);
            $sharedLease->assertSharedHeld();
            return $original;
        }

        $raw = $this->readPrivatePointer($pointer);
        try {
            $row = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Invalid private Cron release pointer.');
        }
        if (! is_array($row)
            || array_keys($row) !== ['schema', 'version', 'root']
            || $row['schema'] !== 1
            || ! is_string($row['version'])
            || preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $row['version']) !== 1
            || version_compare($row['version'], $firstVersion, '<=')
            || ! is_string($row['root'])
            || preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $row['root']) !== 1
            || ! str_starts_with($row['root'], '.walidsite-releases/v'.$row['version'].'-')) {
            throw new DomainException('Cron active release pointer identity is invalid.');
        }

        $releaseBase = $home.'/.walidsite-releases';
        $releaseRoot = $home.'/'.$row['root'];
        $candidate = $releaseRoot.'/application';
        foreach ([$releaseBase, $releaseRoot, $candidate] as $directory) {
            clearstatcache(true, $directory);
            $stat = @lstat($directory);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['mode'] & 0022) !== 0 || is_link($directory)
                || realpath($directory) !== $directory) {
                throw new DomainException('Unsafe immutable Cron release tree.');
            }
        }
        $this->assertBootFiles($candidate);
        $version = (new TrustedInstalledReleasePin())->read(
            $candidate, $public, $firstVersion
        );
        // The existing trusted lineage reader separately checks the original
        // VERSION, pointer inode, maintenance state and canonical active root.
        if ($version !== $row['version']) {
            throw new DomainException('Cron selected code differs from verified live release.');
        }
        $sharedLease->assertSharedHeld();

        // A second pinned read prevents approving a different pointer after
        // the candidate was attested, even if an out-of-contract local writer
        // ignored the cooperating global lease.
        if (! hash_equals($raw, $this->readPrivatePointer($pointer))) {
            throw new DomainException('Cron code pointer changed during selection.');
        }
        return $candidate;
    }

    private function assertBootFiles(string $root): void
    {
        foreach (['vendor/autoload.php', 'bootstrap/app.php', 'VERSION'] as $path) {
            $file = $root.'/'.$path;
            $stat = @lstat($file);
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
                || ($stat['mode'] & 0022) !== 0
                || is_link($file) || realpath($file) !== $file) {
                throw new DomainException('Selected Cron release is not bootable.');
            }
        }
    }

    private function readPrivatePointer(string $path): string
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (! is_array($before) || is_link($path)
            || realpath($path) !== $path
            || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600
            || ($before['nlink'] ?? 0) !== 1
            || $before['size'] < 1 || $before['size'] > 512) {
            throw new DomainException('Private Cron release pointer is unsafe.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Private Cron release pointer unavailable.');
        }
        try {
            if (! $this->sameInode($stream, $path, $before)) {
                throw new DomainException('Private Cron release pointer identity changed.');
            }
            $raw = @stream_get_contents($stream, 513);
            if (! is_string($raw) || strlen($raw) !== $before['size']
                || ! $this->sameInode($stream, $path, $before)) {
                throw new DomainException('Private Cron release pointer was replaced.');
            }
            return $raw;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param array<string|int,mixed> $before */
    private function sameInode($stream, string $path, array $before): bool
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named) || is_link($path)) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (! isset($before[$field], $opened[$field], $named[$field])
                || $before[$field] !== $opened[$field] || $before[$field] !== $named[$field]) {
                return false;
            }
        }
        return true;
    }
}
