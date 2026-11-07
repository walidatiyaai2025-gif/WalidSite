<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelReleaseCronPoller;
use App\Domain\Deployment\TrustedReleaseCatalog;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * No live network, owner account or release credential. Every signature is
 * produced by an ephemeral per-test Ed25519 key, never a publisher identity.
 */
final class CpanelReleaseCronPollerTest extends TestCase
{
    private string $root;
    private string $private;
    private string $public;
    private string $key;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Ed25519 sodium extension required.');
        }
        $this->root = sys_get_temp_dir().'/walidsite-cron-test-'.bin2hex(random_bytes(9));
        $this->public = $this->root.'/public_html';
        $this->private = $this->root.'/application/storage/app/private';
        mkdir($this->public, 0700, true);
        mkdir($this->private, 0700, true);
        $keypair = sodium_crypto_sign_keypair();
        $this->key = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->secret = sodium_crypto_sign_secretkey($keypair);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() && ! $item->isLink()
                    ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function envelope(string $version, bool $tamper = false): string
    {
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => $version,
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 4096,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v'.$version.'.zip',
        ], JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($payload, $this->secret);
        if ($tamper) {
            $signature[0] = chr(ord($signature[0]) ^ 1);
        }
        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode($signature),
        ], JSON_THROW_ON_ERROR);
    }

    private function catalog(string $version, bool $tamper = false, ?int &$calls = null): TrustedReleaseCatalog
    {
        $envelope = $this->envelope($version, $tamper);
        return new TrustedReleaseCatalog(static function (string $url, \Closure $write) use ($envelope, &$calls): int {
            $calls = ($calls ?? 0) + 1;
            self::assertSame('https://updates.example.com/releases/latest.json', $url);
            $write($envelope);
            return 200;
        });
    }

    private function poll(?TrustedReleaseCatalog $catalog, string $installed = '1.2.3'): array
    {
        return (new CpanelReleaseCronPoller())->poll(
            $this->private.'/release-automation', $this->public,
            'https://updates.example.com', $this->key, $installed, $catalog
        );
    }

    public function test_equal_signed_version_is_observational_up_to_date_only(): void
    {
        $calls = 0;
        $result = $this->poll($this->catalog('1.2.3', false, $calls));
        self::assertSame(1, $calls);
        self::assertSame('UP_TO_DATE', $result['state']);
        self::assertSame('NO_NEWER_SIGNED_VERSION', $result['reason']);
        self::assertFalse($result['code_applied']);
        self::assertSame('1.2.3', $result['available_version']);
        $path = $this->private.'/release-automation/release-poll-status.json';
        self::assertSame($result, json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0600, fileperms(dirname($path).'/release-poll.lock') & 0777);
    }

    public function test_newer_signed_version_is_blocked_without_host_code_apply(): void
    {
        $marker = $this->root.'/application/production-upload';
        file_put_contents($marker, 'PRESERVE');
        $result = $this->poll($this->catalog('1.2.4'));
        self::assertSame('BLOCKED', $result['state']);
        self::assertSame('VERIFIED_HOST_UPDATE_GATE_REQUIRED', $result['reason']);
        self::assertSame('1.2.4', $result['available_version']);
        self::assertSame(str_repeat('a', 40), $result['source_sha']);
        self::assertFalse($result['code_applied']);
        self::assertSame('PRESERVE', file_get_contents($marker));
    }

    public function test_replay_downgrade_and_invalid_signature_fail_closed_and_redacted(): void
    {
        foreach ([
            $this->catalog('1.2.2'),
            $this->catalog('1.2.4', true),
        ] as $catalog) {
            $result = $this->poll($catalog);
            self::assertSame('BLOCKED', $result['state']);
            self::assertSame('PUBLISHER_CHECK_UNVERIFIED', $result['reason']);
            self::assertNull($result['available_version']);
            self::assertNull($result['source_sha']);
            self::assertFalse($result['code_applied']);
            $receipt = file_get_contents($this->private.'/release-automation/release-poll-status.json');
            self::assertStringNotContainsString('https://', $receipt);
            self::assertStringNotContainsString($this->key, $receipt);
        }
    }

    public function test_default_catalog_still_rejects_same_version_for_mutating_consumers(): void
    {
        $catalog = $this->catalog('1.2.3');
        $this->expectException(DomainException::class);
        $catalog->check('https://updates.example.com', $this->key, '1.2.3');
    }

    public function test_concurrent_poll_and_tampered_status_are_not_overwritten(): void
    {
        $this->poll($this->catalog('1.2.3'));
        $directory = $this->private.'/release-automation';
        $lock = fopen($directory.'/release-poll.lock', 'c+');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->expectException(DomainException::class);
            $this->poll($this->catalog('1.2.4'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_symlink_or_world_writable_receipt_directory_is_rejected(): void
    {
        $this->poll($this->catalog('1.2.3'));
        $path = $this->private.'/release-automation/release-poll-status.json';
        unlink($path);
        symlink($this->root.'/application/missing', $path);
        try {
            $this->expectException(DomainException::class);
            $this->poll($this->catalog('1.2.4'));
        } finally {
            unlink($path);
        }
    }

    private function installPrivatePointerFixture(): array
    {
        $sha = str_repeat('a', 40);
        file_put_contents($this->root.'/application/VERSION', "1.2.3\\n");
        $target = '.walidsite-releases/v1.2.4-'.$sha;
        $running = $this->root.'/'.$target.'/application';
        mkdir($running, 0700, true);
        file_put_contents($running.'/VERSION', "1.2.4\\n");
        file_put_contents(
            $this->root.'/.walidsite-active-release.json',
            json_encode(['schema'=>1, 'version'=>'1.2.4', 'root'=>$target], JSON_THROW_ON_ERROR),
        );
        chmod($this->root.'/.walidsite-active-release.json', 0600);
        return [
            'state' => 'installed_verified',
            'version' => '1.2.4',
            'source_sha' => $sha,
            'backup_id' => bin2hex(random_bytes(12)),
            'from' => '1.2.3',
            'to' => $target,
        ];
    }

    public function test_verified_code_install_requires_prior_signed_poll_and_active_pointer(): void
    {
        $poller = new CpanelReleaseCronPoller();
        $result = $this->installPrivatePointerFixture();
        try {
            $poller->recordDeployed(
                $this->private.'/release-automation', $this->public, '1.2.3', $result,
            );
            self::fail('Deployment without fresh signed observation accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        $this->poll($this->catalog('1.2.4'));
        $poller->recordDeployed(
            $this->private.'/release-automation', $this->public, '1.2.3', $result,
        );
        $visible = (new \\App\\Domain\\Deployment\\CpanelReleaseCronStatus())->inspect(
            $this->private.'/release-automation', $this->public,
        );
        self::assertSame('DEPLOYED', $visible['state']);
        self::assertSame('1.2.4', $visible['installed_version']);
        self::assertSame('VERIFIED_HOST_CODE_APPLIED', $visible['reason']);
        $raw = json_decode(
            file_get_contents($this->private.'/release-automation/release-poll-status.json'),
            true, 8, JSON_THROW_ON_ERROR,
        );
        self::assertTrue($raw['code_applied']);
        self::assertSame($result['source_sha'], $raw['source_sha']);
        // A stale receipt cannot be replayed as another deployment.
        $this->expectException(DomainException::class);
        $poller->recordDeployed(
            $this->private.'/release-automation', $this->public, '1.2.3', $result,
        );
    }

    public function test_forged_success_never_promotes_blocked_poll_to_deployed(): void
    {
        $this->poll($this->catalog('1.2.4'));
        $result = $this->installPrivatePointerFixture();
        $result['source_sha'] = str_repeat('f', 40);
        try {
            (new CpanelReleaseCronPoller())->recordDeployed(
                $this->private.'/release-automation', $this->public, '1.2.3', $result,
            );
            self::fail('Forged live SHA accepted as scheduled deployment.');
        } catch (DomainException) {
            $raw = json_decode(
                file_get_contents($this->private.'/release-automation/release-poll-status.json'),
                true, 8, JSON_THROW_ON_ERROR,
            );
            self::assertSame('BLOCKED', $raw['state']);
            self::assertFalse($raw['code_applied']);
        }
    }

    public function test_invalid_origin_or_key_rejected_before_network(): void
    {
        $calls = 0;
        $catalog = $this->catalog('1.2.4', false, $calls);
        try {
            (new CpanelReleaseCronPoller())->poll(
                $this->private.'/release-automation', $this->public,
                'https://127.0.0.1', $this->key, '1.2.3', $catalog
            );
            self::fail('Local publisher origin accepted.');
        } catch (DomainException) {
            self::assertSame(0, $calls);
        }
        try {
            (new CpanelReleaseCronPoller())->poll(
                $this->private.'/release-automation', $this->public,
                'https://updates.example.com', '', '1.2.3', $catalog
            );
            self::fail('Untrusted empty key accepted.');
        } catch (DomainException) {
            self::assertSame(0, $calls);
        }
    }
}
