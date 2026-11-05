<?php

namespace App\Http\Controllers;

use App\Domain\Deployment\CpanelPhysicalHostPreflight;
use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Domain\Deployment\TrustedOwnerUpdateInbox;
use Illuminate\Http\UploadedFile;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class AdminUpdateController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        // An authenticated session may outlive an owner suspension, account
        // deletion or role revocation. Always re-read persisted eligibility
        // immediately before inspecting or applying privileged migrations.
        $owner = $request->user()?->fresh();
        abort_unless($owner !== null && $owner->canAccessAdmin()
            && $owner->hasRole('owner'), 403);
    }

    /** Read the deployed code version, never an editable configured claim. */
    private function installedVersion(): ?string
    {
        $file = base_path('VERSION');
        if (! is_file($file) || is_link($file) || ! is_readable($file)
            || filesize($file) === false || filesize($file) > 32) {
            return null;
        }

        $version = trim((string) @file_get_contents($file));
        return preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version) === 1
            ? $version : null;
    }

    private function pinnedInstalledVersion(): ?string
    {
        $disk = $this->installedVersion();
        $configured = config('site.trusted_installed_version');
        return $disk !== null && is_string($configured) && hash_equals($disk, $configured)
            ? $disk : null;
    }

    /** Non-secret fingerprint to invalidate a previously verified flash result. */
    private function releaseTrustFingerprint(): string
    {
        return hash('sha256', (string) config('site.trusted_release_origin')
            ."\n".(string) config('site.trusted_release_public_key'));
    }

    public function index(Request $request, SiteUpdater $updater): View
    {
        $this->authorizeOwner($request);
        $status = $updater->inspect();

        // This displays the code artifact actually installed on disk.
        // A configured source/ref string is not proof of the installed version.
        $installedVersion = $this->installedVersion();

        // Signed discovery is bound to the exact installed code and pinned
        // publisher settings. A changed release, key or stale browser flash
        // must not be presented as a currently verified update.
        $checked = $request->session()->get('trusted_release_check');
        if (is_array($checked) && ($checked['state'] ?? null) === 'signed_release_available') {
            $pinned = $this->pinnedInstalledVersion();
            $at = $checked['checked_at'] ?? null;
            if ($pinned === null
                || ! hash_equals($pinned, (string) ($checked['installed_version'] ?? ''))
                || ! hash_equals($this->releaseTrustFingerprint(), (string) ($checked['trust_fingerprint'] ?? ''))
                || ! is_int($at) || $at > time() || time() - $at > 300
                || config('site.updates_enabled') !== true) {
                $request->session()->forget('trusted_release_check');
            }
        }

        $verifiedUpload = null;
        $inboxId = $request->session()->get('verified_owner_update_inbox');
        // A previously authenticated archive must cease to be a selectable
        // candidate immediately when owner updates or the installed identity
        // are disabled. Do not leave a dormant session reference that could
        // resurface if a server setting is changed back later.
        if (is_string($inboxId)
            && (config('site.updates_enabled') !== true
                || $this->pinnedInstalledVersion() === null)) {
            $request->session()->forget('verified_owner_update_inbox');
            $inboxId = null;
        }
        if (is_string($inboxId) && $this->pinnedInstalledVersion() !== null
            && filled(config('site.trusted_release_origin'))
            && filled(config('site.trusted_release_public_key'))) {
            try {
                $verifiedUpload = app(TrustedOwnerUpdateInbox::class)->getVerified(
                    $inboxId,
                    (string) $request->user()->getKey(),
                    storage_path('app/private/verified-updates'),
                    public_path(),
                    (string) config('site.trusted_release_origin'),
                    (string) config('site.trusted_release_public_key'),
                    $installedVersion,
                );
            } catch (Throwable) {
                $request->session()->forget('verified_owner_update_inbox');
            }
        }

        // A read-only hosting check is informational, never authorization
        // to execute an update. Re-bind the result to the ZIP whose bytes
        // were reverified above, the installed version and a short TTL.
        $hostPreflight = null;
        $proof = $request->session()->get('owner_update_host_preflight');
        if ($verifiedUpload !== null && is_string($inboxId) && is_array($proof)
            && ($proof['id'] ?? null) === $inboxId
            && ($proof['version'] ?? null) === $verifiedUpload['version']
            && ($proof['artifact_sha256'] ?? null) === $verifiedUpload['artifact_sha256']
            && ($proof['installed_version'] ?? null) === $installedVersion
            && is_int($proof['checked_at'] ?? null)
            && $proof['checked_at'] <= time()
            && time() - $proof['checked_at'] <= 300
            && config('site.updates_enabled') === true) {
            $hostPreflight = $proof;
        } else {
            $request->session()->forget('owner_update_host_preflight');
        }

        return view('admin.updates.index', compact('status', 'installedVersion', 'verifiedUpload', 'hostPreflight'));
    }

    /**
     * Read-only, explicitly requested trusted release discovery for the
     * session-authenticated owner. Never take origin/verification key/version
     * from request data, execute remote code, or imply that Apply is ready.
     */
    public function check(Request $request, TrustedReleaseCatalog $catalog): RedirectResponse
    {
        $this->authorizeOwner($request);
        $input = $request->validate(['lang' => ['sometimes', 'in:en,ar']]);
        if (array_diff(array_keys($request->query()), ['lang']) !== []
            || array_diff(array_keys($request->request->all()), ['_token']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'updates' => 'Unexpected release check parameter.',
            ]);
        }

        $state = ['state' => 'not_configured'];
        if (config('site.updates_enabled') !== true) {
            $state = ['state' => 'disabled'];
        } else {
            $installed = $this->pinnedInstalledVersion();
            $origin = config('site.trusted_release_origin');
            $publisher = config('site.trusted_release_public_key');

            if ($installed !== null
                && is_string($origin) && $origin !== ''
                && is_string($publisher) && $publisher !== '') {
                try {
                    // Verifies HTTPS origin, Ed25519 signature, product/version
                    // identity and artifact metadata before any public display.
                    $release = $catalog->check($origin, $publisher, $installed)['release'];
                    $state = [
                        'state' => 'signed_release_available',
                        'installed_version' => $installed,
                        'trust_fingerprint' => $this->releaseTrustFingerprint(),
                        'checked_at' => time(),
                        'version' => $release['version'],
                        'source_sha' => $release['source_sha'],
                        'artifact_sha256' => $release['artifact_sha256'],
                    ];
                } catch (Throwable $exception) {
                    // The transport error may contain private hostnames or
                    // paths; never echo it, the untrusted feed or signing key.
                    $state = ['state' => 'publisher_unverified'];
                }
            }
        }

        return redirect()->route('admin.updates.index', [
            'lang' => ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en',
        ])->with('trusted_release_check', $state);
    }

    /**
     * Upload and verify only. Even a publisher-signed PHP archive MUST NOT be
     * executed from this action. Full Apply still requires independent,
     * restorable backups and an atomic host/runtime gate.
     */
    public function verifyUpload(
        Request $request,
        TrustedOwnerUpdateInbox $inbox,
        TrustedReleaseCatalog $catalog,
    ): RedirectResponse {
        $this->authorizeOwner($request);
        // Every new upload attempt starts a distinct publisher-identity
        // decision. A failed replacement must NEVER leave the older ZIP shown
        // as if it were the package just verified; likewise, discard stale
        // release discovery when the owner requests a different candidate.
        $request->session()->forget([
            'verified_owner_update_inbox',
            'trusted_release_check',
            'owner_update_host_preflight',
        ]);
        abort_unless(config('site.updates_enabled') === true, 403);
        $data = $request->validate([
            'package' => ['required', 'file', 'max:512000'],
        ]);
        $uploaded = $data['package'];
        if (! $uploaded instanceof UploadedFile) {
            abort(422);
        }

        $installed = $this->pinnedInstalledVersion();
        if ($installed === null
            || ! filled(config('site.trusted_release_origin'))
            || ! filled(config('site.trusted_release_public_key'))) {
            return back()->withErrors(['upload' => 'The installed release and trusted publisher are not configured.']);
        }

        try {
            $verified = $inbox->verifyUpload(
                $uploaded, $catalog,
                (string) config('site.trusted_release_origin'),
                (string) config('site.trusted_release_public_key'),
                $installed,
                (string) $request->user()->getKey(),
                storage_path('app/private/verified-updates'),
                public_path(),
            );
        } catch (Throwable $error) {
            // Publisher/network/file errors may contain credentials, paths or
            // untrusted bytes. Log only a class; never echo those values.
            \Illuminate\Support\Facades\Log::warning('admin.updates.package_rejected', [
                'type' => $error::class,
            ]);
            return back()->withErrors(['upload' => 'Update ZIP was rejected: publisher signature, release identity or archive safety could not be verified.']);
        }

        $request->session()->put('verified_owner_update_inbox', $verified['id']);
        return redirect()->route('admin.updates.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Signed update package verified. No code was installed or changed.');
    }

    /**
     * Authenticated, READ-ONLY physical cPanel readiness check for the owner
     * verified ZIP. Does not create a backup, touch maintenance, stage code or
     * enable Install. Unsupported hosting fails closed with redacted details.
     */
    public function preflight(
        Request $request,
        TrustedOwnerUpdateInbox $inbox,
        CpanelPhysicalHostPreflight $hosting,
    ): RedirectResponse {
        $this->authorizeOwner($request);
        $request->session()->forget('owner_update_host_preflight');
        abort_unless(config('site.updates_enabled') === true, 403);
        $input = $request->validate(['lang' => ['sometimes', 'in:en,ar']]);
        if (array_diff(array_keys($request->query()), ['lang']) !== []
            || array_diff(array_keys($request->request->all()), ['_token']) !== []) {
            return back()->withErrors(['hosting' => 'Unexpected hosting preflight parameter.']);
        }

        $installed = $this->pinnedInstalledVersion();
        $inboxId = $request->session()->get('verified_owner_update_inbox');
        if ($installed === null || ! is_string($inboxId)
            || ! filled(config('site.trusted_release_origin'))
            || ! filled(config('site.trusted_release_public_key'))) {
            return back()->withErrors(['hosting' => 'Verify a trusted update ZIP and installed version first.']);
        }

        try {
            // Recheck owner, release signature, source-version binding, ZIP
            // integrity and file safety on EVERY request.
            $verified = $inbox->getVerified(
                $inboxId,
                (string) $request->user()->getKey(),
                storage_path('app/private/verified-updates'),
                public_path(),
                (string) config('site.trusted_release_origin'),
                (string) config('site.trusted_release_public_key'),
                $installed,
            );
            $size = filesize($verified['archive']);
            if ($size === false) {
                throw new \DomainException('Cannot inspect verified archive size.');
            }
            $hosting->assertReady(
                dirname(public_path()),
                base_path('resources/deployment/cpanel-stable-index.php'),
                $installed,
                $size,
            );
        } catch (Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('admin.updates.host_preflight_blocked', [
                'type' => $error::class,
            ]);
            return back()->withErrors([
                'hosting' => 'Physical cPanel hosting is not yet compatible with verified code updates. No backup or installation occurred.',
            ]);
        }

        $request->session()->put('owner_update_host_preflight', [
            'id' => $inboxId,
            'installed_version' => $installed,
            'version' => $verified['version'],
            'artifact_sha256' => $verified['artifact_sha256'],
            'checked_at' => time(),
        ]);
        return redirect()->route('admin.updates.index', [
            'lang' => ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Hosting layout checked. Backups, write isolation and code installation are NOT verified.');
    }

    public function apply(Request $request, SiteUpdater $updater): RedirectResponse
    {
        $this->authorizeOwner($request);
        abort_unless(config('site.updates_enabled'), 403);

        try {
            $result = $updater->apply();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['updates' => 'Migration failed. Review private logs and the database backup.']);
        }

        if ($result['state'] === 'schema_mismatch') {
            return back()->withErrors(['updates' => 'Database migration history is incompatible with this release. Deploy compatible code or restore a verified backup.']);
        }
        if (! in_array($result['state'], ['applied', 'current'], true)) {
            return back()->withErrors(['updates' => 'Database unavailable or migration already running.']);
        }

        return redirect()->route('admin.updates.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $result['state'] === 'applied'
            ? 'Applied '.$result['applied'].' trusted migration(s).'
            : 'Database is already up to date.');
    }
}
