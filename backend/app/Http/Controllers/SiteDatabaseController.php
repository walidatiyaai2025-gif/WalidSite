<?php

namespace App\Http\Controllers;

use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class SiteDatabaseController extends Controller
{
    public function store(Request $request, SiteInstaller $installer, DatabaseConfiguration $database, SiteUpdater $updater): RedirectResponse
    {
        abort_unless($installer->allowed(), 404);
        abort_unless($request->session()->get('site_setup_authorized') === true, 403);
        // Credential recovery is allowed only if the saved connection fails.
        // Keep a healthy database pinned; the destination must still pass the
        // empty-database probe and all original setup protections.
        if ($database->isConfigured()) {
            $status = $updater->inspect();
            // A newer/incompatible schema is reachable, not a credential
            // failure. Never offer a database re-pointing escape hatch.
            abort_if($status['ready'] || ($status['history_mismatch'] ?? false), 403);
        }
        if (app()->environment('production') && ! $request->isSecure()) {
            abort(403, 'HTTPS is required for setup.');
        }

        $data = $request->validate([
            'site_url' => ['required', 'string', 'max:255'],
            'db_host' => ['required', 'string', 'max:253'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['required', 'string', 'max:255'],
        ]);
        foreach (['db_host', 'db_database', 'db_username'] as $field) {
            if (! preg_match('/^[a-zA-Z0-9_.:-]+$/', $data[$field])) {
                return back()->withErrors(['database' => 'Invalid database identifier or host.']);
            }
        }
        // Never persist APP_URL from the request Host header: it may be a
        // temporary cPanel domain or a spoofed reverse-proxy header.
        $siteUrl = $database->canonicalSiteUrl($data['site_url']);
        if ($siteUrl === null) {
            return back()->withErrors([
                'site_url' => 'Enter a valid root HTTPS site URL (e.g. https://example.com).',
            ]);
        }
        unset($data['site_url']);

        if (! $database->probe($data)) {
            return back()->withErrors(['database' => 'Database connection failed or is not empty. Check the cPanel database and credentials.']);
        }
        try {
            $database->save($data, $siteUrl);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['database' => 'Could not save private settings. Check application folder permissions.']);
        }
        $request->session()->forget('site_setup_migrated');

        return redirect()->route('site.setup');
    }
}
