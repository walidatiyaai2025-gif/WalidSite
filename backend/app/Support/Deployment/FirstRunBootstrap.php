<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/** Generate private, stable first-run cryptographic state before web middleware. */
final class FirstRunBootstrap
{
    public function prepare(): void
    {
        if (filled(config('app.key')) || app()->environment('testing')) {
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
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                chmod($path, 0600);
                fwrite($handle, json_encode([
                    'key' => 'base64:'.base64_encode(random_bytes(32)),
                    'token' => bin2hex(random_bytes(32)),
                ], JSON_THROW_ON_ERROR).PHP_EOL);
                fclose($handle);
            }
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
