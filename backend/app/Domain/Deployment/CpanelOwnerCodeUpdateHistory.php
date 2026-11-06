<?php

namespace App\Domain\Deployment;

use DomainException;
use JsonException;
use Throwable;

/**
 * Local, owner-only history of code installations that have already returned
 * from the signed updater with real backup/restore, cutover and HTTPS health.
 *
 * A record is MACed with the private Laravel APP_KEY for offline integrity and
 * kept outside public_html. It is NOT a publisher signature, a current health
 * proof, permission for another update, or evidence of production staging.
 */
final class CpanelOwnerCodeUpdateHistory
{
    private const DIRECTORY = '.walidsite-code-update-history';
    private const SCHEMA = 'walidsite.owner-code-update-history.v1';
    private const MAX_RECEIPT_BYTES = 4096;

    /** @param array{state:string,version:string,source_sha:string,backup_id:string,from:string,to:string} $result */
    public function record(string $homeDirectory, array $result, string $appKey): void
    {
        $home = $this->privateHome($homeDirectory);
        $this->assertResult($result);
        $key = $this->historyKey($appKey);

        // This method runs AFTER the installer returns installed_verified.
        // Independently check the stable active pointer against the actual
        // candidate application; session and flash status cannot forge it.
        $first = (new TrustedInstalledVersion())->read($home.'/application/VERSION');
        if ($first === null || (new TrustedInstalledReleasePin())->read(
            $home.'/'.$result['to'].'/application', $home.'/public_html', $first,
        ) !== $result['version']) {
            throw new DomainException('Installed release lineage is not available for history.');
        }

        $directory = $this->directory($home, true);
        $id = bin2hex(random_bytes(16));
        $payload = [
            'schema' => self::SCHEMA,
            'id' => $id,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $result['version'],
            'source_sha' => $result['source_sha'],
            'backup_id' => $result['backup_id'],
            'from' => $result['from'],
            'to' => $result['to'],
        ];
        $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
        $bytes = json_encode(['payload' => $payload, 'mac' => $signature], JSON_THROW_ON_ERROR);
        if (strlen($bytes) > self::MAX_RECEIPT_BYTES) {
            throw new DomainException('Code update receipt exceeds private limits.');
        }

        $tmp = $directory.'/.partial-'.$id;
        $final = $directory.'/receipt-'.$id.'.json';
        if (@lstat($tmp) !== false || @lstat($final) !== false) {
            throw new DomainException('Code update receipt collision.');
        }
        $stream = @fopen($tmp, 'xb');
        if ($stream === false) {
            throw new DomainException('Cannot create private code update receipt.');
        }
        try {
            if (! @chmod($tmp, 0600)) {
                throw new DomainException('Cannot secure code update receipt.');
            }
            $this->assertFileIdentity($tmp, $stream, strlen($bytes), false);
            $written = fwrite($stream, $bytes);
            if ($written !== strlen($bytes) || ! fflush($stream)
                || (function_exists('fsync') && ! fsync($stream))) {
                throw new DomainException('Cannot durably store code update receipt.');
            }
            $this->assertFileIdentity($tmp, $stream, strlen($bytes), true);
            $this->directory($home, false);
            if (@lstat($final) !== false || ! @rename($tmp, $final)) {
                throw new DomainException('Cannot atomically publish code update history.');
            }
            $this->assertFileIdentity($final, $stream, strlen($bytes), true);
            $this->directory($home, false);
        } finally {
            fclose($stream);
            if (! is_link($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Only exposes a bounded historical summary. An old entry never certifies
     * that the current runtime still executes that release or that a backup
     * is currently restorable.
     *
     * @return array{state:string,receipts:list<array{id:string,created_at:string,version:string,source_sha:string,backup_id:string,from:string}>}
     */
    public function inspect(string $homeDirectory, string $appKey): array
    {
        $empty = ['state' => 'none', 'receipts' => []];
        try {
            $home = $this->privateHome($homeDirectory);
            $key = $this->historyKey($appKey);
            if (@lstat($home.'/'.self::DIRECTORY) === false) {
                return $empty;
            }
            $directory = $this->directory($home, false);
            $items = @scandir($directory);
            if ($items === false || count($items) > 102) {
                throw new DomainException('Private code update history is unavailable.');
            }
            $receipts = [];
            foreach ($items as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (preg_match('/\Areceipt-([a-f0-9]{32})\.json\z/D', $name, $match) !== 1
                    || count($receipts) >= 100) {
                    throw new DomainException('Unexpected private code update history entry.');
                }
                $receipts[] = $this->read($directory.'/'.$name, $match[1], $key);
            }
            $this->directory($home, false);
            usort($receipts, static fn (array $a, array $b): int =>
                strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));
            return [
                'state' => $receipts === [] ? 'none' : 'history',
                'receipts' => array_slice($receipts, 0, 20),
            ];
        } catch (Throwable) {
            // Neither local filesystem paths nor secret/MAC values reach HTML.
            return ['state' => 'blocked', 'receipts' => []];
        }
    }

    /** @param array<string,mixed> $result */
    private function assertResult(array $result): void
    {
        if (array_keys($result) !== ['state', 'version', 'source_sha', 'backup_id', 'from', 'to']
            || $result['state'] !== 'installed_verified'
            || ! is_string($result['version'])
            || preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $result['version']) !== 1
            || ! is_string($result['source_sha'])
            || preg_match('/\A[a-f0-9]{40}\z/D', $result['source_sha']) !== 1
            || ! is_string($result['backup_id'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $result['backup_id']) !== 1
            || ! is_string($result['from'])
            || preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $result['from']) !== 1
            || version_compare($result['version'], $result['from'], '<=')
            || $result['to'] !== '.walidsite-releases/v'.$result['version'].'-'.$result['source_sha']) {
            throw new DomainException('Unverified code installation cannot enter history.');
        }
    }

    private function historyKey(string $appKey): string
    {
        if (strlen($appKey) < 32 || strlen($appKey) > 200) {
            throw new DomainException('Private history integrity key unavailable.');
        }
        return hash_hmac('sha256', 'walidsite-owner-code-update-history-v1', $appKey, true);
    }

    private function privateHome(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0022) !== 0 || is_link($path)
            || realpath($path) !== $path) {
            throw new DomainException('Private cPanel account home is unsafe for update history.');
        }
        return $path;
    }

