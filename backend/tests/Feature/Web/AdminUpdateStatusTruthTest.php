<?php

namespace Tests\Feature\Web;

use App\Domain\Deployment\CpanelOwnerCodeUpdateHost;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

final class AdminUpdateStatusTruthTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::create([
            'email' => 'update-status-'.bin2hex(random_bytes(6)).'@example.test',
            'password_hash' => Hash::make('test-password'),
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return $owner;
    }

    public function test_unverified_browser_claims_never_turn_missing_host_into_installed_update(): void
    {
        config(['site.updates_enabled' => true]);
        $this->actingAs($this->owner(), 'web');

        // Deliberately forged session receipts are NOT a code-install proof.
        $response = $this->withSession([
            'trusted_release_check' => [
                'state' => 'signed_release_available',
                'installed_version' => 'forged-version',
                'trust_fingerprint' => 'forged-fingerprint',
                'checked_at' => time(),
                'version' => '9.9.9',
            ],
            'owner_update_staged_code' => [
                'id' => str_repeat('a', 32),
                'installed_version' => 'forged-version',
                'version' => '9.9.9',
                'source_sha' => str_repeat('b', 40),
                'artifact_sha256' => str_repeat('c', 64),
                'created_at' => time(),
            ],
        ])->get('/admin/updates?lang=en');

        $response->assertOk()
            ->assertSee('data-testid="owner-code-upgrade-status"', false)
            ->assertSee('Blocked: host integration unavailable')
            ->assertSee('data-testid="owner-update-step-check"', false)
            ->assertSee('data-testid="owner-update-step-recovery"', false)
            ->assertSee('data-testid="owner-update-step-install"', false)
            ->assertSee('Code installation remains blocked without trusted host integration.')
            ->assertDontSee('Signed code staged; update not installed')
            ->assertDontSee('9.9.9');
    }

    public function test_host_adapter_registration_does_not_claim_success_or_bypass_disabled_updates(): void
    {
        $this->app->instance(CpanelOwnerCodeUpdateHost::class, new class implements CpanelOwnerCodeUpdateHost {
            public function externalWritersStopped(): bool { return false; }
            public function disposableRestoreConnection(): PDO
            {
                throw new \LogicException('Unreachable: rendering must not request a recovery connection.');
            }
            public function probeNewRelease(string $maintenanceToken, string $version, string $sourceSha): bool
            {
                return false;
            }
        });
        $this->actingAs($this->owner(), 'web');

        config(['site.updates_enabled' => true]);
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('Host integration registered; install still gated')
            ->assertSee('Host update capability is registered, but no code cutover was performed.')
            ->assertDontSee('Signed code staged; update not installed');

        config(['site.updates_enabled' => false]);
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('التحديثات معطّلة من الخادم')
            ->assertSee('data-testid="owner-update-step-install"', false);
    }

    public function test_historical_receipts_are_not_current_authorization(): void
    {
        config(['site.updates_enabled' => true]);
        $this->actingAs($this->owner(), 'web');
        $this->get('/admin/updates?lang=en')->assertOk();

        // Render an isolated historical state without creating a forged vault
        // receipt or weakening the private recovery-history verifier.
        $html = view('admin.updates.index', [
            'status' => ['ready' => true, 'history_mismatch' => false, 'pending' => []],
            'installedVersion' => '0.1.2',
            'verifiedUpload' => null,
            'hostPreflight' => null,
            'stagedCode' => null,
            'hostBackupAvailable' => false,
            'hostCodeUpdateAvailable' => false,
            'recoveryHistory' => [
                'state' => 'history',
                'receipts' => [[
                    'id' => str_repeat('a', 32),
                    'created_at' => '2026-11-06T10:00:00Z',
                    'version' => '0.1.2',
                    'files' => 5,
                    'tables' => 2,
                    'rows' => 9,
                ]],
            ],
        ])->render();

        $this->assertStringContainsString(
            'Historical restore receipts — not current update approval', $html
        );
        $this->assertStringContainsString(
            'Historical recovery receipts exist; a new update still requires an independent restore drill.', $html
        );
        $this->assertStringContainsString('Blocked: host integration unavailable', $html);
        $this->assertStringNotContainsString('Backup and restore have not been verified.', $html);
    }

    public function test_owner_sees_separate_completed_code_update_history_without_fake_success(): void
    {
        $this->actingAs($this->owner(), 'web');
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('data-testid="owner-code-update-history"', false)
            ->assertSee('data-testid="owner-code-update-history-empty"', false)
            ->assertDontSee('data-testid="owner-code-update-history-table"', false);

        $html = view('admin.updates.index', [
            'status' => ['ready' => true, 'history_mismatch' => false, 'pending' => []],
            'installedVersion' => '0.1.2',
            'verifiedUpload' => null,
            'hostPreflight' => null,
            'stagedCode' => null,
            'hostBackupAvailable' => false,
            'hostCodeUpdateAvailable' => false,
            'recoveryHistory' => ['state' => 'none', 'receipts' => []],
            'codeUpdateHistory' => [
                'state' => 'history',
                'receipts' => [[
                    'id' => str_repeat('a', 32),
                    'created_at' => '2026-11-06T12:50:00Z',
                    'from' => '0.1.2',
                    'version' => '0.1.3',
                    'source_sha' => str_repeat('b', 40),
                    'backup_id' => str_repeat('c', 32),
                ]],
            ],
        ])->render();
        $this->assertStringContainsString('Completed code update history', $html);
        $this->assertStringContainsString('owner-code-update-history-table', $html);
        $this->assertStringContainsString('0.1.3', $html);
        $this->assertStringContainsString('Historical entries are not fresh publisher signatures', $html);
        $this->assertStringContainsString('Code installation remains blocked without trusted host integration.', $html);
    }

    public function test_non_owner_cannot_observe_release_status_or_claim_update(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::create([
            'email' => 'update-status-reader@example.test',
            'password_hash' => Hash::make('test-password'),
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'reader'])->id);
        $this->actingAs($user, 'web');

        $this->get('/admin/updates?lang=en')->assertForbidden();
        $this->post('/admin/updates/install-signed?lang=en', [
            'current_password' => 'test-password',
        ])->assertForbidden();
    }

    public function test_preflight_missing_pin_is_actionable_in_owner_dashboard_without_deploy_claim(): void
    {
        $this->actingAs($this->owner(), 'web');
        $data = [
            'status' => ['ready' => true, 'history_mismatch' => false, 'pending' => []],
            'installedVersion' => '0.1.2',
            'verifiedUpload' => null,
            'hostPreflight' => null,
            'stagedCode' => null,
            'hostBackupAvailable' => false,
            'hostCodeUpdateAvailable' => false,
            'recoveryHistory' => ['state' => 'none', 'receipts' => []],
            'cronStatus' => [
                'state' => 'BLOCKED',
                'checked_at' => time(),
                'installed_version' => '0.1.2',
                'available_version' => null,
                'reason' => 'TRUSTED_INSTALLED_VERSION_MISSING',
            ],
        ];

        $en = view('admin.updates.index', $data)->render();
        self::assertStringContainsString('release-cron-block-reason', $en);
        self::assertStringContainsString(
            'Installed release trust pin is missing. Attest the original installation', $en,
        );
        self::assertStringContainsString('No application update was applied.', $en);
        self::assertStringNotContainsString('data-testid="release-cron-deployed"', $en);

        // The existing language switch honors the admin's current AR query.
        $ar = $this->get('/admin/updates?lang=ar');
        $ar->assertOk()->assertSee('dir="rtl"', false);
    }
}
