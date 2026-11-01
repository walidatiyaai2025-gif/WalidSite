<?php

namespace App\Http\Controllers;

use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\SiteInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class SiteDatabaseController extends Controller
{
    public function store(Request $request, SiteInstaller $installer, DatabaseConfiguration $database): RedirectResponse
    {
        abort_unless($installer->allowed(), 404);
        abort_unless($request->session()->get('site_setup_authorized') === true, 403);
        abort_if($database->isConfigured(), 403);
        if (app()->environment('production') && ! $request->isSecure()) {
            abort(403, 'HTTPS is required for setup.');
        }

        $data = $request->validate([
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
        if (! $database->probe($data)) {
            return back()->withErrors(['database' => 'Database connection failed or is not empty. Check the cPanel database and credentials.']);
        }
        try {
            $database->save($data, $request->getSchemeAndHttpHost());
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['database' => 'Could not save private settings. Check application folder permissions.']);
        }
        $request->session()->forget('site_setup_migrated');

        return redirect()->route('site.setup');
    }
}
