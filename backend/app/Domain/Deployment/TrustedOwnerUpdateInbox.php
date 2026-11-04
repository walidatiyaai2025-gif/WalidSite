<?php

namespace App\Domain\Deployment;

use DomainException;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Owner-bound, read-only inbox for cryptographically authenticated update ZIPs.
 *
 * A valid uploaded ZIP is NEVER executable merely because it was accepted
 * here. A separate privileged maintenance, recoverable-backup and atomic-host
 * gate must authorize any future code cutover.
 */
final class TrustedOwnerUpdateInbox
{
    private const MAX_ARCHIVE_BYTES = 1073741824;
    private const MAX_AGE_SECONDS = 3600;

    /**
     * @return array{id:string,version:string,source_sha:string,sha256:string,files:int,bytes:int}
     */
    public function verifyUpload(
        UploadedFile $upload,
        TrustedReleaseCatalog $catalog,
        string $publisherOrigin,
        string $publisherKey,
        string $installedVersion,
        string $ownerId,
        string $privateInboxRoot,
        string $publicDocumentRoot,
    ): array {
        if ($ownerId === '' || strlen($ownerId) > 80 || $upload->getSize() <= 0
            || $upload->getSize() > self::MAX_ARCHIVE_BYTES) {
            throw new DomainException('Unacceptable owner or ZIP upload.');
        }

        // Verify publisher signature/identity independently of the upload.
        // Neither the filename, version nor trust anchor comes from POST.
        $signed = $catalog->check($publisherOrigin, $publisherKey, $installedVersion);
        $release = $signed['release'];
        $filename = basename((string) parse_url($release['artifact_url'], PHP_URL_PATH));
        if ($upload->getSize() !== $release['artifact_size']) {
            throw new DomainException('Uploaded archive size does not match the signed publisher release.');
        }

        $root = $this->privateDirectory($privateInboxRoot, $publicDocumentRoot);
        $id = bin2hex(random_bytes(16));
        $directory = $root.'/'.$id;
        if (! @mkdir($directory, 0700) || realpath($directory) !== $directory
            || is_link($directory) || ! @chmod($directory, 0700)) {
            throw new RuntimeException('Cannot prepare isolated private verified upload directory.');
        }

        $archive = $directory.'/'.$filename;
        try {
            $upload->move($directory, $filename);
            if (! is_file($archive) || is_link($archive) || ! @chmod($archive, 0600)) {
                throw new DomainException('Uploaded archive could not be stored safely.');
            }

            $inventory = (new TrustedReleaseArchive())->inspect($release, $directory, $archive);
            $receipt = [
                'schema' => 'walidsite.owner-update-inbox.v1',
                'owner' => $ownerId,
                'installed' => $installedVersion,
                'version' => $release['version'],
                'sha256' => $release['artifact_sha256'],
                'source_sha' => $release['source_sha'],
                'file' => $filename,
                'size' => $release['artifact_size'],
                'created_at' => time(),
                'envelope' => $signed['envelope'],
            ];
            $this->saveReceipt($directory.'/receipt.json', $receipt);

            return [
                'id' => $id,
                'version' => $release['version'],
                'source_sha' => $release['source_sha'],
                'sha256' => $release['artifact_sha256'],
                'files' => $inventory['files'],
                'bytes' => $inventory['uncompressed_bytes'],
            ];
        } catch (Throwable $exception) {
            @unlink($directory.'/receipt.json');
            @unlink($archive);
            @rmdir($directory);
            throw $exception;
        }
    }

    /**
     * Revalidates *every* file and the signed release on every later request.
     * Never trusts a receipt's claimed checksum or uploaded code directory.
     *
     * @return array{directory:string,archive:string,version:string,source_sha:string,artifact_sha256:string,files:int}
     */
    public function getVerified(
        string $id,
        string $ownerId,
        string $privateInboxRoot,
        string $publicDocumentRoot,
        string $publisherOrigin,
        string $publisherKey,
        string $installedVersion,
    ): array {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id) || $ownerId === '') {
            throw new DomainException('Invalid verified update reference.');
        }
        $root = $this->privateDirectory($privateInboxRoot, $publicDocumentRoot);
        $directory = $root.'/'.$id;
        if (! is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new DomainException('Verified update reference not found.');
        }

        $receiptFile = $directory.'/receipt.json';
        if (! is_file($receiptFile) || is_link($receiptFile)
            || filesize($receiptFile) === false || filesize($receiptFile) > 20000) {
            throw new DomainException('Trusted update receipt is unavailable.');
        }
        $receipt = json_decode((string) file_get_contents($receiptFile), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($receipt) || ($receipt['schema'] ?? '') !== 'walidsite.owner-update-inbox.v1'
            || ! hash_equals($ownerId, (string) ($receipt['owner'] ?? ''))
            || ! hash_equals($installedVersion, (string) ($receipt['installed'] ?? ''))
            || ! is_int($receipt['created_at'] ?? null)
            || ($receipt['created_at'] < time() - self::MAX_AGE_SECONDS)
            || $receipt['created_at'] > time() + 60
            || ! is_string($receipt['envelope'] ?? null)) {
            throw new DomainException('Expired, untrusted or foreign update receipt.');
        }

        $release = (new TrustedReleaseMetadata())->verify(
            $receipt['envelope'], $publisherKey, $publisherOrigin, $installedVersion,
        );
        $name = basename((string) parse_url($release['artifact_url'], PHP_URL_PATH));
        if (($receipt['file'] ?? '') !== $name
            || ($receipt['version'] ?? '') !== $release['version']
            || ($receipt['source_sha'] ?? '') !== $release['source_sha']
            || ($receipt['sha256'] ?? '') !== $release['artifact_sha256']
            || ($receipt['size'] ?? -1) !== $release['artifact_size']) {
            throw new DomainException('Stored update identity does not match the publisher.');
        }
        $archive = $directory.'/'.$name;
        $inventory = (new TrustedReleaseArchive())->inspect($release, $directory, $archive);
        return [
            'directory' => $directory,
            'archive' => $archive,
            'version' => $release['version'],
            'source_sha' => $release['source_sha'],
            'artifact_sha256' => $release['artifact_sha256'],
            'files' => $inventory['files'],
        ];
    }

    private function privateDirectory(string $directory, string $publicDocumentRoot): string
    {
        $public = realpath($publicDocumentRoot);
        $parent = realpath(dirname($directory));
        if ($public === false || $parent === false || is_link(dirname($directory))
            || ! is_dir($parent) || ! is_writable($parent)
            || $parent === $public || str_starts_with($parent, rtrim($public, '/').'/')
            || str_starts_with($public, rtrim($parent, '/').'/')) {
            throw new DomainException('A separate, writable private update inbox is required.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private update inbox.');
        }
        $root = realpath($directory);
        if ($root === false || $root !== $directory || is_link($directory)
            || ! is_writable($root) || $root === $public
            || str_starts_with($root, rtrim($public, '/').'/')
            || str_starts_with($public, rtrim($root, '/').'/')) {
            throw new DomainException('Update inbox must not overlap public web files.');
        }
        return $root;
    }

    /** @param array<string,int|string> $receipt */
    private function saveReceipt(string $path, array $receipt): void
    {
        $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $file = @fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Cannot open exclusive private update receipt.');
        }
        try {
            if (! @chmod($path, 0600) || fwrite($file, $json) !== strlen($json)) {
                throw new RuntimeException('Private update receipt could not be saved.');
            }
        } finally {
            fclose($file);
        }
    }
}
