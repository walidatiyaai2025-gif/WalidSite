<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedOwnerUpdateInbox;
use App\Domain\Deployment\TrustedReleaseCatalog;
use DomainException;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

final class TrustedOwnerUpdateInboxTest extends TestCase
{
    private string $workspace;
    private string $inbox;
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_detached') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Sodium and ZIP extensions are required for signed updates.');
        }
        $this->workspace = sys_get_temp_dir().'/walidsite-owner-zip-'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        mkdir($this->workspace.'/private', 0700);
        mkdir($this->workspace.'/public', 0700);
        $this->inbox = $this->workspace.'/private/inbox';
        $this->public = $this->workspace.'/public';
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                if ($item->isDir() && ! $item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($this->workspace);
        }
        parent::tearDown();
    }

    /** @return array{0:UploadedFile,1:TrustedReleaseCatalog,2:string,3:string} */
    private function candidate(bool $invalidEntry = false, bool $badSignature = false): array
    {
        $file = $this->workspace.'/incoming.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => '<?php return 1;',
            'application/vendor/autoload.php' => '<?php return 2;',
            'public_html/index.php' => '<?php echo "ok";',
            'public_html/.htaccess' => 'RewriteEngine On',
        ] as $name => $body) {
            $zip->addFromString($name, $body);
        }
        if ($invalidEntry) {
            $zip->addFromString('application/../../evil.php', '<?php echo 1;');
        }
        $zip->close();

        $pair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '0.1.1',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash_file('sha256', $file),
            'artifact_size' => filesize($file),
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v0.1.1.zip',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair));
        if ($badSignature) {
            $signature[2] = chr(ord($signature[2]) ^ 1);
        }
        $envelope = json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode($signature),
        ], JSON_THROW_ON_ERROR);
        $catalog = new TrustedReleaseCatalog(
            static function (string $url, \Closure $write) use ($envelope): int {
                self::assertSame('https://updates.example.com/releases/latest.json', $url);
                $write($envelope);
                return 200;
            },
        );
        return [new UploadedFile($file, 'incoming.zip', 'application/zip', null, true),
            $catalog, $publicKey, 'https://updates.example.com'];
    }

    public function test_signed_zip_is_checked_staged_privately_and_bound_to_the_owner(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate();
        $service = new TrustedOwnerUpdateInbox();
        $result = $service->verifyUpload($upload, $catalog, $origin, $key, '0.1.0', '42',
            $this->inbox, $this->public);
        $this->assertSame('0.1.1', $result['version']);
        $this->assertSame(4, $result['files']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $result['id']);
        $directory = $this->inbox.'/'.$result['id'];
        $this->assertSame(0700, fileperms($directory) & 0777);
        $this->assertSame(0600, fileperms($directory.'/receipt.json') & 0777);
        $this->assertSame(0600, fileperms($directory.'/WalidSite-update-v0.1.1.zip') & 0777);
        $this->assertSame('0.1.1', $service->getVerified($result['id'], '42',
            $this->inbox, $this->public, $origin, $key, '0.1.0')['version']);

        $this->expectException(DomainException::class);
        $service->getVerified($result['id'], 'different-owner', $this->inbox, $this->public,
            $origin, $key, '0.1.0');
    }

    public function test_tampered_staged_zip_is_rejected_again_before_any_apply(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate();
        $service = new TrustedOwnerUpdateInbox();
        $ok = $service->verifyUpload($upload, $catalog, $origin, $key, '0.1.0',
            '42', $this->inbox, $this->public);
        file_put_contents($this->inbox.'/'.$ok['id'].'/WalidSite-update-v0.1.1.zip', 'changed');
        $this->expectException(DomainException::class);
        $service->getVerified($ok['id'], '42', $this->inbox, $this->public, $origin, $key, '0.1.0');
    }

    public function test_unknown_publisher_and_zip_slip_fail_closed_without_a_receipt(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate(false, true);
        $service = new TrustedOwnerUpdateInbox();
        try {
            $service->verifyUpload($upload, $catalog, $origin, $key, '0.1.0',
                '42', $this->inbox, $this->public);
            $this->fail('Untrusted publisher must not be accepted.');
        } catch (DomainException) {
            $this->assertDirectoryDoesNotExist($this->inbox);
        }

        [$upload, $catalog, $key, $origin] = $this->candidate(true);
        try {
            $service->verifyUpload($upload, $catalog, $origin, $key, '0.1.0',
                '42', $this->inbox, $this->public);
            $this->fail('ZIP traversal must not be accepted.');
        } catch (DomainException) {
            $this->assertSame([], array_values(array_diff(scandir($this->inbox), ['.', '..'])));
        }
    }

    public function test_exposed_receipt_permissions_cannot_reauthorize_signed_update(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate();
        $service = new TrustedOwnerUpdateInbox();
        $ok = $service->verifyUpload($upload, $catalog, $origin, $key,
            '0.1.0', '42', $this->inbox, $this->public);
        $receipt = $this->inbox.'/'.$ok['id'].'/receipt.json';

        $this->assertTrue(chmod($receipt, 0644));
        clearstatcache(true, $receipt);
        try {
            $service->getVerified($ok['id'], '42',
                $this->inbox, $this->public, $origin, $key, '0.1.0');
            $this->fail('An exposed, owner-bound update receipt must not authorize a ZIP.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('receipt', $exception->getMessage());
        } finally {
            chmod($receipt, 0600);
            clearstatcache(true, $receipt);
        }

        // Once permissions match the private 0600 writer contract, normal
        // signed-envelope and archive verification must still succeed.
        $this->assertSame('0.1.1', $service->getVerified($ok['id'], '42',
            $this->inbox, $this->public, $origin, $key, '0.1.0')['version']);
    }

    public function test_hardlinked_receipt_does_not_reauthorize_signed_update(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate();
        $service = new TrustedOwnerUpdateInbox();
        $ok = $service->verifyUpload($upload, $catalog, $origin, $key,
            '0.1.0', '42', $this->inbox, $this->public);
        $receipt = $this->inbox.'/'.$ok['id'].'/receipt.json';
        $link = $this->workspace.'/linked-owner-receipt.json';
        $this->assertTrue(link($receipt, $link));
        clearstatcache(true, $receipt);

        try {
            $service->getVerified($ok['id'], '42',
                $this->inbox, $this->public, $origin, $key, '0.1.0');
            $this->fail('A hard-linked update receipt must not authorize a ZIP.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('receipt', $exception->getMessage());
        } finally {
            unlink($link);
            clearstatcache(true, $receipt);
        }

        $this->assertSame('0.1.1', $service->getVerified($ok['id'], '42',
            $this->inbox, $this->public, $origin, $key, '0.1.0')['version']);
    }

    public function test_symlinked_receipt_is_rejected_even_with_valid_signed_bytes(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate();
        $service = new TrustedOwnerUpdateInbox();
        $ok = $service->verifyUpload($upload, $catalog, $origin, $key,
            '0.1.0', '42', $this->inbox, $this->public);
        $receipt = $this->inbox.'/'.$ok['id'].'/receipt.json';
        $moved = $this->workspace.'/saved-owner-receipt.json';
        $this->assertTrue(rename($receipt, $moved));
        $this->assertTrue(symlink($moved, $receipt));
        $this->expectException(DomainException::class);
        $service->getVerified($ok['id'], '42',
            $this->inbox, $this->public, $origin, $key, '0.1.0');
    }

    public function test_receipt_expiry_and_installed_version_changes_invalidate_upload(): void
    {
        [$upload, $catalog, $key, $origin] = $this->candidate();
        $service = new TrustedOwnerUpdateInbox();
        $ok = $service->verifyUpload($upload, $catalog, $origin, $key, '0.1.0',
            '42', $this->inbox, $this->public);
        try {
            $service->getVerified($ok['id'], '42', $this->inbox, $this->public,
                $origin, $key, '0.1.1');
            $this->fail('Installed version changed; old receipt must not authorize update.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $receiptFile = $this->inbox.'/'.$ok['id'].'/receipt.json';
        $receipt = json_decode((string) file_get_contents($receiptFile), true);
        $receipt['created_at'] = time() - 7200;
        file_put_contents($receiptFile, json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->expectException(DomainException::class);
        $service->getVerified($ok['id'], '42', $this->inbox, $this->public,
            $origin, $key, '0.1.0');
    }
}
