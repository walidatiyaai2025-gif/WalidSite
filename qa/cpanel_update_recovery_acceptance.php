<?php
/**
 * CI-only recovery acceptance. Uses two fresh disposable MariaDB schemas.
 * Does not use the real site database, files, PHP env or current release.
 */
declare(strict_types=1);

require dirname(__DIR__).'/backend/vendor/autoload.php';

use App\Domain\Deployment\CpanelOwnerBackupCoordinator;
use App\Domain\Deployment\OwnerUpdateRecoveryBackup;
use App\Domain\Deployment\PrivateRecoverySnapshot;
use App\Domain\Deployment\MariaDbRecoverySnapshot;

$host = getenv('CI_RECOVERY_HOST') ?: '';
$rootPassword = getenv('CI_RECOVERY_ROOT_PASSWORD') ?: '';
if ($host !== '127.0.0.1' || $rootPassword === '' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Recovery acceptance requires CI loopback MariaDB.\n");
    exit(2);
}

$root = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4", 'root', $rootPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$suffix = bin2hex(random_bytes(5));
$source = 'walidsite_recovery_source_'.$suffix;
$target = 'walidsite_recovery_target_'.$suffix;
$corruptTarget = 'walidsite_recovery_corrupt_'.$suffix;
$lateTarget = 'walidsite_recovery_late_'.$suffix;
$home = sys_get_temp_dir().'/walidsite-recovery-acceptance-'.$suffix;

function cleanupRecovery(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        $entry->isDir() && ! $entry->isLink()
            ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($directory);
}

