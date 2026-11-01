<?php

namespace App\Http\Controllers;

use App\Support\Deployment\SiteUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class AdminUpdateController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->hasRole('owner'), 403);
    }

    public function index(Request $request, SiteUpdater $updater): View
    {
        $this->authorizeOwner($request);
        $status = $updater->inspect();

        return view('admin.updates.index', compact('status'));
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
