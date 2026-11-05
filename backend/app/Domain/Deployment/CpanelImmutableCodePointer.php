<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;
use Throwable;

/**
 * One atomic version-pointer switch on a physical cPanel document root.
 * No live PHP file is overwritten and original .env/storage stay untouched.
 *
 * Requires an installed, independently trusted stable front controller and
 * real owner/maintenance, signed ZIP and recoverable backup proof upstream.
 * Only code-only releases with IDENTICAL migration inventory are eligible.
 */
final class CpanelImmutableCodePointer
{
    /**
     * @param callable():bool $maintenanceReady
     * @param callable():bool $recoveryVerified
     * @param callable():bool $signedCandidateVerified
     * @param callable():bool $healthCheck Must perform a real new-release HTTP health request.
     * @return array{state:string,from:string,to:string,version:string}
     */
    public function activate(
        string $homeDirectory,
        string $candidateDirectory,
        string $version,
        string $sourceSha,
        string $trustedFrontController,
        string $maintenanceToken,
        callable $maintenanceReady,
        callable $recoveryVerified,
        callable $signedCandidateVerified,
        callable $healthCheck,
    ): array {
        $home = realpath($homeDirectory);
        $candidate = realpath($candidateDirectory);
        $front = realpath($trustedFrontController);
        if ($home === false || $candidate === false || $front === false
            || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version)
            || ! preg_match('/\A[a-f0-9]{40}\z/D', $sourceSha)
            || $candidate !== $home.'/.walidsite-releases/v'.$version.'-'.$sourceSha
            || is_link($candidateDirectory) || is_link($homeDirectory)
            || ! is_dir($home.'/application') || is_link($home.'/application')
            || ! is_file($home.'/application/.env') || is_link($home.'/application/.env')
            || ! is_dir($home.'/application/storage') || is_link($home.'/application/storage')
            || ! is_dir($home.'/public_html') || is_link($home.'/public_html')
            || ! is_writable($home)
            || ! is_file($home.'/public_html/index.php')
            || is_link($home.'/public_html/index.php')
            || ! is_file($front) || is_link($trustedFrontController)
            || ! hash_equals(hash_file('sha256', $front), hash_file('sha256', $home.'/public_html/index.php'))) {
            throw new DomainException('BLOCKED HOSTING: safe cPanel release pointer requires the trusted stable web entrypoint.');
        }

        foreach (['application/bootstrap/app.php','application/vendor/autoload.php','application/VERSION',
            'public_html/index.php','public_html/.htaccess'] as $entry) {
            if (! is_file($candidate.'/'.$entry) || is_link($candidate.'/'.$entry)
                || realpath($candidate.'/'.$entry) !== $candidate.'/'.$entry) {
                throw new DomainException('Untrusted or incomplete immutable release candidate.');
            }
        }
        if (trim((string) file_get_contents($candidate.'/application/VERSION')) !== $version) {
            throw new DomainException('Candidate version differs from signed version.');
        }
        // The active cPanel router and rewrite policy are deliberately stable.
        // A release requiring changed webserver config needs a separate tested
        // host transition; silently ignoring the signed .htaccess is unsafe.
        if (! is_file($home.'/public_html/.htaccess')
            || is_link($home.'/public_html/.htaccess')
            || ! hash_equals((string) hash_file('sha256', $candidate.'/public_html/.htaccess'),
                (string) hash_file('sha256', $home.'/public_html/.htaccess'))
            || ! hash_equals((string) hash_file('sha256', $candidate.'/public_html/index.php'),
                (string) hash_file('sha256', $home.'/public_html/index.php'))) {
            throw new DomainException('Signed release changes the stable cPanel webserver entrypoint.');
        }

