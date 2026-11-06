<?php

namespace App\Domain\Deployment;

use JsonException;

/**
 * Provenance of the *running* code across chained cPanel releases.
 *
 * SITE_TRUSTED_INSTALLED_VERSION is the first-install trust pin and is NOT
 * rewritten after a signed immutable code switch. For a later update, accept
 * the running version only when the trusted original installation and the
 * private stable-dispatcher pointer form an independently attested lineage.
 *
 * No request fields, URLs or environment version claims select executable code.
 * This is identity discovery, never permission to install an update.
 */
final class TrustedInstalledReleasePin
{
    public function read(string $runningApplication, string $publicDocumentRoot, mixed $firstInstalledVersion): ?string
    {
        if (! is_string($firstInstalledVersion)
            || preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $firstInstalledVersion) !== 1) {
            return null;
        }

        $running = realpath($runningApplication);
        $public = realpath($publicDocumentRoot);
        if ($running === false || $public === false || $running !== $runningApplication
            || $public !== $publicDocumentRoot
            || is_link($runningApplication) || is_link($publicDocumentRoot)) {
            return null;
        }

        $versionReader = new TrustedInstalledVersion();
        $deployed = $versionReader->read($running.'/VERSION');
        if ($deployed === null) {
            return null;
        }

        $home = dirname($public);
        $pointer = $home.'/.walidsite-active-release.json';
        if (! file_exists($pointer) && ! is_link($pointer)) {
            // Existing source/first-install behavior remains strictly pinned.
            // A missing pointer cannot be used to justify a version drift.
            return hash_equals($firstInstalledVersion, $deployed) ? $deployed : null;
        }

        // The stable front controller boots release code from exactly one
        // physical cPanel public_html. It retains the ORIGINAL installation
        // in HOME/application for .env, storage and the publisher trust pin.
        if ($public !== $home.'/public_html'
            || is_link($home) || is_link($home.'/application')
            || is_link($home.'/.walidsite-releases')
            || ! is_dir($home.'/.walidsite-releases')
            || realpath($home.'/.walidsite-releases') !== $home.'/.walidsite-releases'
            || ! $this->privateReleaseDirectory($home.'/.walidsite-releases')
            || ! hash_equals($firstInstalledVersion,
                $versionReader->read($home.'/application/VERSION') ?? '')) {
            return null;
        }

        // An interrupted cutover is never a valid owner update starting point.
        if (file_exists($home.'/.walidsite-update-maintenance.json')
            || is_link($home.'/.walidsite-update-maintenance.json')) {
            return null;
        }

        $raw = $this->readPinnedPointer($pointer);
        if ($raw === null) {
            return null;
        }
        try {
            $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($state)
            || array_keys($state) !== ['schema', 'version', 'root']
            || $state['schema'] !== 1
            || $state['version'] !== $deployed
            || ! is_string($state['root'])
            || preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $state['root']) !== 1
            || ! str_starts_with($state['root'], '.walidsite-releases/v'.$deployed.'-')) {
            return null;
        }

        $release = $home.'/'.$state['root'];
        if (! $this->privateReleaseDirectory($release)
            || is_link($release.'/application')
            || realpath($release.'/application') !== $running) {
            return null;
        }
        // Even a valid pointer cannot authorize code that is not ACTUALLY
        // booted. The stable dispatcher must already have selected it.
        return $deployed;
    }

    private function privateReleaseDirectory(string $path): bool
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        return is_array($info)
            && ($info['mode'] & 0170000) === 0040000
            && ($info['mode'] & 0022) === 0
            && ! is_link($path)
            && realpath($path) === $path;
    }

    /**
     * Keep ONE file descriptor for the 0600 private active pointer. Checking
     * lstat and then reopening with file_get_contents would trust an exchanged
     * inode or a hard-linked alias.
     */
    private function readPinnedPointer(string $path): ?string
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (! is_array($before)
            || is_link($path)
            || realpath($path) !== $path
            || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600
            || ($before['nlink'] ?? 0) !== 1
            || ! is_int($before['size']) || $before['size'] < 1 || $before['size'] > 512) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            if (! $this->sameFile($stream, $path, $before)) {
                return null;
            }
            $bytes = @stream_get_contents($stream, 513);
            if (! is_string($bytes) || strlen($bytes) !== $before['size']
                || ! $this->sameFile($stream, $path, $before)) {
                return null;
            }
            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param array<string|int,mixed> $baseline */
    private function sameFile($stream, string $path, array $baseline): bool
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = @fstat($stream);
        if (! is_array($named) || ! is_array($opened) || is_link($path)) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'] as $field) {
            if (! isset($named[$field], $opened[$field], $baseline[$field])
                || $named[$field] !== $baseline[$field]
                || $opened[$field] !== $baseline[$field]) {
                return false;
            }
        }
        return true;
    }
}
