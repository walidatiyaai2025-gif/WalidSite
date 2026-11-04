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
if (is_link($archive) || ! is_file($archive) || filesize($archive) !== $meta['artifact_size']
    || ! hash_equals($meta['artifact_sha256'], hash_file('sha256', $archive))) {
    failClosed();
}
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
