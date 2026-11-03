@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($releaseRef = trim((string) config('site.release_ref', '')))
@php($identifiedRelease = $releaseRef !== '' && $releaseRef !== 'unidentified')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'تحديثات النظام — وليد سايت' : 'System updates — WalidSite' }}</title>
<style>
:root{color-scheme:light;--ink:#142e4d;--link:#126de1;--line:#d3e3ed;--muted:#315471;--danger:#a01826}
*{box-sizing:border-box}body{margin:0;background:#f5f9fc;color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
main{max-width:1020px;margin:3vh auto;padding:clamp(18px,4vw,40px);background:white;border:1px solid var(--line);border-radius:18px}
h1{font-size:clamp(29px,4vw,42px);line-height:1.25;margin:16px 0 8px}h2{font-size:clamp(20px,3vw,26px);line-height:1.35}
a{color:var(--link);display:inline-flex;align-items:center;min-height:44px;font-weight:700}a:focus-visible,button:focus-visible{outline:3px solid #008b80;outline-offset:3px}
.top-links{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
button{background:var(--link);color:white;border:0;border-radius:9px;min-height:46px;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer}
button:disabled{background:#718096;cursor:not-allowed}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:14px;margin:24px 0}
.summary>div{background:#f5f9fc;border:1px solid var(--line);border-radius:14px;padding:18px;min-width:0}
.summary dt{font-size:14px;font-weight:700;color:var(--muted)}.summary dd{font-weight:800;margin:8px 0 0;overflow-wrap:anywhere}
.panel{border:1px solid var(--line);border-radius:14px;padding:clamp(16px,3vw,24px);margin-top:22px}
.panel h2{margin:0 0 10px}.panel p{margin:10px 0}.panel code{overflow-wrap:anywhere}
.blocked{border-inline-start:5px solid #bf7f12;background:#fff8e8;padding:14px 18px;border-radius:8px;color:#644400}
.error{border-inline-start:5px solid #a01826;background:#fff3f4;padding:12px 18px;color:var(--danger);border-radius:8px}
.success{border-inline-start:5px solid #179b67;background:#edf9f1;padding:12px 18px;color:#145b3a;border-radius:8px}
.steps{padding-inline-start:24px;margin:10px 0}.steps li{padding:10px 4px}.steps li+li{border-top:1px solid var(--line)}
.status{display:block;font-size:14px;color:var(--muted);overflow-wrap:anywhere}
.migrations{overflow-x:auto}.migrations li{overflow-wrap:anywhere;margin:6px 0}
.small{font-size:14px;color:var(--muted)}
@media(max-width:600px){main{margin:0;border:0;min-height:100vh;border-radius:0}.top-links{align-items:flex-start}}
</style>
@include('partials.web-fonts')
</head>
<body><main>
<nav class="top-links" aria-label="{{ $locale === 'ar' ? 'تنقل تحديثات النظام' : 'System update navigation' }}">
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'العودة للوحة الإدارة' : 'Back to dashboard' }}</a>
<a href="{{ route('admin.updates.index', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}" hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
</nav>
<h1>{{ $locale === 'ar' ? 'النظام — التحديثات' : 'System — Updates' }}</h1>
<p>{{ $locale === 'ar'
    ? 'هذه الصفحة تميّز بين تحديث ملفات الموقع كاملة وبين ترحيلات قاعدة البيانات الموجودة بالفعل على الخادم.'
    : 'This page distinguishes full application code releases from migrations already checked into the deployed server.' }}</p>

<dl class="summary" aria-label="{{ $locale === 'ar' ? 'ملخص حالة الإصدار' : 'Release status summary' }}">
<div><dt>{{ $locale === 'ar' ? 'معرّف الإصدار الموجود' : 'Configured deployed release' }}</dt>
<dd><code>{{ $identifiedRelease ? $releaseRef : ($locale === 'ar' ? 'غير محدد' : 'Unidentified') }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'الإصدار المتاح' : 'Available version' }}</dt>
<dd>{{ $locale === 'ar' ? 'غير متحقق منه' : 'Not verified' }}</dd></div>
<div><dt>{{ $locale === 'ar' ? 'النسخة الاحتياطية' : 'Backup status' }}</dt>
<dd>{{ $locale === 'ar' ? 'لم يتم التحقق' : 'Not verified' }}</dd></div>
<div><dt>{{ $locale === 'ar' ? 'تحديث الكود من الإدارة' : 'Admin code upgrade' }}</dt>
<dd>{{ $locale === 'ar' ? 'غير جاهز' : 'Not available' }}</dd></div>
</dl>

<section class="panel" aria-labelledby="code-updates-title">
<h2 id="code-updates-title">{{ $locale === 'ar' ? 'تحديث ملفات الموقع كاملة' : 'Complete application code update' }}</h2>
<div class="blocked" role="status" data-testid="trusted-code-update-unavailable">
<strong>{{ $locale === 'ar' ? 'التحديث الآمن للكود غير متاح بعد.' : 'Trusted code updates are not yet available.' }}</strong>
<p>{{ $locale === 'ar'
    ? 'لا توجد في هذا الإصدار واجهة خلفية موصولة للتحقق من توقيع الإصدار وتنزيله، وإنشاء نسخة احتياطية قابلة للاستعادة، واستبدال الكود والتحقق من السلامة. لذلك لم يُعرض زر تثبيت قد يغيّر ملفات الموقع دون حماية.'
    : 'This deployed interface has no connected publisher-authenticated release, verified recoverable backup, controlled code replacement or post-update health service. No unsafe install button is offered.' }}</p>
</div>
<ol class="steps" aria-label="{{ $locale === 'ar' ? 'مراحل تحديث الإصدار الكامل' : 'Full code update requirements' }}">
<li><strong>{{ $locale === 'ar' ? 'فحص إصدار موثوق' : 'Check authenticated release' }}</strong><span class="status">{{ $locale === 'ar' ? 'لا يوجد مصدر إصدارات موثق ومتصل بعد.' : 'No verified release feed connected.' }}</span></li>
<li><strong>{{ $locale === 'ar' ? 'فحص التوافق والنسخ الاحتياطي' : 'Preflight and recoverable backup' }}</strong><span class="status">{{ $locale === 'ar' ? 'لم تُنفّذ نسخة احتياطية أو تجربة استعادة.' : 'Backup and restore have not been verified.' }}</span></li>
<li><strong>{{ $locale === 'ar' ? 'تطبيق الملفات والترحيلات' : 'Install code and migrations' }}</strong><span class="status">{{ $locale === 'ar' ? 'استبدال الكود الكامل غير متاح؛ الترحيلات المحلية منفصلة أدناه.' : 'Full code replacement is not available; local migrations are handled separately below.' }}</span></li>
<li><strong>{{ $locale === 'ar' ? 'التحقق من الصحة والاستعادة' : 'Verify health and recovery' }}</strong><span class="status">{{ $locale === 'ar' ? 'لا توجد نتيجة تحقق أو سجل تحديث كود.' : 'No code-update health receipt or version history exists.' }}</span></li>
</ol>
</section>

<section class="panel" aria-labelledby="migration-title">
<h2 id="migration-title">{{ $locale === 'ar' ? 'ترحيلات قاعدة البيانات في الكود الموجود' : 'Database migrations from deployed code' }}</h2>
<p>{{ $locale === 'ar'
    ? 'هذه العملية لا تنزّل إصدارًا جديدًا ولا تستبدل ملفات PHP أو الواجهة. لا تعتبر نجاحها دليلاً على تحديث الموقع.'
    : 'This action neither downloads a new release nor replaces PHP or website files. A successful migration is not a code upgrade.' }}</p>
<a href="{{ route('admin.updates.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إعادة فحص الترحيلات' : 'Refresh migration status' }}</a>
@if(session('status'))<p class="success" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($status['history_mismatch'] ?? false)
<p role="alert" class="error">{{ $locale === 'ar'
    ? 'تاريخ ترحيلات قاعدة البيانات لا يتوافق مع هذا الإصدار. تم منع الترحيلات لحماية البيانات. لا تعيد تشغيلها دون نسخة استعادة موثقة.'
    : 'Incompatible migration history. The deployed code cannot safely apply migrations to this database; recover a compatible release or verified backup.' }}</p>
@elseif(!($status['ready'] ?? false))
<p role="alert" class="error">{{ $locale === 'ar' ? 'قاعدة البيانات غير متاحة؛ لا يمكن فحص الترحيلات.' : 'Database unavailable; migrations cannot be inspected.' }}</p>
@else
<h3>{{ $locale === 'ar' ? 'الترحيلات المنتظرة' : 'Pending migrations' }}: {{ count($status['pending']) }}</h3>
@if(count($status['pending']) === 0)
<p role="status">{{ $locale === 'ar' ? 'لا توجد ترحيلات معلقة في ملفات هذا الإصدار.' : 'No pending migrations in this deployed code release.' }}</p>
@else
<ul class="migrations">@foreach($status['pending'] as $migration)<li><code>{{ $migration }}</code></li>@endforeach</ul>
<p class="blocked">{{ $locale === 'ar' ? 'تحذير: لا يوجد إثبات بإنشاء نسخة احتياطية قابلة للاستعادة من هذه الصفحة. تحقق منها قبل الترحيل.' : 'Warning: this page cannot prove a restorable backup exists. Verify one before applying migrations.' }}</p>
@endif
<form method="post" action="{{ route('admin.updates.apply', ['lang' => $locale]) }}">@csrf
<button type="submit" @disabled(!config('site.updates_enabled') || count($status['pending']) === 0)>
{{ $locale === 'ar' ? 'تطبيق الترحيلات الموجودة فقط' : 'Apply deployed migrations only' }}</button>
</form>
@endif
</section>
<p class="small">{{ $locale === 'ar' ? 'ترحيلات Cron التلقائية:' : 'Automatic migration scheduling:' }}
<strong>{{ config('site.auto_migrate') ? ($locale === 'ar' ? 'مُفعّل بشرط تشغيل Cron' : 'Enabled, requires working cron') : ($locale === 'ar' ? 'معطّل' : 'Disabled') }}</strong>.
{{ $locale === 'ar' ? 'يُراجع سجل Laravel الخاص عند حدوث خطأ، ولا يُعد تشغيل Cron تحديثًا لكود الموقع.' : 'Errors are held in private Laravel logs. Cron does not install new application code.' }}</p>
</main></body></html>
