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

    /** Keep the first-run locale on POST redirects without trusting arbitrary query keys. */
    private function setupRedirect(Request $request): RedirectResponse
    {
        return redirect()->route('site.setup', $request->query('lang') === 'ar'
            ? ['lang' => 'ar'] : []);
    }

    private function localeMessage(Request $request, string $english, string $arabic): string
    {
        return $request->query('lang') === 'ar' ? $arabic : $english;
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
        // Only an actual connectivity failure may trigger credential recovery.
        // An incompatible migration history must remain pinned and visible.
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
        $data = $request->validate(['token' => ['required', 'string', 'max:256']],
            $request->query('lang') === 'ar' ? [
                'token.required' => 'رمز الإعداد مطلوب.',
                'token.string' => 'رمز الإعداد غير صالح.',
                'token.max' => 'رمز الإعداد أطول من الحد المسموح.',
            ] : []);
        if (! hash_equals((string) config('site.setup_token'), $data['token'])) {
            return back()->withErrors(['token' => $this->localeMessage($request,
                'Invalid setup token.', 'رمز الإعداد غير صحيح.')]);
        }
        $request->session()->regenerate();
        $request->session()->put('site_setup_authorized', true);

        return $this->setupRedirect($request);
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
            return back()->withErrors(['setup' => $this->localeMessage($request,
                'Migration failed. See the private application log. Restore your backup before retrying.',
                'فشل تنفيذ الترحيلات. راجع سجل التطبيق الخاص واستعد نسخة احتياطية موثقة قبل إعادة المحاولة.')]);
        }

        if ($result['state'] === 'schema_mismatch') {
            return back()->withErrors(['setup' => $this->localeMessage($request,
                'Database migration history is incompatible with this release. Deploy compatible code or restore a verified backup.',
                'سجل ترحيلات قاعدة البيانات غير متوافق مع هذا الإصدار. انشر الكود المتوافق أو استعد نسخة احتياطية موثقة.')]);
        }
        if (! in_array($result['state'], ['applied', 'current'], true)) {
            return back()->withErrors(['setup' => $this->localeMessage($request,
                'Database unavailable or an update is already running.',
                'قاعدة البيانات غير متاحة أو هناك تحديث جارٍ بالفعل.')]);
        }
        $request->session()->put('site_setup_migrated', true);

        return $this->setupRedirect($request);
    }

    public function finish(Request $request, SiteInstaller $installer, SiteUpdater $updater): RedirectResponse
    {
        $this->guard($installer);
        $this->unlocked($request);
        abort_unless($request->session()->get('site_setup_migrated') === true, 403);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:14', 'max:128', 'confirmed'],
        ], $request->query('lang') === 'ar' ? [
            'password.required' => 'كلمة مرور المالك مطلوبة.',
            'password.string' => 'كلمة مرور المالك غير صالحة.',
            'password.min' => 'كلمة مرور المالك يجب أن تتكون من 14 حرفًا على الأقل.',
            'password.max' => 'كلمة مرور المالك أطول من الحد المسموح.',
            'password.confirmed' => 'تأكيد كلمة مرور المالك غير مطابق.',
        ] : []);

        try {
            if (! $installer->complete($data['password'], $updater)) {
                return back()->withErrors(['setup' => $this->localeMessage($request,
                    'Setup is already running.', 'هناك عملية إعداد جارية بالفعل.')]);
            }
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['setup' => $this->localeMessage($request,
                'Installation could not finish. Check the private application logs.',
                'تعذّر إكمال التثبيت. راجع سجلات التطبيق الخاصة.')]);
        }

        $request->session()->forget(['site_setup_authorized', 'site_setup_migrated']);
        $request->session()->regenerate();

        return redirect()->route('login', $request->query('lang') === 'ar'
            ? ['lang' => 'ar'] : [])->with('status', $this->localeMessage($request,
                'Installation complete. Sign in with your owner email.',
                'اكتمل التثبيت. سجّل الدخول باستخدام بريد المالك.'));
    }
}
