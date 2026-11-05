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
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)
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
        $trustedHash = hash_file('sha256', $trusted);
        $deployedHash = hash_file('sha256', $public.'/index.php');
        if (! is_string($trustedHash) || ! is_string($deployedHash)
            || ! hash_equals($trustedHash, $deployedHash)) {
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
            if (is_link($pointer) || ! is_file($pointer)
                || filesize($pointer) === false || filesize($pointer) > 512) {
                throw new DomainException('Private release identity is unsafe.');
            }
            try {
                $state = json_decode((string) file_get_contents($pointer), true, 8, JSON_THROW_ON_ERROR);
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
        if (is_link($versionFile) || ! is_file($versionFile)
            || filesize($versionFile) === false || filesize($versionFile) > 32
            || ! hash_equals($installedVersion, trim((string) file_get_contents($versionFile)))) {
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
}