        $pointer = $home.'/.walidsite-active-release.json';
        $lockPath = $home.'/.walidsite-active-release.lock';
        if (is_link($pointer) || (file_exists($pointer) && ! is_file($pointer))
            || is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new DomainException('Private release pointer or lock is unsafe.');
        }
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new DomainException('Unable to lock private code pointer.');
        }
        try {
            $this->assertLockIdentity($lock, $lockPath);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another code update is in progress.');
            }
            $this->assertLockIdentity($lock, $lockPath);
            if ($maintenanceReady() !== true || $recoveryVerified() !== true
                || $signedCandidateVerified() !== true) {
                throw new DomainException('Code installation requires write isolation, signed release and restored backup.');
            }
            $this->assertLockIdentity($lock, $lockPath);

            $previous = $this->read($pointer, $home);
            $oldRoot = $previous === null ? $home : $home.'/'.$previous['root'];
            if (! is_dir($oldRoot) || is_link($oldRoot)
                || ! is_file($oldRoot.'/application/VERSION')) {
                throw new DomainException('Previous installed release cannot be identified.');
            }
            // A real private, expiring, one-owner update barrier is mandatory,
            // not merely a caller returning true from a maintenance callback.
            $previousRoot = $previous === null ? 'legacy' : $previous['root'];
            (new CpanelReleaseMaintenance())->assertActive($home, $maintenanceToken, $previousRoot);
            $oldVersion = trim((string) file_get_contents($oldRoot.'/application/VERSION'));
            if (! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $oldVersion)
                || version_compare($version, $oldVersion, '<=')) {
                throw new DomainException('The signed release must be newer than installed code.');
            }

            // Migrations are not modified by a code-only update; database
            // rollback of arbitrary migrations is never assumed safe.
            $schema = new ReleaseSchemaCompatibility();
            if ($schema->inventory($oldRoot) !== $schema->inventory($candidate)) {
                throw new DomainException('Changed migrations require a separately tested database upgrade.');
            }
            // The archive-only check is advisory. Revalidate the physical
            // dependency graph at the final trusted cutover entrypoint too.
            $this->assertComposerParity($oldRoot, $candidate);
            // Preload only non-executable static assets before pointer switch.
            // Public name collisions with different bytes fail closed.
            $this->preloadImmutableAssets($candidate.'/public_html', $home.'/public_html');
            (new CpanelReleaseMaintenance())->assertActive($home, $maintenanceToken, $previousRoot);
            if ($maintenanceReady() !== true || $signedCandidateVerified() !== true) {
                throw new DomainException('Maintenance or candidate identity changed during update.');
            }
            $this->assertLockIdentity($lock, $lockPath);
            // Maintenance and candidate callbacks may have changed files
            // after the first check. Never publish a different runtime graph
            // or a candidate with a different migration inventory.
            $this->assertComposerParity($oldRoot, $candidate);
            if ($schema->inventory($oldRoot) !== $schema->inventory($candidate)) {
                throw new DomainException('Migrations changed before code-only release cutover.');
            }

            $next = ['schema' => 1, 'version' => $version,
                'root' => '.walidsite-releases/v'.$version.'-'.$sourceSha];
            $this->replace($pointer, $next);
            try {
                if ($healthCheck() !== true || $maintenanceReady() !== true) {
                    throw new RuntimeException('New release failed health validation.');
                }
                $this->assertLockIdentity($lock, $lockPath);
                // The health probe is also an external callback. If it
                // changes runtime dependencies, restore the old pointer
                // rather than reporting the unverified code as installed.
                $this->assertComposerParity($oldRoot, $candidate);
                // The HTTP health callback can also change migration sources;
                // never certify a code-only release against an obsolete
                // pre-health inventory.
                if ($schema->inventory($oldRoot) !== $schema->inventory($candidate)) {
                    throw new RuntimeException('Migration inventory changed during health verification.');
                }
                // The HTTP health callback may have altered non-Composer PHP
                // code while the new pointer was provisionally active. Recheck
                // the complete signed candidate identity before accepting it.
                if ($signedCandidateVerified() !== true) {
                    throw new RuntimeException('Signed release identity changed during health verification.');
                }
                // The HTTP health probe may consume the last seconds of the
                // private maintenance lease. Never return "switched" after
                // its expiration: the stable dispatcher would serve old code
                // until the marker is cleared, exposing unverified new code.
                (new CpanelReleaseMaintenance())->assertActive(
                    $home, $maintenanceToken, $previousRoot,
                );
                // External callbacks are not entitled to replace our private
                // pointer or lock. A successful health response alone is not
                // proof that the proposed release remains selected.
                $this->assertLockIdentity($lock, $lockPath);
                if ($this->read($pointer, $home) !== $next) {
                    throw new RuntimeException('Active release pointer changed during health verification.');
                }
            } catch (Throwable $failure) {
                // Rollback is safe only while the updater still owns both
                // the original lock inode and its provisional pointer.
                // Otherwise a blind replace/unlink could destroy another
                // update. Preserve the evidence for supervised recovery.
                try {
                    $this->assertLockIdentity($lock, $lockPath);
                    if ($this->read($pointer, $home) !== $next) {
                        throw new DomainException('Active release pointer ownership was lost.');
                    }
                } catch (Throwable $ownershipFailure) {
                    throw new RuntimeException(
                        'Release ownership changed; manual recovery required before further updates.',
                        0, $ownershipFailure,
                    );
                }
                if ($previous === null) {
                    if (! @unlink($pointer)) {
                        throw new RuntimeException('Automatic code pointer rollback failed.', 0, $failure);
                    }
                } else {
                    $this->replace($pointer, $previous);
                }
                throw new RuntimeException('New release health failed; old code pointer restored.', 0, $failure);
            }
            return ['state'=>'switched','from'=>$oldVersion,'to'=>$next['root'],'version'=>$version];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A physical code-only cutover must not change Composer requirements or
     * dependency lockfile. A different graph needs its own tested full-runtime
     * upgrade path; a signed archive compatibility result is not sufficient.
     */
    private function assertComposerParity(string $installedRoot, string $candidateRoot): void
    {
        foreach (['composer.json' => 1048576, 'composer.lock' => 8388608] as $file => $maximumBytes) {
            $relative = '/application/'.$file;
            $old = $this->composerDigest($installedRoot.$relative, $maximumBytes);
            $next = $this->composerDigest($candidateRoot.$relative, $maximumBytes);
            if (! hash_equals($old, $next)) {
                throw new DomainException('Changed Composer dependencies require a verified full-runtime upgrade.');
            }
        }
    }

    /**
     * Read a bounded, real, single-link Composer metadata file without
     * trusting a symlink, hard-link alias, or a swapped pathname after open.
     */
    private function composerDigest(string $path, int $maximumBytes): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
            throw new DomainException('Unsafe Composer dependency identity at release cutover.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Unreadable Composer dependency metadata at release cutover.');
        }
        try {
            $opened = @fstat($stream);
            if (! is_array($opened)
                || ($opened['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1
                || $opened['size'] < 1 || $opened['size'] > $maximumBytes) {
                throw new DomainException('Unsupported Composer dependency metadata at release cutover.');
            }
            $contents = stream_get_contents($stream);
            clearstatcache(true, $path);
            $named = @lstat($path);
            if (! is_string($contents) || strlen($contents) !== $opened['size']
                || ! is_array($named)
                || ($named['mode'] & 0170000) !== 0100000
                || $named['nlink'] !== 1 || is_link($path)
                || $named['dev'] !== $opened['dev']
                || $named['ino'] !== $opened['ino']
                || realpath($path) !== $path
                || ! is_array(json_decode($contents, true))) {
                throw new DomainException('Composer dependency identity changed during release cutover.');
            }

            return hash('sha256', $contents);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Match the exclusively locked descriptor to the one-link regular file at
     * its expected private pathname. Reject hard-link aliases and pathname
     * replacement, including changes made inside maintenance/health callbacks.
     *
     * @param resource $stream
     */
    private function assertLockIdentity($stream, string $path): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || $opened['dev'] !== $named['dev']
            || $opened['ino'] !== $named['ino']
            || is_link($path)) {
            throw new DomainException('Unsafe private code pointer lock identity; no release may be activated.');
        }
    }

    /**
     * Copy-once versioned static assets, never replacing existing web files.
     * A failed update may leave harmless, immutable new assets, but never
     * overwrites the previous frontend or public PHP files.
     */
    private function preloadImmutableAssets(string $sourceRoot, string $publicRoot): void
    {
        if (! is_dir($sourceRoot) || is_link($sourceRoot)) {
            throw new DomainException('Missing trusted public asset inventory.');
        }
        $count = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new DomainException('Linked release assets are forbidden.');
            }
            $relative = substr($entry->getPathname(), strlen($sourceRoot) + 1);
            if (! preg_match('#\A(?:[a-zA-Z0-9_.@+-]+/)*[a-zA-Z0-9_.@+-]+\z#D', $relative)
                || strlen($relative) > 450 || str_contains($relative, '..')) {
                throw new DomainException('Unsafe release asset path.');
            }
            if ($entry->isDir()) {
                continue;
            }
            if (! $entry->isFile()) {
                throw new DomainException('Unsupported public asset entry.');
            }
            if (in_array($relative, ['index.php', '.htaccess'], true)) {
                continue;
            }
            if (! (preg_match('/\.(?:css|js|mjs|png|jpe?g|webp|svg|gif|ico|woff2?|ttf|pdf|txt|xml|json|map|webmanifest|md)$/iD', $relative)
                    || basename($relative) === 'LICENSE')
                || ++$count > 5000) {
                throw new DomainException('Executable or oversized public release inventory.');
            }
            $size = filesize($entry->getPathname());
            if ($size === false || $size > 134217728 || ($bytes += $size) > 536870912) {
                throw new DomainException('Release asset size exceeds hosting limits.');
            }
            $target = $publicRoot.'/'.$relative;
            $parent = dirname($target);
            // Validate every ancestor BEFORE creating nested directories:
            // recursive mkdir would otherwise follow an in-root symlink and
            // write into a different location even when the final check fails.
            $this->ensurePhysicalAssetParent($publicRoot, $parent);
            clearstatcache(true, $parent);
            if (is_link($target) || realpath($parent) !== $parent) {
                throw new DomainException('Asset destination is linked or escapes web root.');
            }
            $sourceHash = hash_file('sha256', $entry->getPathname());
            if (! is_string($sourceHash)) {
                throw new DomainException('Cannot verify new asset hash.');
            }
            if (is_file($target)) {
                if (! hash_equals($sourceHash, (string) hash_file('sha256', $target))) {
                    throw new DomainException('Existing public asset collision; refusing overwrite.');
                }
                continue;
            }
            if (file_exists($target)) {
                throw new DomainException('Asset destination is not a regular file.');
            }
            $temporary = $parent.'/.new-static-'.bin2hex(random_bytes(12));
            if (! @copy($entry->getPathname(), $temporary) || ! @chmod($temporary, 0644)
                || ! hash_equals($sourceHash, (string) hash_file('sha256', $temporary))) {
                @unlink($temporary);
                throw new RuntimeException('Failed staging immutable public asset.');
            }
            // rename() overwrites an existing target on POSIX. Publish
            // atomically without replacement: another writer creating the
            // path between the existence check and activation must not lose
            // its file. cPanel/Linux supports same-directory hard links.
            try {
                if (! @link($temporary, $target)) {
                    throw new RuntimeException('Unable to publish immutable asset without overwriting an existing file.');
                }
            } finally {
                @unlink($temporary);
            }
        }
    }

    /**
     * Create new public directories one segment at a time, rejecting links
     * before following them. The web root is an already verified real path.
     */
    private function ensurePhysicalAssetParent(string $publicRoot, string $parent): void
    {
        if (! str_starts_with($parent.'/', $publicRoot.'/')) {
            throw new DomainException('Asset destination escapes web root.');
        }
        $relative = substr($parent, strlen($publicRoot) + 1);
        $current = $publicRoot;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/'.$segment;
            clearstatcache(true, $current);
            if (is_link($current) || (file_exists($current) && ! is_dir($current))) {
                throw new DomainException('Asset destination is linked or escapes web root.');
            }
            if (! is_dir($current) && ! @mkdir($current, 0755)) {
                throw new RuntimeException('Cannot stage public asset directory.');
            }
            if (realpath($current) !== $current) {
                throw new DomainException('Asset destination is linked or escapes web root.');
            }
        }
    }

    /** @return array{schema:int,version:string,root:string}|null */
    private function read(string $path, string $home): ?array
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path)) {
            throw new DomainException('Invalid private release pointer.');
        }
        // A release pointer is security-sensitive control data. Read exactly
        // one opened regular inode, never stat one path and later reopen it
        // via file_get_contents after an attacker can swap the filename.
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Cannot securely open private release pointer.');
        }
        try {
            $opened = @fstat($stream);
            if (! is_array($opened)
                || ($opened['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1
                || $opened['size'] < 1 || $opened['size'] > 512) {
                throw new DomainException('Unsafe private release pointer inode.');
            }
            $this->assertPointerReadIdentity($stream, $path, $opened);
            $contents = stream_get_contents($stream, 513);
            if (! is_string($contents) || strlen($contents) !== $opened['size']) {
                throw new DomainException('Private release pointer changed during read.');
            }
            $this->assertPointerReadIdentity($stream, $path, $opened);
        } finally {
            fclose($stream);
        }
        $json = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($json) || array_keys($json) !== ['schema','version','root']
            || $json['schema'] !== 1
            || ! is_string($json['version']) || ! is_string($json['root'])
            || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $json['version'])
            || ! preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $json['root'])
            || ! str_starts_with($json['root'], '.walidsite-releases/v'.$json['version'].'-')
            || realpath($home.'/'.$json['root']) !== $home.'/'.$json['root']) {
            throw new DomainException('Invalid or missing private active release.');
        }
        return $json;
    }

    /**
     * @param resource $stream
     * @param array<string|int,mixed> $baseline
     */
    private function assertPointerReadIdentity($stream, string $path, array $baseline): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || is_link($path) || realpath($path) !== $path
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) {
            throw new DomainException('Private release pointer pathname or inode changed during read.');
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($opened[$field] !== $baseline[$field] || $named[$field] !== $baseline[$field]) {
                throw new DomainException('Private release pointer changed during read.');
            }
        }
    }

    /** @param array{schema:int,version:string,root:string} $value */
    private function replace(string $path, array $value): void
    {
        $temporary = dirname($path).'/.release-switch-'.bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot prepare exclusive private release pointer.');
        }
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! @chmod($temporary, 0600) || fwrite($stream, $json) !== strlen($json)
                || ! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new RuntimeException('Cannot write safe private pointer.');
            }
        } finally {
            fclose($stream);
        }
        try {
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Atomic release pointer replacement failed.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
