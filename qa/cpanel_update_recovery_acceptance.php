<?php
/**
 * CI-only recovery acceptance. Uses two fresh disposable MariaDB schemas.
 * Does not use the real site database, files, PHP env or current release.
 */
declare(strict_types=1);

require dirname(__DIR__).'/backend/vendor/autoload.php';

use App\Domain\Deployment\OwnerUpdateRecoveryBackup;

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

    $live = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$source};charset=utf8mb4", 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $disposable = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$target};charset=utf8mb4", 'root', $rootPassword, [
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
    $result = $service->createAndProve(
        $live, $disposable,
        $app.'/.env', $app.'/storage/app', $web.'/uploads',
        $app, $web, $home.'/.walidsite-backups', $home.'/.walidsite-restore-drills',
        '0.1.1',
        static function () use (&$calls): bool { ++$calls; return true; },
    );

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
    echo "PASS: private .env/uploads/data and MariaDB rows restored independently; no live code changed\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$source.chr(96));
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$target.chr(96));
    } finally {
        cleanupRecovery($home);
    }
}
