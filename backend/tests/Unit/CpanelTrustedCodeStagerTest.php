<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelTrustedCodeStager;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class CpanelTrustedCodeStagerTest extends TestCase
{
    private string $home;
    private string $inbox;
    private string $sourceSha;
    private string $front;
    private string $publisherPublic;
    private string $publisherSecret;
    private string $composerJson;
    private string $composerLock;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)
            || ! function_exists('sodium_crypto_sign_detached')
            || ! extension_loaded('pdo_mysql') || ! extension_loaded('curl')
            || ! extension_loaded('openssl')) {
            self::markTestSkipped('Real cPanel code-only ZIP staging requires production PHP extensions.');
        }
        $this->home = sys_get_temp_dir().'/walidsite-stage-'.bin2hex(random_bytes(8));
        $this->sourceSha = str_repeat('a', 40);
        $this->front = dirname(__DIR__, 2).'/resources/deployment/cpanel-stable-index.php';
        $pair = sodium_crypto_sign_keypair();
        $this->publisherPublic = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->publisherSecret = sodium_crypto_sign_secretkey($pair);
        $this->composerJson = '{"name":"walidsite/app","require":{"php":"^8.3"}}';
        $this->composerLock = '{"packages":[],"content-hash":"stable"}';

        $this->inbox = $this->home.'/application/storage/app/private/verified-updates/'.str_repeat('b', 32);
        foreach ([
            $this->inbox,
            $this->home.'/application/bootstrap',
            $this->home.'/application/vendor',
            $this->home.'/public_html/uploads',
        ] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }
        file_put_contents($this->home.'/application/VERSION', "0.1.2\n");
        file_put_contents($this->home.'/application/.env', 'APP_KEY=EXISTING_PRIVATE_CI_KEY');
        file_put_contents($this->home.'/application/composer.json', $this->composerJson);
        file_put_contents($this->home.'/application/composer.lock', $this->composerLock);
        file_put_contents($this->home.'/application/storage/app/upload.txt', 'PRESERVED_DATA');
        self::assertTrue(copy($this->front, $this->home.'/public_html/index.php'));
        file_put_contents($this->home.'/public_html/.htaccess', 'RewriteEngine On');
        file_put_contents($this->home.'/public_html/uploads/customer.txt', 'PRESERVED_UPLOAD');
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    /** @return array{0:string,1:string} */
    private function signedZip(array $overrides = [], ?string $secret = null): array
    {
        $file = $this->inbox.'/WalidSite-update-v0.1.3.zip';
        $entries = [
            'application/bootstrap/app.php' => '<?php return true;',
            'application/vendor/autoload.php' => '<?php return [];',
            'application/VERSION' => "0.1.3\n",
            'application/composer.json' => $this->composerJson,
            'application/composer.lock' => $this->composerLock,
            'public_html/index.php' => (string) file_get_contents($this->front),
            'public_html/.htaccess' => 'RewriteEngine On',
            'contracts/product.json' => '{"product":"walidsite"}',
        ];
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach (array_replace($entries, $overrides) as $name => $bytes) {
            self::assertTrue($zip->addFromString($name, $bytes));
        }
        self::assertTrue($zip->close());
        self::assertTrue(chmod($file, 0600));

        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '0.1.3',
            'source_sha' => $this->sourceSha,
            'artifact_sha256' => hash_file('sha256', $file),
            'artifact_size' => filesize($file),
            'min_php' => '8.3.0',
            'artifact_file' => basename($file),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $envelope = json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $payload, $secret ?? $this->publisherSecret,
            )),
        ], JSON_THROW_ON_ERROR);
        return [$file, $envelope];
    }

    private function stage(string $file, string $envelope, ?string $inbox = null): array
    {
        return (new CpanelTrustedCodeStager())->stage(
            $envelope, $this->publisherPublic, 'https://updates.example.com',
            '0.1.2', $this->home, $this->front,
            $inbox ?? $this->inbox, $file,
        );
    }

    private function assertLiveUntouched(): void
    {
        self::assertSame('0.1.2', trim((string) file_get_contents($this->home.'/application/VERSION')));
        self::assertSame('APP_KEY=EXISTING_PRIVATE_CI_KEY', file_get_contents($this->home.'/application/.env'));
        self::assertSame('PRESERVED_DATA', file_get_contents($this->home.'/application/storage/app/upload.txt'));
        self::assertSame('PRESERVED_UPLOAD', file_get_contents($this->home.'/public_html/uploads/customer.txt'));
        self::assertSame(file_get_contents($this->front), file_get_contents($this->home.'/public_html/index.php'));
        self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
    }

    public function test_signed_compatible_code_is_staged_privately_without_installing_it(): void
    {
        [$file, $envelope] = $this->signedZip();
        $result = $this->stage($file, $envelope);
        self::assertSame('staged_not_installed', $result['state']);
        self::assertSame('0.1.3', $result['version']);
        self::assertSame($this->sourceSha, $result['source_sha']);
        self::assertSame(8, $result['files']);

        $target = $this->home.'/.walidsite-releases/v0.1.3-'.$this->sourceSha;
        self::assertSame('0.1.3', trim((string) file_get_contents($target.'/application/VERSION')));
        self::assertSame($this->composerLock, file_get_contents($target.'/application/composer.lock'));
        self::assertSame(0700, fileperms($this->home.'/.walidsite-releases') & 0777);
        $this->assertLiveUntouched();

        // A second attempt cannot overwrite or reinstall the immutable code.
        try {
            $this->stage($file, $envelope);
            self::fail('An already staged immutable version was overwritten.');
        } catch (DomainException) {
            $this->assertLiveUntouched();
        }
    }

    public function test_changed_or_untrusted_zip_cannot_prepare_executable_code(): void
    {
        [$file, $envelope] = $this->signedZip();
        file_put_contents($file, 'tampered after publisher signing');
        try {
            $this->stage($file, $envelope);
            self::fail('Altered archive bytes were trusted.');
        } catch (DomainException) {
            $this->assertLiveUntouched();
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-releases');
        }

        $wrongSecret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        [$file, $badSignature] = $this->signedZip([], $wrongSecret);
        try {
            $this->stage($file, $badSignature);
            self::fail('Foreign publisher was trusted.');
        } catch (DomainException) {
            $this->assertLiveUntouched();
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-releases');
        }
    }

    public function test_even_valid_signed_zip_with_changed_dependencies_is_rejected(): void
    {
        [$file, $envelope] = $this->signedZip([
            'application/composer.lock' => '{"packages":[{"name":"unreviewed/runtime"}]}',
        ]);
        try {
            $this->stage($file, $envelope);
            self::fail('Changed Composer dependencies cannot use code-only staging.');
        } catch (DomainException) {
            $this->assertLiveUntouched();
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-releases');
        }
    }

    public function test_private_owner_inbox_must_be_under_installed_application(): void
    {
        [$file, $envelope] = $this->signedZip();
        try {
            $this->stage($file, $envelope, $this->home.'/public_html');
            self::fail('A public document root must never be trusted as an update inbox.');
        } catch (DomainException) {
            $this->assertLiveUntouched();
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-releases');
        }
    }
}