try {
    $root->exec('CREATE DATABASE '.chr(96).$source.chr(96).' CHARACTER SET utf8mb4');
    $root->exec('CREATE DATABASE '.chr(96).$target.chr(96).' CHARACTER SET utf8mb4');
    $root->exec('CREATE DATABASE '.chr(96).$corruptTarget.chr(96).' CHARACTER SET utf8mb4');
    $root->exec('CREATE DATABASE '.chr(96).$lateTarget.chr(96).' CHARACTER SET utf8mb4');

    $live = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$source};charset=utf8mb4", 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $disposable = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$target};charset=utf8mb4", 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $corrupt = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$corruptTarget};charset=utf8mb4", 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $late = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$lateTarget};charset=utf8mb4", 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $live->exec('CREATE TABLE records (id INT PRIMARY KEY, note VARCHAR(100) NOT NULL) ENGINE=InnoDB');
    $live->exec("INSERT INTO records(id, note) VALUES (1,'retain-original'), (2,'verify-database-restore')");

    $app = $home.'/application';
    $web = $home.'/public_html';
    mkdir($app.'/storage/app', 0700, true);
    mkdir($web.'/uploads', 0750, true);
    file_put_contents($app.'/.env', "APP_KEY=base64:RECOVERY_PRIVATE_CI_ONLY\n");
    file_put_contents($app.'/storage/app/personal.txt', 'private-avatar-example');
    file_put_contents($web.'/uploads/avatar.png', 'fixture-binary-content');

    $service = new OwnerUpdateRecoveryBackup();
    $calls = 0;
    // Prove the REAL cross-request writer lock + private maintenance
    // transaction around the same disposable files/MariaDB restore. This is
    // an isolated CI fixture, NOT evidence of production foreign-writer drain.
    $result = (new CpanelOwnerBackupCoordinator())->prove(
        $live, $disposable,
        $app.'/.env', $app.'/storage/app', $web.'/uploads',
        $app, $web, $home.'/.walidsite-backups', $home.'/.walidsite-restore-drills',
        '0.1.1', 'legacy',
        static function () use (&$calls): bool { ++$calls; return true; },
    );
    if (is_file($home.'/.walidsite-update-maintenance.json')
        || (fileperms($home.'/.walidsite-writer-drain.lock') & 0777) !== 0600
        || $result['code_update_performed'] !== false) {
        throw new RuntimeException('Owner backup did not safely release its cPanel isolation barrier.');
    }

    if ($calls < 2 || $result['tables'] !== 1 || $result['rows'] !== 2
        || $result['files'] !== 3 || $result['version'] !== '0.1.1'
        || (int) $disposable->query('SELECT COUNT(*) FROM records')->fetchColumn() !== 2
        || $disposable->query('SELECT note FROM records WHERE id=1')->fetchColumn() !== 'retain-original'
        || $live->query('SELECT note FROM records WHERE id=2')->fetchColumn() !== 'verify-database-restore') {
        throw new RuntimeException('A real private-file or MariaDB restore check did not pass.');
    }

    $receipt = $home.'/.walidsite-backups/receipt-'.$result['id'].'.json';
    $raw = file_get_contents($receipt);
    if ($raw === false || (fileperms($receipt) & 0777) !== 0600
        || str_contains($raw, $rootPassword)
        || str_contains($raw, 'RECOVERY_PRIVATE_CI_ONLY')
        || str_contains($raw, $home)
        || json_decode($raw, true, 16, JSON_THROW_ON_ERROR)['code_update_performed'] !== false) {
        throw new RuntimeException('Recovery receipt privacy or status check failed.');
    }
    // An interrupted writer can invalidate a snapshot before any backup begins.
    // This must not issue a second recovery receipt or touch the live database.
    $beforeReceipts = glob($home.'/.walidsite-backups/receipt-*.json');
    try {
        $service->createAndProve(
            $live, $corrupt,
            $app.'/.env', $app.'/storage/app', $web.'/uploads',
            $app, $web, $home.'/.walidsite-backups', $home.'/.walidsite-restore-drills',
            '0.1.1', static fn (): bool => false,
        );
        throw new RuntimeException('Unquiesced writers were accepted for recovery.');
    } catch (DomainException $expected) {
        if (! str_contains($expected->getMessage(), 'Maintenance')) {
            throw $expected;
        }
    }
    if (glob($home.'/.walidsite-backups/receipt-*.json') !== $beforeReceipts) {
        throw new RuntimeException('Rejected recovery operation issued a receipt.');
    }

    // Tamper with a byte of the REAL private backup after successful creation.
    // The source .env and uploads must survive unchanged, and no restored copy
    // of the corrupted snapshot may be treated as valid.
    $fileBackups = glob($home.'/.walidsite-backups/recovery-*', GLOB_ONLYDIR);
    $databaseBackups = glob($home.'/.walidsite-backups/.mariadb-*', GLOB_ONLYDIR);
    if (count($fileBackups) !== 1 || count($databaseBackups) !== 1) {
        throw new RuntimeException('Expected one private-file and one database snapshot.');
    }
    $envBackup = $fileBackups[0].'/environment/.env';
    if (! is_file($envBackup) || file_put_contents($envBackup, "TAMPERED-PRIVATE-BACKUP\n") === false) {
        throw new RuntimeException('Unable to prepare tampered private backup fixture.');
    }
    try {
        (new PrivateRecoverySnapshot())->drill(
            $fileBackups[0], $result['file_sha256'], $home.'/.walidsite-restore-drills',
        );
        throw new RuntimeException('Corrupted private-file restore was accepted.');
    } catch (DomainException $expected) {
        if (! str_contains($expected->getMessage(), 'corrupt')) {
            throw $expected;
        }
    }

    // Preflight must detect the corrupt MariaDB row stream BEFORE DDL.
    // A third fresh disposable schema proves the failure left zero tables.
    $rowsBackup = $databaseBackups[0].'/records.jsonl';
    if (! is_file($rowsBackup) || file_put_contents($rowsBackup, "{\"tampered\":true}\n") === false) {
        throw new RuntimeException('Unable to prepare tampered MariaDB fixture.');
    }
    try {
        (new MariaDbRecoverySnapshot())->restoreAndVerify(
            $corrupt, $databaseBackups[0], $result['database_sha256'],
        );
        throw new RuntimeException('Corrupted MariaDB restore was accepted.');
    } catch (DomainException $expected) {
        if (! str_contains($expected->getMessage(), 'checksum')) {
            throw $expected;
        }
    }
    if ((int) $corrupt->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '
        .$corrupt->quote($corruptTarget))->fetchColumn() !== 0
        || file_get_contents($app.'/.env') !== "APP_KEY=base64:RECOVERY_PRIVATE_CI_ONLY\n"
        || file_get_contents($web.'/uploads/avatar.png') !== 'fixture-binary-content'
        || $live->query('SELECT note FROM records WHERE id=1')->fetchColumn() !== 'retain-original'
        || $live->query('SELECT note FROM records WHERE id=2')->fetchColumn() !== 'verify-database-restore') {
        throw new RuntimeException('Rejected recovery corrupted live state or dirtied target database.');
    }
    // Prove a late vault mode mutation AFTER real file snapshot AND real
    // MariaDB disposable restore cannot produce a second trusted receipt.
    // We deliberately use a separate disposable DB: no production DB touched.
    $previousReceipts = glob($home.'/.walidsite-backups/receipt-*.json') ?: [];
    $lateCalls = 0;
    try {
        $service->createAndProve(
            $live, $late,
            $app.'/.env', $app.'/storage/app', $web.'/uploads',
            $app, $web, $home.'/.walidsite-backups', $home.'/.walidsite-restore-drills',
            '0.1.1',
            static function () use (&$lateCalls, $home): bool {
                ++$lateCalls;
                if ($lateCalls === 3) {
                    chmod($home.'/.walidsite-backups', 0777);
                }
                return true;
            },
        );
        throw new RuntimeException('An exposed vault after DB/file restore produced a trusted receipt.');
    } catch (DomainException $expected) {
        if (! str_contains($expected->getMessage(), 'directory')) {
            throw $expected;
        }
    } finally {
        chmod($home.'/.walidsite-backups', 0700);
    }
    if ($lateCalls !== 3
        || (glob($home.'/.walidsite-backups/receipt-*.json') ?: []) !== $previousReceipts
        || (int) $late->query('SELECT COUNT(*) FROM records')->fetchColumn() !== 2
        || $live->query('SELECT note FROM records WHERE id=1')->fetchColumn() !== 'retain-original'
        || file_get_contents($app.'/.env') !== "APP_KEY=base64:RECOVERY_PRIVATE_CI_ONLY\n"
        || file_get_contents($web.'/uploads/avatar.png') !== 'fixture-binary-content') {
        throw new RuntimeException('Late owner recovery vault guard or live data preservation failed.');
    }
    echo "PASS: late post-MariaDB-restore vault chmod denies recovery receipt\n";

    echo "PASS: corrupt private-file/DB backups rejected; live data and empty recovery DB protected\n";
    echo "PASS: private .env/uploads/data and MariaDB rows restored independently; no live code changed\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$source.chr(96));
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$target.chr(96));
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$corruptTarget.chr(96));
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$lateTarget.chr(96));
    } finally {
        cleanupRecovery($home);
    }
}
