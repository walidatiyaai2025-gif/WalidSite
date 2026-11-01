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

}
