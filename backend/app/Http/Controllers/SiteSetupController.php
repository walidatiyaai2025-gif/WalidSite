<?php

namespace App\Http\Controllers;

use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class SiteSetupController extends Controller
{
    private function guard(SiteInstaller $installer): void
    {
        abort_unless($installer->allowed(), 404);
    }

    private function unlocked(Request $request): void
    {
        abort_unless($request->session()->get('site_setup_authorized') === true, 403);
    }

    public function show(Request $request, SiteInstaller $installer, SiteUpdater $updater, DatabaseConfiguration $database): View
    {
        $this->guard($installer);
        $unlocked = $request->session()->get('site_setup_authorized') === true;
        $configured = $unlocked && $database->isConfigured();
        $status = $configured ? $updater->inspect() : null;
        // A saved but unreachable database must not strand the owner on an
        // error page with no way to fix the credentials without a terminal.
        // A healthy configured database remains locked against re-pointing.
        $historyMismatch = $configured && ($status['history_mismatch'] ?? false);
        $connectionFailed = $configured && ! $status['ready'] && ! $historyMismatch;
        // If a deployment introduced new migrations or the DB went offline
        // between steps, return safely to the database step instead of
        // trapping the owner on an unusable final form.
        $migrated = $configured
            && $request->session()->get('site_setup_migrated') === true
            && $status['ready'] && $status['pending'] === [];
        $step = ! $unlocked ? 'unlock' : ((! $configured || $connectionFailed) ? 'configure' : ($migrated ? 'owner' : 'database'));
        $ownerEmail = $migrated ? $installer->ownerEmail() : null;

        return view('setup.wizard', compact('step', 'status', 'ownerEmail', 'connectionFailed', 'historyMismatch'));
    }

    public function unlock(Request $request, SiteInstaller $installer): RedirectResponse
    {
        $this->guard($installer);
        $data = $request->validate(['token' => ['required', 'string', 'max:256']]);
        if (! hash_equals((string) config('site.setup_token'), $data['token'])) {
            return back()->withErrors(['token' => 'Invalid setup token.']);
        }
        $request->session()->regenerate();
        $request->session()->put('site_setup_authorized', true);

        return redirect()->route('site.setup');
    }

    public function database(Request $request, SiteInstaller $installer, SiteUpdater $updater): RedirectResponse
    {
        $this->guard($installer);
        $this->unlocked($request);
        abort_unless(config('site.setup_database_ready'), 403);

        try {
            $result = $updater->apply();
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['setup' => 'Migration failed. See the private application log. Restore your backup before retrying.']);
        }

        if ($result['state'] === 'schema_mismatch') {
            return back()->withErrors(['setup' => 'Database migration history is incompatible with this application release. No changes were applied.']);
        }
        if (! in_array($result['state'], ['applied', 'current'], true)) {
            return back()->withErrors(['setup' => 'Database unavailable or an update is already running.']);
        }
        $request->session()->put('site_setup_migrated', true);

        return redirect()->route('site.setup');
    }

    public function finish(Request $request, SiteInstaller $installer, SiteUpdater $updater): RedirectResponse
    {
        $this->guard($installer);
        $this->unlocked($request);
        abort_unless($request->session()->get('site_setup_migrated') === true, 403);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:14', 'max:128', 'confirmed'],
        ]);

        try {
            if (! $installer->complete($data['password'], $updater)) {
                return back()->withErrors(['setup' => 'Setup is already running.']);
            }
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['setup' => 'Installation could not finish. Check the private application logs.']);
        }

        $request->session()->forget(['site_setup_authorized', 'site_setup_migrated']);
        $request->session()->regenerate();

        return redirect()->route('login')->with('status', 'Installation complete. Sign in with your owner email.');
    }
}
