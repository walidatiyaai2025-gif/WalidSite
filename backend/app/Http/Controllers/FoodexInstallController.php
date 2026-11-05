<?php

namespace App\Http\Controllers;

use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\SetupPreflight;
use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * FOODEX-style 15-stage first-run wizard for the one-extract cPanel ZIP.
 *
 * Server-side session progression is authoritative. Step 1 requires the
 * generated private possession token, not first-visitor ownership. The old
 * four-step /setup route remains for backward-compatible interrupted installs.
 */
final class FoodexInstallController extends Controller
{
    public const STEPS = [
        1 => ['Welcome', 'مرحبًا'],
        2 => ['Server requirements', 'متطلبات الخادم'],
        3 => ['Permissions and document root', 'الصلاحيات وجذر الموقع'],
        4 => ['Database configuration', 'إعداد قاعدة البيانات'],
        5 => ['Test database connection', 'اختبار اتصال قاعدة البيانات'],
        6 => ['Platform information', 'بيانات الموقع'],
        7 => ['Prepare Super Admin', 'إعداد المدير العام'],
        8 => ['Storage configuration', 'إعداد التخزين'],
        9 => ['Cache and queue configuration', 'إعداد الكاش والمهام'],
        10 => ['Mail configuration', 'إعداد البريد'],
        11 => ['Run migrations', 'تنفيذ الترحيلات'],
        12 => ['Seed managed content', 'إضافة البيانات الأولية'],
        13 => ['Verify application key', 'التحقق من مفتاح التشفير'],
        14 => ['Health and scheduler', 'فحص التشغيل والمهام الدورية'],
        15 => ['Finish and lock installer', 'الإكمال وقفل التثبيت'],
    ];

    public function show(
        Request $request,
        SiteInstaller $installer,
        SetupPreflight $preflight,
        SiteUpdater $updater,
    ): View {
        abort_unless($installer->allowed(), 404);
        $authorized = $request->session()->get('site_setup_authorized') === true;
        $stage = $authorized ? $this->stage($request) : 1;
        $checks = $authorized ? $preflight->inspect($request) : [];
        $status = $authorized && $stage >= 5 ? $updater->inspect() : null;
        $ownerEmail = $authorized && $stage >= 7 ? $installer->ownerEmail() : null;
        $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';
        return view('setup.foodex-install', [
            'stage' => $stage,
            'steps' => self::STEPS,
            'checks' => $checks,
            'checksReady' => $authorized && $preflight->readyForInstallation($checks),
            'status' => $status,
            'ownerEmail' => $ownerEmail,
            'locale' => $locale,
        ]);
    }

