<?php

namespace Tests\Feature\Web;

use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

final class AdminSignedUpdateUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_verify_signed_zip_without_applying_php_or_exposing_keys(): void
    {
        if (! function_exists('sodium_crypto_sign_detached') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Sodium/Zip required.');
        }
        $owner = User::create([
            'email' => 'update-package-owner@example.test',
            'password_hash' => Hash::make('test-password'),
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);

        $zipFile = tempnam(sys_get_temp_dir(), 'signed-update-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => '<?php return 1;',
            'application/vendor/autoload.php' => '<?php return 2;',
            'public_html/index.php' => '<?php echo "ok";',
            'public_html/.htaccess' => 'RewriteEngine On',
        ] as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        $pair = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($pair));
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '0.1.1',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash_file('sha256', $zipFile),
            'artifact_size' => filesize($zipFile),
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v0.1.1.zip',
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
                $write($envelope);
                return 200;
            },
        ));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => trim(file_get_contents(base_path('VERSION'))),
            'site.trusted_release_origin' => 'https://updates.example.com',
            'site.trusted_release_public_key' => $pub,
        ]);

        try {
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertSee('data-testid="trusted-zip-upload-form"', false)
                ->assertSee('data-testid="verify-signed-update-zip"', false)
                ->assertSee('Install Update — Hosting/Recovery Blocked')
                ->assertDontSee('name="release_url"', false);

            $this->post('https://localhost/admin/updates/verify-upload', [
                'package' => new UploadedFile($zipFile, 'incoming.zip', 'application/zip', null, true),
            ])->assertRedirect();

            $id = session('verified_owner_update_inbox');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertSee('data-testid="owner-signed-upload-verified"', false)
                ->assertSee('0.1.1')
                ->assertSee(str_repeat('a', 40))
                ->assertDontSee($pub)
                ->assertDontSee($envelope);
            $this->assertSame('0.1.0', trim(file_get_contents(base_path('VERSION'))));
        } finally {
            $id = session('verified_owner_update_inbox');
            if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)) {
                $root = storage_path('app/private/verified-updates/'.$id);
                @unlink($root.'/receipt.json');
                @unlink($root.'/WalidSite-update-v0.1.1.zip');
                @rmdir($root);
                @rmdir(storage_path('app/private/verified-updates'));
            }
            @unlink($zipFile);
        }
    }

    public function test_non_owner_cannot_upload_executable_release_zip(): void
    {
        $user = User::create([
            'email' => 'unauthorized-update@example.test',
            'password_hash' => Hash::make('test-password'),
            'status' => 'active',
        ]);
        $this->actingAs($user)->post('https://localhost/admin/updates/verify-upload')
            ->assertForbidden();
    }
}
