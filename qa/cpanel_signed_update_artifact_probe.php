<?php

/**
 * CI-only acceptance against the ACTUAL built Update ZIP, not a fixture.
 *
 * Ephemeral signing is used exclusively to exercise the same Ed25519 and ZIP
 * validation classes that an authorized publisher would use later. This is
 * NOT publisher authentication, release signing or an Admin code update.
 * There is no network request or production database/file operation.
 */
declare(strict_types=1);

use App\Domain\Deployment\TrustedReleaseArchive;
use App\Domain\Deployment\TrustedReleaseMetadata;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "CI-only signed ZIP acceptance is unavailable.\n");
    exit(2);
}
if ($argc !== 4
    || preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $argv[2] ?? '') !== 1
    || preg_match('/\A[a-f0-9]{40}\z/D', $argv[3] ?? '') !== 1
    || ! function_exists('sodium_crypto_sign_detached')
    || ! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Invalid isolated acceptance parameters or extensions.\n");
    exit(2);
}

require dirname(__DIR__).'/backend/vendor/autoload.php';

$sourceDirectory = realpath($argv[1]);
$version = $argv[2];
$sourceSha = $argv[3];
$filename = 'WalidSite-Update-v'.$version.'.zip';
$canonical = 'WalidSite-update-v'.$version.'.zip';
$manifestName = 'WalidSite-Release-Manifest-v'.$version.'.json';
$tempDirectory = null;

