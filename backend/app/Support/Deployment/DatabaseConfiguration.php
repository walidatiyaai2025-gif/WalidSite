<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\Artisan;
use PDO;
use PDOException;
use RuntimeException;

class DatabaseConfiguration
{
    /**
     * A verified, empty database only. The wizard never drops preexisting tables.
     */
    public function probe(array $input): bool
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $input['db_host'], (int) $input['db_port'], $input['db_database']),
                $input['db_username'],
                $input['db_password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $pdo->query('SELECT 1')->fetchColumn();
            return $pdo->query('SHOW TABLES')->fetch(PDO::FETCH_NUM) === false;
        } catch (PDOException) {
            return false;
        }
    }

    public function isConfigured(): bool
    {
        return config('site.setup_database_ready') === true;
    }

    /**
     * The canonical URL must be entered by the installer, never inferred from
     * an untrusted HTTP Host header. Keep installations at the domain root:
     * console-generated links must not inherit query strings or credentials.
     */
    public function canonicalSiteUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 255
            || preg_match('/[\\x00-\\x20\\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http'
            && app()->environment(['local', 'testing']) && $isLoopback)) {
            return null;
        }
        // Reject ambiguous or invalid DNS names. IP literals are accepted
        // only when PHP validates them; localhost is for local setup alone.
        $ip = trim($host, '[]');
        $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        // Production canonical links must never point at private or reserved networks.
        if ($isIp && app()->environment('production')
            && filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        if (! $isIp && $host !== 'localhost'
            && preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\\.[a-z0-9]+(?:-[a-z0-9]+)*)+\\z/D', $host) !== 1) {
            return null;
        }
        if ($host === 'localhost' && ! app()->environment(['local', 'testing'])) {
            return null;
        }

        return $scheme.'://'.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function save(array $input, string $appUrl): void
    {
        $appUrl = $this->canonicalSiteUrl($appUrl);
        if ($appUrl === null) {
            throw new RuntimeException('Invalid canonical site URL.');
        }
        $key = (string) config('app.key');
        $token = (string) config('site.setup_token');
        if (! str_starts_with($key, 'base64:') || strlen($token) < 32) {
            throw new RuntimeException('First-run key material is unavailable.');
        }
        $this->write([
            'APP_NAME' => 'WalidSite',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_KEY' => $key,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $input['db_host'],
            'DB_PORT' => (string) $input['db_port'],
            'DB_DATABASE' => $input['db_database'],
            'DB_USERNAME' => $input['db_username'],
            'DB_PASSWORD' => $input['db_password'],
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'SITE_SETUP_ENABLED' => 'true',
            'SITE_SETUP_TOKEN' => $token,
            'SITE_SETUP_DATABASE_READY' => 'true',
            'SITE_UPDATES_ENABLED' => 'true',
            'SITE_AUTO_MIGRATE' => 'false',
        ]);
    }

    public function disableSetup(): void
    {
        $this->write([
            'SITE_SETUP_ENABLED' => 'false',
            'SITE_SETUP_TOKEN' => '',
            'SITE_SETUP_DATABASE_READY' => 'false',
            'APP_DEBUG' => 'false',
        ]);
        $bootstrap = (string) config('site.bootstrap_path');
        if (is_file($bootstrap) && ! is_link($bootstrap)) {
            @unlink($bootstrap);
        }
    }

    private function write(array $fields): void
    {
        $path = (string) config('site.environment_path');
        if (is_link($path) || ! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            throw new RuntimeException('Private application directory is not writable.');
        }
        $text = is_file($path) ? file_get_contents($path) : '';
        if ($text === false) {
            throw new RuntimeException('Cannot read application settings.');
        }
        foreach ($fields as $name => $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new RuntimeException('Invalid environment field.');
            }
            // Preserve quoted DB passwords including spaces, quotes and symbols.
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
            $line = in_array($name, ['APP_DEBUG', 'SITE_SETUP_ENABLED', 'SITE_SETUP_DATABASE_READY', 'SITE_UPDATES_ENABLED', 'SITE_AUTO_MIGRATE'], true)
                ? $name.'='.$value : $name.'="'.$escaped.'"';
            $pattern = '/^'.preg_quote($name, '/').'\s*=.*$/m';
            if (preg_match($pattern, $text)) {
                $text = preg_replace_callback($pattern, static fn (): string => $line, $text, 1);
            } else {
                $text .= (str_ends_with($text, "\n") || $text === '' ? '' : "\n").$line."\n";
            }
        }
        $temp = tempnam(dirname($path), '.walidsite-setup-');
        if ($temp === false) {
            throw new RuntimeException('Cannot write private settings.');
        }
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, $text, LOCK_EX) === false || ! rename($temp, $path)) {
                throw new RuntimeException('Cannot save private settings.');
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        Artisan::call('config:clear', ['--no-interaction' => true]);
    }
}
