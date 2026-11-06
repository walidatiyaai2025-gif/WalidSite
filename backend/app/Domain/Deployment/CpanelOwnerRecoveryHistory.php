<?php

namespace App\Domain\Deployment;

use DomainException;
use JsonException;

/**
 * Read-only, bounded listing of PRIVATE first-install backup/restore receipts.
 *
 * A receipt is historical evidence that a disposable restore was performed at
 * issuance time. It NEVER authorizes new updates, proves an existing snapshot
 * is still recoverable, or replaces current host writer-isolation evidence.
 */
final class CpanelOwnerRecoveryHistory
{
    /** @return array{state:string,receipts:list<array{id:string,created_at:string,version:string,files:int,tables:int,rows:int}>} */
    public function inspect(string $homeDirectory): array
    {
        $empty = ['state' => 'none', 'receipts' => []];
        $home = realpath($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)) {
            return ['state' => 'blocked', 'receipts' => []];
        }
        $vault = $home.'/.walidsite-backups';
        clearstatcache(true, $vault);
        if (@lstat($vault) === false) {
            return $empty;
        }
        try {
            $this->assertPrivateVault($vault, $home);
            $names = @scandir($vault);
            if ($names === false || count($names) > 1000) {
                throw new DomainException('Private backup inventory unavailable.');
            }
            $receipts = [];
            foreach ($names as $name) {
                if (! str_starts_with($name, 'receipt-')) {
                    continue;
                }
                if (preg_match('/\Areceipt-([a-f0-9]{32})\.json\z/D', $name, $match) !== 1) {
                    throw new DomainException('Unexpected backup receipt path.');
                }
                if (count($receipts) >= 100) {
                    throw new DomainException('Too many recovery receipts to inspect safely.');
                }
                $receipts[] = $this->readReceipt($vault.'/'.$name, $match[1]);
            }
            $this->assertPrivateVault($vault, $home);
            usort($receipts, static fn (array $a, array $b): int =>
                strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));
            return ['state' => $receipts === [] ? 'none' : 'history', 'receipts' => array_slice($receipts, 0, 20)];
        } catch (\Throwable) {
            // Never emit pathnames, SQL diagnostics, .env content or raw JSON.
            return ['state' => 'blocked', 'receipts' => []];
        }
    }

    private function assertPrivateVault(string $vault, string $home): void
    {
        clearstatcache(true, $vault);
        $stat = @lstat($vault);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0077) !== 0 || is_link($vault)
            || realpath($vault) !== $vault || dirname($vault) !== $home
            || ! is_readable($vault)) {
            throw new DomainException('Private recovery vault identity invalid.');
        }
    }

    /** @return array{id:string,created_at:string,version:string,files:int,tables:int,rows:int} */
    private function readReceipt(string $path, string $expectedId): array
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (! is_array($named) || ($named['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0777) !== 0600 || ($named['nlink'] ?? 0) !== 1
            || ! is_int($named['size']) || $named['size'] < 2 || $named['size'] > 4096
            || is_link($path) || realpath($path) !== $path) {
            throw new DomainException('Untrusted recovery receipt.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new DomainException('Unavailable recovery receipt.');
        }
        try {
            $opened = @fstat($handle);
            $fields = ['dev', 'ino', 'mode', 'size', 'nlink', 'mtime', 'ctime'];
            if (! is_array($opened)) {
                throw new DomainException('Recovery receipt cannot be verified.');
            }
            foreach ($fields as $field) {
                if ($opened[$field] !== $named[$field]) {
                    throw new DomainException('Recovery receipt changed.');
                }
            }
            $bytes = stream_get_contents($handle, 4097);
            $after = @fstat($handle);
            clearstatcache(true, $path);
            $namedAfter = @lstat($path);
            if (! is_string($bytes) || strlen($bytes) !== $opened['size']
                || ! is_array($after) || ! is_array($namedAfter)) {
                throw new DomainException('Recovery receipt read incomplete.');
            }
            foreach ($fields as $field) {
                if ($opened[$field] !== $after[$field] || $opened[$field] !== $namedAfter[$field]) {
                    throw new DomainException('Recovery receipt changed during read.');
                }
            }
        } finally {
            fclose($handle);
        }
        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Invalid recovery receipt format.');
        }
        if (! is_array($data) || array_keys($data) !== [
            'schema', 'id', 'created_at', 'installed_version', 'files', 'bytes',
            'tables', 'rows', 'file_sha256', 'database_sha256', 'code_update_performed',
        ] || $data['schema'] !== 'walidsite.backup-restore-receipt.v1'
            || $data['id'] !== $expectedId || ! is_string($data['created_at'])
            || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\z/D', $data['created_at'])
            || ! is_string($data['installed_version'])
            || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $data['installed_version'])
            || ! is_int($data['files']) || $data['files'] < 0
            || ! is_int($data['bytes']) || $data['bytes'] < 0
            || ! is_int($data['tables']) || $data['tables'] < 0
            || ! is_int($data['rows']) || $data['rows'] < 0
            || ! is_string($data['file_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $data['file_sha256']) !== 1
            || ! is_string($data['database_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $data['database_sha256']) !== 1
            || $data['code_update_performed'] !== false) {
            throw new DomainException('Recovery receipt identity invalid.');
        }
        return [
            'id' => $data['id'], 'created_at' => $data['created_at'],
            'version' => $data['installed_version'], 'files' => $data['files'],
            'tables' => $data['tables'], 'rows' => $data['rows'],
        ];
    }
}
