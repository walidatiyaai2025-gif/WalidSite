<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Domain\Deployment\TrustedReleaseDownloader;
use App\Domain\Deployment\TrustedReleaseUpdateJourney;
use DomainException;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class TrustedReleaseUpdateJourneyTest extends TestCase
{
    private PDO $rootDb;
    private PDO $liveDb;
    private PDO $restoreDb;
    private string $sourceSchema;
    private string $targetSchema;
    private string $home;
    private string $privateKey;
    private string $publicKey;
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            self::markTestSkipped('Real MariaDB 10.11 + ZIP acceptance runs in GitHub CI.');
        }
        if (! class_exists(ZipArchive::class) || ! function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('Both ext-zip and sodium are required.');
        }

        $pair = sodium_crypto_sign_keypair();
        $this->privateKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));

        $dsn = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
        $this->rootDb = new PDO($dsn, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $nonce = bin2hex(random_bytes(5));
        $this->sourceSchema = 'signed_live_'.$nonce;
        $this->targetSchema = 'signed_restore_'.$nonce;
        $this->rootDb->exec('CREATE DATABASE '.$this->ident($this->sourceSchema).' CHARACTER SET utf8mb4');
        $this->rootDb->exec('CREATE DATABASE '.$this->ident($this->targetSchema).' CHARACTER SET utf8mb4');
        $this->liveDb = new PDO($dsn.';dbname='.$this->sourceSchema, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->restoreDb = new PDO($dsn.';dbname='.$this->targetSchema, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->liveDb->exec('CREATE TABLE billing (id INT NOT NULL PRIMARY KEY, memo VARCHAR(100), payload VARBINARY(32)) ENGINE=InnoDB');
        $stmt = $this->liveDb->prepare('INSERT INTO billing (id,memo,payload) VALUES (?,?,?)');
        self::assertTrue($stmt->execute([1, 'محتوى عربي', "\x00\xffDATA"]));

        $this->home = sys_get_temp_dir().'/walidsite-trusted-journey-'.bin2hex(random_bytes(8));
        $this->paths = [
            'quarantine' => $this->home.'/quarantine',
            'incoming' => $this->home.'/quarantine/incoming',
            'archive' => $this->home.'/quarantine/incoming/WalidSite-update-v1.2.0.zip',
            'releases' => $this->home.'/releases',
            'current' => $this->home.'/current',
            'public' => $this->home.'/public_html',
            'environment' => $this->home.'/runtime/.env',
            'private_data' => $this->home.'/runtime/storage',
            'uploads' => $this->home.'/user-public/uploads',
            'backup_vault' => $this->home.'/backup-vault',
            'disposable_files' => $this->home.'/disposable-files',
        ];
        foreach ([
            $this->paths['incoming'],
            $this->paths['releases'],
            $this->paths['private_data'],
            $this->paths['uploads'],
            $this->paths['backup_vault'],
            $this->paths['disposable_files'],
        ] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }

        $old = $this->paths['releases'].'/v1';
        foreach (['application/bootstrap', 'application/vendor', 'public_html'] as $dir) {
            self::assertTrue(mkdir($old.'/'.$dir, 0700, true));
        }
        foreach ([
            'application/bootstrap/app.php',
            'application/vendor/autoload.php',
            'public_html/index.php',
            'public_html/.htaccess',
        ] as $path) {
            file_put_contents($old.'/'.$path, 'LIVE-vA');
        }
        self::assertTrue(symlink($old, $this->paths['current']));
        self::assertTrue(symlink($this->paths['current'].'/public_html', $this->paths['public']));
        file_put_contents($this->paths['environment'], 'APP_KEY=keep-existing-secret');
        file_put_contents($this->paths['private_data'].'/memo.txt', 'keep-private');
        file_put_contents($this->paths['uploads'].'/avatar.png', "\x00\xffUPLOAD");
    }

    protected function tearDown(): void
    {
        if (isset($this->liveDb)) {
            unset($this->liveDb, $this->restoreDb);
        }
        if (isset($this->rootDb)) {
            foreach ([$this->sourceSchema ?? '', $this->targetSchema ?? ''] as $name) {
                if ($name !== '') {
                    $this->rootDb->exec('DROP DATABASE IF EXISTS '.$this->ident($name));
                }
            }
        }
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() && ! $file->isLink()
                    ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function ident(string $name): string
    {
        return chr(96).$name.chr(96);
    }

    private function signedZip(bool $wrongSignature = false, bool $schemaChange = false): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->paths['archive'], ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => 'NEW-vB',
            'application/vendor/autoload.php' => 'NEW-vB',
            'public_html/index.php' => 'NEW-vB',
            'public_html/.htaccess' => 'NEW-vB',
            'contracts/product.json' => '{"product":"walidsite"}',
        ] as $path => $bytes) {
            self::assertTrue($zip->addFromString($path, $bytes));
        }
        if ($schemaChange) {
            self::assertTrue($zip->addFromString(
                'application/database/migrations/2026_11_03_000001_update_billing.php',
                '<?php return "schema update";',
            ));
        }
        self::assertTrue($zip->close());
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '1.2.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash_file('sha256', $this->paths['archive']),
            'artifact_size' => filesize($this->paths['archive']),
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v1.2.0.zip',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $key = $wrongSignature
            ? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()) : $this->privateKey;
        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $key)),
        ], JSON_THROW_ON_ERROR);
    }

    private function update(string $signed, ?callable $writes = null, ?callable $health = null): array
    {
        return (new TrustedReleaseUpdateJourney())->execute(
            $this->liveDb, $this->restoreDb,
            $signed, $this->publicKey, 'https://updates.example.com',
            '1.0.0', $this->paths,
            $writes ?? static fn (): bool => true,
            $health ?? fn (): bool => file_get_contents($this->paths['public'].'/index.php') === 'NEW-vB',
        );
    }

    public function test_unsupported_document_root_blocks_remote_download_before_transport(): void
    {
        $signed = $this->signedZip();
        $bytes = file_get_contents($this->paths['archive']);
        unlink($this->paths['archive']);

        // This symlink resolves to the same files, but does NOT follow the
        // approved current/public_html indirection and cannot auto-update.
        self::assertTrue(unlink($this->paths['public']));
        self::assertTrue(symlink(
            $this->paths['releases'].'/v1/public_html', $this->paths['public'],
        ));

        $requests = 0;
        $transport = new TrustedReleaseDownloader(
            static function (string $url, \Closure $write) use (&$requests, $bytes): int {
                $requests++;
                $write($bytes);
                return 200;
            },
        );

        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => true,
                static fn (): bool => true, $transport,
            );
            self::fail('Incompatible public_html topology cannot permit update download.');
        } catch (DomainException $error) {
            self::assertStringContainsString('BLOCKED HOSTING', $error->getMessage());
            self::assertSame(0, $requests);
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['incoming'].'/WalidSite-update-*') ?: []);
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM billing')->fetchColumn());
        }
    }

    public function test_unsupported_document_root_blocks_offline_promotion_before_backups(): void
    {
        $signed = $this->signedZip();
        self::assertTrue(unlink($this->paths['public']));
        self::assertTrue(symlink(
            $this->paths['releases'].'/v1/public_html', $this->paths['public'],
        ));
        try {
            $this->update($signed);
            self::fail('Incompatible host must not stage a signed ZIP even offline.');
        } catch (DomainException $error) {
            self::assertStringContainsString('BLOCKED HOSTING', $error->getMessage());
            self::assertSame([], glob($this->paths['releases'].'/v1.2*') ?: []);
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        }
    }

    public function test_complete_catalog_discovery_and_verified_zip_to_real_mariadb_recovery_and_vb_switch(): void
    {
        $signed = $this->signedZip();
        $bytes = file_get_contents($this->paths['archive']);
        self::assertIsString($bytes);
        self::assertTrue(unlink($this->paths['archive']));
        $catalogCalls = 0;
        $zipCalls = 0;

        $catalog = new TrustedReleaseCatalog(static function (string $url, \Closure $write) use (
            $signed, &$catalogCalls,
        ): int {
            self::assertSame('https://updates.example.com/releases/latest.json', $url);
            $catalogCalls++;
            self::assertSame(strlen($signed), $write($signed));
            return 200;
        });
        $downloader = new TrustedReleaseDownloader(static function (string $url, \Closure $write) use (
            $bytes, &$zipCalls,
        ): int {
            self::assertSame('https://updates.example.com/releases/WalidSite-update-v1.2.0.zip', $url);
            $zipCalls++;
            foreach (str_split($bytes, 29) as $chunk) {
                self::assertSame(strlen($chunk), $write($chunk));
            }
            return 200;
        });

        $result = (new TrustedReleaseUpdateJourney())->discoverAndExecute(
            $this->liveDb, $this->restoreDb,
            $this->publicKey, 'https://updates.example.com', '1.0.0',
            $this->paths,
            static fn (): bool => true,
            fn (): bool => file_get_contents($this->paths['public'].'/index.php') === 'NEW-vB',
            $catalog, $downloader,
        );

        self::assertSame(1, $catalogCalls);
        self::assertSame(1, $zipCalls);
        self::assertSame('switched', $result['state']);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame('NEW-vB', file_get_contents($this->paths['public'].'/index.php'));
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        self::assertSame("\x00\xffUPLOAD", file_get_contents($this->paths['uploads'].'/avatar.png'));
        self::assertSame('محتوى عربي',
            $this->restoreDb->query('SELECT memo FROM billing WHERE id=1')->fetchColumn());
        self::assertSame($bytes, file_get_contents($this->paths['archive']));
    }

    public function test_untrusted_catalog_never_starts_zip_transfer_or_backup(): void
    {
        $signed = $this->signedZip(true);
        self::assertTrue(unlink($this->paths['archive']));
        $zipCalls = 0;
        $catalog = new TrustedReleaseCatalog(static function (string $url, \Closure $write) use ($signed): int {
            $write($signed);
            return 200;
        });
        $downloader = new TrustedReleaseDownloader(static function () use (&$zipCalls): int {
            $zipCalls++;
            return 200;
        });

        try {
            (new TrustedReleaseUpdateJourney())->discoverAndExecute(
                $this->liveDb, $this->restoreDb,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths,
                static fn (): bool => true, static fn (): bool => true,
                $catalog, $downloader,
            );
            self::fail('Unknown publisher cannot start an executable update.');
        } catch (DomainException $failure) {
            self::assertSame(0, $zipCalls);
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
            self::assertSame([], glob($this->paths['incoming'].'/WalidSite-update-*') ?: []);
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM billing')->fetchColumn());
        }
    }

    public function test_catalog_redirect_and_unquiesced_writes_never_start_signed_zip_transfer(): void
    {
        $signed = $this->signedZip();
        self::assertTrue(unlink($this->paths['archive']));
        $catalogCalls = 0;
        $zipCalls = 0;
        $catalog = new TrustedReleaseCatalog(static function (string $url, \Closure $write) use (
            &$catalogCalls, $signed,
        ): int {
            $catalogCalls++;
            $write($signed);
            return 302;
        });
        $downloader = new TrustedReleaseDownloader(static function () use (&$zipCalls): int {
            $zipCalls++;
            return 200;
        });

        foreach ([true, false] as $quiesced) {
            try {
                (new TrustedReleaseUpdateJourney())->discoverAndExecute(
                    $this->liveDb, $this->restoreDb,
                    $this->publicKey, 'https://updates.example.com', '1.0.0',
                    $this->paths,
                    static fn (): bool => $quiesced, static fn (): bool => true,
                    $catalog, $downloader,
                );
                self::fail('Publisher redirect or active writers cannot trigger an update.');
            } catch (DomainException) {
                self::assertSame(0, $zipCalls);
                self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            }
        }
        self::assertSame(1, $catalogCalls);
        self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
    }

    public function test_remote_signed_download_through_real_mariadb_restore_and_vb_cutover(): void
    {
        $signed = $this->signedZip();
        $bytes = file_get_contents($this->paths['archive']);
        unlink($this->paths['archive']);
        $requests = 0;

        $downloader = new TrustedReleaseDownloader(
            static function (string $url, \Closure $write) use ($bytes, &$requests): int {
                $requests++;
                self::assertSame(
                    'https://updates.example.com/releases/WalidSite-update-v1.2.0.zip',
                    $url,
                );
                for ($offset = 0; $offset < strlen($bytes); $offset += 17) {
                    $part = substr($bytes, $offset, 17);
                    self::assertSame(strlen($part), $write($part));
                }
                return 200;
            },
        );

        $result = (new TrustedReleaseUpdateJourney())->downloadAndExecute(
            $this->liveDb, $this->restoreDb, $signed,
            $this->publicKey, 'https://updates.example.com', '1.0.0',
            $this->paths, static fn (): bool => true,
            fn (): bool => file_get_contents($this->paths['public'].'/index.php') === 'NEW-vB',
            $downloader,
        );

        self::assertSame(1, $requests);
        self::assertSame('switched', $result['state']);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame('NEW-vB', file_get_contents($this->paths['public'].'/index.php'));
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        self::assertSame('محتوى عربي', $this->restoreDb->query('SELECT memo FROM billing WHERE id=1')->fetchColumn());
        self::assertSame($bytes, file_get_contents($this->paths['archive']));
    }

    /**
     * A failed, signed attempt must leave a reusable archive/candidate without
     * ever moving the live pointer. Stop after promotion but BEFORE backups.
     */
    private function leaveVerifiedRemoteReleaseAfterFailedMaintenance(): string
    {
        $signed = $this->signedZip();
        $bytes = file_get_contents($this->paths['archive']);
        self::assertIsString($bytes);
        self::assertTrue(unlink($this->paths['archive']));
        $downloads = 0;
        $writes = 0;
        $transport = new TrustedReleaseDownloader(
            static function (string $url, \Closure $write) use ($bytes, &$downloads): int {
                $downloads++;
                self::assertSame(
                    'https://updates.example.com/releases/WalidSite-update-v1.2.0.zip', $url,
                );
                self::assertSame(strlen($bytes), $write($bytes));
                return 200;
            },
        );
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths,
                static function () use (&$writes): bool {
                    return ++$writes < 4;
                },
                static fn (): bool => true, $transport,
            );
            self::fail('Late writer activity must block the first cutover.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('preconditions changed after locking', $failure->getMessage());
        }
        self::assertSame(4, $writes);
        self::assertSame(1, $downloads);
        self::assertSame($bytes, file_get_contents($this->paths['archive']));
        self::assertDirectoryExists(
            $this->paths['releases'].'/v1.2.0-'.str_repeat('a', 40),
        );
        self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        self::assertSame("\x00\xffUPLOAD", file_get_contents($this->paths['uploads'].'/avatar.png'));
        self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);

        return $signed;
    }

    private function noRetryNetwork(): TrustedReleaseDownloader
    {
        return new TrustedReleaseDownloader(
            static function (): int {
                self::fail('Retained verified artifact must not trigger a second network transfer.');
                return 500;
            },
        );
    }

    public function test_recovered_write_barrier_retries_same_signed_code_without_redownload_or_reinstall(): void
    {
        $signed = $this->leaveVerifiedRemoteReleaseAfterFailedMaintenance();
        $result = (new TrustedReleaseUpdateJourney())->downloadAndExecute(
            $this->liveDb, $this->restoreDb, $signed,
            $this->publicKey, 'https://updates.example.com', '1.0.0',
            $this->paths, static fn (): bool => true,
            fn (): bool => file_get_contents($this->paths['public'].'/index.php') === 'NEW-vB',
            $this->noRetryNetwork(),
        );

        self::assertSame('switched', $result['state']);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame('NEW-vB', file_get_contents($this->paths['public'].'/index.php'));
        self::assertSame('LIVE-vA', file_get_contents($this->paths['releases'].'/v1/public_html/index.php'));
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        self::assertSame('keep-private', file_get_contents($this->paths['private_data'].'/memo.txt'));
        self::assertSame("\x00\xffUPLOAD", file_get_contents($this->paths['uploads'].'/avatar.png'));
        self::assertSame('محتوى عربي',
            $this->restoreDb->query('SELECT memo FROM billing WHERE id=1')->fetchColumn());
        self::assertSame([], glob($this->paths['quarantine'].'/release-staging/candidate-*') ?: []);

        // A caller holding a stale installedVersion cannot reinstall the now
        // active version even if the same authenticated ZIP still exists.
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => true,
                static fn (): bool => true, $this->noRetryNetwork(),
            );
            self::fail('An active candidate must not be reinstalled.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('cannot be reused', $failure->getMessage());
        }
        self::assertSame('NEW-vB', file_get_contents($this->paths['public'].'/index.php'));
    }

    public function test_changed_retained_code_is_never_trusted_by_signed_zip_retry(): void
    {
        $signed = $this->leaveVerifiedRemoteReleaseAfterFailedMaintenance();
        $target = $this->paths['releases'].'/v1.2.0-'.str_repeat('a', 40);
        self::assertNotFalse(file_put_contents($target.'/public_html/index.php', 'MALICIOUS CODE'));
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => true,
                static fn (): bool => true, $this->noRetryNetwork(),
            );
            self::fail('Changed code cannot be accepted as previously signed.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('differs from signed code', $failure->getMessage());
        }
        self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        self::assertSame([], glob($this->paths['quarantine'].'/release-staging/candidate-*') ?: []);
        self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
    }

    public function test_tampered_or_symlinked_retained_zip_blocks_retry_before_backups(): void
    {
        $signed = $this->leaveVerifiedRemoteReleaseAfterFailedMaintenance();
        $archive = $this->paths['archive'];
        $original = file_get_contents($archive);
        self::assertIsString($original);
        self::assertSame(strlen($original), file_put_contents($archive, str_repeat('X', strlen($original))));
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => true,
                static fn (): bool => true, $this->noRetryNetwork(),
            );
            self::fail('Corrupt retained ZIP cannot authorize an update.');
        } catch (DomainException) {
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        }
        self::assertSame(strlen($original), file_put_contents($archive, $original));
        $outside = $this->home.'/outside-approved-archive.zip';
        self::assertTrue(rename($archive, $outside));
        self::assertTrue(symlink($outside, $archive));
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => true,
                static fn (): bool => true, $this->noRetryNetwork(),
            );
            self::fail('Symlinked retained ZIP cannot authorize an update.');
        } catch (DomainException) {
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        }
        self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
    }

    public function test_broken_remote_release_never_touches_live_code_or_starts_backup(): void
    {
        $signed = $this->signedZip();
        unlink($this->paths['archive']);

        foreach ([302, 200] as $status) {
            $transport = new TrustedReleaseDownloader(
                static function (string $url, \Closure $write) use ($status): int {
                    $write('not-a-signed-archive');
                    return $status;
                },
            );
            try {
                (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                    $this->liveDb, $this->restoreDb, $signed,
                    $this->publicKey, 'https://updates.example.com', '1.0.0',
                    $this->paths, static fn (): bool => true,
                    static fn (): bool => true, $transport,
                );
                self::fail('Untrusted downloaded artifact must not activate.');
            } catch (DomainException) {
                self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
                self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
                self::assertSame([], glob($this->paths['incoming'].'/WalidSite-update-*') ?: []);
            }
        }
    }

    public function test_remote_download_is_blocked_while_writers_are_active(): void
    {
        $signed = $this->signedZip();
        unlink($this->paths['archive']);
        $requested = false;
        $transport = new TrustedReleaseDownloader(
            static function () use (&$requested): int {
                $requested = true;
                return 200;
            },
        );
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => false,
                static fn (): bool => true, $transport,
            );
            self::fail('An active writer must prevent the trusted transfer.');
        } catch (DomainException) {
            self::assertFalse($requested);
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        }
    }

    public function test_new_schema_migration_in_signed_release_cannot_cutover_without_rollback_proof(): void
    {
        $signed = $this->signedZip(false, true);
        try {
            $this->update($signed);
            self::fail('A correctly signed release with new schema migrations cannot bypass the migration gate.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('migration change', $failure->getMessage());
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame($this->paths['releases'].'/v1', realpath($this->paths['current']));
            self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM billing')->fetchColumn());
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        }
    }

    public function test_hard_linked_trusted_lock_is_rejected_before_backup_or_cutover(): void
    {
        if (! function_exists('link')) {
            self::markTestSkipped('Hard-link fixture unavailable.');
        }
        $unrelated = $this->home.'/other-secret.txt';
        file_put_contents($unrelated, 'DO_NOT_MODIFY');
        $lockPath = $this->paths['backup_vault'].'/.trusted-update.lock';
        if (! @link($unrelated, $lockPath)) {
            self::markTestSkipped('Host disallows hard-link fixture.');
        }

        try {
            $this->update($this->signedZip());
            self::fail('Hard-linked update lock was trusted.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock path or inode', $error->getMessage());
            self::assertSame('DO_NOT_MODIFY', file_get_contents($unrelated));
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        }
    }

    public function test_swapped_trusted_lock_during_maintenance_blocks_update_before_promotion(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX lock rename required.');
        }
        $signed = $this->signedZip();
        $lock = $this->paths['backup_vault'].'/.trusted-update.lock';
        $displaced = $this->paths['backup_vault'].'/.displaced-lock';
        $writes = static function () use ($lock, $displaced): bool {
            if (! rename($lock, $displaced)
                || file_put_contents($lock, 'replacement') === false) {
                throw new RuntimeException('Cannot arrange controlled lock swap.');
            }
            return true;
        };
        try {
            $this->update($signed, $writes);
            self::fail('Replaced lock identity was accepted during update.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock path or inode', $error->getMessage());
            self::assertSame('replacement', file_get_contents($lock));
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
            self::assertSame([], glob($this->paths['releases'].'/v1.2*') ?: []);
        }
    }

    public function test_second_offline_update_is_rejected_before_promoting_or_backing_up(): void
    {
        $signed = $this->signedZip();
        $lock = fopen($this->paths['backup_vault'].'/.trusted-update.lock', 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->update($signed);
            self::fail('Concurrent trusted updater must not enter its first phase.');
        } catch (DomainException $error) {
            self::assertStringContainsString('already in progress', $error->getMessage());
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['releases'].'/v1.2*') ?: []);
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_second_remote_update_does_not_even_download_when_lock_is_held(): void
    {
        $signed = $this->signedZip();
        unlink($this->paths['archive']);
        $called = false;
        $downloader = new TrustedReleaseDownloader(
            static function () use (&$called): int {
                $called = true;
                return 200;
            },
        );
        $lock = fopen($this->paths['backup_vault'].'/.trusted-update.lock', 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            (new TrustedReleaseUpdateJourney())->downloadAndExecute(
                $this->liveDb, $this->restoreDb, $signed,
                $this->publicKey, 'https://updates.example.com', '1.0.0',
                $this->paths, static fn (): bool => true, static fn (): bool => true, $downloader,
            );
            self::fail('Concurrent updater must not invoke its HTTPS transport.');
        } catch (DomainException $error) {
            self::assertStringContainsString('already in progress', $error->getMessage());
            self::assertFalse($called);
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['incoming'].'/WalidSite-update-*') ?: []);
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_health_failure_releases_outer_trusted_update_lock(): void
    {
        $signed = $this->signedZip();
        try {
            $this->update($signed, null, static fn (): bool => false);
            self::fail('Health failures must not leave the failed release active.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('previous release restored', $error->getMessage());
        }
        $lock = fopen($this->paths['backup_vault'].'/.trusted-update.lock', 'c+');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_signed_zip_to_isolated_restore_to_version_switch_preserves_live_data(): void
    {
        $result = $this->update($this->signedZip());
        self::assertSame('switched', $result['state']);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame(3, $result['files']);
        self::assertSame(1, $result['database_tables']);
        self::assertSame(1, $result['database_rows']);
        self::assertSame('NEW-vB', file_get_contents($this->paths['public'].'/index.php'));
        self::assertSame('LIVE-vA', file_get_contents($this->paths['releases'].'/v1/public_html/index.php'));
        self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        self::assertSame("\x00\xffUPLOAD", file_get_contents($this->paths['uploads'].'/avatar.png'));
        self::assertSame('محتوى عربي', $this->restoreDb->query('SELECT memo FROM billing WHERE id=1')->fetchColumn());
        self::assertSame("\x00\xffDATA", $this->restoreDb->query('SELECT payload FROM billing WHERE id=1')->fetchColumn());
    }

    public function test_untrusted_publisher_never_creates_candidate_or_touches_live_site(): void
    {
        try {
            $this->update($this->signedZip(true));
            self::fail('Wrong publisher signature should fail closed.');
        } catch (DomainException) {
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['releases'].'/v1.2*') ?: []);
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        }
    }

    public function test_unavailable_quiescence_blocks_all_backups_and_pointer_switches(): void
    {
        try {
            $this->update($this->signedZip(), static fn (): bool => false);
            self::fail('Writer activity must prevent any update.');
        } catch (DomainException) {
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame([], glob($this->paths['backup_vault'].'/mariadb-*') ?: []);
        }
    }

    public function test_nonempty_recovery_database_prevents_cutover_after_signed_promotion(): void
    {
        $this->restoreDb->exec('CREATE TABLE preexisting (id INT) ENGINE=InnoDB');
        try {
            $this->update($this->signedZip());
            self::fail('Recovery must be proven against an empty disposable database.');
        } catch (DomainException) {
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame('APP_KEY=keep-existing-secret', file_get_contents($this->paths['environment']));
        }
    }

    public function test_health_failure_rolls_back_code_without_destroying_user_data(): void
    {
        try {
            $this->update($this->signedZip(), null, static fn (): bool => false);
            self::fail('An unhealthy signed release cannot remain active.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('previous release restored', $error->getMessage());
            self::assertSame('LIVE-vA', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame('keep-private', file_get_contents($this->paths['private_data'].'/memo.txt'));
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM billing')->fetchColumn());
        }
    }
}
