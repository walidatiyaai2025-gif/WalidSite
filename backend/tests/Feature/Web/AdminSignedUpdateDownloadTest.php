<?php

namespace Tests\Feature\Web;

use App\Domain\Deployment\TrustedOwnerUpdateInbox;
use App\Domain\Deployment\TrustedReleaseArtifactDownloader;
use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

final class AdminSignedUpdateDownloadTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<string> */
    private array $privateInboxIds = [];

    protected function tearDown(): void
    {
        foreach ($this->privateInboxIds as $id) {
            $directory = storage_path('app/private/verified-updates/'.$id);
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file) && ! is_link($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
        @rmdir(storage_path('app/private/verified-updates'));
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function user(bool $owner): User
    {
        $user = User::create([
            'email' => 'download-'.bin2hex(random_bytes(6)).'@example.test',
            'password_hash' => Hash::make('Password-for-test-123!'),
            'status' => 'active',
        ]);
        if ($owner) {
            $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        }
        return $user;
    }

    /** @return array{owner:User,installed:string,version:string,bytes:string,public:string} */
    private function publisherFixture(bool $changedDownload = false): array
    {
        if (! class_exists(ZipArchive::class) || ! function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('Real ZipArchive and Sodium required for publisher signed release fixture.');
        }
        $installed = trim(file_get_contents(base_path('VERSION')));
        $parts = explode('.', $installed);
        $version = $parts[0].'.'.$parts[1].'.'.((int) $parts[2] + 1);
        $file = tempnam(sys_get_temp_dir(), 'walidsite-signed-download-');
        self::assertIsString($file);
        $this->temporaryFiles[] = $file;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => '<?php return true;',
            'application/vendor/autoload.php' => '<?php return [];',
            'application/VERSION' => $version."\n",
            'application/composer.json' => '{"name":"walidsite/test"}',
            'application/composer.lock' => '{"packages":[]}',
            'public_html/index.php' => '<?php echo "stable";',
            'public_html/.htaccess' => "RewriteEngine On\n",
        ] as $path => $value) {
            self::assertTrue($zip->addFromString($path, $value));
        }
        self::assertTrue($zip->close());
        $bytes = (string) file_get_contents($file);

        $pair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => $version,
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash('sha256', $bytes),
            'artifact_size' => strlen($bytes),
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v'.$version.'.zip',
            'release_notes' => "Security and recovery improvements\n<script>alert(1)</script>",
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $envelope = json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $payload, sodium_crypto_sign_secretkey($pair),
            )),
        ], JSON_THROW_ON_ERROR);
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function (string $url, \Closure $write) use ($envelope): int {
                self::assertSame('https://updates.example.com/releases/latest.json', $url);
                self::assertSame(strlen($envelope), $write($envelope));
                return 200;
            },
        ));
        $actual = $changedDownload ? str_repeat('Z', strlen($bytes)) : $bytes;
        $this->app->instance(TrustedReleaseArtifactDownloader::class, new TrustedReleaseArtifactDownloader(
            static function (string $url, \Closure $write) use ($version, $actual): int {
                self::assertSame('https://updates.example.com/releases/WalidSite-update-v'.$version.'.zip', $url);
                foreach (str_split($actual, 97) as $chunk) {
                    self::assertSame(strlen($chunk), $write($chunk));
                }
                return 200;
            },
        ));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => $installed,
            'site.trusted_release_origin' => 'https://updates.example.com',
            'site.trusted_release_public_key' => $public,
        ]);

        return ['owner' => $this->user(true), 'installed' => $installed, 'version' => $version, 'bytes' => $bytes, 'public' => $public];
    }

    public function test_owner_can_check_then_download_exact_signed_zip_and_continue_to_cpanel_preflight(): void
    {
        $fixture = $this->publisherFixture();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($fixture['owner']);

        $this->get('https://localhost/admin/updates')->assertOk()
            ->assertDontSee('data-testid="owner-publisher-download-form"', false);
        $this->post('https://localhost/admin/updates/check?lang=ar')->assertRedirect();
        $this->get('https://localhost/admin/updates?lang=ar')->assertOk()
            ->assertSee('data-testid="owner-publisher-download-form"', false)
            ->assertSee('data-testid="download-publisher-signed-update"', false)
            ->assertSee('data-testid="publisher-signed-release-notes"', false)
            ->assertSee('Security and recovery improvements')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee($fixture['public']);

        $this->post('https://localhost/admin/updates/fetch-signed?lang=ar')
            ->assertRedirect('https://localhost/admin/updates?lang=ar');
        $id = session('verified_owner_update_inbox');
        self::assertIsString($id);
        $this->privateInboxIds[] = $id;

        $verified = (new TrustedOwnerUpdateInbox())->getVerified(
            $id, (string) $fixture['owner']->getKey(),
            storage_path('app/private/verified-updates'), public_path(),
            'https://updates.example.com', $fixture['public'],
            trim(file_get_contents(base_path('VERSION'))),
        );
        self::assertSame($fixture['version'], $verified['version']);
        self::assertSame("Security and recovery improvements\n<script>alert(1)</script>", $verified['release_notes']);
        self::assertSame($fixture['bytes'], file_get_contents($verified['archive']));
        self::assertSame(7, $verified['files']);
        self::assertTrue(str_starts_with($verified['directory'], storage_path('app/private/')));
        self::assertSame(0600, fileperms($verified['archive']) & 0777);

        $this->get('https://localhost/admin/updates?lang=ar')->assertOk()
            ->assertSee('data-testid="owner-signed-upload-verified"', false)
            ->assertSee('data-testid="owner-host-preflight-form"', false)
            ->assertSee('data-testid="publisher-signed-release-notes"', false)
            ->assertSee('data-testid="latest-verified-package-version"', false)
            ->assertSee($fixture['version'])
            ->assertSee('Security and recovery improvements')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('data-testid="owner-code-staged-not-installed"', false)
            ->assertDontSee($fixture['public']);
        // The test checkout is NOT a real sibling-directory cPanel host.
        // It must not stage or activate code without an authentic host gate.
        $this->post('https://localhost/admin/updates/preflight')
            ->assertRedirect()->assertSessionHasErrors('hosting');
        $this->assertNull(session('owner_update_staged_code'));
        self::assertSame($fixture['installed'], trim(file_get_contents(base_path('VERSION'))));
    }

    public function test_wrong_download_bytes_fail_and_never_leave_verified_receipt_or_stale_candidate(): void
    {
        $fixture = $this->publisherFixture(true);
        $this->actingAs($fixture['owner']);
        $this->post('https://localhost/admin/updates/fetch-signed')
            ->assertRedirect()->assertSessionHasErrors('download');
        self::assertNull(session('verified_owner_update_inbox'));
        self::assertSame([], glob(storage_path('app/private/verified-updates/*')) ?: []);
        $this->get('https://localhost/admin/updates')->assertOk()
            ->assertDontSee('data-testid="owner-signed-upload-verified"', false);
    }

    public function test_nonowner_and_untrusted_http_fields_cannot_trigger_signed_code_download(): void
    {
        $fixture = $this->publisherFixture();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->user(false))
            ->post('https://localhost/admin/updates/fetch-signed')->assertForbidden();

        $this->actingAs($fixture['owner']);
        foreach ([
            ['release_url' => 'https://malicious.example.com/evil.zip'],
            ['artifact_sha256' => str_repeat('f', 64)],
        ] as $parameters) {
            $this->post('https://localhost/admin/updates/fetch-signed', $parameters)
                ->assertRedirect()->assertSessionHasErrors('download');
            self::assertNull(session('verified_owner_update_inbox'));
        }
        $this->post('https://localhost/admin/updates/fetch-signed?url=https://evil.example.com')
            ->assertRedirect()->assertSessionHasErrors('download');
        self::assertSame([], glob(storage_path('app/private/verified-updates/*')) ?: []);
    }

    public function test_publisher_trust_pin_mismatch_blocks_download_before_archive_transport(): void
    {
        $fixture = $this->publisherFixture();
        $this->actingAs($fixture['owner']);
        config(['site.trusted_release_public_key' => base64_encode(str_repeat('Z', 32))]);
        $this->post('https://localhost/admin/updates/fetch-signed')
            ->assertRedirect()->assertSessionHasErrors('download');
        self::assertNull(session('verified_owner_update_inbox'));
        self::assertSame([], glob(storage_path('app/private/verified-updates/*')) ?: []);
    }
}
