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
        try {
            // An attacker-writable account HOME can replace the entire
            // private vault immediately after a successful receipt check.
            $homeIdentity = $this->privateHomeIdentity($home);
        } catch (DomainException) {
            return ['state' => 'blocked', 'receipts' => []];
        }
        $vault = $home.'/.walidsite-backups';
        clearstatcache(true, $vault);
        if (@lstat($vault) === false) {
            // Even an absent vault cannot attest an unsafe/swapped home.
            try {
                $this->assertPrivateHomeUnchanged($home, $homeIdentity);
            } catch (DomainException) {
                return ['state' => 'blocked', 'receipts' => []];
            }
            return $empty;
        }
        try {
            $this->assertPrivateHomeUnchanged($home, $homeIdentity);
            $vaultIdentity = $this->assertPrivateVault($vault, $home, $homeIdentity['uid']);
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
            $this->assertPrivateVault($vault, $home, $homeIdentity['uid'], $vaultIdentity);
            $this->assertPrivateHomeUnchanged($home, $homeIdentity);
            usort($receipts, static fn (array $a, array $b): int =>
                strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));
            return ['state' => $receipts === [] ? 'none' : 'history', 'receipts' => array_slice($receipts, 0, 20)];
        } catch (\Throwable) {
            // Never emit pathnames, SQL diagnostics, .env content or raw JSON.
            return ['state' => 'blocked', 'receipts' => []];
        }
    }

    /**
     * Private cutover attestation of the EXACT just-created recovery receipt.
     * Never accepts an old receipt, guessed counts, or merely its presence in
     * the owner-facing (20-entry) history list as current restore evidence.
     * Returns only a boolean: no paths, SQL, secrets or digest in the UI.
     *
     * @param array{id:string,version:string,files:int,bytes:int,tables:int,rows:int,file_sha256:string,database_sha256:string} $proof
     */
    public function matchesFreshProof(string $homeDirectory, array $proof): bool
    {
        try {
            $id = $proof['id'] ?? null;
            if (! is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
                return false;
            }
            $home = realpath($homeDirectory);
            if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)) {
                return false;
            }
            $homeIdentity = $this->privateHomeIdentity($home);
            $vault = $home.'/.walidsite-backups';
            $vaultIdentity = $this->assertPrivateVault($vault, $home, $homeIdentity['uid']);
            $receipt = $this->readReceipt($vault.'/receipt-'.$id.'.json', $id, true);
            $ok = $receipt['id'] === $id
                && is_string($proof['version'] ?? null)
                && hash_equals($receipt['version'], $proof['version']);
            foreach (['files', 'bytes', 'tables', 'rows'] as $field) {
                $ok = $ok && is_int($proof[$field] ?? null)
                    && $receipt[$field] === $proof[$field];
            }
            foreach (['file_sha256', 'database_sha256'] as $field) {
                $ok = $ok && is_string($proof[$field] ?? null)
                    && hash_equals($receipt[$field], $proof[$field]);
            }
            $this->assertPrivateVault($vault, $home, $homeIdentity['uid'], $vaultIdentity);
            $this->assertPrivateHomeUnchanged($home, $homeIdentity);
            return $ok;
        } catch (\Throwable) {
            // A missing/replaced/insecure receipt must never authorize cutover.
            return false;
        }
    }

    /** @return array{dev:int,ino:int,mode:int,uid:int} */
    private function privateHomeIdentity(string $home): array
    {
        clearstatcache(true, $home);
        $stat = @lstat($home);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0022) !== 0 || is_link($home)
            || realpath($home) !== $home) {
            throw new DomainException('Private recovery account home is unsafe.');
        }
        return [
            'dev' => $stat['dev'], 'ino' => $stat['ino'],
            'mode' => $stat['mode'], 'uid' => $stat['uid'],
        ];
    }

    /** @param array{dev:int,ino:int,mode:int,uid:int} $expected */
    private function assertPrivateHomeUnchanged(string $home, array $expected): void
    {
        if ($this->privateHomeIdentity($home) !== $expected) {
            throw new DomainException('Private recovery account home changed.');
        }
    }

    /**
     * @param ?array{dev:int,ino:int,mode:int,uid:int} $expected
     * @return array{dev:int,ino:int,mode:int,uid:int}
     */
    private function assertPrivateVault(
        string $vault, string $home, int $homeUid, ?array $expected = null,
    ): array {
        clearstatcache(true, $vault);
        $stat = @lstat($vault);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0077) !== 0 || $stat['uid'] !== $homeUid
            || is_link($vault) || realpath($vault) !== $vault
            || dirname($vault) !== $home || ! is_readable($vault)) {
            throw new DomainException('Private recovery vault identity invalid.');
        }
        $identity = [
            'dev' => $stat['dev'], 'ino' => $stat['ino'],
            'mode' => $stat['mode'], 'uid' => $stat['uid'],
        ];
        if ($expected !== null && $identity !== $expected) {
            throw new DomainException('Private recovery vault changed while reading receipts.');
        }
        return $identity;
    }

    /** @return array{id:string,created_at:string,version:string,files:int,tables:int,rows:int} */
    private function readReceipt(string $path, string $expectedId, bool $privateProof = false): array
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
        $public = [
            'id' => $data['id'], 'created_at' => $data['created_at'],
            'version' => $data['installed_version'], 'files' => $data['files'],
            'tables' => $data['tables'], 'rows' => $data['rows'],
        ];
        // Snapshot digests remain internal: the owner history UI receives only
        // the existing deliberately limited public metadata.
        return $privateProof ? [
            ...$public, 'bytes' => $data['bytes'],
            'file_sha256' => $data['file_sha256'],
            'database_sha256' => $data['database_sha256'],
        ] : $public;
    }
}