try {
    if ($sourceDirectory === false || is_link($argv[1])
        || ! is_dir($sourceDirectory)
        || ! is_file($sourceDirectory.'/'.$filename)
        || is_link($sourceDirectory.'/'.$filename)
        || ! is_file($sourceDirectory.'/'.$manifestName)
        || is_link($sourceDirectory.'/'.$manifestName)) {
        throw new RuntimeException('Missing immutable release candidate');
    }

    $manifest = json_decode(
        (string) file_get_contents($sourceDirectory.'/'.$manifestName),
        true, 16, JSON_THROW_ON_ERROR,
    );
    if (! is_array($manifest)
        || ($manifest['schema'] ?? null) !== 'walidsite.release-candidate.v1'
        || ($manifest['version'] ?? null) !== $version
        || ($manifest['source_sha'] ?? null) !== $sourceSha
        || ($manifest['product'] ?? null) !== 'walidsite'
        || ($manifest['published'] ?? null) !== false
        || ($manifest['production_ready'] ?? null) !== false
        || ($manifest['publisher_signature_verified'] ?? null) !== false
        || ($manifest['update']['signed_catalog_included'] ?? null) !== false
        || ($manifest['update']['file'] ?? null) !== $filename
        || ($manifest['update']['publisher_upload_name'] ?? null) !== $canonical
        || ! is_int($manifest['update']['bytes'] ?? null)
        || ! is_string($manifest['update']['sha256'] ?? null)) {
        throw new RuntimeException('Candidate provenance or unsigned safety contract invalid');
    }
    $source = $sourceDirectory.'/'.$filename;
    $size = filesize($source);
    $digest = hash_file('sha256', $source);
    if ($size === false || $size !== $manifest['update']['bytes']
        || ! is_string($digest)
        || ! hash_equals($manifest['update']['sha256'], $digest)) {
        throw new RuntimeException('Actual Update ZIP does not match the release train manifest');
    }

    $tempDirectory = sys_get_temp_dir().'/walidsite-signed-zip-test-'.bin2hex(random_bytes(16));
    if (! @mkdir($tempDirectory, 0700) || realpath($tempDirectory) !== $tempDirectory
        || (fileperms($tempDirectory) & 0077) !== 0) {
        throw new RuntimeException('Cannot create private test-only ZIP directory');
    }
    $staged = $tempDirectory.'/'.$canonical;
    if (! copy($source, $staged) || filesize($staged) !== $size
        || ! hash_equals($digest, (string) hash_file('sha256', $staged))) {
        throw new RuntimeException('Test-only source snapshot mismatch');
    }

    $payload = [
        'product' => 'walidsite',
        'version' => $version,
        'source_sha' => $sourceSha,
        'artifact_sha256' => $digest,
        'artifact_size' => $size,
        'min_php' => '8.3.0',
        'artifact_file' => $canonical,
    ];
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $encode = static function (array $data) use ($secret): string {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload' => base64_encode($json),
            'signature' => base64_encode(sodium_crypto_sign_detached($json, $secret)),
        ], JSON_THROW_ON_ERROR);
    };
    $origin = 'https://release-acceptance.walidsite.org'; // Syntax only: NO HTTP I/O.
    $trust = new TrustedReleaseMetadata();
    $inspector = new TrustedReleaseArchive();
    $signed = $trust->verify($encode($payload), $public, $origin, '0.0.0');
    if ($signed['source_sha'] !== $sourceSha || $signed['artifact_sha256'] !== $digest
        || $signed['version'] !== $version) {
        throw new RuntimeException('PHP signed metadata disagrees with Python release train');
    }
    $inventory = $inspector->inspect($signed, $tempDirectory, $staged);
    if ($inventory['files'] < 4 || $inventory['uncompressed_bytes'] < 1) {
        throw new RuntimeException('PHP failed to inventory the actual Update ZIP');
    }

    $reject = static function (callable $action): void {
        try {
            $action();
        } catch (DomainException) {
            return;
        }
        throw new RuntimeException('Untrusted release was incorrectly accepted');
    };
    $wrongProduct = $payload;
    $wrongProduct['product'] = 'not-walidsite';
    $reject(static fn () => $trust->verify($encode($wrongProduct), $public, $origin, '0.0.0'));

    $badEnvelope = json_decode($encode($payload), true, 8, JSON_THROW_ON_ERROR);
    $sig = base64_decode($badEnvelope['signature'], true);
    $sig[0] = chr(ord($sig[0]) ^ 1);
    $badEnvelope['signature'] = base64_encode($sig);
    $reject(static fn () => $trust->verify(
        json_encode($badEnvelope, JSON_THROW_ON_ERROR), $public, $origin, '0.0.0'
    ));

    $wrongName = $tempDirectory.'/wrong-name.zip';
    if (! rename($staged, $wrongName)) {
        throw new RuntimeException('Cannot prepare wrong-name rejection test');
    }
    $reject(static fn () => $inspector->inspect($signed, $tempDirectory, $wrongName));
    if (! rename($wrongName, $staged)) {
        throw new RuntimeException('Cannot restore test archive name');
    }

    $handle = fopen($staged, 'c+b');
    if ($handle === false || fread($handle, 1) === false || fseek($handle, 0) !== 0
        || fwrite($handle, '!') !== 1 || ! fflush($handle)) {
        throw new RuntimeException('Cannot prepare tampered ZIP rejection test');
    }
    fclose($handle);
    $reject(static fn () => $inspector->inspect($signed, $tempDirectory, $staged));

    echo json_encode([
        'schema' => 'walidsite.signed-zip-ci-acceptance.v1',
        'source_sha' => $sourceSha,
        'version' => $version,
        'artifact_sha256' => $digest,
        'verified_files' => $inventory['files'],
        'negative_rejections' => 4,
        'test_only_ephemeral_key' => true,
        'publisher_signature_verified' => false,
        'code_update_performed' => false,
        'production_ready' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $failure) {
    // No untrusted filename, path, ZIP contents or ephemeral keys in CI output.
    fwrite(STDERR, "Actual signed ZIP compatibility acceptance failed.\n");
    exit(1);
} finally {
    if (is_string($tempDirectory) && is_dir($tempDirectory) && ! is_link($tempDirectory)) {
        foreach ([$tempDirectory.'/'.$canonical, $tempDirectory.'/wrong-name.zip'] as $file) {
            if (is_file($file) && ! is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($tempDirectory);
    }
}
