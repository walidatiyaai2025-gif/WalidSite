<?php

namespace Tests\Feature\Web;

use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
        // This test deliberately makes three consecutive upload attempts.
        // Keep the real production throttle intact while isolating the
        // session/identity boundary from its independent 2/minute limit.
        $this->withoutMiddleware(ThrottleRequests::class);

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
        $validArchiveBytes = (string) file_get_contents($zipFile);
        $verifiedIds = [];
        $invalidZipFile = null;
        $replacementZipFile = null;

        $pair = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($pair));
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '0.1.3',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash_file('sha256', $zipFile),
            'artifact_size' => filesize($zipFile),
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v0.1.3.zip',
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
            $verifiedIds[] = $id;
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertSee('data-testid="owner-signed-upload-verified"', false)
                ->assertSee('0.1.3')
                ->assertSee(str_repeat('a', 40))
                ->assertDontSee($pub)
                ->assertDontSee($envelope);
            $this->assertSame('0.1.2', trim(file_get_contents(base_path('VERSION'))));

            // Replacing a known-good upload with a rejected ZIP must not leave
            // that older signed artifact presented as the NEW verified ZIP.
            // No new publisher origin/key or archive bytes are ever trusted.
            session()->put('trusted_release_check', ['state' => 'signed_release_available']);
            $invalidZipFile = tempnam(sys_get_temp_dir(), 'rejected-update-');
            file_put_contents($invalidZipFile, 'this is not the signed ZIP');
            $this->post('https://localhost/admin/updates/verify-upload', [
                'package' => new UploadedFile($invalidZipFile, 'bad.zip', 'application/zip', null, true),
            ])->assertRedirect()->assertSessionHasErrors('upload');
            $this->assertNull(session('verified_owner_update_inbox'));
            $this->assertNull(session('trusted_release_check'));
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertDontSee('data-testid="owner-signed-upload-verified"', false)
                ->assertSee('Install Update — Hosting/Recovery Blocked');

            // A later valid upload is a NEW owner action with a NEW receipt.
            $replacementZipFile = tempnam(sys_get_temp_dir(), 'replacement-update-');
            file_put_contents($replacementZipFile, $validArchiveBytes);
            $this->post('https://localhost/admin/updates/verify-upload', [
                'package' => new UploadedFile($replacementZipFile, 'replacement.zip', 'application/zip', null, true),
            ])->assertRedirect();
            $secondId = session('verified_owner_update_inbox');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $secondId);
            $this->assertNotSame($id, $secondId);
            $verifiedIds[] = $secondId;
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertSee('data-testid="owner-signed-upload-verified"', false);

            // Disabling Admin updates invalidates the verified-session claim,
            // rather than allowing the old candidate to resurface later.
            config(['site.updates_enabled' => false]);
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertDontSee('data-testid="owner-signed-upload-verified"', false);
            $this->assertNull(session('verified_owner_update_inbox'));
            config(['site.updates_enabled' => true]);
            $this->get('https://localhost/admin/updates')->assertOk()
                ->assertDontSee('data-testid="owner-signed-upload-verified"', false);
            $this->assertSame('0.1.2', trim(file_get_contents(base_path('VERSION'))));
        } finally {
            foreach ($verifiedIds as $id) {
                $root = storage_path('app/private/verified-updates/'.$id);
                @unlink($root.'/receipt.json');
                @unlink($root.'/WalidSite-update-v0.1.3.zip');
                @rmdir($root);
            }
            @rmdir(storage_path('app/private/verified-updates'));
            @unlink($zipFile);
            if ($invalidZipFile !== null) @unlink($invalidZipFile);
            if ($replacementZipFile !== null) @unlink($replacementZipFile);
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
