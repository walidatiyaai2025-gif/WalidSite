<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/** Generate private, stable first-run cryptographic state before web middleware. */
final class FirstRunBootstrap
{
    public function prepare(): void
    {
        // CLI test runners intentionally provide their own test APP_KEY.
        // A real HTTP request must still bootstrap securely even if the host
        // incorrectly exported APP_ENV=testing (cPanel may inherit PHP env).
        if (filled(config('app.key')) || (app()->runningInConsole() && app()->environment('testing'))) {
            return;
        }
        config(['app.debug' => false]);

        if (is_file((string) config('site.install_lock_path'))) {
            throw new RuntimeException('Installed application has lost its private APP_KEY.');
        }

        $path = (string) config('site.bootstrap_path');
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path)) || is_link($path)) {
            throw new RuntimeException('First-run private storage is not writable.');
        }

        if (! is_file($path)) {
            // Never expose an empty or partially written final key/token file:
            // a killed PHP worker must leave either no state or complete state.
            // tempnam creates a private file; link() publishes without replacing
            // a state concurrently created by another HTTP worker.
            $staged = @tempnam(dirname($path), '.walidsite-bootstrap-');
            if ($staged === false) {
                throw new RuntimeException('Cannot stage private first-run state.');
            }

            try {
                if (is_link($staged) || ! @chmod($staged, 0600)) {
                    throw new RuntimeException('Cannot protect private first-run state.');
                }

                $payload = json_encode([
                    'key' => 'base64:'.base64_encode(random_bytes(32)),
                    'token' => bin2hex(random_bytes(32)),
                ], JSON_THROW_ON_ERROR).PHP_EOL;
                $stream = @fopen($staged, 'r+b');
                if ($stream === false) {
                    throw new RuntimeException('Cannot write private first-run state.');
                }
                try {
                    $offset = 0;
                    while ($offset < strlen($payload)) {
                        $written = @fwrite($stream, substr($payload, $offset));
                        if ($written === false || $written === 0) {
                            throw new RuntimeException('Incomplete private first-run state.');
                        }
                        $offset += $written;
                    }
                    if (! @fflush($stream) || (function_exists('fsync') && ! @fsync($stream))) {
                        throw new RuntimeException('Cannot flush private first-run state.');
                    }
                } finally {
                    fclose($stream);
                }

                $stagedStat = @lstat($staged);
                if ($stagedStat === false || ($stagedStat['mode'] & 0170000) !== 0100000
                    || ($stagedStat['mode'] & 0077) !== 0 || $stagedStat['nlink'] !== 1) {
                    throw new RuntimeException('Private first-run state staging is unsafe.');
                }
                $published = @link($staged, $path);
                if ($published) {
                    // Keep the original installer permission contract explicit.
                    // The staged inode is already 0600; only chmod a newly
                    // published file, never a preexisting competing worker's.
                    if (! chmod($path, 0600)) {
                        throw new RuntimeException('Cannot protect published first-run state.');
                    }
                } elseif (! is_file($path)) {
                    // Never fall back to a non-atomic fopen on the final path.
                    throw new RuntimeException('Atomic private first-run state is unsupported by this host.');
                }
            } finally {
                if (is_file($staged) && ! is_link($staged)) {
                    @unlink($staged);
                }
            }
        }

        $stateStat = @lstat($path);
        if ($stateStat === false || ($stateStat['mode'] & 0170000) !== 0100000
            || ($stateStat['mode'] & 0077) !== 0 || $stateStat['nlink'] !== 1
            || is_link($path)) {
            throw new RuntimeException('Private first-run state has unsafe file permissions or type.');
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data)
            || ! preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/', (string) ($data['key'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($data['token'] ?? ''))) {
            throw new RuntimeException('First-run cryptographic state is unavailable.');
        }

        Config::set([
            'app.key' => $data['key'],
            'app.debug' => false,
            'session.driver' => 'file',
            'cache.default' => 'file',
            'site.setup_enabled' => true,
            'site.setup_token' => $data['token'],
            'site.bootstrap_mode' => true,
        ]);
    }
}
