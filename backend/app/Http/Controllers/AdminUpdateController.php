<?php

namespace App\Http\Controllers;

use App\Domain\Deployment\TrustedReleaseCatalog;
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

    public function index(Request $request, SiteUpdater $updater): View
    {
        $this->authorizeOwner($request);
        $status = $updater->inspect();

        return view('admin.updates.index', compact('status'));
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
            $installed = config('site.trusted_installed_version');
            $origin = config('site.trusted_release_origin');
            $publisher = config('site.trusted_release_public_key');

            if (is_string($installed) && preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $installed) === 1
                && is_string($origin) && $origin !== ''
                && is_string($publisher) && $publisher !== '') {
                try {
                    // Verifies HTTPS origin, Ed25519 signature, product/version
                    // identity and artifact metadata before any public display.
                    $release = $catalog->check($origin, $publisher, $installed)['release'];
                    $state = [
                        'state' => 'signed_release_available',
                        'installed_version' => $installed,
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
