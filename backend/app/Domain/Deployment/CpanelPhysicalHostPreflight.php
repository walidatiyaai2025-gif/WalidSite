<?php

namespace App\Domain\Deployment;

use Closure;
use DomainException;
use JsonException;

/**
 * Read-only compatibility check for the *physical* one-extract cPanel layout.
 *
 * This is NOT the symlink deployment preflight, a backup receipt, a signed
 * candidate approval or a release switch. It never makes directories, runs
 * commands, opens the database or writes to the installed site.
 */
final class CpanelPhysicalHostPreflight
{
    /** @param ?Closure(string):int|float|false $diskFree Fixture injection only. */
    public function __construct(private readonly ?Closure $diskFree = null) {}

    /** @return array{state:string,installed_version:string,backup_verified:bool,code_update_performed:bool} */
    public function assertReady(
        string $homeDirectory,
        string $trustedFrontController,
        string $installedVersion,
        int $signedZipBytes,
    ): array {
        if (! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $installedVersion)
            || $signedZipBytes < 1 || $signedZipBytes > 1073741824) {
            throw new DomainException('Unsupported installed version or signed update size.');
        }

        foreach (['zip', 'sodium', 'pdo_mysql', 'curl', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new DomainException('Required physical cPanel updater capability is unavailable.');
            }
        }
        foreach (['rename', 'flock'] as $function) {
            if (! function_exists($function)) {
                throw new DomainException('Atomic private update controls are unavailable.');
            }
        }

