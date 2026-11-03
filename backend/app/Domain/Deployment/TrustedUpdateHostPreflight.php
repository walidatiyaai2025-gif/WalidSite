<?php

namespace App\Domain\Deployment;

use DomainException;

/**
 * Read-only hosting capability gate before fetching or staging a trusted code
 * release. Never changes code, DB, environment, or site maintenance state.
 *
 * The installer may support other layouts, but this atomic updater does not:
 * unsupported cPanel document roots MUST fail closed before a download.
 */
final class TrustedUpdateHostPreflight
{
    /** @param array<string,string> $paths */
    public function assertReady(array $paths, int $artifactBytes): void
    {
        if ($artifactBytes < 1 || $artifactBytes > 1073741824) {
            throw new DomainException('BLOCKED HOSTING: signed update size is unsupported.');
        }

        foreach (['zip', 'sodium', 'pdo_mysql', 'curl', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new DomainException('BLOCKED HOSTING: missing PHP extension '.$extension.'.');
            }
        }
        foreach (['symlink', 'rename', 'flock'] as $function) {
            if (! function_exists($function)) {
                throw new DomainException('BLOCKED HOSTING: atomic updates require PHP '.$function.'.');
            }
        }

        $release = $this->directory($paths, 'releases');
        $current = $paths['current'] ?? '';
        $publicLink = $paths['public'] ?? '';
        if (dirname($current) !== dirname($release)
            || basename($current) !== 'current'
            || ! is_link($current) || ! is_link($publicLink)
            || readlink($publicLink) !== $current.'/public_html'
            || ! is_writable($release) || ! is_writable(dirname($current))) {
            throw new DomainException('BLOCKED HOSTING: immutable current/public_html symlink layout is required.');
        }
        $previous = realpath($current);
        $public = realpath($publicLink);
        if ($previous === false || $public === false
            || ! $this->within($previous, $release)
            || ! is_dir($previous)
            || $public !== $previous.'/public_html'
            || ! is_file($public.'/index.php')
            || ! is_file($public.'/.htaccess')) {
            throw new DomainException('BLOCKED HOSTING: live document root is not linked to a complete release.');
        }

        $quarantine = $this->directory($paths, 'quarantine');
        $incoming = $this->directory($paths, 'incoming');
        $vault = $this->directory($paths, 'backup_vault');
        $disposable = $this->directory($paths, 'disposable_files');
        $privateData = $this->directory($paths, 'private_data');
        $uploads = $this->directory($paths, 'uploads');

        if (! $this->within($incoming, $quarantine)
            || ! is_writable($incoming) || ! is_writable($quarantine)
            || ! is_writable($vault) || ! is_writable($disposable)
            || $this->overlap($release, $quarantine)
            || $this->overlap($release, $vault)
            || $this->overlap($release, $disposable)
            || $this->overlap($quarantine, $vault)
            || $this->overlap($quarantine, $disposable)
            || $this->overlap($vault, $disposable)
            || $this->overlap($vault, $privateData)
            || $this->overlap($vault, $uploads)
            || $this->overlap($disposable, $privateData)
            || $this->overlap($disposable, $uploads)
            || $this->overlap($quarantine, $privateData)
            || $this->overlap($quarantine, $uploads)
            || $this->overlap($quarantine, $public)
            || $this->overlap($vault, $public)
            || $this->overlap($disposable, $public)) {
            throw new DomainException('BLOCKED HOSTING: code, staging, backups, and user data must stay isolated.');
        }

        $environment = $paths['environment'] ?? '';
        if (! is_file($environment) || is_link($environment)
            || realpath($environment) !== $environment
            || ! is_readable($environment)
            || $this->within($environment, $public)
            || $this->within($environment, $vault)
            || $this->within($environment, $quarantine)
            || $this->within($environment, $release)) {
            throw new DomainException('BLOCKED HOSTING: private installed environment is unsafe.');
        }

        $quarantineDevice = @stat($quarantine);
        $releaseDevice = @stat($release);
        if ($quarantineDevice === false || $releaseDevice === false
            || $quarantineDevice['dev'] !== $releaseDevice['dev']) {
            throw new DomainException('BLOCKED HOSTING: staging and code release must share a filesystem.');
        }

        // Conservative admission headroom: signed ZIP, extracted code, previous
        // release, and private backups. Exact sizes are checked again later.
        // Shared filesystems use a single maximum rather than double-counting.
        $workingBudget = $artifactBytes * 3 + 134217728;
        $vaultBudget = max(67108864, $artifactBytes);
        foreach ([
            [$incoming, $workingBudget],
            [$release, $workingBudget],
            [$vault, $vaultBudget],
        ] as [$directory, $minimum]) {
            $free = @disk_free_space($directory);
            if ($free === false || $free < $minimum) {
                throw new DomainException('BLOCKED HOSTING: insufficient available disk space for recoverable update.');
            }
        }
    }

    /** @param array<string,string> $paths */
    private function directory(array $paths, string $key): string
    {
        $value = $paths[$key] ?? '';
        $resolved = realpath($value);
        if ($resolved === false || $resolved !== $value || is_link($value) || ! is_dir($resolved)) {
            throw new DomainException('BLOCKED HOSTING: '.$key.' must be an existing real directory.');
        }
        return $resolved;
    }

    private function within(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/').'/');
    }

    private function overlap(string $a, string $b): bool
    {
        return $a === $b || $this->within($a, $b) || $this->within($b, $a);
    }
}
