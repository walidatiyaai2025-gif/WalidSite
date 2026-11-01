<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteSchemaMismatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function insertFutureMigration(): string
    {
        $migration = '2099_12_31_235959_private_future_schema';
        DB::table('migrations')->insert(['migration' => $migration, 'batch' => 999]);

        return $migration;
    }

    public function test_first_run_exposes_incompatible_history_without_allowing_database_switch(): void
    {
        $path = storage_path('app/schema-mismatch-env-'.bin2hex(random_bytes(6)));
        $lock = storage_path('app/schema-mismatch-install-'.bin2hex(random_bytes(6)));
        $token = str_repeat('m', 40);
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => true,
            'site.environment_path' => $path,
            'site.install_lock_path' => $lock,
        ]);
        $future = $this->insertFutureMigration();

        try {
            $this->post('/setup/unlock', ['token' => $token])->assertRedirect('/setup');

            $this->get('/setup?lang=en')->assertOk()
                ->assertSee('Database migration history belongs to a different or newer release')
                ->assertDontSee('name="db_database"', false)
                ->assertDontSee($future);
            $this->get('/setup?lang=ar')->assertOk()
                ->assertSee('قاعدة البيانات تحتوي على ترحيلات من إصدار مختلف أو أحدث')
                ->assertDontSee('name="db_database"', false)
                ->assertDontSee($future);

            // Validly unlocked sessions cannot re-point an incompatible
            // database as if it were an unreachable empty database.
            $this->post('/setup/configure', [
                'site_url' => 'https://walid.example.test',
                'db_host' => 'localhost',
                'db_port' => 3306,
                'db_database' => 'different_empty_database',
                'db_username' => 'writer',
                'db_password' => 'must-never-be-saved',
            ])->assertForbidden();
            $this->assertFileDoesNotExist($path);

            // No migration is attempted; the user gets the actual cause.
            $this->post('/setup/database')->assertSessionHasErrors('setup');
            $this->get('/setup?lang=en')->assertOk()
                ->assertSee('Deploy compatible code or restore a verified backup.')
                ->assertDontSee($future);
            $this->assertDatabaseHas('migrations', ['migration' => $future, 'batch' => 999]);
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
            @unlink($lock);
        }
    }

    public function test_owner_update_center_shows_incompatible_history_and_refuses_apply(): void
    {
        $future = $this->insertFutureMigration();
        $owner = User::create([
            'email' => 'migration-owner@example.test',
            'password_hash' => 'not-used-for-this-test',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);

        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('Incompatible database migration history')
            ->assertDontSee('Database unavailable; update status cannot be verified.')
            ->assertDontSee('Database is current for the deployed code.')
            ->assertDontSee('Apply trusted migrations')
            ->assertDontSee($future);
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('قاعدة البيانات تحتوي على ترحيلات غير موجودة')
            ->assertDontSee($future);

        $this->post('/admin/updates/apply')->assertSessionHasErrors('updates');
        $this->assertDatabaseHas('migrations', ['migration' => $future, 'batch' => 999]);
    }
}
