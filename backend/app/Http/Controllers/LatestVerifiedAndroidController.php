<?php

namespace App\Http\Controllers;

use App\Domain\Deployment\VerifiedAndroidLatestDownload;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * No external redirect, no GitHub token and no Actions-artifact URL.
 * The named APK stream stays private until the signature, active Web code
 * identity, approved TLS host and the actual APK bytes are all verified.
 */
final class LatestVerifiedAndroidController extends Controller
{
    /** @return array{meta:array<string,int|string>,stream:resource} */
    private function authorized(Request $request, VerifiedAndroidLatestDownload $verifier): array
    {
        $siteOrigin = (string) config('app.url');
        $host = parse_url($siteOrigin, PHP_URL_HOST);
        if (config('site.android_download_enabled') !== true
            || ! $request->isSecure() || ! is_string($host)
            || ! hash_equals($host, strtolower($request->getHost()))
            || $request->getPort() !== 443) {
            abort(503, 'Verified Android download is unavailable.');
        }
        try {
            return $verifier->resolve(
                base_path(), public_path(),
                storage_path('app/private/android-release'),
                (string) config('site.trusted_installed_version'),
                $siteOrigin,
                (string) config('site.trusted_release_public_key'),
                (string) config('site.android_trusted_signer_sha256'),
            );
        } catch (DomainException) {
            // Neither private filesystem paths nor publisher details may
            // appear in public error responses.
            abort(503, 'Verified Android download is unavailable.');
        }
    }

    public function metadata(Request $request, VerifiedAndroidLatestDownload $verifier): JsonResponse
    {
        $verified = $this->authorized($request, $verifier);
        fclose($verified['stream']);
        $meta = $verified['meta'];
        return response()->json([
            'state' => 'VERIFIED_COMPATIBLE',
            'web_version' => $meta['web_version'],
            'deployed_web_sha' => $meta['web_source_sha'],
            'api_origin' => $meta['api_origin'],
            'apk_version' => $meta['apk_version'],
            'apk_source_sha' => $meta['apk_source_sha'],
            'apk_sha256' => $meta['apk_sha256'],
            'apk_signer_sha256' => $meta['apk_signer_sha256'],
            'verified_at' => $meta['verified_at'],
            'release_notes' => $meta['release_notes'],
            'latest_download_url' => route('public.android.latest'),
        ])->header('Cache-Control', 'no-store, max-age=0')
          ->header('X-Content-Type-Options', 'nosniff');
    }

    public function download(Request $request, VerifiedAndroidLatestDownload $verifier): StreamedResponse
    {
        $verified = $this->authorized($request, $verifier);
        $stream = $verified['stream'];
        $meta = $verified['meta'];
        return response()->stream(static function () use ($stream): void {
            try {
                while (! feof($stream)) {
                    $bytes = fread($stream, 65536);
                    if (! is_string($bytes) || ($bytes === '' && ! feof($stream))) {
                        throw new \RuntimeException('Verified APK stream interrupted.');
                    }
                    echo $bytes;
                }
            } finally {
                @flock($stream, LOCK_UN);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="'.$meta['filename'].'"; filename*=UTF-8\'\''.$meta['filename'],
            'Content-Length' => (string) $meta['apk_size'],
            'X-WalidSite-APK-SHA256' => $meta['apk_sha256'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
