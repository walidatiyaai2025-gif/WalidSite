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
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another code update is in progress.');
            }
            if ($maintenanceReady() !== true || $recoveryVerified() !== true
                || $signedCandidateVerified() !== true) {
                throw new DomainException('Code installation requires write isolation, signed release and restored backup.');
            }

            $previous = $this->read($pointer, $home);
            $oldRoot = $previous === null ? $home : $home.'/'.$previous['root'];
            if (! is_dir($oldRoot) || is_link($oldRoot)
                || ! is_file($oldRoot.'/application/VERSION')) {
                throw new DomainException('Previous installed release cannot be identified.');
            }
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
            if ($maintenanceReady() !== true || $signedCandidateVerified() !== true) {
                throw new DomainException('Maintenance or candidate identity changed during update.');
            }

            $next = ['schema' => 1, 'version' => $version,
                'root' => '.walidsite-releases/v'.$version.'-'.$sourceSha];
            $this->replace($pointer, $next);
            try {
                if ($healthCheck() !== true || $maintenanceReady() !== true) {
                    throw new RuntimeException('New release failed health validation.');
                }
            } catch (Throwable $failure) {
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

    /** @return array{schema:int,version:string,root:string}|null */
    private function read(string $path, string $home): ?array
    {
        if (! file_exists($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path) || filesize($path) === false
            || filesize($path) > 512) {
            throw new DomainException('Invalid private release pointer.');
        }
        $json = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($json) || array_keys($json) !== ['schema','version','root']
            || $json['schema'] !== 1
            || ! is_string($json['version']) || ! is_string($json['root'])
            || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $json['version'])
            || ! preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $json['root'])
            || realpath($home.'/'.$json['root']) !== $home.'/'.$json['root']) {
            throw new DomainException('Invalid or missing private active release.');
        }
        return $json;
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
