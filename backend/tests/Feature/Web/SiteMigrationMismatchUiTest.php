<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\User;
use App\Support\Deployment\DatabaseConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SiteMigrationMismatchUiTest extends TestCase
{
    use RefreshDatabase;

    private function addIncompatibleMigration(): string
    {
        $future = '2099_12_31_235959_private_future_schema_token';
        DB::table('migrations')->insert(['migration' => $future, 'batch' => 999]);

        return $future;
    }

    public function test_incompatible_reachable_database_never_unlocks_credential_recovery(): void
    {
        $future = $this->addIncompatibleMigration();
        $path = storage_path('app/schema-mismatch-recovery-'.bin2hex(random_bytes(6)));
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('m', 40),
            'site.setup_database_ready' => true,
            'site.environment_path' => $path,
        ]);

        try {
            $this->post('/setup/unlock', ['token' => str_repeat('m', 40)])
                ->assertRedirect('/setup');

            $this->get('/setup?lang=en')->assertOk()
                ->assertSee('Database migration history is incompatible with the installed release.')
                ->assertDontSee('name="db_password"', false)
                ->assertDontSee('The saved database connection is unavailable')
                ->assertDontSee($future);

            $this->get('/setup?lang=ar')->assertOk()
                ->assertSee('سجل ترحيلات قاعدة البيانات غير متوافق')
                ->assertDontSee('name="db_password"', false)
                ->assertDontSee($future);

            $configuration = Mockery::mock(DatabaseConfiguration::class)->makePartial();
            $configuration->shouldNotReceive('probe');
            $configuration->shouldNotReceive('save');
            $this->app->instance(DatabaseConfiguration::class, $configuration);

            $this->post('/setup/configure', [
                'site_url' => 'https://changed.example.test',
                'db_host' => 'localhost', 'db_port' => 3306,
                'db_database' => 'different_site', 'db_username' => 'other_writer',
                'db_password' => 'must-not-be-saved',
            ])->assertForbidden();

            $this->post('/setup/database')->assertSessionHasErrors([
                'setup' => 'Database migration history is incompatible with this application release. No changes were applied.',
            ]);
            $this->assertFileDoesNotExist($path);
            $this->assertDatabaseHas('migrations', ['migration' => $future, 'batch' => 999]);
        } finally {
            @unlink($path);
        }
    }

    public function test_owner_update_center_identifies_mismatch_in_both_languages_and_blocks_apply(): void
    {
        $future = $this->addIncompatibleMigration();
        $owner = User::create([
            'email' => 'schema-mismatch-owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        config(['site.updates_enabled' => true]);

        $this->actingAs($owner)->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('Database migration history is incompatible with the deployed release.')
            ->assertDontSee('Database unavailable; update status cannot be verified.')
            ->assertDontSee('Apply trusted migrations')
            ->assertDontSee($future);
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('سجل ترحيلات قاعدة البيانات غير متوافق')
            ->assertDontSee('قاعدة البيانات غير متاحة')
            ->assertDontSee($future);

        // A mismatch must not reach Laravel's migrate command.
        Artisan::shouldReceive('call')->never();
        $this->post('/admin/updates/apply?lang=en')->assertSessionHasErrors([
            'updates' => 'Migration history is incompatible with the deployed application release. No migrations were executed; restore the matching verified release or backup.',
        ]);
        $this->assertDatabaseHas('migrations', ['migration' => $future, 'batch' => 999]);
    }
}
