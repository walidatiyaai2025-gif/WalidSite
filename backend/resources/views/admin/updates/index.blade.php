@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'التحديثات والباتشات' : 'Updates and patches' }}</title>
<style>
:root{color-scheme:light;--ink:#142e4d;--link:#126de1;--line:#d3e3ed}*{box-sizing:border-box}
body{margin:0;background:#f5f9fc;color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
main{max-width:850px;margin:5vh auto;padding:clamp(18px,4vw,40px);background:white;border:1px solid var(--line);border-radius:18px}
a{color:var(--link)}button{background:#126de1;color:white;border:0;border-radius:9px;min-height:46px;padding:10px 18px;font:inherit;cursor:pointer}
button:disabled{background:#718096;cursor:not-allowed}a:focus-visible,button:focus-visible{outline:3px solid #09a;outline-offset:3px}
ul{padding-inline-start:24px}code{overflow-wrap:anywhere}.error{color:#a01826}
</style>
@include('partials.web-fonts')
</head>
<body><main>
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'العودة للإدارة' : 'Back to dashboard' }}</a>
<h1>{{ $locale === 'ar' ? 'مركز التحديثات والباتشات' : 'Updates & patch center' }}</h1>
<a href="{{ route('admin.updates.index', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<p>{{ $locale === 'ar' ? 'الإصدار المنشور الموثوق:' : 'Trusted deployed release:' }} <code>{{ config('site.release_ref') }}</code></p>
<p>{{ $locale === 'ar' ? 'الملفات البرمجية تُنشر عبر Git/CI الموثوق فقط. هذا القسم يفحص Migrations وينفذها دون رفع PHP من المتصفح.' : 'Code patches must be deployed via trusted Git/CI. This page detects and runs checked-in migrations; it cannot upload executable PHP.' }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(!$status['ready'])
<p role="alert" class="error">{{ $locale === 'ar' ? 'قاعدة البيانات غير متاحة؛ لا يمكن التحقق من حالة التحديث.' : 'Database unavailable; update status cannot be verified.' }}</p>
@else
<h2>{{ $locale === 'ar' ? 'الترحيلات المنتظرة' : 'Pending migrations' }}: {{ count($status['pending']) }}</h2>
@if(count($status['pending']) === 0)
<p>{{ $locale === 'ar' ? 'قاعدة البيانات محدثة للإصدار المنشور.' : 'Database is current for the deployed code.' }}</p>
@else
<ul>@foreach($status['pending'] as $migration)<li><code>{{ $migration }}</code></li>@endforeach</ul>
<p>{{ $locale === 'ar' ? 'أنشئ نسخة احتياطية من قاعدة البيانات قبل تنفيذ أي ترحيل.' : 'Take a database backup before applying migrations.' }}</p>
@endif
<form method="post" action="{{ route('admin.updates.apply', ['lang' => $locale]) }}">@csrf
<button type="submit" @disabled(!config('site.updates_enabled') || count($status['pending']) === 0)>
{{ $locale === 'ar' ? 'تطبيق التحديثات الموثوقة' : 'Apply trusted migrations' }}</button>
</form>
@endif
<p>{{ $locale === 'ar' ? 'التحديث التلقائي بعد نشر إصدار جديد:' : 'Automatic migrations after a trusted code deploy:' }}
<strong>{{ config('site.auto_migrate') ? ($locale === 'ar' ? 'مُفعّل بشرط تشغيل Cron' : 'Enabled (requires cron)') : ($locale === 'ar' ? 'معطّل' : 'Disabled') }}</strong></p>
<p><small>{{ $locale === 'ar' ? 'لا تنفَّذ التحديثات التلقائية إلا عند تفعيل SITE_AUTO_MIGRATE وتشغيل Laravel Scheduler على الخادم. يُراجع سجل Laravel الخاص للأخطاء.' : 'Automatic updates require SITE_AUTO_MIGRATE and a working Laravel scheduler; errors are recorded in private application logs.' }}</small></p>
</main></body></html>
