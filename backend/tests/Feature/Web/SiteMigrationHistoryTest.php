<?php

namespace Tests\Feature\Web;

use App\Support\Deployment\SiteUpdater;
use App\Support\Deployment\SiteInstaller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SiteMigrationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_applied_migration_fails_closed_instead_of_claiming_current(): void
    {
        $updater = app(SiteUpdater::class);
        $future = '2099_12_31_235959_future_incompatible_schema';

        DB::table('migrations')->insert([
            'migration' => $future,
            'batch' => 999,
        ]);

        Log::spy();
        $status = $updater->inspect();

        $this->assertFalse($status['ready']);
        $this->assertSame([], $status['pending']);
        $this->assertTrue($status['history_mismatch']);
        // Log counts only, never user-controlled migration names or secrets.
        Log::shouldHaveReceived('warning')
            ->with('site.updates.unknown_applied_migrations', ['count' => 1])
            ->once();

        // No migrations can be executed when the installed release is older.
        Artisan::shouldReceive('call')->never();
        $this->assertSame(
            ['state' => 'schema_mismatch', 'applied' => 0],
            $updater->apply()
        );
        $this->assertDatabaseHas('migrations', ['migration' => $future, 'batch' => 999]);
    }

    public function test_pending_installed_site_migrations_fail_closed_before_artisan(): void
    {
        $updater = app(SiteUpdater::class);
        $migration = DB::table('migrations')->orderBy('migration')->value('migration');
        self::assertIsString($migration);
        DB::table('migrations')->where('migration', $migration)->delete();
        self::assertContains($migration, $updater->inspect()['pending']);

        Artisan::shouldReceive('call')->never();
        self::assertSame(
            ['state' => 'recovery_required', 'applied' => 0],
            $updater->apply(),
        );
        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);

        config(['site.setup_enabled' => false]);
        self::assertSame(
            ['state' => 'setup_closed', 'applied' => 0],
            $updater->applyForFirstInstall(),
        );
        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);
    }

    public function test_initial_install_possession_can_run_pending_trusted_schema(): void
    {
        $migration = DB::table('migrations')->orderBy('migration')->value('migration');
        self::assertIsString($migration);
        DB::table('migrations')->where('migration', $migration)->delete();
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('k', 40),
            'site.install_lock_path' => storage_path('app/test-no-install-'.bin2hex(random_bytes(10)).'.lock'),
        ]);
        self::assertTrue(app(\App\Support\Deployment\SiteInstaller::class)->allowed());

        // Model completed migrate without recreating existing tables inside
        // the test transaction. The real Setup ZIP runs full migrations in CI.
        Artisan::shouldReceive('call')->once()
            ->with('migrate', ['--force' => true, '--no-interaction' => true])
            ->andReturnUsing(static function () use ($migration): int {
                DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
                return 0;
            });
        self::assertSame(
            ['state' => 'applied', 'applied' => 1],
            app(SiteUpdater::class)->applyForFirstInstall(),
        );
        $this->assertDatabaseHas('migrations', ['migration' => $migration]);
    }

    public function test_first_install_blocks_existing_schema_without_migration_history_and_preserves_data(): void
    {
        // Simulate an empty DB accepted at the wizard's Step 4, then filled
        // with an unrelated table/data before the migration step. The
        // installation token is genuine, but it is NOT authorization to
        // overwrite an existing database with no trusted migration history.
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('k', 40),
            'site.install_lock_path' => storage_path('app/nonexistent-first-run-'.bin2hex(random_bytes(10))),
        ]);
        $person = User::create([
            'email' => 'preserve-before-install@example.test',
            'password_hash' => null,
            'status' => 'pending_activation',
        ]);
        self::assertTrue(app(SiteInstaller::class)->allowed());

        DB::table('migrations')->delete();
        self::assertSame(0, DB::table('migrations')->count());
        self::assertNotEmpty(app(SiteUpdater::class)->inspect()['pending']);

        Artisan::shouldReceive('call')->never();
        self::assertSame(
            ['state' => 'database_not_empty', 'applied' => 0],
            app(SiteUpdater::class)->applyForFirstInstall(),
        );
        $this->assertDatabaseHas('users', [
            'id' => $person->getKey(),
            'email' => 'preserve-before-install@example.test',
        ]);
        self::assertSame(0, DB::table('migrations')->count());
    }

    public function test_matching_migration_history_remains_current_and_safe_to_retry(): void
    {
        $updater = app(SiteUpdater::class);
        $status = $updater->inspect();

        $this->assertTrue($status['ready']);
        $this->assertFalse($status['history_mismatch']);
        $this->assertSame([], $status['pending']);
        $this->assertSame(['state' => 'current', 'applied' => 0], $updater->apply());
    }
}
