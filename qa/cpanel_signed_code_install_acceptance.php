<?php
/**
 * CI-only real Setup/Update ZIP and MariaDB code cutover rehearsal.
 *
 * Test signing keys, foreign-writer assurance and health callbacks below are
 * DISPOSABLE FIXTURES. This is not a real HTTPS hosting acceptance receipt.
 */
declare(strict_types=1);

require dirname(__DIR__).'/backend/vendor/autoload.php';

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelOwnerCodeInstallCoordinator;
use App\Domain\Deployment\CpanelOwnerCodeUpdateHost;
use App\Domain\Deployment\CpanelReleaseMaintenance;
use App\Domain\Deployment\TrustedInstalledReleasePin;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_RECOVERY_HOST') !== '127.0.0.1'
    || !getenv('CI_RECOVERY_ROOT_PASSWORD') || count($argv) !== 5) {
    fwrite(STDERR, "Disposable GitHub Actions MariaDB required.\n");
    exit(2);
}
[, $setup, $update, $version, $sha] = $argv;
if (!preg_match('/\A[a-f0-9]{40}\z/D', $sha)
    || !preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version)
    || !is_file($setup) || !is_file($update)
    || is_link($setup) || is_link($update)
    || basename($setup) !== 'WalidSite-Setup-v'.$version.'.zip'
    || basename($update) !== 'WalidSite-Update-v'.$version.'.zip') {
    throw new RuntimeException('Untrusted CI package inputs.');
}
$numbers = array_map('intval', explode('.', $version));
if ($numbers === [0, 0, 0]) throw new RuntimeException('No earlier version exists.');
if ($numbers[2] > 0) --$numbers[2];
elseif ($numbers[1] > 0) --$numbers[1];
else --$numbers[0];
$old = implode('.', $numbers);
$pass = getenv('CI_RECOVERY_ROOT_PASSWORD');
$root = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
    'root', $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$id = bin2hex(random_bytes(5));
$base = sys_get_temp_dir().'/walidsite-code-cutover-'.$id;
if (!mkdir($base, 0700)) throw new RuntimeException('Cannot create private fixture.');
$schemas = [];