    private function directory(string $home, bool $create): string
    {
        $dir = $home.'/'.self::DIRECTORY;
        if ($create && @lstat($dir) === false && ! @mkdir($dir, 0700)) {
            throw new DomainException('Cannot create private code update history.');
        }
        clearstatcache(true, $dir);
        $homeStat = @lstat($home);
        $stat = @lstat($dir);
        if (! is_array($homeStat) || ! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0077) !== 0 || $stat['uid'] !== $homeStat['uid']
            || is_link($dir) || realpath($dir) !== $dir
            || ! is_readable($dir) || ($create && ! is_writable($dir))) {
            throw new DomainException('Private code update history directory is unsafe.');
        }
        return $dir;
    }

    /** @param resource $stream */
    private function assertFileIdentity(string $path, $stream, int $size, bool $complete): void
    {
        clearstatcache(true, $path);
        $open = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($open) || ! is_array($named) || is_link($path)
            || ($open['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || ($open['mode'] & 0777) !== 0600
            || ($named['mode'] & 0777) !== 0600
            || $open['dev'] !== $named['dev'] || $open['ino'] !== $named['ino']
            || $open['uid'] !== $named['uid']
            || $open['nlink'] !== 1 || $named['nlink'] !== 1
            || ($complete && ($open['size'] !== $size || $named['size'] !== $size))) {
            throw new DomainException('Private code update receipt identity changed.');
        }
    }

    /** @return array{id:string,created_at:string,version:string,source_sha:string,backup_id:string,from:string} */
    private function read(string $path, string $id, string $key): array
    {
        // Reject FIFO/device/symlink before fopen could block on a planted
        // nonregular file. The held inode is independently checked below.
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (! is_array($named) || is_link($path)
            || ($named['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0777) !== 0600
            || ($named['nlink'] ?? 0) !== 1
            || $named['size'] < 1 || $named['size'] > self::MAX_RECEIPT_BYTES) {
            throw new DomainException('Private code update receipt type is unsafe.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Private code update history cannot be read.');
        }
        try {
            $stat = @fstat($stream);
            if (! is_array($stat) || $stat['size'] < 1 || $stat['size'] > self::MAX_RECEIPT_BYTES) {
                throw new DomainException('Private history receipt size invalid.');
            }
            $this->assertFileIdentity($path, $stream, $stat['size'], true);
            $raw = stream_get_contents($stream, self::MAX_RECEIPT_BYTES + 1);
            if (! is_string($raw) || strlen($raw) !== $stat['size']) {
                throw new DomainException('Private history receipt changed while reading.');
            }
            $this->assertFileIdentity($path, $stream, $stat['size'], true);
        } finally {
            fclose($stream);
        }
        try {
            $entry = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Malformed private update receipt.');
        }
        if (! is_array($entry) || array_keys($entry) !== ['payload', 'mac']
            || ! is_array($entry['payload']) || ! is_string($entry['mac'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $entry['mac']) !== 1) {
            throw new DomainException('Unsafe private code update history receipt.');
        }
        $payload = $entry['payload'];
        if (array_keys($payload) !== [
            'schema', 'id', 'created_at', 'version', 'source_sha', 'backup_id', 'from', 'to',
        ] || $payload['schema'] !== self::SCHEMA || $payload['id'] !== $id
            || ! is_string($payload['created_at'])
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $payload['created_at']) !== 1) {
            throw new DomainException('Private update receipt schema invalid.');
        }
        $this->assertResult([
            'state' => 'installed_verified',
            'version' => $payload['version'],
            'source_sha' => $payload['source_sha'],
            'backup_id' => $payload['backup_id'],
            'from' => $payload['from'],
            'to' => $payload['to'],
        ]);
        $mac = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
        if (! hash_equals($mac, $entry['mac'])) {
            throw new DomainException('Private code update receipt authentication failed.');
        }
        return [
            'id' => $id,
            'created_at' => $payload['created_at'],
            'version' => $payload['version'],
            'source_sha' => $payload['source_sha'],
            'backup_id' => $payload['backup_id'],
            'from' => $payload['from'],
        ];
    }
}
