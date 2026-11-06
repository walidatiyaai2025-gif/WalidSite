<?php

namespace App\Domain\Deployment;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * Re-attests every byte of privately staged executable code against the
 * approved publisher ZIP. A signed ZIP and a valid staged VERSION are not
 * sufficient: an attacker or crashed worker might have changed another PHP
 * file after private staging. This is read-only, and never authorizes Apply.
 *
 * Call again immediately before and after a provisional pointer cutover;
 * do not replace the approved ZIP/Ed25519 signer with a session boolean.
 */
final class CpanelSignedCandidateAttestor
{
    /** @return array{state:string,files:int,bytes:int,version:string,source_sha:string} */
    public function attest(
        string $signedEnvelope,
        string $publisherKey,
        string $pinnedOrigin,
        string $installedVersion,
        string $archiveDirectory,
        string $archiveFile,
        string $homeDirectory,
    ): array {
        $signed = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope, $publisherKey, $pinnedOrigin, $installedVersion,
        );
        $home = realpath($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)) {
            throw new DomainException('Private cPanel home cannot be verified.');
        }
        $root = $home.'/.walidsite-releases';
        $candidate = $root.'/v'.$signed['version'].'-'.$signed['source_sha'];
        $homeStat = @lstat($home);
        $rootStat = @lstat($root);
        $candidateStat = @lstat($candidate);
        if (! is_array($homeStat) || ! is_array($rootStat) || ! is_array($candidateStat)
            || ($homeStat['mode'] & 0170000) !== 0040000
            || ($rootStat['mode'] & 0170000) !== 0040000
            || ($candidateStat['mode'] & 0170000) !== 0040000
            || ($homeStat['mode'] & 0022) !== 0
            || ($rootStat['mode'] & 0077) !== 0
            || ($candidateStat['mode'] & 0077) !== 0
            || $homeStat['uid'] !== $rootStat['uid']
            || $homeStat['uid'] !== $candidateStat['uid']
            || is_link($root) || is_link($candidate)
            || realpath($root) !== $root || realpath($candidate) !== $candidate) {
            throw new DomainException('Immutable candidate directory is untrusted.');
        }

        $inventory = (new TrustedReleaseArchive())->inspect($signed, $archiveDirectory, $archiveFile);
        $zip = new ZipArchive();
        if ($zip->open($archiveFile, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new DomainException('Signed ZIP cannot be reopened for code verification.');
        }
        $expected = [];
        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                    throw new DomainException('Signed file inventory changed.');
                }
                $name = $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $expected[$name] = true;
                $path = $candidate.'/'.$name;
                $named = @lstat($path);
                if (! is_array($named)
                    || ($named['mode'] & 0170000) !== 0100000
                    || ($named['mode'] & 0022) !== 0
                    || ($named['nlink'] ?? 0) !== 1
                    || $named['uid'] !== $candidateStat['uid']
                    || ! is_int($named['size']) || $named['size'] !== $stat['size']
                    || is_link($path) || realpath($path) !== $path) {
                    throw new DomainException('Staged code file identity is untrusted.');
                }
                $file = @fopen($path, 'rb');
                $entry = $zip->getStream($name);
                if ($file === false || $entry === false) {
                    if (is_resource($file)) {
                        fclose($file);
                    }
                    if (is_resource($entry)) {
                        fclose($entry);
                    }
                    throw new DomainException('Cannot compare signed candidate bytes.');
                }
                try {
                    $opened = @fstat($file);
                    if (! is_array($opened) || !$this->sameFile($named, $opened)) {
                        throw new DomainException('Staged candidate inode changed.');
                    }
                    $archiveHash = hash_init('sha256');
                    $physicalHash = hash_init('sha256');
                    $archiveBytes = hash_update_stream($archiveHash, $entry);
                    $physicalBytes = hash_update_stream($physicalHash, $file);
                    $after = @fstat($file);
                    clearstatcache(true, $path);
                    $namedAfter = @lstat($path);
                    if ($archiveBytes !== $stat['size'] || $physicalBytes !== $stat['size']
                        || ! is_array($after) || ! is_array($namedAfter)
                        || ! $this->sameFile($named, $after)
                        || ! $this->sameFile($named, $namedAfter)
                        || ! hash_equals(hash_final($archiveHash), hash_final($physicalHash))) {
                        throw new DomainException('Staged candidate differs from signed ZIP.');
                    }
                    $total += $physicalBytes;
                } finally {
                    fclose($file);
                    fclose($entry);
                }
            }
        } finally {
            $zip->close();
        }

        // Detect additional PHP, autoloaders, hidden paths, or symlink nodes
        // that were not present in the signed inventory.
        $actual = 0;
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($walk as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || ($item->isDir() && (($item->getPerms() & 0022) !== 0))) {
                throw new DomainException('Staged release contains an unsafe directory or link.');
            }
            if ($item->isDir()) {
                continue;
            }
            $name = substr($path, strlen($candidate) + 1);
            if (! $item->isFile() || ! isset($expected[$name])) {
                throw new DomainException('Staged release has files absent from signed ZIP.');
            }
            ++$actual;
        }

        clearstatcache(true, $home);
        clearstatcache(true, $root);
        clearstatcache(true, $candidate);
        foreach ([$home => $homeStat, $root => $rootStat, $candidate => $candidateStat] as $path => $initial) {
            $latest = @lstat($path);
            if (! is_array($latest) || is_link($path)
                || $latest['dev'] !== $initial['dev']
                || $latest['ino'] !== $initial['ino']
                || $latest['mode'] !== $initial['mode']
                || $latest['uid'] !== $initial['uid']) {
                throw new DomainException('Private code directory changed during verification.');
            }
        }
        // Inspect the signed archive again after all reads. Never attest a
        // candidate if the underlying ZIP was replaced during comparison.
        if ($actual !== $inventory['files'] || $actual !== count($expected)
            || $total !== $inventory['uncompressed_bytes']) {
            throw new DomainException('Staged code inventory is incomplete.');
        }
        (new TrustedReleaseArchive())->inspect($signed, $archiveDirectory, $archiveFile);

        return [
            'state' => 'signed_candidate_attested',
            'files' => $actual,
            'bytes' => $total,
            'version' => $signed['version'],
            'source_sha' => $signed['source_sha'],
        ];
    }

    /** @param array<string,int> $a @param array<string,int> $b */
    private function sameFile(array $a, array $b): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ($a[$field] !== $b[$field]) {
                return false;
            }
        }
        return true;
    }
}
