<?php

namespace Tests\Feature;

use App\Support\Deployment\DatabaseConfiguration;
use RuntimeException;
use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_database_connections_include_canonical_mariadb_driver(): void
    {
        $this->assertSame('mariadb', config('database.connections.mariadb.driver'));
        $this->assertSame('mysql', config('database.connections.mysql.driver'));
        $this->assertTrue(config('database.connections.mariadb.strict'));
    }

    public function test_bootstrap_uses_in_memory_sqlite_for_tests(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
    public function test_canonical_site_url_blocks_untrusted_origins_and_non_root_paths(): void
    {
        $database = new DatabaseConfiguration;
        $this->assertSame('https://example.test', $database->canonicalSiteUrl('https://EXAMPLE.test/'));
        $this->assertSame('https://example.test:8443', $database->canonicalSiteUrl('https://example.test:8443'));
        $this->assertSame('http://127.0.0.1:8000', $database->canonicalSiteUrl('http://127.0.0.1:8000'));

        foreach ([
            'http://example.test',
            'https://user:password@example.test',
            'https://example.test/path',
            'https://example.test/?token=secret',
            'https://example.test/#fragment',
            'https://example.test.evil\\n.test',
            'https://-bad.example',
            'https://example.test.',
            'javascript:alert(1)',
            '',
        ] as $url) {
            $this->assertNull($database->canonicalSiteUrl($url), $url);
        }
    }

    public function test_database_environment_save_fails_closed_for_unsafe_site_origin(): void
    {
        $path = storage_path('app/rejected-site-url-'.bin2hex(random_bytes(6)));
        config(['site.environment_path' => $path]);
        try {
            $this->expectException(RuntimeException::class);
            (new DatabaseConfiguration)->save([], 'https://example.test/private?unsafe=1');
        } finally {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_production_canonical_site_url_rejects_private_dns_zones_but_preserves_public_https(): void
    {
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $database = new DatabaseConfiguration;

            foreach ([
                'https://metadata.google.internal',
                'https://service.svc',
                'https://printer.lan',
                'https://router.home',
                'https://gateway.home.arpa',
                'https://node.localdomain',
                'https://api.dev.local',
                'https://admin.localhost',
                'https://cluster.corp',
                'https://intranet',
                'https://staging.intranet',
                'https://database.private',
                'https://spoofed.invalid',
                'https://source.example.test',
                'https://site.onion',
                'https://ROUTER.HOME.ARPA',
                'https://sub.service.svc:443',
            ] as $url) {
                $this->assertNull(
                    $database->canonicalSiteUrl($url),
                    'Private DNS site URL must not be installed: '.$url
                );
            }

            // Exact DNS label boundaries matter: normal public names using
            // the same substrings must not become false positives.
            foreach ([
                'https://walidatiya.com',
                'https://myhome.example.com',
                'https://lantern.example.org',
                'https://service-svc.example.org',
                'https://example.com:8443',
                'https://8.8.8.8',
            ] as $url) {
                $this->assertSame($url, $database->canonicalSiteUrl($url));
            }
        } finally {
            $this->app->instance('env', $old);
        }

        // Example/test domains used by the local suite are not released as
        // real site URLs; their behavior in a test environment is unchanged.
        $this->assertSame(
            'https://example.test',
            (new DatabaseConfiguration)->canonicalSiteUrl('https://example.test')
        );
    }

}