function cleanupCodeFixture(string $root): void
{
    if (!is_dir($root) || is_link($root)) return;
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($walk as $item) {
        $item->isDir() && !$item->isLink()
            ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}
function unpackCodeFixture(string $zipPath, string $target): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
        throw new RuntimeException('Invalid actual CI ZIP.');
    try {
        if (!$zip->extractTo($target))
            throw new RuntimeException('Cannot extract disposable ZIP.');
    } finally { $zip->close(); }
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($walk as $item) {
        if ($item->isLink() || !$item->isFile() && !$item->isDir()
            || !chmod($item->getPathname(), $item->isDir() ? 0700 : 0600))
            throw new RuntimeException('Unsafe fixture code node.');
    }
    chmod($target, 0700);
}
function codeDb(string $name, string $pass): PDO
{
    return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4',
        'root', $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
/** Second version exists ONLY inside disposable CI, not a published release. */
function synthesizeSecondSignedCodeZip(string $previous, string $target, string $nextVersion): void
{
    $source = new ZipArchive();
    if ($source->open($previous, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
        throw new RuntimeException('Cannot inspect first actual update ZIP.');
    $dest = new ZipArchive();
    if ($dest->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $source->close();
        throw new RuntimeException('Cannot create disposable second code ZIP.');
    }
    $versionPresent = false;
    try {
        for ($i = 0; $i < $source->numFiles; $i++) {
            $info = $source->statIndex($i);
            if (!is_array($info) || !is_string($info['name'] ?? null))
                throw new RuntimeException('Invalid source ZIP member.');
            $name = $info['name'];
            if (str_ends_with($name, '/')) {
                if (!$dest->addEmptyDir($name))
                    throw new RuntimeException('Cannot add test directory.');
                continue;
            }
            $bytes = $source->getFromIndex($i);
            if (!is_string($bytes))
                throw new RuntimeException('Cannot read test archive content.');
            if ($name === 'application/VERSION') {
                $bytes = $nextVersion."\n";
                $versionPresent = true;
            }
            if (!$dest->addFromString($name, $bytes))
                throw new RuntimeException('Cannot copy test archive member.');
        }
        if (!$versionPresent)
            throw new RuntimeException('Second release lacks version identity.');
    } finally {
        $dest->close();
        $source->close();
    }
}

try {
    $keys = sodium_crypto_sign_keypair();
    $key = base64_encode(sodium_crypto_sign_publickey($keys));
    $digest = hash_file('sha256', $update);
    $size = filesize($update);
    if (!$digest || !$size) throw new RuntimeException('Empty release input.');
    $payload = json_encode([
        'product' => 'walidsite',
        'version' => $version,
        'source_sha' => $sha,
        'artifact_sha256' => $digest,
        'artifact_size' => $size,
        'min_php' => '8.3.0',
        'artifact_file' => 'WalidSite-update-v'.$version.'.zip',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $envelope = json_encode([
        'payload' => base64_encode($payload),
        'signature' => base64_encode(sodium_crypto_sign_detached(
            $payload, sodium_crypto_sign_secretkey($keys))),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    foreach (['ok', 'chained-health', 'chained-tampered', 'rejected-health', 'late-populated-restore'] as $mode) {
        $home = $base.'/'.$mode;
        mkdir($home, 0700);
        unpackCodeFixture($setup, $home);
        $app = $home.'/application';
        $public = $home.'/public_html';
        if (trim((string) file_get_contents($app.'/VERSION')) !== $version)
            throw new RuntimeException('Setup ZIP version differs.');
        file_put_contents($app.'/VERSION', $old."\n");
        mkdir($app.'/storage/app/private/verified-updates/ci', 0700, true);
        mkdir($public.'/uploads', 0700, true);
        file_put_contents($app.'/.env', "APP_KEY=base64:DISPOSABLE_CI_ONLY\n");
        file_put_contents($app.'/storage/app/owner.txt', 'KEEP_OWNER');
        file_put_contents($public.'/uploads/avatar.png', 'KEEP_UPLOAD');
        $candidate = $home.'/.walidsite-releases/v'.$version.'-'.$sha;
        mkdir($candidate, 0700, true);
        chmod($home.'/.walidsite-releases', 0700);
        unpackCodeFixture($update, $candidate);
        $inbox = $app.'/storage/app/private/verified-updates/ci';
        $artifact = $inbox.'/WalidSite-update-v'.$version.'.zip';
        if (!copy($update, $artifact) || !chmod($artifact, 0600))
            throw new RuntimeException('Private signed ZIP could not be retained.');

        $source = 'ci_release_src_'.$mode.'_'.$id;
        $target = 'ci_release_restore_'.$mode.'_'.$id;
        $source = str_replace('-', '_', $source);
        $target = str_replace('-', '_', $target);
        foreach ([$source, $target] as $schema) {
            $root->exec('CREATE DATABASE '.chr(96).$schema.chr(96).' CHARACTER SET utf8mb4');
            $schemas[] = $schema;
        }
        $live = codeDb($source, $pass);
        $restore = codeDb($target, $pass);
        $live->exec('CREATE TABLE owner_records (id INT PRIMARY KEY, note VARCHAR(50)) ENGINE=InnoDB');
        $live->exec("INSERT INTO owner_records VALUES (1,'PRESERVE_ROW'),(2,'RECOVERY_CHECK')");

        $host = new class($home, in_array($mode, ['ok', 'chained-health', 'chained-tampered'], true), $version, $sha, $restore,
            $mode === 'late-populated-restore')
            implements CpanelOwnerCodeUpdateHost {
            public int $probes = 0;
            public int $isolationChecks = 0;
            public function __construct(private string $home,
                private bool $healthy, private string $version,
                private string $sha, private PDO $db,
                private bool $populateAfterPreflight) {}
            public function externalWritersStopped(): bool
            {
                ++$this->isolationChecks;
                // First call precedes the initial database guard. A second
                // host check occurs after exclusive lease acquisition; use
                // this fixture to poison the recovery database during the
                // intervening signature and staging checks.
                if ($this->populateAfterPreflight && $this->isolationChecks === 2) {
                    $this->db->exec(
                        'CREATE TABLE unexpected_owner_data (id INT PRIMARY KEY) ENGINE=InnoDB'
                    );
                }
                return true;
            }
            public function disposableRestoreConnection(): PDO { return $this->db; }
            public function probeNewRelease(string $token, string $version, string $sha): bool
            {
                ++$this->probes;
                (new CpanelReleaseMaintenance())->assertLiveHealthProbe($this->home, $token);
                $pointer = json_decode((string) file_get_contents(
                    $this->home.'/.walidsite-active-release.json'), true, 8, JSON_THROW_ON_ERROR);
                return $this->healthy && $version === $this->version
                    && $sha === $this->sha
                    && ($pointer['root'] ?? null) === '.walidsite-releases/v'.$version.'-'.$sha;
            }
        };

        $lease = new CpanelConsoleWriterLease();
        $lease->beginForConsole($app);
        $rejected = false;
        try {
            $result = (new CpanelOwnerCodeInstallCoordinator())->install(
                $host, $lease, $live, $restore, $envelope, $key,
                'https://updates.walidatiya.com', $old, $home, $inbox, $artifact);
            if (!in_array($mode, ['ok', 'chained-health', 'chained-tampered'], true) || $result['state'] !== 'installed_verified'
                || $result['version'] !== $version || $result['from'] !== $old
                || $result['source_sha'] !== $sha || !isset($result['backup_id'])) {
                throw new RuntimeException('Unverified source or update was accepted.');
            }
        } catch (Throwable $error) {
            $expectedFailure = $mode === 'rejected-health'
                ? 'old code pointer restored'
                : ($mode === 'late-populated-restore'
                    ? 'Disposable recovery target is not empty.'
                    : null);
            if ($expectedFailure === null
                || !str_contains($error->getMessage(), $expectedFailure)) {
                throw $error;
            }
            $rejected = true;
        } finally { $lease->end(); }

        if ($mode === 'late-populated-restore') {
            if (!$rejected || $host->probes !== 0
                || file_get_contents($app.'/.env') !== "APP_KEY=base64:DISPOSABLE_CI_ONLY\n"
                || file_get_contents($app.'/storage/app/owner.txt') !== 'KEEP_OWNER'
                || file_get_contents($public.'/uploads/avatar.png') !== 'KEEP_UPLOAD'
                || $live->query('SELECT note FROM owner_records WHERE id=1')->fetchColumn() !== 'PRESERVE_ROW'
                || $host->isolationChecks !== 2
                || is_file($home.'/.walidsite-active-release.json')
                || is_file($home.'/.walidsite-update-maintenance.json')
                || is_dir($home.'/.walidsite-backups')
                || (int) $restore->query(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"
                )->fetchColumn() !== 1) {
                throw new RuntimeException('Late-populated recovery schema was not blocked pre-backup.');
            }
        } elseif (($mode === 'rejected-health') !== $rejected || $host->probes !== 1
            || file_get_contents($app.'/.env') !== "APP_KEY=base64:DISPOSABLE_CI_ONLY\n"
            || file_get_contents($app.'/storage/app/owner.txt') !== 'KEEP_OWNER'
            || file_get_contents($public.'/uploads/avatar.png') !== 'KEEP_UPLOAD'
            || $live->query('SELECT note FROM owner_records WHERE id=1')->fetchColumn() !== 'PRESERVE_ROW'
            || (int) $restore->query('SELECT COUNT(*) FROM owner_records')->fetchColumn() !== 2
            || in_array($mode, ['ok', 'chained-health', 'chained-tampered'], true) !== is_file($home.'/.walidsite-active-release.json')) {
            throw new RuntimeException('Owner data/recovery or release pointer was not preserved.');
        }
        // A second, CI-only signed CODE-ONLY candidate is derived from the
        // exact source-attested Update ZIP by changing solely VERSION. The
        // ephemeral signing key and synthetic SHA are not publisher authority
        // or proof of a real second release generated from a Git commit.
        if (in_array($mode, ['ok', 'chained-health', 'chained-tampered'], true)) {
            $nextParts = array_map('intval', explode('.', $version));
            ++$nextParts[2];
            $nextVersion = implode('.', $nextParts);
            $nextSha = hash('sha1', 'DISPOSABLE-CHAIN-FIXTURE:'.$sha);
            $nextArchive = $inbox.'/WalidSite-update-v'.$nextVersion.'.zip';
            synthesizeSecondSignedCodeZip($update, $nextArchive, $nextVersion);
            if (!chmod($nextArchive, 0600))
                throw new RuntimeException('Cannot secure second fixture ZIP.');
            $nextCandidate = $home.'/.walidsite-releases/v'.$nextVersion.'-'.$nextSha;
            if (!mkdir($nextCandidate, 0700, true))
                throw new RuntimeException('Cannot create second candidate directory.');
            unpackCodeFixture($nextArchive, $nextCandidate);

            $nextHash = hash_file('sha256', $nextArchive);
            $nextSize = filesize($nextArchive);
            if (!$nextHash || !$nextSize)
                throw new RuntimeException('Empty second fixture ZIP.');
            $nextPayload = json_encode([
                'product' => 'walidsite',
                'version' => $nextVersion,
                'source_sha' => $nextSha,
                'artifact_sha256' => $nextHash,
                'artifact_size' => $nextSize,
                'min_php' => '8.3.0',
                'artifact_file' => 'WalidSite-update-v'.$nextVersion.'.zip',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $nextEnvelope = json_encode([
                'payload' => base64_encode($nextPayload),
                'signature' => base64_encode(sodium_crypto_sign_detached(
                    $nextPayload, sodium_crypto_sign_secretkey($keys))),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $nextSchema = 'ci_release_restore_next_'.$mode.'_'.$id;
            $nextSchema = str_replace('-', '_', $nextSchema);
            $root->exec('CREATE DATABASE '.chr(96).$nextSchema.chr(96).' CHARACTER SET utf8mb4');
            $schemas[] = $nextSchema;
            $secondRestore = codeDb($nextSchema, $pass);
            $nextHost = new class($home, $mode === 'ok', $nextVersion, $nextSha,
                $mode === 'chained-tampered' ? $candidate.'/application/bootstrap/app.php' : null)
                implements CpanelOwnerCodeUpdateHost {
                public int $probes = 0;
                public function __construct(
                    private string $home, private bool $healthy,
                    private string $version, private string $sha,
                    private ?string $tamperPreviousFile,
                ) {}
                public function externalWritersStopped(): bool { return true; }
                public function acceptHealthyRetry(): void { $this->healthy = true; }
                public function disposableRestoreConnection(): PDO
                {
                    throw new RuntimeException('Fixture supplies its separate recovery database directly.');
                }
                public function probeNewRelease(string $token, string $version, string $sha): bool
                {
                    ++$this->probes;
                    (new CpanelReleaseMaintenance())->assertLiveHealthProbe($this->home, $token);
                    $pointer = json_decode((string) file_get_contents(
                        $this->home.'/.walidsite-active-release.json'), true, 8, JSON_THROW_ON_ERROR);
                    if ($this->tamperPreviousFile !== null
                        && file_put_contents($this->tamperPreviousFile,
                            '<?php // MUTATED_BY_DISPOSABLE_CI_HEALTH') === false) {
                        throw new RuntimeException('Disposable adversarial code mutation failed.');
                    }
                    return $this->healthy && $version === $this->version
                        && $sha === $this->sha
                        && ($pointer['root'] ?? null) === '.walidsite-releases/v'.$version.'-'.$sha;
                }
            };

            // The second HTTP worker really boots the previously activated
            // immutable application. An original HOME/application claim would
            // be a forged running-root identity at this point.
            $running = $candidate.'/application';
            $secondLease = new CpanelConsoleWriterLease();
            $secondLease->beginForConsole($running);
            $secondFailed = false;
            try {
                $second = (new CpanelOwnerCodeInstallCoordinator())->install(
                    $nextHost, $secondLease, $live, $secondRestore,
                    $nextEnvelope, $key, 'https://updates.walidatiya.com',
                    $version, $home, $inbox, $nextArchive, $running,
                );
                if ($mode !== 'ok' || $second['state'] !== 'installed_verified'
                    || $second['version'] !== $nextVersion
                    || $second['from'] !== $version || !isset($second['backup_id'])) {
                    throw new RuntimeException('Chained signed code cutover was not verified.');
                }
            } catch (Throwable $failure) {
                $expectedFailure = match ($mode) {
                    'chained-health' => 'old code pointer restored',
                    'chained-tampered' => 'Previous release code tree changed during rollback.',
                    default => null,
                };
                if ($expectedFailure === null
                    || !str_contains($failure->getMessage(), $expectedFailure)) {
                    throw $failure;
                }
                $secondFailed = true;
            } finally { $secondLease->end(); }

            $afterPointer = json_decode((string) file_get_contents(
                $home.'/.walidsite-active-release.json'), true, 8, JSON_THROW_ON_ERROR);
            $expectedRoot = $mode === 'ok'
                ? '.walidsite-releases/v'.$nextVersion.'-'.$nextSha
                : '.walidsite-releases/v'.$version.'-'.$sha;
            if ($afterPointer['root'] !== $expectedRoot
                || $afterPointer['version'] !== ($mode === 'ok' ? $nextVersion : $version)
                || $nextHost->probes !== 1
                || ($mode !== 'ok') !== $secondFailed
                || ($mode === 'chained-tampered') !== is_file(
                    $home.'/.walidsite-update-maintenance.json')
                || file_get_contents($app.'/.env') !== "APP_KEY=base64:DISPOSABLE_CI_ONLY\n"
                || file_get_contents($app.'/storage/app/owner.txt') !== 'KEEP_OWNER'
                || file_get_contents($public.'/uploads/avatar.png') !== 'KEEP_UPLOAD'
                || $live->query('SELECT note FROM owner_records WHERE id=1')->fetchColumn() !== 'PRESERVE_ROW'
                || (int) $secondRestore->query('SELECT COUNT(*) FROM owner_records')->fetchColumn() !== 2) {
                throw new RuntimeException('Second signed update corrupted previous release or private owner data.');
            }

            if ($mode === 'chained-tampered') {
                // The disposable health probe modified the previously
                // healthy immutable PHP tree. Never auto-resume that code.
                if (! $secondFailed
                    || ! is_file($home.'/.walidsite-update-maintenance.json')
                    || file_get_contents($running.'/bootstrap/app.php')
                        !== '<?php // MUTATED_BY_DISPOSABLE_CI_HEALTH') {
                    throw new RuntimeException('Tampered chained rollback bypassed maintenance.');
                }

                // A fresh signed retry must be refused while quarantine
                // remains, even with a different empty recovery database.
                $deniedSchema = 'ci_release_denied_'.$id;
                $root->exec('CREATE DATABASE '.chr(96).$deniedSchema.chr(96).' CHARACTER SET utf8mb4');
                $schemas[] = $deniedSchema;
                $deniedRestore = codeDb($deniedSchema, $pass);
                $nextHost->acceptHealthyRetry();
                $deniedLease = new CpanelConsoleWriterLease();
                $deniedLease->beginForConsole($running);
                $retryAccepted = false;
                try {
                    (new CpanelOwnerCodeInstallCoordinator())->install(
                        $nextHost, $deniedLease, $live, $deniedRestore,
                        $nextEnvelope, $key, 'https://updates.walidatiya.com',
                        $version, $home, $inbox, $nextArchive, $running,
                    );
                    $retryAccepted = true;
                } catch (Throwable) {
                    // Rejection is expected, regardless of the error text.
                } finally {
                    $deniedLease->end();
                }
                if ($retryAccepted || $nextHost->probes !== 1
                    || ! is_file($home.'/.walidsite-update-maintenance.json')
                    || $live->query('SELECT note FROM owner_records WHERE id=1')->fetchColumn()
                        !== 'PRESERVE_ROW'
                    || file_get_contents($app.'/.env')
                        !== "APP_KEY=base64:DISPOSABLE_CI_ONLY\n"
                    || file_get_contents($app.'/storage/app/owner.txt') !== 'KEEP_OWNER'
                    || file_get_contents($public.'/uploads/avatar.png') !== 'KEEP_UPLOAD') {
                    throw new RuntimeException('Mutated chained release resumed or changed owner data.');
                }
            }

            if ($mode === 'chained-health') {
                // Failed health cannot be a successful update. Restore the
                // immutable prior code and independently verify it is live-
                // eligible without any lingering maintenance barrier. This
                // disposable check is NOT a real HTTPS Admin login.
                if (is_file($home.'/.walidsite-update-maintenance.json')
                    || (new TrustedInstalledReleasePin())->read(
                        $running, $public, $old,
                    ) !== $version) {
                    throw new RuntimeException(
                        'Verified chained rollback did not resume the previous release.'
                    );
                }

                // Retry the same CI-only signed candidate from the prior
                // healthy release, with a fresh empty independent MariaDB
                // recovery database and a newly acquired exclusive lease.
                // A stale candidate or maintenance marker must not strand
                // the owner after a safe rollback.
                $retrySchema = 'ci_release_retry_'.$id;
                $root->exec('CREATE DATABASE '.chr(96).$retrySchema.chr(96).' CHARACTER SET utf8mb4');
                $schemas[] = $retrySchema;
                $retryRestore = codeDb($retrySchema, $pass);
                $nextHost->acceptHealthyRetry();
                $retryLease = new CpanelConsoleWriterLease();
                $retryLease->beginForConsole($running);
                try {
                    $retryResult = (new CpanelOwnerCodeInstallCoordinator())->install(
                        $nextHost, $retryLease, $live, $retryRestore,
                        $nextEnvelope, $key, 'https://updates.walidatiya.com',
                        $version, $home, $inbox, $nextArchive, $running,
                    );
                } finally {
                    $retryLease->end();
                }
                if ($retryResult['state'] !== 'installed_verified'
                    || $retryResult['version'] !== $nextVersion
                    || $retryResult['from'] !== $version
                    || $retryResult['source_sha'] !== $nextSha
                    || !isset($retryResult['backup_id'])
                    || $nextHost->probes !== 2
                    || is_file($home.'/.walidsite-update-maintenance.json')
                    || $live->query('SELECT note FROM owner_records WHERE id=1')->fetchColumn() !== 'PRESERVE_ROW'
                    || (int) $retryRestore->query('SELECT COUNT(*) FROM owner_records')->fetchColumn() !== 2
                    || file_get_contents($app.'/.env') !== "APP_KEY=base64:DISPOSABLE_CI_ONLY\n"
                    || file_get_contents($app.'/storage/app/owner.txt') !== 'KEEP_OWNER'
                    || file_get_contents($public.'/uploads/avatar.png') !== 'KEEP_UPLOAD') {
                    throw new RuntimeException(
                        'Chained rollback retry did not preserve identity, code or owner data.'
                    );
                }
            }
        }
    }

    echo json_encode([
        'schema' => 'walidsite.disposable-code-install-acceptance.v1',
        'source_sha' => $sha,
        'version' => $version,
        'same_build_setup_and_update' => true,
        'signed_code_cutover_fixture' => true,
        'disposable_mariadb_restore' => true,
        'failed_health_pointer_reverted' => true,
        'late_populated_restore_blocked' => true,
        'chained_code_only_fixture_tested' => true,
        'chained_health_failure_previous_pointer_restored' => true,
        'chained_health_recovery_cleared_maintenance' => true,
        'chained_health_recovery_retry_succeeded' => true,
        'chained_tampered_previous_code_keeps_maintenance_and_blocks_retry' => true,
        'chained_fixture_source_sha_not_git_attested' => true,
        'real_https_host_verified' => false,
        'independent_foreign_writer_isolation' => false,
        'publisher_signature_verified_for_production' => false,
        'production_ready' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} finally {
    foreach ($schemas as $schema)
        $root->exec('DROP DATABASE IF EXISTS '.chr(96).$schema.chr(96));
    cleanupCodeFixture($base);
}
