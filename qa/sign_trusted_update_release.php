<?php
/**
 * Publisher-only Ed25519 signer for the immutable complete-code update archive.
 *
 * Usage: WALIDSITE_PUBLISHER_SECRET_KEY_B64=<private env secret> php qa/sign_trusted_update_release.php /private/releases < unsigned-metadata.json
 * Output: private/releases/latest.json; publish ONLY with a separately approved HTTPS origin.
 * The secret is never accepted as an argument, printed or written to a file.
 */
declare(strict_types=1);

function failClosed(): never
{
    fwrite(STDERR, "Signed release publication rejected; no trusted catalog issued.\n");
    exit(1);
}

if ($argc !== 2 || ! extension_loaded('sodium') || ! is_dir($argv[1]) || is_link($argv[1])) {
    failClosed();
}
$directory = realpath($argv[1]);
$secretEncoded = getenv('WALIDSITE_PUBLISHER_SECRET_KEY_B64');
if ($secretEncoded === false || strlen($secretEncoded) > 200) {
    failClosed();
}
$secret = base64_decode($secretEncoded, true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
    || base64_encode($secret) !== $secretEncoded) {
    failClosed();
}
$raw = stream_get_contents(STDIN, 8193);
if (! is_string($raw) || strlen($raw) > 8192) {
    failClosed();
}
try {
    $meta = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    failClosed();
}
$keys = ['product','version','source_sha','artifact_sha256','artifact_size','min_php','artifact_file'];
if (! is_array($meta) || array_is_list($meta)) {
    failClosed();
}
$actual = array_keys($meta);
sort($actual);
sort($keys);
if ($actual !== $keys || $meta['product'] !== 'walidsite'
    || ! is_string($meta['version'])
    || ! preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $meta['version'])
    || $meta['artifact_file'] !== 'WalidSite-update-v'.$meta['version'].'.zip'
    || ! is_string($meta['source_sha']) || ! preg_match('/^[0-9a-f]{40}$/D', $meta['source_sha'])
    || ! is_string($meta['artifact_sha256']) || ! preg_match('/^[0-9a-f]{64}$/D', $meta['artifact_sha256'])
    || ! is_int($meta['artifact_size']) || $meta['artifact_size'] < 1 || $meta['artifact_size'] > 1073741824
    || $meta['min_php'] !== '8.3.0') {
    failClosed();
}
$archive = $directory.'/'.$meta['artifact_file'];

// Publisher inspection MUST be performed on the SAME immutable bytes whose
// digest will be signed. Hashing a pathname, reopening it with ZipArchive,
// then hashing the pathname again can inspect a different inode/ZIP after a
// local publisher workspace rename (including a hard-link or in-place edit).
// Pin the source descriptor, copy it into a private new directory and scan
// only those frozen bytes; reject identity drift before publishing a catalog.
if (is_link($archive) || realpath($archive) !== $archive
    || (fileperms($directory) & 0022) !== 0) {
    failClosed();
}
$input = @fopen($archive, 'rb');
if ($input === false) {
    failClosed();
}
clearstatcache(true, $archive);
$baseline = @fstat($input);
$named = @lstat($archive);
$fields = ['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'];
$sameInput = static function () use ($input, $archive, $baseline, $fields): bool {
    clearstatcache(true, $archive);
    $open = @fstat($input);
    $name = @lstat($archive);
    if (! is_array($open) || ! is_array($name) || is_link($archive)
        || realpath($archive) !== $archive) {
        return false;
    }
    foreach ($fields as $field) {
        if (($open[$field] ?? null) !== ($baseline[$field] ?? null)
            || ($name[$field] ?? null) !== ($baseline[$field] ?? null)) {
            return false;
        }
    }
    return true;
};
if (! is_array($baseline) || ! is_array($named)
    || ($baseline['mode'] & 0170000) !== 0100000
    || ($baseline['mode'] & 0022) !== 0
    || ($baseline['nlink'] ?? 0) !== 1
    || $baseline['size'] !== $meta['artifact_size']
    || ! $sameInput()) {
    failClosed();
}

