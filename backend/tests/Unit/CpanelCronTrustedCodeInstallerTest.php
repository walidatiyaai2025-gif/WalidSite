<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelCronTrustedCodeInstaller;
use App\Domain\Deployment\CpanelOwnerCodeUpdateHost;
use App\Domain\Deployment\TrustedReleaseCatalog;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CpanelCronTrustedCodeInstallerTest extends TestCase
{
    private string $home;
    private string $public;
    private string $running;
    private PDO $database;
    private CpanelConsoleWriterLease $lease;
    private string $publisher;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)
            || ! function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Disposable SQLite and Ed25519 support are needed.');
        }
        $this->home = sys_get_temp_dir().'/walidsite-cron-install-'.bin2hex(random_bytes(8));
        $this->public = $this->home.'/public_html';
        $this->running = $this->home.'/application';
        mkdir($this->public, 0700, true);
        mkdir($this->running, 0700, true);
        file_put_contents($this->running.'/VERSION', "1.2.3\n");
        $this->database = new PDO('sqlite::memory:');
        $this->lease = new CpanelConsoleWriterLease();
        $keys = sodium_crypto_sign_keypair();
        $this->publisher = base64_encode(sodium_crypto_sign_publickey($keys));
        $this->secret = sodium_crypto_sign_secretkey($keys);
    }

    protected function tearDown(): void
    {
        if (isset($this->lease)) {
            $this->lease->end();
        }
        if (isset($this->home) && is_dir($this->home)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function host(bool $isolated = true): CpanelOwnerCodeUpdateHost
    {
        return new class($this->database, $isolated) implements CpanelOwnerCodeUpdateHost {
            public function __construct(private PDO $db, private bool $isolated) {}
            public function externalWritersStopped(): bool { return $this->isolated; }
            public function disposableRestoreConnection(): PDO { return $this->db; }
            public function probeNewRelease(string $token, string $version, string $sourceSha): bool
            {
                return false; // A unit fixture can NEVER certify real HTTPS.
            }
        };
    }

    private function catalog(bool $tamper = false, ?int &$calls = null): TrustedReleaseCatalog
    {
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '1.2.4',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 4000,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v1.2.4.zip',
        ], JSON_THROW_ON_ERROR);
        $sig = sodium_crypto_sign_detached($payload, $this->secret);
        if ($tamper) {
            $sig[0] = chr(ord($sig[0]) ^ 1);
        }
        $envelope = json_encode([
            'payload' => base64_encode($payload), 'signature' => base64_encode($sig),
        ], JSON_THROW_ON_ERROR);
        return new TrustedReleaseCatalog(static function (string $url, \Closure $write) use ($envelope, &$calls): int {
            $calls = ($calls ?? 0) + 1;
            self::assertSame('https://updates.example.com/releases/latest.json', $url);
            $write($envelope);
            return 200;
        });
    }

    private function attemptInstall(?CpanelOwnerCodeUpdateHost $host, ?TrustedReleaseCatalog $catalog = null, string $expectedSha = ''): array
    {
        return (new CpanelCronTrustedCodeInstaller())->install(
            $this->home, $this->running, $this->public,
            '1.2.3', '1.2.4', $expectedSha === '' ? str_repeat('a', 40) : $expectedSha,
            'https://updates.example.com', $this->publisher,
            $host, $this->lease, $this->database, $catalog,
        );
    }

    public function test_no_host_capability_refuses_even_before_network_or_locks(): void
    {
        $calls = 0;
        try {
            $this->attemptInstall(null, $this->catalog(false, $calls));
            self::fail('No host adapter can authorize scheduled code install.');
        } catch (DomainException) {
            self::assertSame(0, $calls);
            self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }
    }

    public function test_only_already_held_console_writer_lease_may_enter_update(): void
    {
        $calls = 0;
        try {
            $this->attemptInstall($this->host(), $this->catalog(false, $calls));
            self::fail('Missing active writer lease accepted.');
        } catch (DomainException) {
            self::assertSame(0, $calls);
        }
    }

    public function test_independent_foreign_writer_gate_is_checked_before_network(): void
    {
        $calls = 0;
        $this->lease->beginForConsole($this->running);
        try {
            $this->attemptInstall($this->host(false), $this->catalog(false, $calls));
            self::fail('Foreign SQL/cron writers cannot be bypassed.');
        } catch (DomainException) {
            self::assertSame(0, $calls);
            $this->lease->assertSharedHeld();
        }
    }

    public function test_signed_poll_version_and_sha_must_match_second_authenticated_catalog(): void
    {
        $calls = 0;
        $this->lease->beginForConsole($this->running);
        try {
            $this->attemptInstall($this->host(), $this->catalog(false, $calls), str_repeat('e', 40));
            self::fail('Changed release SHA after signed discovery accepted.');
        } catch (DomainException) {
            self::assertSame(1, $calls);
            $this->lease->assertSharedHeld();
            self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }
    }

    public function test_forged_publisher_signature_is_rejected_before_zip_download(): void
    {
        $calls = 0;
        $this->lease->beginForConsole($this->running);
        try {
            $this->attemptInstall($this->host(), $this->catalog(true, $calls));
            self::fail('Forged publisher approved executable download.');
        } catch (DomainException) {
            self::assertSame(1, $calls);
            $this->lease->assertSharedHeld();
            self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }
    }
}
