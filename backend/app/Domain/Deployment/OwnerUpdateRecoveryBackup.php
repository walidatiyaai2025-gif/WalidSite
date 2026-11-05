<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Browser-triggered, real recovery rehearsal for first-installed cPanel sites.
 *
 * Snapshotting is not a code deployment. This class copies the original
 * .env, uploaded data, InnoDB tables/rows and verifies each against a
 * disposable file tree and a distinct, empty MariaDB database.
 *
 * All paths are server-derived from the installed application; the owner
 * cannot choose backup paths, scripts or a replacement production database.
 */
final class OwnerUpdateRecoveryBackup
{
    /**
     * @param callable():bool $writesQuiesced True ONLY when the application
     *   is already in maintenance and all independent write queues are stopped.
     * @return array{id:string,version:string,files:int,bytes:int,tables:int,rows:int,file_sha256:string,database_sha256:string}
     */
    public function createAndProve(
        PDO $source,
        PDO $disposable,
        string $environmentFile,
        string $privateData,
        string $publicUploads,
        string $applicationBase,
        string $publicRoot,
        string $vaultPath,
        string $restorePath,
        string $installedVersion,
        callable $writesQuiesced,
    ): array {
        if (! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $installedVersion)) {
            throw new DomainException('Installed version cannot be identified.');
        }

        // A backup taken during active writes is not an acceptable update
        // recovery receipt. Recheck after acquiring the exclusive lock.
        if ($writesQuiesced() !== true) {
            throw new DomainException('Maintenance and stopped writers are required for backup.');
        }

        $base = realpath($applicationBase);
        $public = realpath($publicRoot);
        $environment = realpath($environmentFile);
        $data = realpath($privateData);
        $uploads = realpath($publicUploads);
        if ($base === false || $public === false || $environment === false
            || $data === false || $uploads === false
            || is_link($applicationBase) || is_link($publicRoot)
            || is_link($environmentFile) || is_link($privateData) || is_link($publicUploads)
            || ! is_dir($base) || ! is_dir($public) || ! is_file($environment)
            || ! is_dir($data) || ! is_dir($uploads)
            || $base === $public
            || $this->overlap($base, $public)
            || ! $this->under($environment, $base)
            || ! $this->under($data, $base)
            || ! $this->under($uploads, $public)) {
            throw new DomainException('Installed private files and uploads are not safely isolated.');
        }

        $home = realpath(dirname($base));
        if ($home === false || ! is_dir($home) || ! is_writable($home)
            || dirname($public) !== $home
            || dirname($vaultPath) !== $home || dirname($restorePath) !== $home
            || $vaultPath === $restorePath || ! str_starts_with(basename($vaultPath), '.walidsite-')
            || ! str_starts_with(basename($restorePath), '.walidsite-')) {
            throw new DomainException('cPanel account home and private recovery paths are unsupported.');
        }

        $vault = $this->makePrivateDirectory($vaultPath);
        $drills = $this->makePrivateDirectory($restorePath);
        if ($this->overlap($vault, $base) || $this->overlap($vault, $public)
            || $this->overlap($drills, $base) || $this->overlap($drills, $public)
            || $this->overlap($drills, $vault)) {
            throw new DomainException('Backup roots must be isolated from code, uploads and each other.');
        }

        $lockFile = $vault.'/.owner-update-backup.lock';
        if (is_link($lockFile) || (file_exists($lockFile) && ! is_file($lockFile))) {
            throw new DomainException('Private recovery lock is unsafe.');
        }
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Unable to create private recovery lock.');
        }

        try {
            $this->assertSecureLockIdentity($lock, $lockFile);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another recovery operation is running.');
            }
            // Do not trust the pathname after acquiring a lock on an inode:
            // another local actor can replace it or create a hard-link alias.
            $this->assertSecureLockIdentity($lock, $lockFile);
            if ($writesQuiesced() !== true) {
                throw new DomainException('Maintenance changed after recovery lock.');
            }
            // The maintenance callback itself may have switched the named
            // lock. Fail before creating ANY private/file/SQL snapshot.
            $this->assertSecureLockIdentity($lock, $lockFile);

            $files = (new PrivateRecoverySnapshot())->create(
                $environment, $data, $uploads, $vault,
            );
            $fileProof = (new PrivateRecoverySnapshot())->drill(
                $files['directory'], $files['manifest_sha256'], $drills,
            );
            if ($fileProof['files'] !== $files['files'] || $fileProof['bytes'] !== $files['bytes']) {
                throw new DomainException('Private file restore proof is inconsistent.');
            }

            $database = (new MariaDbRecoverySnapshot())->create(
                $source, $vault, $public,
            );
            $dbProof = (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $disposable, $database['directory'], $database['manifest_sha256'],
            );
            if ($dbProof['tables'] !== $database['tables'] || $dbProof['rows'] !== $database['rows']) {
                throw new DomainException('MariaDB restore proof is inconsistent.');
            }

            // Ledger contains NO database credentials, APP_KEY, account email,
            // absolute file paths, or user data. The actual snapshots remain
            // chmod 0600 / directories 0700 outside the web document root.
            $id = bin2hex(random_bytes(16));
            $receipt = [
                'schema' => 'walidsite.backup-restore-receipt.v1',
                'id' => $id,
                'created_at' => gmdate('c'),
                'installed_version' => $installedVersion,
                'files' => $fileProof['files'],
                'bytes' => $fileProof['bytes'],
                'tables' => $dbProof['tables'],
                'rows' => $dbProof['rows'],
                'file_sha256' => $files['manifest_sha256'],
                'database_sha256' => $database['manifest_sha256'],
                'code_update_performed' => false,
            ];
            $this->saveReceipt($vault.'/receipt-'.$id.'.json', $receipt);

            return [
                'id' => $id,
                'version' => $installedVersion,
                'files' => $fileProof['files'],
                'bytes' => $fileProof['bytes'],
                'tables' => $dbProof['tables'],
                'rows' => $dbProof['rows'],
                'file_sha256' => $files['manifest_sha256'],
                'database_sha256' => $database['manifest_sha256'],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Require both the open descriptor and the named lock to be the same
     * single-linked regular inode. is_link() alone does not prevent hard-link
     * aliasing or a lock pathname swap before the protected operation.
     *
     * @param resource $stream
     */
    private function assertSecureLockIdentity($stream, string $path): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || $opened['dev'] !== $named['dev']
            || $opened['ino'] !== $named['ino']
            || is_link($path)) {
            throw new DomainException('Unsafe owner backup lock identity; no backup was performed.');
        }
    }

    private function makePrivateDirectory(string $path): string
    {
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw new DomainException('Private recovery directory is unsafe.');
        }
        if (! is_dir($path) && ! @mkdir($path, 0700)) {
            throw new RuntimeException('Unable to initialize private recovery directory.');
        }
        $real = realpath($path);
        if ($real === false || $real !== $path || ! is_writable($real)
            || ! @chmod($real, 0700)) {
            throw new DomainException('Recovery directory cannot be made private.');
        }

        return $real;
    }

    /** @param array<string,mixed> $receipt */
    private function saveReceipt(string $path, array $receipt): void
    {
        $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot create private recovery receipt.');
        }

        try {
            if (! @chmod($path, 0600) || fwrite($stream, $json) !== strlen($json)) {
                throw new RuntimeException('Cannot persist verified recovery receipt.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function under(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/').'/');
    }

    private function overlap(string $a, string $b): bool
    {
        return $a === $b || $this->under($a, $b) || $this->under($b, $a);
    }
}