    public function next(
        Request $request,
        int $step,
        SiteInstaller $installer,
        SetupPreflight $preflight,
        DatabaseConfiguration $database,
        SiteUpdater $updater,
    ): RedirectResponse {
        abort_unless($installer->allowed(), 404);
        abort_unless(isset(self::STEPS[$step]), 404);
        // An unauthenticated visitor must not learn the active step number
        // from a 409 response; reject before comparing server-side progress.
        if ($step > 1) {
            abort_unless($request->session()->get('site_setup_authorized') === true, 403);
        }
        abort_unless($this->stage($request) === $step, 409);

        try {
            switch ($step) {
                case 1:
                    $input = $request->validate(['token' => ['required', 'string', 'max:256']]);
                    if (! hash_equals((string) config('site.setup_token'), $input['token'])) {
                        return $this->fail($request, 'The private setup token is invalid.', 'رمز التثبيت الخاص غير صحيح.');
                    }
                    $request->session()->regenerate();
                    $request->session()->put('site_setup_authorized', true);
                    break;

                case 2:
                case 3:
                    if (! $preflight->readyForInstallation($preflight->inspect($request))) {
                        return $this->fail($request, 'cPanel PHP, HTTPS, permissions or private paths are not ready.', 'متطلبات PHP أو HTTPS أو الصلاحيات أو مسارات التخزين غير جاهزة.');
                    }
                    break;

                case 4:
                    if (! $preflight->readyForInstallation($preflight->inspect($request))) {
                        return $this->fail($request, 'Hosting preflight failed.', 'فشل فحص متطلبات الاستضافة.');
                    }
                    if ($database->isConfigured()) {
                        $old = $updater->inspect();
                        if (! $old['ready'] || $old['history_mismatch']) {
                            return $this->fail($request, 'An existing database is not healthy; its credentials cannot be replaced.', 'قاعدة البيانات المحفوظة غير سليمة، ولا يمكن استبدال بيانات اتصالها.');
                        }
                        break;
                    }
                    $input = $request->validate([
                        'site_url' => ['required', 'url', 'max:255'],
                        'db_host' => ['required', 'string', 'max:253'],
                        'db_port' => ['required', 'integer', 'between:1,65535'],
                        'db_database' => ['required', 'string', 'max:64'],
                        'db_username' => ['required', 'string', 'max:64'],
                        'db_password' => ['required', 'string', 'max:255'],
                    ]);
                    foreach (['db_host', 'db_database', 'db_username'] as $field) {
                        if (! preg_match('/^[A-Za-z0-9_.:-]+$/D', $input[$field])) {
                            return $this->fail($request, 'Invalid database host or identifier.', 'خادم قاعدة البيانات أو اسمها غير صالح.');
                        }
                    }
                    $url = $database->canonicalSiteUrl($input['site_url']);
                    if ($url === null || ! $database->probe($input)) {
                        return $this->fail($request, 'HTTPS domain or empty MySQL/MariaDB connection is invalid.', 'رابط الموقع أو اتصال قاعدة البيانات الفارغة غير صالح.');
                    }
                    unset($input['site_url']);
                    $database->save($input, $url);
                    break;

                case 5:
                    $state = $updater->inspect();
                    if (! $database->isConfigured() || ! $state['ready'] || $state['history_mismatch']) {
                        return $this->fail($request, 'Database test failed; check your cPanel credentials.', 'فشل اختبار قاعدة البيانات؛ راجع بيانات cPanel.');
                    }
                    break;

                case 6:
                    $data = $request->validate([
                        'app_name' => ['required', 'string', 'min:2', 'max:100'],
                        'app_locale' => ['required', 'in:en,ar'],
                    ]);
                    $database->saveWizardRuntime([
                        'APP_NAME' => $data['app_name'],
                        'APP_LOCALE' => $data['app_locale'],
                    ]);
                    break;

                case 7:
                    $data = $request->validate([
                        'admin_email' => ['required', 'email', 'max:255'],
                        'admin_password' => ['required', 'string', 'min:14', 'max:128', 'confirmed'],
                    ]);
                    // WalidSite identity is the approved, reviewed CV owner,
                    // not a new public signup or operator-supplied identity.
                    if (! hash_equals(strtolower($installer->ownerEmail()), strtolower($data['admin_email']))) {
                        return $this->fail($request, 'The owner email must match the approved site identity.', 'بريد المدير يجب أن يطابق هوية مالك الموقع المعتمدة.');
                    }
                    $request->session()->put('foodex_install_owner_hash', Hash::make($data['admin_password']));
                    break;

                case 8:
                    $data = $request->validate(['disk' => ['required', 'in:local,public']]);
                    if ($data['disk'] === 'public') {
                        $target = realpath(storage_path('app/public'));
                        $link = public_path('storage');
                        if ($target === false || ! is_dir($target) || ! function_exists('symlink')) {
                            return $this->fail($request, 'Public storage cannot be linked on this host.', 'تعذّر ربط التخزين العام على الاستضافة.');
                        }
                        if (is_link($link)) {
                            if (realpath($link) !== $target) {
                                return $this->fail($request, 'Public storage link points to another location.', 'رابط التخزين العام يشير إلى موقع مختلف.');
                            }
                        } elseif (file_exists($link) || ! @symlink($target, $link)) {
                            return $this->fail($request, 'Public storage linking is unsupported; select private local storage.', 'ربط التخزين العام غير مدعوم؛ اختر التخزين الخاص.');
                        }
                    }
                    $database->saveWizardRuntime(['FILESYSTEM_DISK' => $data['disk']]);
                    break;

                case 9:
                    $data = $request->validate([
                        'cache_store' => ['required', 'in:file'],
                        'queue_connection' => ['required', 'in:sync'],
                    ]);
                    // The shared-host default works without Redis or a daemon.
                    // A queue worker cannot be claimed active until independently
                    // configured and checked in the hosting panel.
                    $database->saveWizardRuntime([
                        'CACHE_STORE' => $data['cache_store'],
                        'QUEUE_CONNECTION' => $data['queue_connection'],
                    ]);
                    break;

                case 10:
                    $data = $request->validate([
                        'mail_mailer' => ['required', 'in:log,smtp'],
                        'mail_host' => ['required_if:mail_mailer,smtp', 'nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/D'],
                        'mail_port' => ['required_if:mail_mailer,smtp', 'nullable', 'integer', 'between:1,65535'],
                        'mail_username' => ['nullable', 'string', 'max:255'],
                        'mail_password' => ['nullable', 'string', 'max:255'],
                        'mail_from_address' => ['required', 'email', 'max:255'],
                        'mail_from_name' => ['required', 'string', 'max:100'],
                    ]);
                    $settings = [
                        'MAIL_MAILER' => $data['mail_mailer'],
                        'MAIL_FROM_ADDRESS' => $data['mail_from_address'],
                        'MAIL_FROM_NAME' => $data['mail_from_name'],
                    ];
                    if ($data['mail_mailer'] === 'smtp') {
                        $settings['MAIL_HOST'] = $data['mail_host'];
                        $settings['MAIL_PORT'] = (string) $data['mail_port'];
                        $settings['MAIL_USERNAME'] = (string) ($data['mail_username'] ?? '');
                        $settings['MAIL_PASSWORD'] = (string) ($data['mail_password'] ?? '');
                    }
                    $database->saveWizardRuntime($settings);
                    break;

                case 11:
                    $result = $updater->applyForFirstInstall();
                    if ($result['state'] === 'database_not_empty') {
                        return $this->fail($request,
                            'Database contents changed after the empty-database check. Select a new empty database or recover the interrupted install. No migrations were run.',
                            'تغيرت محتويات قاعدة البيانات بعد فحص أنها فارغة. اختر قاعدة فارغة جديدة أو استعد التثبيت المنقطع. لم يتم تنفيذ أي ترحيلات.');
                    }
                    if (! in_array($result['state'], ['current', 'applied'], true)) {
                        return $this->fail($request, 'Trusted database migrations failed; inspect private logs.', 'فشل تنفيذ ترحيلات قاعدة البيانات المعتمدة؛ راجع السجلات الخاصة.');
                    }
                    break;

                case 12:
                    $installer->seedForFirstInstall($updater);
                    break;

                case 13:
                    $key = (string) config('app.key');
                    $env = (string) config('site.environment_path');
                    if (! preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/D', $key)
                        || ! is_file($env) || is_link($env) || ! is_readable($env)
                        || ! str_contains((string) file_get_contents($env), 'APP_KEY=')) {
                        return $this->fail($request, 'Private APP_KEY has not been saved safely.', 'لم يتم حفظ مفتاح التشفير الخاص بطريقة سليمة.');
                    }
                    // Never rotate the key already used by encrypted sessions.
                    break;

                case 14:
                    $state = $updater->inspect();
                    if (! $state['ready'] || $state['pending'] !== []) {
                        return $this->fail($request, 'Database schema health check failed.', 'فشل فحص قاعدة البيانات.');
                    }
                    DB::connection()->select('SELECT 1');
                    $probe = 'foodex-install-health-'.bin2hex(random_bytes(8));
                    $nonce = bin2hex(random_bytes(16));
                    Cache::put($probe, $nonce, 15);
                    $ok = hash_equals($nonce, (string) Cache::get($probe));
                    Cache::forget($probe);
                    if (! $ok || ! is_writable(storage_path('app'))) {
                        return $this->fail($request, 'Storage or cache health check failed.', 'فشل فحص التخزين أو الكاش.');
                    }
                    // Scheduler: shared cPanel cannot be assumed to permit
                    // crontab creation. State is honestly reported in the UI.
                    break;

                case 15:
                    $hash = $request->session()->get('foodex_install_owner_hash');
                    if (! is_string($hash)) {
                        return $this->fail($request, 'Owner preparation is missing; restart step 7.', 'لم يتم إعداد حساب المدير؛ أعد الخطوة السابعة.');
                    }
                    if (! $installer->completeWithPreparedPasswordHash($hash, $updater)) {
                        return $this->fail($request, 'Another installation is currently running.', 'هناك تثبيت آخر جارٍ الآن.');
                    }
                    $request->session()->forget([
                        'site_setup_authorized', 'site_setup_migrated',
                        'foodex_install_step', 'foodex_install_owner_hash',
                    ]);
                    $request->session()->regenerate();
                    return redirect()->route('login')->with('status', $this->message(
                        $request, 'Installation finished. Sign in to the Admin Dashboard.',
                        'اكتمل التثبيت. سجّل الدخول إلى لوحة الإدارة.',
                    ));
            }
        } catch (Throwable $exception) {
            // Never echo/log raw DB/SMTP connection errors, credentials,
            // private paths or secrets from any installer operation.
            Log::warning('site.install.step_failed', ['step' => $step, 'type' => $exception::class]);
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }
            return $this->fail($request,
                'This step could not be completed. Check the cPanel settings and private application log.',
                'تعذر إكمال الخطوة. تحقق من إعدادات cPanel والسجل الخاص بالتطبيق.');
        }

        $request->session()->put('foodex_install_step', $step + 1);
        return $this->backToWizard($request);
    }

    private function stage(Request $request): int
    {
        return max(1, min(15, (int) $request->session()->get('foodex_install_step', 1)));
    }

    private function backToWizard(Request $request): RedirectResponse
    {
        return redirect()->route('site.install', $request->query('lang') === 'ar' ? ['lang' => 'ar'] : []);
    }

    private function message(Request $request, string $en, string $ar): string
    {
        return $request->query('lang') === 'ar' ? $ar : $en;
    }

    private function fail(Request $request, string $en, string $ar): RedirectResponse
    {
        return $this->backToWizard($request)->withErrors([
            'install' => $this->message($request, $en, $ar),
        ]);
    }
}