// A random 0700 directory and x-created 0600 file ensure that ZipArchive
// never follows or scans the untrusted mutable source path. Shutdown cleanup
// also runs when failClosed() exits from deep inside an inspection loop.
$freezeRoot = rtrim(sys_get_temp_dir(), '/').'/.walidsite-publisher-'.bin2hex(random_bytes(16));
if (! @mkdir($freezeRoot, 0700)
    || realpath($freezeRoot) !== $freezeRoot
    || (fileperms($freezeRoot) & 0077) !== 0) {
    failClosed();
}
$frozen = $freezeRoot.'/approved-update.zip';
register_shutdown_function(static function () use ($freezeRoot, $frozen): void {
    if (! is_link($frozen) && is_file($frozen)) {
        @unlink($frozen);
    }
    if (! is_link($freezeRoot) && is_dir($freezeRoot)) {
        @rmdir($freezeRoot);
    }
});
$output = @fopen($frozen, 'xb');
if ($output === false || ! @chmod($frozen, 0600)) {
    failClosed();
}
$hash = hash_init('sha256');
$totalCopied = 0;
while (! feof($input)) {
    $part = fread($input, 1048576);
    if (! is_string($part) || ($part === '' && ! feof($input))) {
        failClosed();
    }
    if ($part === '') {
        break;
    }
    $totalCopied += strlen($part);
    if ($totalCopied > $meta['artifact_size']) {
        failClosed();
    }
    hash_update($hash, $part);
    $remaining = $part;
    while ($remaining !== '') {
        $written = fwrite($output, $remaining);
        if ($written === false || $written < 1) {
            failClosed();
        }
        $remaining = substr($remaining, $written);
    }
}
if (! fflush($output) || (function_exists('fsync') && ! fsync($output))) {
    failClosed();
}
fclose($output);
$copiedHash = hash_final($hash);
if ($totalCopied !== $meta['artifact_size']
    || ! hash_equals($meta['artifact_sha256'], $copiedHash)
    || ! $sameInput()
    || filesize($frozen) !== $totalCopied) {
    failClosed();
}
// The publisher must independently reject structural ZIP hazards, even when
// an operator supplied a recalculated SHA-256. The recipient applies its own
// separate inspection; neither checksum nor signing alone makes code safe.
if (! class_exists(ZipArchive::class)) {
    failClosed();
}
$zip = new ZipArchive();
if ($zip->open($frozen, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
    failClosed();
}
$required = [
    'application/bootstrap/app.php',
    'application/vendor/autoload.php',
    'application/composer.json',
    'application/composer.lock',
    'application/VERSION',
    'public_html/index.php',
    'public_html/.htaccess',
];
$seen = [];
$total = 0;
$versionInZip = null;
try {
    if ($zip->numFiles < count($required) || $zip->numFiles > 20000) {
        failClosed();
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (! is_array($stat) || ! is_string($stat['name'] ?? null)
            || ! is_int($stat['size'] ?? null) || ! is_int($stat['comp_size'] ?? null)) {
            failClosed();
        }
        $name = $stat['name'];
        if (strlen($name) > 512 || isset($seen[$name])
            || ! preg_match('#\\A(?:application|public_html|contracts)/(?:[A-Za-z0-9_.@+ -]+/)*[A-Za-z0-9_.@+ -]+\\z#D', $name)
            || str_contains($name, chr(0)) || str_contains($name, chr(92))) {
            failClosed();
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || ($part[0] === '.' && $part !== '.htaccess')
                || str_starts_with(strtolower($part), '.env')
                || in_array(strtolower($part), ['site-installed.lock', 'site-first-run.json',
                    'site-setup-token', 'id_rsa', 'id_ed25519'], true)) {
                failClosed();
            }
        }
        if (str_starts_with($name, 'application/storage/')
            || str_starts_with($name, 'public_html/storage/')
            || str_starts_with($name, 'public_html/uploads/')) {
            failClosed();
        }
        $seen[$name] = true;
        if (! $zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
            failClosed();
        }
        if ($opsys === ZipArchive::OPSYS_UNIX) {
            $type = ($attributes >> 16) & 0170000;
            if ($type !== 0 && $type !== 0100000) {
                failClosed();
            }
        }
        if ($stat['size'] < 0 || $stat['size'] > 134217728
            || $stat['comp_size'] < 0
            || ($stat['size'] !== 0 && $stat['comp_size'] === 0)
            || ($stat['size'] > 1048576 && $stat['size'] > $stat['comp_size'] * 200)
            || ($total += $stat['size']) > 1073741824) {
            failClosed();
        }
        if ($name === 'application/VERSION') {
            if ($stat['size'] < 5 || $stat['size'] > 32) {
                failClosed();
            }
            $bytes = $zip->getFromIndex($i);
            if (! is_string($bytes) || strlen($bytes) !== $stat['size']) {
                failClosed();
            }
            $versionInZip = trim($bytes);
        }
    }
} finally {
    $zip->close();
}
foreach ($required as $path) {
    if (! isset($seen[$path])) {
        failClosed();
    }
}
if ($versionInZip !== $meta['version']
    || ! hash_equals($meta['artifact_sha256'], (string) hash_file('sha256', $frozen))
    || ! $sameInput()
    || filesize($frozen) !== $meta['artifact_size']) {
    failClosed();
}

// Re-read from the SAME held source descriptor, not a reopened pathname.
// The published archive still must match the exact frozen, inspected digest
// at the final trust boundary; a substituted source must not get a catalog.
if (@fseek($input, 0) !== 0) {
    failClosed();
}
$again = hash_init('sha256');
$againBytes = 0;
while (! feof($input)) {
    $part = fread($input, 1048576);
    if (! is_string($part) || ($part === '' && ! feof($input))) {
        failClosed();
    }
    if ($part === '') {
        break;
    }
    $againBytes += strlen($part);
    if ($againBytes > $meta['artifact_size']) {
        failClosed();
    }
    hash_update($again, $part);
}
if ($againBytes !== $meta['artifact_size']
    || ! hash_equals($copiedHash, hash_final($again))
    || ! $sameInput()) {
    failClosed();
}
fclose($input);

$catalog = $directory.'/latest.json';
if (file_exists($catalog) || is_link($catalog)) {
    // Immutable publication: never replace an existing latest.json without an
    // independently controlled and audited publisher promotion operation.
    failClosed();
}
$payload = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$envelope = json_encode([
    'payload' => base64_encode($payload),
    'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret)),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
sodium_memzero($secret);
$handle = @fopen($catalog, 'xb');
if ($handle === false) {
    failClosed();
}
try {
    if (! chmod($catalog, 0600) || fwrite($handle, $envelope) !== strlen($envelope) || ! fflush($handle)) {
        throw new RuntimeException('Cannot persist signature.');
    }
} catch (Throwable) {
    fclose($handle);
    @unlink($catalog);
    failClosed();
}
fclose($handle);
echo "SIGNED CATALOG PREPARED (not deployed, installed or published).\n";
