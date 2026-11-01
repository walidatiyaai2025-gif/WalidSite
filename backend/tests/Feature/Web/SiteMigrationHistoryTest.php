<?php

namespace Tests\Feature\Web;

use App\Support\Deployment\SiteUpdater;
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
