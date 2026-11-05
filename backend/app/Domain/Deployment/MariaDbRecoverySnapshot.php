<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Conservative MariaDB/InnoDB-only backup with a disposable database restore.
 * This service NEVER creates databases, restores production, or switches code.
 */
final class MariaDbRecoverySnapshot
{
    private const MAX_TABLES = 256;
    private const MAX_ROWS = 250000;
    private const MAX_BYTES = 1073741824;
    private const MAX_MANIFEST_BYTES = 8388608;

    /**
     * @return array{directory:string,manifest_sha256:string,tables:int,rows:int}
     */
    public function create(PDO $source, string $privateBackupRoot, string $publicRoot): array
    {
        $this->assertPdo($source);
        $root = realpath($privateBackupRoot);
        $public = realpath($publicRoot);
        if ($root === false || $public === false || ! is_dir($root) || ! is_dir($public)
            || is_link($privateBackupRoot) || ! is_writable($root)
            || $root === $public || $this->within($root, $public) || $this->within($public, $root)) {
            throw new DomainException('MariaDB backup must use a separate private storage root.');
        }

        $db = $this->database($source);
        $this->assertSimpleSchema($source);
        $names = $this->tables($source);
        if ($names === [] || count($names) > self::MAX_TABLES) {
            throw new DomainException('MariaDB table inventory exceeds safety bounds.');
        }

        $partial = $root.'/.mariadb-'.bin2hex(random_bytes(16)).'.partial';
        $finished = substr($partial, 0, -8);
        if (! mkdir($partial, 0700)) {
            throw new RuntimeException('Cannot create private database snapshot directory.');
        }
        try {
            $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $manifest = [];
                $size = 0;
                $rows = 0;
                foreach ($names as $table) {
                    $quoted = $this->ident($table);
                    $engine = $source->query('SHOW TABLE STATUS LIKE '.$source->quote($table))->fetch(PDO::FETCH_ASSOC);
                    $schema = $source->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_ASSOC);
                    $ddl = $schema['Create Table'] ?? null;
                    if (! is_array($engine) || strtoupper((string) ($engine['Engine'] ?? '')) !== 'INNODB'
                        || ! is_string($ddl) || ! str_starts_with($ddl, 'CREATE TABLE ')) {
                        throw new DomainException('Only InnoDB base tables with trusted schema are supported.');
                    }
                    $file = $partial.'/'.$table.'.jsonl';
                    $out = fopen($file, 'xb');
                    if ($out === false) {
                        throw new RuntimeException('Cannot create MariaDB data stream.');
                    }
                    $count = 0;
                    $hashes = [];
                    try {
                        $stmt = $source->query('SELECT * FROM '.$quoted);
                        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                            $encoded = $this->encode($row);
                            $line = json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                            $this->assertTotalRowCapacity($rows, ++$count);
                            if (($size += strlen($line)) > self::MAX_BYTES) {
                                throw new DomainException('MariaDB backup exceeds safe recovery capacity.');
                            }
                            if (fwrite($out, $line) !== strlen($line)) {
                                throw new RuntimeException('MariaDB snapshot write incomplete.');
                            }
                            $hashes[] = hash('sha256', $this->canonical($encoded));
                        }
                        $stmt->closeCursor();
                    } finally {
                        fclose($out);
                    }
                    if (! chmod($file, 0600)) {
                        throw new RuntimeException('Cannot make database recovery bytes private.');
                    }
                    sort($hashes, SORT_STRING);
                    $manifest[$table] = [
                        'ddl' => $ddl,
                        'rows' => $count,
                        'file_sha256' => hash_file('sha256', $file),
                        'data_sha256' => hash('sha256', implode("\n", $hashes)),
                    ];
                    $rows += $count;
                }
                if ($this->tables($source) !== $names) {
                    throw new DomainException('MariaDB table schema changed during backup.');
                }
            } finally {
                $source->exec('ROLLBACK');
            }
            $json = json_encode([
                'format' => 'walidsite-mariadb-1', 'source' => $db, 'tables' => $manifest,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->assertManifestCapacity($json);
            if (file_put_contents($partial.'/manifest.json', $json, LOCK_EX) !== strlen($json)
                || ! chmod($partial.'/manifest.json', 0600)
                || ! rename($partial, $finished)) {
                throw new RuntimeException('Cannot finalize MariaDB recovery snapshot.');
            }
            return [
                'directory' => $finished,
                'manifest_sha256' => hash('sha256', $json),
                'tables' => count($manifest),
                'rows' => $rows,
            ];
        } catch (Throwable $error) {
            $this->erase($partial);
            throw $error;
        }
    }

    /**
     * Target must be a DIFFERENT, already-created, completely EMPTY database.
     * On failure destroy the disposable target; DDL cannot be rolled back.
     *
     * @return array{tables:int,rows:int}
     */
    public function restoreAndVerify(PDO $target, string $snapshotDirectory, string $expectedManifestHash): array
    {
        $this->assertPdo($target);
        $directory = realpath($snapshotDirectory);
        if ($directory === false || ! is_dir($directory) || is_link($snapshotDirectory)
            || ! $this->validHash($expectedManifestHash)) {
            throw new DomainException('Invalid MariaDB recovery source.');
        }
        $manifestFile = $directory.'/manifest.json';
        $input = @fopen($manifestFile, 'rb');
        if ($input === false) {
            throw new DomainException('MariaDB recovery manifest is missing.');
        }
        try {
            // Keep the verified manifest bytes and its no-follow named inode
            // tied to the same descriptor. A checksum alone permits a
            // hard-linked or replaced path to masquerade as our manifest.
            $before = $this->assertStableRowFile($input, $manifestFile);
            if ($before['size'] < 1 || $before['size'] > self::MAX_MANIFEST_BYTES) {
                throw new DomainException('MariaDB recovery manifest exceeds safe size.');
            }
            $json = stream_get_contents($input, $before['size'] + 1);
            if (! is_string($json) || strlen($json) !== $before['size']) {
                throw new DomainException('MariaDB recovery manifest changed during read.');
            }
            $this->assertStableRowFile($input, $manifestFile, $before);
            if (! hash_equals($expectedManifestHash, hash('sha256', $json))) {
                throw new DomainException('MariaDB recovery manifest checksum mismatch.');
            }
        } finally {
            fclose($input);
        }
        try {
            $metadata = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new DomainException('Invalid MariaDB recovery manifest encoding.', 0, $error);
        }
        if (! is_array($metadata) || ($metadata['format'] ?? null) !== 'walidsite-mariadb-1'
            || ! is_string($metadata['source'] ?? null)
            || ! is_array($metadata['tables'] ?? null) || $metadata['tables'] === []
            || count($metadata['tables']) > self::MAX_TABLES
            || $this->database($target) === $metadata['source'] || $this->tables($target) !== []) {
            throw new DomainException('Target must be a distinct empty disposable MariaDB database.');
        }

        // Inspect and hash the SAME opened inode as the verified row bytes.
        // A separate hash_file(path) followed by fopen(path) permits an
        // atomic pathname replacement after checksum validation. Keep each
        // preflight inode identity for the later DDL/restore phase.
        $preflightBytes = 0;
        $preflightRows = 0;
        $verifiedInodes = [];
        foreach ($metadata['tables'] as $table => $info) {
            $this->ident($table);
            if (! is_array($info) || ! is_string($info['ddl'] ?? null)
                || ! str_starts_with($info['ddl'], 'CREATE TABLE ')
                || ! is_int($info['rows'] ?? null) || $info['rows'] < 0
                || $info['rows'] > self::MAX_ROWS
                || ! $this->validHash($info['file_sha256'] ?? null)
                || ! $this->validHash($info['data_sha256'] ?? null)) {
                throw new DomainException('Untrusted MariaDB recovery table metadata.');
            }
            $this->assertTotalRowCapacity($preflightRows, $info['rows']);
            $preflightRows += $info['rows'];
            $file = $directory.'/'.$table.'.jsonl';
            $input = @fopen($file, 'rb');
            if ($input === false) {
                throw new DomainException('Cannot open MariaDB recovery rows.');
            }
            try {
                $before = $this->assertStableRowFile($input, $file);
                $preflightBytes += $before['size'];
                if ($preflightBytes > self::MAX_BYTES) {
                    throw new DomainException('MariaDB recovery archive exceeds safe byte limits.');
                }

                // Validate the raw byte checksum on this SAME open descriptor
                // before parsing any JSON. A tampered stream must report the
                // integrity failure rather than leak parsing/encoding details.
                $preflightHash = hash_init('sha256');
                $hashedBytes = hash_update_stream($preflightHash, $input, $before['size'] + 1);
                $this->assertStableRowFile($input, $file, $before);
                if ($hashedBytes !== $before['size']
                    || ! hash_equals($info['file_sha256'], hash_final($preflightHash))) {
                    throw new DomainException('MariaDB recovery file checksum mismatch before restore.');
                }
                if (! rewind($input)) {
                    throw new DomainException('Unable to rewind verified MariaDB recovery row source.');
                }

                $count = 0;
                $digests = [];
                $fileHash = hash_init('sha256');
                while (($line = fgets($input)) !== false) {
                    hash_update($fileHash, $line);
                    try {
                        $encoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $error) {
                        throw new DomainException('Malformed MariaDB recovery row; restore rejected.', 0, $error);
                    }
                    $decoded = $this->decode($encoded);
                    $this->assertTotalRowCapacity($preflightRows - $info['rows'], ++$count);
                    $digests[] = hash('sha256', $this->canonical($this->encode($decoded)));
                }
                if (! feof($input)) {
                    throw new DomainException('MariaDB row stream read was interrupted.');
                }
                $this->assertStableRowFile($input, $file, $before);
                if (! hash_equals($info['file_sha256'], hash_final($fileHash))) {
                    throw new DomainException('MariaDB recovery file checksum mismatch before restore.');
                }
                sort($digests, SORT_STRING);
                if ($count !== $info['rows']
                    || ! hash_equals($info['data_sha256'], hash('sha256', implode("\n", $digests)))) {
                    throw new DomainException('MariaDB recovery row contents mismatched before restore.');
                }
                $verifiedInodes[$table] = $before;
            } finally {
                fclose($input);
            }
        }

        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $rows = 0;
            foreach ($metadata['tables'] as $table => $info) {
                $quoted = $this->ident($table);
                $file = $directory.'/'.$table.'.jsonl';
                $input = @fopen($file, 'rb');
                if ($input === false) {
                    throw new DomainException('Cannot open verified MariaDB recovery rows.');
                }
                try {
                    // Verify the SAME source inode seen during all-table
                    // preflight BEFORE any CREATE TABLE; do not rely on a
                    // second, unrelated hash_file() of its current pathname.
                    $this->assertStableRowFile($input, $file, $verifiedInodes[$table]);
                    $target->exec($info['ddl']);
                    $count = 0;
                    $fileHash = hash_init('sha256');
                    while (($line = fgets($input)) !== false) {
                        hash_update($fileHash, $line);
                        try {
                            $encoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $error) {
                            throw new DomainException('Malformed MariaDB recovery row; restore rejected.', 0, $error);
                        }
                        $row = $this->decode($encoded);
                        $this->assertTotalRowCapacity($rows, ++$count);
                        $columns = array_keys($row);
                        $sql = 'INSERT INTO '.$quoted.' ('.implode(',', array_map($this->ident(...), $columns))
                            .') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')';
                        $stmt = $target->prepare($sql);
                        if ($stmt === false || ! $stmt->execute(array_values($row))) {
                            throw new RuntimeException('MariaDB restore insert failed.');
                        }
                    }
                    if (! feof($input)) {
                        throw new DomainException('MariaDB restore stream read was interrupted.');
                    }
                    $this->assertStableRowFile($input, $file, $verifiedInodes[$table]);
                    if (! hash_equals($info['file_sha256'], hash_final($fileHash))) {
                        throw new DomainException('MariaDB recovery file changed during restore.');
                    }
                } finally {
                    fclose($input);
                }

                if ($count !== $info['rows']) {
                    throw new DomainException('MariaDB restore row count mismatched.');
                }
                $hashes = [];
                $result = $target->query('SELECT * FROM '.$quoted);
                while (($row = $result->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $hashes[] = hash('sha256', $this->canonical($this->encode($row)));
                    if (count($hashes) > self::MAX_ROWS) {
                        throw new DomainException('Restored table verification budget exceeded.');
                    }
                }
                $result->closeCursor();
                sort($hashes, SORT_STRING);
                if (count($hashes) !== $info['rows']
                    || ! hash_equals($info['data_sha256'], hash('sha256', implode("\n", $hashes)))) {
                    throw new DomainException('MariaDB restored content does not match snapshot.');
                }
                $rows += $count;
            }
            if ($this->tables($target) !== array_keys($metadata['tables'])) {
                throw new DomainException('MariaDB restore table inventory mismatch.');
            }
            return ['tables' => count($metadata['tables']), 'rows' => $rows];
        } finally {
            $target->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Bind a MariaDB recovery stream to a single, non-linked regular inode
     * and its named path, including stable size and modification metadata.
     * This also rejects byte-identical pathname swaps between preflight and
     * DDL. Caller never receives private row contents in exception messages.
     *
     * @param resource $stream
     * @param ?array<string|int,mixed> $baseline
     * @return array<string|int,mixed>
     */
    private function assertStableRowFile($stream, string $path, ?array $baseline = null): array
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || ($opened['nlink'] ?? 0) !== 1 || ($named['nlink'] ?? 0) !== 1
            || is_link($path) || realpath($path) !== $path
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            || ! is_int($opened['size']) || $opened['size'] < 0
            || $opened['size'] > self::MAX_BYTES) {
            throw new DomainException('MariaDB backup row pathname or inode changed during verification.');
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($opened[$field] !== $named[$field]
                || ($baseline !== null && $opened[$field] !== $baseline[$field])) {
                throw new DomainException('MariaDB backup row identity changed during verification.');
            }
        }

        return $opened;
    }

    /** @param array<string,mixed> $row @return array<string,string|null> */
    private function encode(array $row): array
    {
        ksort($row, SORT_STRING);
        $out = [];
        foreach ($row as $column => $value) {
            $out[$column] = $value === null ? null : base64_encode((string) $value);
        }
        return $out;
    }

    /** @return array<string,string|null> */
    private function decode(mixed $encoded): array
    {
        if (! is_array($encoded) || array_is_list($encoded) || $encoded === []) {
            throw new DomainException('Invalid MariaDB row encoding.');
        }
        $row = [];
        foreach ($encoded as $column => $value) {
            $this->ident($column);
            if ($value === null) {
                $row[$column] = null;
            } elseif (is_string($value) && ($bytes = base64_decode($value, true)) !== false
                && base64_encode($bytes) === $value) {
                $row[$column] = $bytes;
            } else {
                throw new DomainException('Corrupt MariaDB binary data encoding.');
            }
        }
        ksort($row, SORT_STRING);
        return $row;
    }

    /** @param array<string,string|null> $value */
    private function canonical(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        $names = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (strtoupper((string) ($row[1] ?? '')) !== 'BASE TABLE') {
                throw new DomainException('Views are not supported by this recovery backend.');
            }
            $this->ident($row[0] ?? null);
            $names[] = $row[0];
        }
        sort($names, SORT_STRING);
        return $names;
    }

    private function assertSimpleSchema(PDO $pdo): void
    {
        if ($pdo->query('SHOW TRIGGERS')->fetch(PDO::FETCH_ASSOC) !== false
            || $pdo->query('SHOW EVENTS')->fetch(PDO::FETCH_ASSOC) !== false
            || $pdo->query('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() LIMIT 1')
                ->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new DomainException('Triggers, routines and events require a different backup mechanism.');
        }
    }

    private function database(PDO $pdo): string
    {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (! is_string($db) || $db === '') {
            throw new DomainException('MariaDB recovery requires an explicit database.');
        }
        return $db;
    }

    private function assertPdo(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new DomainException('Only MariaDB PDO is supported.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function ident(mixed $name): string
    {
        if (! is_string($name) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name)) {
            throw new DomainException('Unsupported MariaDB database identifier.');
        }
        return chr(96).$name.chr(96);
    }

    /**
     * Cap the complete snapshot, not each table independently; multi-table
     * databases must share the same bounded row budget at create and restore.
     */
    private function assertTotalRowCapacity(int $previous, int $additional): void
    {
        if ($previous < 0 || $additional < 0
            || $previous > self::MAX_ROWS
            || $additional > self::MAX_ROWS - $previous) {
            throw new DomainException('MariaDB recovery total row capacity exceeded.');
        }
    }

    /** Reject snapshots that our own restore verifier would refuse to open. */
    private function assertManifestCapacity(string $json): void
    {
        $length = strlen($json);
        if ($length < 1 || $length > self::MAX_MANIFEST_BYTES) {
            throw new DomainException('MariaDB recovery manifest exceeds safe size.');
        }
    }

    private function validHash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }

    private function within(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/').'/');
    }

    private function erase(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
}
