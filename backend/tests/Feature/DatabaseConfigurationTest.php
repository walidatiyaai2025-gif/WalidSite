<?php

namespace Tests\Feature;

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
}