        $home = realpath($homeDirectory);
        $public = realpath($homeDirectory.'/public_html');
        $private = realpath($homeDirectory.'/application');
        $trusted = realpath($trustedFrontController);
        clearstatcache(true, $homeDirectory);
        $homeStat = @lstat($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)
            || ! is_array($homeStat)
            || ($homeStat['mode'] & 0170000) !== 0040000
            // A later maintenance lease MUST refuse account homes writable
            // by another local principal. Surface this at read-only preflight
            // instead of claiming the same host is ready to stage code.
            || ($homeStat['mode'] & 0022) !== 0
            || ! is_dir($home) || ! is_writable($home)
            || $public === false || $public !== $home.'/public_html'
            || $private === false || $private !== $home.'/application'
            || ! is_dir($public) || ! is_dir($private)
            || is_link($home.'/public_html') || is_link($home.'/application')
            || $trusted === false || ! is_file($trusted) || is_link($trustedFrontController)) {
            throw new DomainException('Physical cPanel home and trusted document root are unsupported.');
        }

        foreach ([
            $private.'/.env' => 'file',
            $private.'/storage' => 'dir',
            $public.'/uploads' => 'dir',
            $public.'/index.php' => 'file',
            $public.'/.htaccess' => 'file',
        ] as $path => $type) {
            if (is_link($path) || realpath($path) !== $path
                || ($type === 'file' ? ! is_file($path) : ! is_dir($path))) {
                throw new DomainException('Private runtime state or stable public entrypoint is unsafe.');
            }
        }
        // Compare bytes through held, regular, single-link descriptors.
        // A pathname-only hash after is_file()/realpath() may authenticate
        // a replaced or hardlinked front controller rather than the opened
        // immutable source that the release gate intended to inspect.
        $trustedHash = $this->hashPinnedFrontController($trusted);
        $deployedHash = $this->hashPinnedFrontController($public.'/index.php');
        if (! hash_equals($trustedHash, $deployedHash)) {
            throw new DomainException('The live dispatcher is not the immutable trusted cPanel entrypoint.');
        }

        // A damaged or stale maintenance marker means a prior transaction may
        // still be unverified; do not certify readiness for another update.
        $maintenance = $home.'/.walidsite-update-maintenance.json';
        if (file_exists($maintenance) || is_link($maintenance)) {
            throw new DomainException('A cPanel release is already in maintenance or recovery.');
        }

        $releases = $home.'/.walidsite-releases';
        if (file_exists($releases) || is_link($releases)) {
            if (is_link($releases) || ! is_dir($releases)
                || realpath($releases) !== $releases || ! is_writable($releases)) {
                throw new DomainException('Private immutable release root is unsafe.');
            }
        }

        $codeRoot = $private;
        $pointer = $home.'/.walidsite-active-release.json';
        if (file_exists($pointer) || is_link($pointer)) {
            try {
                $state = json_decode(
                    $this->readPinnedIdentityFile($pointer, 512, true),
                    true, 8, JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                throw new DomainException('Private release identity is malformed.');
            }
            if (! is_array($state)
                || array_keys($state) !== ['schema', 'version', 'root']
                || ($state['schema'] ?? null) !== 1
                || ($state['version'] ?? null) !== $installedVersion
                || ! is_string($state['root'] ?? null)
                || ! preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $state['root'])
                || ! str_starts_with($state['root'], '.walidsite-releases/v'.$installedVersion.'-')) {
                throw new DomainException('Private release pointer does not match installed code.');
            }
            $releaseRoot = $home.'/'.$state['root'];
            if (is_link($releaseRoot) || realpath($releaseRoot) !== $releaseRoot
                || ! is_dir($releaseRoot)
                || realpath($releaseRoot.'/application') !== $releaseRoot.'/application') {
                throw new DomainException('Versioned installed release root is unsafe.');
            }
            $codeRoot = $releaseRoot.'/application';
        }

        $versionFile = $codeRoot.'/VERSION';
        if (! hash_equals(
            $installedVersion,
            trim($this->readPinnedIdentityFile($versionFile, 32, false)),
        )) {
            throw new DomainException('Physical code version differs from the verified installed identity.');
        }

        // Physical Setup requires account HOME and public_html as siblings.
        // Leave ample headroom for ZIP + unpacked code + private backup; a
        // failure is not overridden from the browser.
        $free = $this->diskFree !== null
            ? ($this->diskFree)($home)
            : @disk_free_space($home);
        if ($free === false || $free < $signedZipBytes * 3 + 134217728) {
            throw new DomainException('Insufficient private cPanel disk headroom for recoverable update.');
        }

        return [
            'state' => 'hosting_layout_verified',
            'installed_version' => $installedVersion,
            'backup_verified' => false,
            'code_update_performed' => false,
        ];
    }

    /**
     * Hash the exact open inode, never a second mutable pathname.
     * This is a read-only preflight, not authorization to execute the file.
     */
    private function hashPinnedFrontController(string $path): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || realpath($path) !== $path) {
            throw new DomainException('Unsafe cPanel front controller path.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Trusted cPanel front controller cannot be read.');
        }
        try {
            $baseline = @fstat($stream);
            if (! is_array($baseline)
                || ($baseline['mode'] & 0170000) !== 0100000
                || ($baseline['nlink'] ?? 0) !== 1
                || $baseline['size'] < 1 || $baseline['size'] > 1048576
                || ($baseline['mode'] & 0022) !== 0) {
                throw new DomainException('Unsafe cPanel front controller inode.');
            }
            $this->assertPinnedIdentity($stream, $path, $baseline);
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) !== $baseline['size']) {
                throw new DomainException('cPanel front controller changed while hashing.');
            }
            $digest = hash_final($hash);
            $this->assertPinnedIdentity($stream, $path, $baseline);

            return $digest;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Read a release identity from one held, bounded file descriptor. The
     * owner-update read-only preflight must authenticate the SAME inode the
     * cPanel dispatcher and update orchestrator will use later. Never stat
     * one filename and then reopen a replaceable path with file_get_contents.
     *
     * The atomic writer requires a private 0600 pointer; versioned code files
     * may be 0644 but must never be writable by a different host principal.
     */
    private function readPinnedIdentityFile(string $path, int $maximum, bool $private): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || realpath($path) !== $path) {
            throw new DomainException('Unsafe physical release identity path.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Physical release identity is unavailable.');
        }
        try {
            clearstatcache(true, $path);
            $baseline = @fstat($stream);
            if (! is_array($baseline)
                || ($baseline['mode'] & 0170000) !== 0100000
                || ($baseline['nlink'] ?? 0) !== 1
                || $baseline['size'] < 1 || $baseline['size'] > $maximum
                || ($private && ($baseline['mode'] & 0777) !== 0600)
                || (! $private && ($baseline['mode'] & 0022) !== 0)) {
                throw new DomainException('Unsafe physical release identity inode.');
            }
            $this->assertPinnedIdentity($stream, $path, $baseline);
            $contents = @stream_get_contents($stream, $maximum + 1);
            if (! is_string($contents) || strlen($contents) !== $baseline['size']) {
                throw new DomainException('Physical release identity changed during read.');
            }
            $this->assertPinnedIdentity($stream, $path, $baseline);

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     * @param array<string|int,mixed> $baseline
     */
    private function assertPinnedIdentity($stream, string $path, array $baseline): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || is_link($path) || realpath($path) !== $path
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000) {
            throw new DomainException('Physical release identity pathname changed.');
        }
        foreach (['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'] as $field) {
            if ($opened[$field] !== $baseline[$field] || $named[$field] !== $baseline[$field]) {
                throw new DomainException('Physical release identity replaced or changed.');
            }
        }
    }
}
