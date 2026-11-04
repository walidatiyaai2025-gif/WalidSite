<?php

namespace App\Http\Controllers;

use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use App\Support\Deployment\SetupPreflight;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class SiteDatabaseController extends Controller
{
    private function localeMessage(Request $request, string $english, string $arabic): string
    {
        return $request->query('lang') === 'ar' ? $arabic : $english;
    }

    public function store(Request $request, SiteInstaller $installer, DatabaseConfiguration $database, SiteUpdater $updater, SetupPreflight $preflight): RedirectResponse
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

        // A cPanel first run must not write .env or test DB credentials when
        // the runtime / filesystem is not ready. ZIP is advisory here:
        // it becomes mandatory only before enabling signed code updates.
        if (! $preflight->readyForInstallation($preflight->inspect($request))) {
            return back()->withErrors(['preflight' => $this->localeMessage($request,
                'Server requirements are not ready. Review the checks above the database form.',
                'متطلبات الاستضافة غير جاهزة. راجع الفحوصات أعلى نموذج قاعدة البيانات.')]);
        }

        $data = $request->validate([
            'site_url' => ['required', 'string', 'max:255'],
            'db_host' => ['required', 'string', 'max:253'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['required', 'string', 'max:255'],
        ], $request->query('lang') === 'ar' ? [
            'required' => 'حقل :attribute مطلوب.',
            'string' => 'حقل :attribute غير صالح.',
            'integer' => 'حقل :attribute يجب أن يكون عددًا صحيحًا.',
            'between' => 'يجب أن يكون :attribute بين :min و:max.',
            'max' => 'حقل :attribute أطول من الحد المسموح.',
        ] : [], $request->query('lang') === 'ar' ? [
            'site_url' => 'رابط الموقع',
            'db_host' => 'خادم قاعدة البيانات',
            'db_port' => 'منفذ قاعدة البيانات',
            'db_database' => 'اسم قاعدة البيانات',
            'db_username' => 'مستخدم قاعدة البيانات',
            'db_password' => 'كلمة مرور قاعدة البيانات',
        ] : []);
        foreach (['db_host', 'db_database', 'db_username'] as $field) {
            if (! preg_match('/^[a-zA-Z0-9_.:-]+$/', $data[$field])) {
                return back()->withErrors(['database' => $this->localeMessage($request,
                    'Invalid database identifier or host.', 'اسم قاعدة البيانات أو خادمها غير صالح.')]);
            }
        }
        // Never persist APP_URL from the request Host header: it may be a
        // temporary cPanel domain or a spoofed reverse-proxy header.
        $siteUrl = $database->canonicalSiteUrl($data['site_url']);
        if ($siteUrl === null) {
            return back()->withErrors([
                'site_url' => $this->localeMessage($request,
                    'Enter a valid root HTTPS site URL (e.g. https://example.com).',
                    'أدخل رابط الموقع الأساسي بصيغة HTTPS صحيحة، مثل https://example.com.'),
            ]);
        }
        unset($data['site_url']);

        if (! $database->probe($data)) {
            return back()->withErrors(['database' => $this->localeMessage($request,
                'Database connection failed or is not empty. Check the cPanel database and credentials.',
                'فشل الاتصال بقاعدة البيانات أو أنها ليست فارغة. تحقق من قاعدة cPanel وبيانات الدخول.')]);
        }
        try {
            $database->save($data, $siteUrl);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['database' => $this->localeMessage($request,
                'Could not save private settings. Check application folder permissions.',
                'تعذّر حفظ الإعدادات الخاصة. تحقق من أذونات مجلد التطبيق.')]);
        }
        $request->session()->forget('site_setup_migrated');

        return redirect()->route('site.setup', $request->query('lang') === 'ar'
            ? ['lang' => 'ar'] : []);
    }
}
