<?php

namespace App\Support\Deployment;

use Illuminate\Http\Request;

/**
 * Read-only FOODEX-style host preflight for the first browser installation.
 *
 * Never prints paths, credentials, APP_KEY or setup token. Nothing is written
 * and no remote host/database is contacted while inspecting local hosting.
 */
final class SetupPreflight
{
    /** @return array<string, array{ok:bool,label_en:string,label_ar:string,hint_en:string,hint_ar:string}> */
    public function inspect(Request $request): array
    {
        $root = realpath(base_path());
        $public = realpath(public_path());
        $storage = realpath(storage_path());

        $protectedRoot = $root !== false && $public !== false
            && $root !== $public
            && ! str_starts_with($root, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            && is_file(public_path('index.php'))
            && ! is_link(public_path('index.php'));

        $extensions = ['ctype', 'fileinfo', 'mbstring', 'openssl', 'pdo_mysql', 'tokenizer', 'xml'];
        $missing = array_values(array_filter($extensions, static fn (string $ext): bool => ! extension_loaded($ext)));
        $writable = [
            base_path(),
            base_path('bootstrap/cache'),
            storage_path('app'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
        ];
        $runtimeWritable = count(array_filter(
            $writable,
            static fn (string $path): bool => is_dir($path) && ! is_link($path) && is_writable($path),
        )) === count($writable);

        $env = base_path('.env');
        $environmentWritable = ! is_link($env) && (is_file($env)
            ? is_writable($env) : is_writable(base_path()));
        $local = app()->environment(['local', 'testing'])
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true);
        $https = $request->isSecure() || $local;

        return [
            'php' => [
                'ok' => PHP_VERSION_ID >= 80300,
                'label_en' => 'PHP 8.3 or newer',
                'label_ar' => 'PHP 8.3 أو أحدث',
                'hint_en' => 'Select PHP 8.3+ in cPanel MultiPHP Manager.',
                'hint_ar' => 'اختر PHP 8.3 أو أحدث من MultiPHP Manager.',
            ],
            'extensions' => [
                'ok' => $missing === [],
                'label_en' => 'Required PHP extensions',
                'label_ar' => 'إضافات PHP المطلوبة',
                'hint_en' => $missing === [] ? 'All required extensions are loaded.' : 'Enable: '.implode(', ', $missing).' using cPanel PHP Extensions.',
                'hint_ar' => $missing === [] ? 'جميع الإضافات الأساسية مفعلة.' : 'فعّل الإضافات: '.implode(', ', $missing).' من إعدادات PHP في cPanel.',
            ],
            'dependencies' => [
                'ok' => is_file(base_path('vendor/autoload.php')),
                'label_en' => 'Production Composer dependencies',
                'label_ar' => 'مكتبات Composer الإنتاجية',
                'hint_en' => 'Use the complete Setup ZIP, not a GitHub source archive.',
                'hint_ar' => 'استخدم ملف Setup ZIP الكامل وليس ملف Source Code من GitHub.',
            ],
            'private_root' => [
                'ok' => $protectedRoot && $storage !== false
                    && ! str_starts_with($storage, rtrim((string) $public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR),
                'label_en' => 'Application and storage outside public web root',
                'label_ar' => 'التطبيق والتخزين خارج المسار العام',
                'hint_en' => 'Extract once in the cPanel account home; the public_html directory is only for public files. Verify Document Root in cPanel.',
                'hint_ar' => 'فك الضغط مرة في المجلد الرئيسي للحساب، واترك ملفات الموقع العامة فقط في public_html. تأكد من Document Root.',
            ],
            'permissions' => [
                'ok' => $runtimeWritable && $environmentWritable,
                'label_en' => 'Private storage, cache and environment are writable',
                'label_ar' => 'صلاحيات مجلدات التخزين والكاش والإعدادات',
                'hint_en' => 'Grant the PHP account owner write access to private storage, bootstrap/cache and application. Do not use 777.',
                'hint_ar' => 'اسمح لمستخدم PHP بالكتابة في storage وbootstrap/cache ومجلد التطبيق دون استخدام 777.',
            ],
            'https' => [
                'ok' => $https,
                'label_en' => 'HTTPS is active',
                'label_ar' => 'شهادة HTTPS مفعّلة',
                'hint_en' => 'Enable SSL for your domain in cPanel before entering secrets.',
                'hint_ar' => 'فعّل شهادة SSL للدومين في cPanel قبل إدخال البيانات السرية.',
            ],
            'zip' => [
                'ok' => extension_loaded('zip'),
                'label_en' => 'ZIP extension for future Admin updates',
                'label_ar' => 'إضافة ZIP لتحديثات لوحة الإدارة',
                'hint_en' => 'Enable PHP ext-zip before using signed ZIP update packages.',
                'hint_ar' => 'فعّل إضافة zip في PHP قبل تحديث الموقع من لوحة الإدارة.',
            ],
        ];
    }

    /** @param array<string, array{ok:bool,label_en:string,label_ar:string,hint_en:string,hint_ar:string}> $checks */
    public function readyForInstallation(array $checks): bool
    {
        foreach (['php', 'extensions', 'dependencies', 'private_root', 'permissions', 'https'] as $required) {
            if (($checks[$required]['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
