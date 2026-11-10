@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($releaseRef = trim((string) config('site.release_ref', '')))
@php($trustedCheck = session('trusted_release_check'))
@php($trustedState = is_array($trustedCheck) ? ($trustedCheck['state'] ?? null) : null)
@php($signedCandidateVerified = $trustedState === 'signed_release_available')
@php($releaseNotes = $verifiedUpload ? ($verifiedUpload['release_notes'] ?? null) : ($signedCandidateVerified ? ($trustedCheck['release_notes'] ?? null) : null))
@php($releaseNotesVersion = $verifiedUpload ? $verifiedUpload['version'] : ($signedCandidateVerified ? $trustedCheck['version'] : null))
@php($codeUpdateHistory = $codeUpdateHistory ?? ['state' => 'none', 'receipts' => []])
@php($cronStatus = $cronStatus ?? ['state' => 'UNVERIFIED', 'checked_at' => null, 'installed_version' => null, 'available_version' => null, 'reason' => 'NO_VERIFIED_RECENT_POLL'])
@php
$cronBlockReasonText = [
    'TRUSTED_INSTALLED_VERSION_MISSING' => [
        'ar' => 'هوية الإصدار المثبّت غير موثقة. مطلوب التحقق من التثبيت الأصلي قبل تثبيت رقم الإصدار الموثوق.',
        'en' => 'Installed release trust pin is missing. Attest the original installation before pinning its version.',
    ],
    'INSTALLED_RUNTIME_IDENTITY_UNVERIFIED' => [
        'ar' => 'تعذّر إثبات هوية ملفات Laravel النشطة. التحديث ممنوع حتى التحقق من نسخة التشغيل.',
        'en' => 'The active Laravel runtime identity is unverified. Updates remain disabled until independently attested.',
    ],
    'TRUSTED_RELEASE_ORIGIN_MISSING' => [
        'ar' => 'مصدر إصدارات HTTPS الموثوق غير مهيأ.',
        'en' => 'The approved HTTPS release publisher origin is not configured.',
    ],
    'TRUSTED_RELEASE_PUBLIC_KEY_MISSING' => [
        'ar' => 'مفتاح التحقق العام من توقيع الإصدارات غير مهيأ.',
        'en' => 'The pinned release publisher public verification key is missing.',
    ],
    'RELEASE_MAINTENANCE_ACTIVE' => [
        'ar' => 'توجد عملية صيانة أو تحديث معلّقة. يلزم فحص الاسترجاع قبل أي تحديث جديد.',
        'en' => 'An update maintenance barrier is present. Recovery must be verified before any new update.',
    ],
    'VERIFIED_HOST_UPDATE_GATE_REQUIRED' => [
        'ar' => 'يوجد إصدار موقّع أحدث لكن يلزم إثبات الاستضافة والنسخ الاحتياطي والاستعادة قبل تطبيقه.',
        'en' => 'A newer signed release is visible, but host isolation, backup and restore gates are not verified.',
    ],
    'PUBLISHER_CHECK_UNVERIFIED' => [
        'ar' => 'فشل التحقق من كتالوج الناشر الموقّع أو اتصاله؛ لم تُثبت أي نسخة جديدة.',
        'en' => 'Signed publisher discovery is unverified; no new release has been authenticated.',
    ],
];
@endphp
@php($identifiedRelease = $releaseRef !== '' && $releaseRef !== 'unidentified')
@php($androidLatest = $androidLatest ?? null)
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
<section class="panel" aria-labelledby="cron-release-status-title" data-testid="trusted-release-cron-panel">
<h2 id="cron-release-status-title">{{ $locale === 'ar' ? 'مراقبة الإصدارات التلقائية' : 'Automatic release monitoring' }}</h2>
<p class="small">{{ $locale === 'ar'
    ? 'فحص الإصدارات الموقعة يعمل للقراءة فقط افتراضيًا. لا يُسمح بالتثبيت التلقائي إلا بعد تفعيل مشغّل الاستضافة الموثوق والتحقق من عزل الكتابة والاستعادة والفحص الصحي.'
    : 'Signed publisher discovery is read-only by default. Auto-install requires an independently registered hosting adapter, writer isolation, recovery drill and live HTTPS health.' }}</p>
<p class="small" data-testid="release-cron-install-opt-in">{{ $locale === 'ar' ? 'السماح المحلي بالتثبيت التلقائي:' : 'Local automatic-install opt-in:' }}
<strong>{{ config('site.release_cron_apply_enabled') === true && $hostCodeUpdateAvailable
    ? ($locale === 'ar' ? 'مهيأ بشرط فحوص التشغيل' : 'Provisioned, runtime gates still required')
    : ($locale === 'ar' ? 'محجوب — يلزم تفعيل الاستضافة' : 'Blocked — host activation required') }}</strong></p>
<dl class="summary">
<div><dt>{{ $locale === 'ar' ? 'حالة الفحص' : 'Poll status' }}</dt>
<dd data-testid="release-cron-state">{{ $cronStatus['state'] }}</dd></div>
@if($cronStatus['state'] === 'BLOCKED')
<div><dt>{{ $locale === 'ar' ? 'سبب تعطّل الفحص أو النشر' : 'Blocked reason' }}</dt>
<dd data-testid="release-cron-block-reason">{{ $cronBlockReasonText[$cronStatus['reason']][$locale] ?? ($locale === 'ar' ? 'لم تكتمل متطلبات التحقق من الإصدارات.' : 'Release verification prerequisites remain unmet.') }}</dd></div>
@endif
<div><dt>{{ $locale === 'ar' ? 'آخر فحص مسجّل محليًا' : 'Last recorded local check' }}</dt>
<dd data-testid="release-cron-last-check">{{ $cronStatus['checked_at'] !== null ? gmdate('Y-m-d H:i:s', $cronStatus['checked_at']).' UTC' : ($locale === 'ar' ? 'غير متاح' : 'Unavailable') }}</dd></div>
<div><dt>{{ $locale === 'ar' ? 'الإصدار المتاح بالكتالوج' : 'Catalog version' }}</dt>
<dd>{{ $cronStatus['available_version'] ?? ($locale === 'ar' ? 'غير مُثبت' : 'Not verified') }}</dd></div>
</dl>
@if($cronStatus['state'] === 'DEPLOYED')
<p class="success" role="status" data-testid="release-cron-deployed">{{ $locale === 'ar'
    ? 'يوجد إيصال محلي لتثبيت نسخة كود موقّعة بعد اختبار الاستعادة وتبديل الإصدار وفحص HTTPS. هذا ليس شهادة نشر عام ولا يثبت نسخة APK.'
    : 'Private receipt: signed code was installed after recovery rehearsal, version switch and HTTPS health verification. This is not a public release or APK certification.' }}</p>
@elseif($cronStatus['state'] !== 'UP_TO_DATE')
<p class="blocked" data-testid="release-cron-update-not-deployed">{{ $locale === 'ar'
    ? 'التحديث البرمجي لم يُطبّق. مطلوب مصدر إصدار موثوق وتفعيل الاستضافة وإثبات النسخ الاحتياطي والاستعادة والفحص الصحي.'
    : 'No application update was applied. Trusted publication, hosting activation, restorable backup and live health evidence remain required.' }}</p>
@else
<p class="small" data-testid="release-cron-same-version-not-deployment">{{ $locale === 'ar'
    ? 'لم يعرض الكتالوج الموقّع إصدارًا أحدث وقت الفحص؛ هذا لا يثبت صحة النسخة المنشورة أو رابط APK.'
    : 'No newer signed catalog version was observed at the last check; this does not prove deployed health or APK compatibility.' }}</p>
@endif
</section>

<section class="panel" aria-labelledby="verified-android-download-title" data-testid="verified-android-download">
<h2 id="verified-android-download-title">{{ $locale === 'ar' ? 'أحدث تطبيق Android معتمد للموقع' : 'Latest verified website-compatible Android APK' }}</h2>
@if(is_array($androidLatest))
<p class="success" role="status" data-testid="android-production-download-ready">{{ $locale === 'ar'
    ? 'تم التحقق من التوقيع والملف وربطه بإصدار كود الموقع النشط؛ التحميل متاح.'
    : 'Publisher pairing, archive bytes and active website release match; download is available.' }}</p>
<dl class="summary">
<div><dt>{{ $locale === 'ar' ? 'إصدار التطبيق والموقع' : 'App and Web version' }}</dt><dd>{{ $androidLatest['apk_version'] }}</dd></div>
<div><dt>Git SHA</dt><dd><code>{{ $androidLatest['web_source_sha'] }}</code></dd></div>
<div><dt>APK SHA-256</dt><dd><code>{{ $androidLatest['apk_sha256'] }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'دومين API المعتمد' : 'Approved API origin' }}</dt><dd><code>{{ $androidLatest['api_origin'] }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'وقت التحقق' : 'Verification time' }}</dt><dd>{{ gmdate('Y-m-d H:i:s', $androidLatest['verified_at']) }} UTC</dd></div>
</dl>
<p><a href="{{ route('public.android.latest') }}" data-testid="verified-android-download-link">{{ $locale === 'ar'
    ? 'تنزيل APK المتوافق والمعتمد'
    : 'Download last verified compatible APK' }}</a></p>
<p><a href="{{ route('public.android.latest.metadata') }}">{{ $locale === 'ar' ? 'عرض بيانات التحقق العامة' : 'View public verification metadata' }}</a></p>
@else
<p class="blocked" role="status" data-testid="android-production-download-blocked">{{ $locale === 'ar'
    ? 'لا يوجد APK إنتاجي قابل للتحقق ومربوط بإصدار الموقع الحالي. لا نعرض ملفات QA أو روابط GitHub مؤقتة كنسخ جاهزة للنشر.'
    : 'No production APK is independently verified against the currently deployed Web release. Debug QA and expiring GitHub artifact links are not offered as production downloads.' }}</p>
<p class="small">{{ $locale === 'ar'
    ? 'رابط التحميل الدائم /downloads/android/latest يظل محجوبًا حتى ينشر مشغّل موثوق ملف APK وإيصال الربط الموقّع في التخزين الخاص.'
    : 'The permanent /downloads/android/latest URL remains unavailable until an authorized publisher installs a signed Web–APK receipt and production APK in private storage.' }}</p>
@endif
</section>
@if(session('status'))<p class="success" role="status" data-testid="owner-update-action-result">{{ session('status') }}</p>@endif
@if(session('code_update_history_warning'))
<p class="error" role="alert" data-testid="owner-code-update-history-write-warning">{{ $locale === 'ar'
    ? 'اكتمل تثبيت الكود والتحقق من صحته، لكن تعذّر تسجيل إيصال السجل الخاص. لا تعتبر السجل مكتملًا؛ راجع صلاحيات المسار الخاص وسجلات المشغّل.'
    : 'Code installation and health verification completed, but the private history receipt could not be saved. The audit trail is incomplete; review private host permissions and operator logs.' }}</p>
@endif
<p>{{ $locale === 'ar'
    ? 'هذه الصفحة تميّز بين تحديث ملفات الموقع كاملة وبين ترحيلات قاعدة البيانات الموجودة بالفعل على الخادم.'
    : 'This page distinguishes full application code releases from migrations already checked into the deployed server.' }}</p>

<dl class="summary" aria-label="{{ $locale === 'ar' ? 'ملخص حالة الإصدار' : 'Release status summary' }}">
<div><dt>{{ $locale === 'ar' ? 'الإصدار المثبّت فعليًا' : 'Current code version' }}</dt>
<dd data-testid="installed-package-version"><code>{{ $installedVersion ?? ($locale === 'ar' ? 'غير معروف' : 'Unknown') }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'معرّف الإصدار الموجود' : 'Configured deployed release' }}</dt>
<dd><code>{{ $identifiedRelease ? $releaseRef : ($locale === 'ar' ? 'غير محدد' : 'Unidentified') }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'الإصدار المتاح' : 'Available version' }}</dt>
<dd data-testid="latest-verified-package-version">{{ $releaseNotesVersion ?? ($locale === 'ar' ? 'غير متحقق منه' : 'Not verified') }}</dd></div>
<div><dt>{{ $locale === 'ar' ? 'النسخة الاحتياطية' : 'Backup status' }}</dt>
<dd data-testid="owner-backup-status">
@if($recoveryHistory['state'] === 'blocked')
{{ $locale === 'ar' ? 'تعذّر التحقق من سجل الاستعادة' : 'Recovery history cannot be verified' }}
@elseif($recoveryHistory['state'] === 'history')
{{ $locale === 'ar' ? 'إيصالات استعادة تاريخية — ليست موافقة حالية على التحديث' : 'Historical restore receipts — not current update approval' }}
@else
{{ $locale === 'ar' ? 'لا توجد إيصالات استعادة مسجّلة' : 'No recorded restore receipts' }}
@endif
</dd></div>
<div><dt>{{ $locale === 'ar' ? 'تحديث الكود من الإدارة' : 'Admin code upgrade' }}</dt>
<dd data-testid="owner-code-upgrade-status">
@if(config('site.updates_enabled') !== true)
{{ $locale === 'ar' ? 'التحديثات معطّلة من الخادم' : 'Updates disabled by server' }}
@elseif(!$hostCodeUpdateAvailable)
{{ $locale === 'ar' ? 'محجوب: تكامل الاستضافة غير متاح' : 'Blocked: host integration unavailable' }}
@elseif($stagedCode)
{{ $locale === 'ar' ? 'الحزمة مجهّزة؛ لم يُثبّت التحديث بعد' : 'Signed code staged; update not installed' }}
@else
{{ $locale === 'ar' ? 'تكامل الاستضافة مسجّل؛ التثبيت يحتاج تحققًا مستقلًا' : 'Host integration registered; install still gated' }}
@endif
</dd></div>
</dl>

@if($releaseNotesVersion !== null)
<section class="panel" aria-labelledby="signed-release-notes-title" data-testid="publisher-signed-release-notes">
<h2 id="signed-release-notes-title">{{ $locale === 'ar' ? 'ملاحظات الإصدار الموقّع' : 'Publisher-signed release notes' }}</h2>
<p class="small">{{ $locale === 'ar' ? 'الإصدار المستهدف:' : 'Target version:' }} <code>{{ $releaseNotesVersion }}</code></p>
@if(is_string($releaseNotes))
<p data-testid="verified-release-notes-content" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $releaseNotes }}</p>
@else
<p class="small" data-testid="signed-release-notes-not-provided">{{ $locale === 'ar' ? 'لم يرفق الناشر ملاحظات موقّعة لهذا الإصدار.' : 'The publisher did not supply signed release notes for this version.' }}</p>
@endif
<p class="small">{{ $locale === 'ar'
    ? 'هذه الملاحظات جزء من بيان الناشر الذي تم التحقق من توقيعه. عرضها لا يعني تثبيت التحديث أو نجاح الاستعادة.'
    : 'These notes come only from verified publisher metadata. Displaying them does not install code or attest backup/recovery.' }}</p>
</section>
@endif

<section class="panel" aria-labelledby="code-updates-title">
<h2 id="code-updates-title">{{ $locale === 'ar' ? 'تحديث ملفات الموقع كاملة' : 'Complete application code update' }}</h2>
@if(!$hostCodeUpdateAvailable)
<div class="blocked" role="status" data-testid="trusted-code-update-unavailable">
<strong>{{ $locale === 'ar' ? 'التحديث الآمن للكود غير متاح بعد.' : 'Trusted code updates are not yet available.' }}</strong>
<p>{{ $locale === 'ar'
    ? 'يمكن فحص كتالوج الإصدارات الموقّعة بمفتاح ناشر مثبت على الخادم. لكن تثبيت الكود يتطلب إثبات النسخ الاحتياطي والاستعادة والتبديل الآمن وفحص السلامة؛ لذلك لا يوجد زر تثبيت غير آمن.'
    : 'You can check the publisher-signed release catalog using server-pinned trust settings. Full-code Apply remains unavailable until recoverable backups, controlled cutover and health checks are proven.' }}</p>
</div>
@else
<div class="blocked" role="status" data-testid="trusted-code-update-requires-stage">
<strong>{{ $locale === 'ar' ? 'يتطلب التحديث حزمة موقّعة وتجهيزًا موثقًا.' : 'Install requires an approved signed and attested release.' }}</strong>
<p>{{ $locale === 'ar'
    ? 'مزوّد الاستضافة متصل. لا يُقبل التثبيت إلا بعد فحص الحزمة وجميع ملفات الكود وعزل الكتّاب ونجاح الاستعادة وفحص التشغيل عبر HTTPS.'
    : 'A host update capability is registered. Install is enabled only for a privately staged signed release; live backup/restore, writer isolation and HTTPS health must still pass at execution time.' }}</p>
</div>
@endif
<section aria-labelledby="signed-zip-upload">
<h3 id="signed-zip-upload">{{ $locale === 'ar' ? 'رفع حزمة تحديث موقّعة والتحقق منها' : 'Upload & Verify Signed Update ZIP' }}</h3>
<p>{{ $locale === 'ar'
    ? 'ارفع فقط حزمة WalidSite-Update الصادرة من الناشر المعتمد. يتم فحص التوقيع الرقمي Ed25519 والبصمة والمحتويات دون تشغيل PHP أو تحديث الموقع.'
    : 'Upload only the approved publisher WalidSite Update ZIP. Ed25519, SHA-256 and ZIP path/symlink checks run before the package is kept in private quarantine. No PHP is executed.' }}</p>
<form method="post" enctype="multipart/form-data"
    action="{{ route('admin.updates.verify-upload', ['lang' => $locale]) }}" data-testid="trusted-zip-upload-form">
@csrf
<label for="signed-package">{{ $locale === 'ar' ? 'ملف تحديث ZIP الموقّع' : 'Signed Update ZIP file' }}</label>
<input type="file" name="package" id="signed-package" accept=".zip,application/zip" required>
<button type="submit" @disabled(!config('site.updates_enabled')) data-testid="verify-signed-update-zip">
{{ $locale === 'ar' ? 'فحص أصالة الحزمة ومحتوياتها' : 'Verify Package' }}</button>
</form>
@if($errors->has('download'))
<p class="error" role="alert" data-testid="owner-publisher-download-blocked">{{ $locale === 'ar'
    ? 'تعذّر تنزيل الحزمة المعتمدة أو التحقق منها؛ لم يتغير الموقع أو قاعدة البيانات.'
    : $errors->first('download') }}</p>
@endif
@if($errors->has('upload'))
<p class="error" role="alert">{{ $errors->first('upload') }}</p>
@endif
@if($verifiedUpload)
<div class="success" data-testid="owner-signed-upload-verified" role="status">
<strong>{{ $locale === 'ar' ? 'تم التحقق من التوقيع والحزمة، ولم يتم تثبيت التحديث.' : 'Verified signed package. Not installed.' }}</strong>
<p>{{ $locale === 'ar' ? 'الإصدار المستهدف:' : 'Target version:' }} <code>{{ $verifiedUpload['version'] }}</code>
— {{ $locale === 'ar' ? 'ملفات الحزمة:' : 'Verified files:' }} {{ $verifiedUpload['files'] }}</p>
<p>{{ $locale === 'ar' ? 'Source SHA:' : 'Source SHA:' }} <code>{{ $verifiedUpload['source_sha'] }}</code></p>
<p>{{ $locale === 'ar' ? 'SHA-256:' : 'SHA-256:' }} <code>{{ $verifiedUpload['artifact_sha256'] }}</code></p>
</div>
<form method="post" action="{{ route('admin.updates.preflight', ['lang' => $locale]) }}"
    data-testid="owner-host-preflight-form">
@csrf
<button type="submit" data-testid="verify-physical-hosting">
{{ $locale === 'ar' ? 'فحص توافق استضافة cPanel (قراءة فقط)' : 'Check cPanel hosting compatibility (read-only)' }}
</button>
</form>
@endif
@if($hostPreflight)
<form method="post" action="{{ route('admin.updates.stage', ['lang' => $locale]) }}"
    data-testid="owner-private-code-stage-form">
@csrf
<button type="submit" data-testid="stage-owner-signed-code">
{{ $locale === 'ar'
    ? 'تجهيز ملفات الإصدار الموقّع في مساحة خاصة — دون تثبيت'
    : 'Stage verified code privately — do not install' }}
</button>
<p class="small">{{ $locale === 'ar'
    ? 'يُعاد التحقق من توقيع الحزمة وتوافق الاستضافة وقاعدة الترحيلات والتبعيات قبل نسخ الكود. يبقى الموقع على إصداره الحالي.'
    : 'Rechecks signed ZIP bytes, hosting, migrations and dependencies before preparing an immutable private version. The live site stays on its current version.' }}</p>
</form>
@endif
@if($stagedCode)
<div class="success" role="status" data-testid="owner-code-staged-not-installed">
<strong>{{ $locale === 'ar' ? 'ملفات إصدار موقّع مُجهّزة فقط؛ لم تُثبّت.' : 'Signed code staged privately — NOT installed.' }}</strong>
<p>{{ $locale === 'ar' ? 'الإصدار المستهدف:' : 'Target version:' }}
<code>{{ $stagedCode['version'] }}</code> —
{{ $locale === 'ar' ? 'معرّف المصدر:' : 'Source SHA:' }}
<code>{{ $stagedCode['source_sha'] }}</code></p>
<p>{{ $locale === 'ar'
    ? 'التجهيز لا يعني اعتماد الإصدار. تبقى النسخة الاحتياطية واختبار الاستعادة وإيقاف الكتابة وفحص التشغيل والتثبيت محجوبة إلى أن تثبت بشكل مستقل.'
    : 'Staging is not release approval. Backup, restored-data proof, isolated writers, health check and Install remain blocked until independently verified.' }}</p>
</div>
@endif
@if($errors->has('stage'))
<p class="error" role="alert" data-testid="owner-code-stage-blocked">{{ $locale === 'ar'
    ? 'تعذّر تجهيز الكود في المساحة الخاصة؛ لم يُنفّذ أي تثبيت أو ترحيل أو نسخة احتياطية.'
    : $errors->first('stage') }}</p>
@endif
@if($hostPreflight)
<div class="success" role="status" data-testid="owner-host-preflight-verified">
<strong>{{ $locale === 'ar' ? 'اجتاز تخطيط الاستضافة وتوافق حزمة الكود الفحص المبدئي.' : 'Physical hosting and code-only ZIP compatibility preflight passed.' }}</strong>
<p>{{ $locale === 'ar'
    ? 'لم يتم إنشاء نسخة احتياطية أو تنفيذ تجربة استعادة أو إيقاف الكتابة أو تثبيت التحديث. يظل التثبيت محجوبًا.'
    : 'No backup, restore drill, queue isolation or code installation was performed. Install remains blocked.' }}</p>
</div>
@endif
@if($errors->has('compatibility'))
<p class="error" role="alert" data-testid="owner-code-compatibility-blocked">{{ $locale === 'ar'
    ? 'تتطلب الحزمة الموقّعة ترحيلات قاعدة بيانات مختلفة أو تعديلًا لملفات تشغيل cPanel الثابتة؛ لا يمكن تثبيتها كترقية كود فقط. لم يتم تغيير أي بيانات أو ملفات.'
    : $errors->first('compatibility') }}</p>
@endif
@if($errors->has('hosting'))
<p class="error" role="alert" data-testid="owner-host-preflight-blocked">{{ $locale === 'ar'
    ? 'لا يمكن اعتماد جاهزية استضافة cPanel للتحديث حتى الآن؛ لم يحدث أي تغيير للبيانات أو الكود.'
    : $errors->first('hosting') }}</p>
@endif
<section aria-labelledby="backup-now-title" data-testid="owner-backup-action">
<h3 id="backup-now-title">{{ $locale === 'ar' ? 'نسخة احتياطية مع اختبار الاستعادة' : 'Backup Now — verified recovery rehearsal' }}</h3>
<p class="small">{{ $locale === 'ar'
    ? 'لا تتاح العملية إلا باستضافة مجهّزة للتحقق المستقل من إيقاف جميع الكتّاب الخارجيين وقاعدة MariaDB اختبارية فارغة ومنفصلة. لا يتم تغيير إصدار الموقع.'
    : 'Requires a server-installed provider that independently verifies all external writers are stopped and supplies a separate, empty recovery MariaDB database. Does not install code.' }}</p>
@if($errors->has('backup'))
<p class="error" role="alert" data-testid="owner-backup-blocked">{{ $locale === 'ar'
    ? 'تعذّر إثبات العزل أو النسخ والاستعادة. لا توجد نسخة احتياطية معتمدة من هذا الطلب؛ راجع حالة الاستعادة الخاصة.'
    : $errors->first('backup') }}</p>
@endif
@if(session('owner_backup_receipt'))
<div class="success" role="status" data-testid="owner-backup-verified">
<strong>{{ $locale === 'ar' ? 'اكتمل اختبار استعادة هذه النسخة. لم يتم تحديث الكود.' : 'Backup and separate restore drill verified. Code not updated.' }}</strong>
<p>{{ $locale === 'ar' ? 'رقم الإيصال:' : 'Receipt:' }} <code>{{ session('owner_backup_receipt.id') }}</code>
— {{ $locale === 'ar' ? 'الإصدار:' : 'Version:' }} <code>{{ session('owner_backup_receipt.version') }}</code></p>
</div>
@endif
<form method="post" action="{{ route('admin.updates.backup-now', ['lang' => $locale]) }}" data-testid="owner-backup-now-form">
@csrf
<button type="submit" data-testid="owner-backup-now" @disabled(!config('site.updates_enabled') || !$hostBackupAvailable)>{{ $locale === 'ar' ? 'إنشاء نسخة واختبار الاستعادة الآن' : 'Backup Now & Verify Restore' }}</button>
</form>
@if(!$hostBackupAvailable)
<p class="blocked" role="status" data-testid="owner-host-backup-provider-unavailable">{{ $locale === 'ar'
    ? 'محجوب: مزوّد إثبات عزل الكتابات الخارجية وقاعدة الاستعادة المنفصلة غير مثبّت على الخادم.'
    : 'Blocked hosting: independent external-writer evidence and disposable database provider are not installed.' }}</p>
@endif
</section>
<p class="blocked">{{ $locale === 'ar'
    ? 'لا يصرّح نجاح Backup Now بالتثبيت تلقائيًا. يلزم إثبات مستقل جديد للنسخ والاستعادة قبل أي تبديل لكود الإصدار، وإلا يُرفض التحديث.'
    : 'Backup Now is not an installation permit. Signed-code Install independently repeats backup/restore, exclusive writer and live-health checks and rejects any missing evidence.' }}</p>
@if($stagedCode && $hostCodeUpdateAvailable)
<form method="post" action="{{ route('admin.updates.install-signed', ['lang' => $locale]) }}"
      data-testid="owner-signed-install-form">
@csrf
<label for="signed-install-owner-password">{{ $locale === 'ar'
    ? 'أعد إدخال كلمة مرور المالك لتأكيد تثبيت الكود'
    : 'Re-enter your owner password to authorize code installation' }}</label>
<input id="signed-install-owner-password" name="current_password" type="password"
       autocomplete="current-password" required maxlength="4096"
       data-testid="owner-signed-install-password">
<button type="submit" data-testid="owner-signed-install">
{{ $locale === 'ar' ? 'تثبيت الإصدار الموقّع بعد اختبار الاستعادة' : 'Install signed release with verified recovery' }}</button>
<p class="small">{{ $locale === 'ar'
    ? 'هذه عملية تحديث الكود فقط: تعيد فحص التوقيع وكل الملفات ثم عزل الكتابة والنسخة الاحتياطية والاستعادة والتبديل وفحص HTTPS. أي فشل يوقف التثبيت.'
    : 'Code-only update: rechecks signed files, exclusive writer isolation, real backup/restore, atomic code pointer and live HTTPS health. Failures block installation.' }}</p>
</form>
@else
<button type="button" disabled aria-describedby="code-updates-title" data-testid="owner-install-host-blocked">{{ $locale === 'ar' ? 'تثبيت التحديث — الاستضافة أو الاستعادة غير جاهزة' : 'Install Update — Hosting/Recovery Blocked' }}</button>
@endif
@if($errors->has('install'))
<p class="error" role="alert" data-testid="owner-signed-install-failed">{{ $locale === 'ar'
    ? 'توقف تثبيت الكود أو فشل إثبات الاستعادة. لم يتم اعتماد الإصدار الجديد؛ راجع حالة الصيانة الخاصة.'
    : $errors->first('install') }}</p>
@endif
</section>

<form method="post" action="{{ route('admin.updates.check', ['lang' => $locale]) }}">
@csrf
<button type="submit" @disabled(!config('site.updates_enabled')) data-testid="trusted-release-check">
{{ $locale === 'ar' ? 'فحص إصدار موقّع (قراءة فقط)' : 'Check for signed release (read-only)' }}
</button>
</form>
@if($signedCandidateVerified)
<div class="success" role="status" data-testid="trusted-release-verified">
<strong>{{ $locale === 'ar' ? 'تم التحقق من توقيع الناشر وهوية الإصدار.' : 'Publisher signature and release identity verified.' }}</strong>
<p>{{ $locale === 'ar' ? 'الإصدار الحالي الموثق:' : 'Pinned installed version:' }} <code>{{ $trustedCheck['installed_version'] }}</code>
— {{ $locale === 'ar' ? 'الإصدار الموقّع المتاح:' : 'Verified available version:' }} <code>{{ $trustedCheck['version'] }}</code></p>
<p>{{ $locale === 'ar' ? 'مرجع المصدر الموقّع:' : 'Signed source commit:' }} <code>{{ $trustedCheck['source_sha'] }}</code></p>
<p>{{ $locale === 'ar' ? 'بصمة الحزمة الموقّعة SHA-256:' : 'Signed archive SHA-256:' }} <code>{{ $trustedCheck['artifact_sha256'] }}</code></p>
<p>{{ $locale === 'ar' ? 'هذا فحص قراءة فقط. لم يتم تنزيل كود أو تطبيق تحديث أو إنشاء نسخة احتياطية.' : 'Read-only discovery only: no code downloaded, no update applied and no backup performed.' }}</p>
</div>
<form method="post" action="{{ route('admin.updates.fetch-signed', ['lang' => $locale]) }}"
    data-testid="owner-publisher-download-form">
@csrf
<button type="submit" data-testid="download-publisher-signed-update">
{{ $locale === 'ar'
    ? 'تنزيل الحزمة الموقّعة مباشرة من الناشر والتحقق منها'
    : 'Download & verify signed ZIP from approved publisher' }}
</button>
<p class="small">{{ $locale === 'ar'
    ? 'يُجلب أحدث بيان موقع من الناشر مرة أخرى، ثم تُنزّل الحزمة في مخزن خاص ويُفحص التوقيع والبصمة والأمان. لا تُنصّب ملفات أو تُغيّر بيانات.'
    : 'Rechecks the approved publisher signature, then privately downloads and inspects the exact signed ZIP. No code installation or database change.' }}</p>
</form>
@elseif($trustedState === 'not_configured')
<p class="blocked" role="status" data-testid="trusted-release-not-configured">{{ $locale === 'ar'
    ? 'إعدادات نسخة التثبيت ومصدر الإصدارات والمفتاح العام الموثوق غير مكتملة على الخادم. لم يُجرَ اتصال خارجي.'
    : 'Pinned installed version, release origin or publisher public key is not configured on the server. No network request was made.' }}</p>
@elseif($trustedState === 'disabled')
<p class="blocked" role="status">{{ $locale === 'ar' ? 'فحص التحديثات معطّل من إعدادات الخادم.' : 'Release checks are disabled by server configuration.' }}</p>
@elseif($trustedState === 'publisher_unverified')
<p class="error" role="alert" data-testid="trusted-release-unverified">{{ $locale === 'ar'
    ? 'تعذّر التحقق من المصدر الموثوق أو التوقيع. لم يُقبل أي إصدار؛ راجع إعدادات الخادم وسجلاته الخاصة.'
    : 'Publisher transport or signature verification failed. No release was accepted. Review private server configuration and logs.' }}</p>
@endif
<ol class="steps" aria-label="{{ $locale === 'ar' ? 'مراحل تحديث الإصدار الكامل' : 'Full code update requirements' }}">
<li><strong>{{ $locale === 'ar' ? 'فحص إصدار موثوق' : 'Check authenticated release' }}</strong><span class="status" data-testid="owner-update-step-check">
@if($verifiedUpload)
{{ $locale === 'ar' ? 'تم التحقق من أصالة حزمة ZIP؛ لم تُثبّت.' : 'Publisher-authenticated ZIP verified; not installed.' }}
@elseif($signedCandidateVerified)
{{ $locale === 'ar' ? 'تم التحقق من بيان الناشر فقط؛ لم تُنزّل الحزمة.' : 'Signed publisher catalog verified; ZIP not yet downloaded.' }}
@else
{{ $locale === 'ar' ? 'يجب فحص بيان الناشر أو رفع حزمة موقّعة قبل التثبيت.' : 'Verify the signed publisher catalog or upload a trusted ZIP first.' }}
@endif
</span></li>
<li><strong>{{ $locale === 'ar' ? 'فحص التوافق والنسخ الاحتياطي' : 'Preflight and recoverable backup' }}</strong><span class="status" data-testid="owner-update-step-recovery">
@if($hostPreflight)
{{ $locale === 'ar' ? 'نجح فحص التوافق فقط؛ النسخ والاستعادة والعزل تُفحص مجددًا عند التنفيذ.' : 'Compatibility preflight only passed; backup, restore and writer isolation must be proven again during install.' }}
@elseif($recoveryHistory['state'] === 'history')
{{ $locale === 'ar' ? 'توجد إيصالات تاريخية؛ لا تُجيز تحديثًا جديدًا دون اختبار استعادة مستقل.' : 'Historical recovery receipts exist; a new update still requires an independent restore drill.' }}
@else
{{ $locale === 'ar' ? 'لا يوجد إثبات استعادة حالي يجيز تثبيت إصدار جديد.' : 'No current recovery proof authorizes a code installation.' }}
@endif
</span></li>
<li><strong>{{ $locale === 'ar' ? 'تطبيق ملفات الكود الموقّع' : 'Install signed application code' }}</strong><span class="status" data-testid="owner-update-step-install">
@if($stagedCode)
{{ $locale === 'ar' ? 'تم تجهيز ملفات موثوقة دون تثبيت؛ يتطلب التطبيق موافقة المالك وعزلًا واستعادة وفحص HTTPS.' : 'Trusted code staged, not installed; owner confirmation, isolation, recovery and HTTPS health are required.' }}
@elseif($hostCodeUpdateAvailable && config('site.updates_enabled') === true)
{{ $locale === 'ar' ? 'مسار التحديث متاح بشرط تجهيز الحزمة واجتياز ضوابط التشغيل؛ لم يُنفّذ تبديل كود.' : 'Host update capability is registered, but no code cutover was performed.' }}
@else
{{ $locale === 'ar' ? 'التحديث محجوب حتى يتوفر تكامل استضافة موثوق.' : 'Code installation remains blocked without trusted host integration.' }}
@endif
</span></li>
<li><strong>{{ $locale === 'ar' ? 'التحقق من الصحة والاستعادة' : 'Verify health and recovery' }}</strong><span class="status" data-testid="owner-update-step-health">
{{ $locale === 'ar' ? 'الإيصالات التاريخية لا تُثبت سلامة التحديث الحالي؛ يُطلب فحص HTTPS بعد كل تثبيت.' : 'Historical receipts do not establish current release health; each installation requires its own HTTPS health proof.' }}
</span></li>
</ol>
<section class="panel" aria-labelledby="recovery-history-title" data-testid="owner-recovery-history">
<h3 id="recovery-history-title">{{ $locale === 'ar' ? 'سجل النسخ الاحتياطي واختبار الاستعادة' : 'Backup & recovery history' }}</h3>
<p class="small">{{ $locale === 'ar'
    ? 'هذه إيصالات تاريخية مقروءة من المخزن الخاص بعد فحص سلامتها. لا تثبت أن نسخة قديمة ما زالت قابلة للاستعادة ولا تسمح ببدء تحديث أو استعادة مباشرة.'
    : 'These are historical receipts from a protected private vault. They do not prove that old snapshots remain restorable and never authorize a new update or an automatic restore.' }}</p>
@if($recoveryHistory['state'] === 'blocked')
<p class="error" role="alert" data-testid="recovery-history-blocked">{{ $locale === 'ar'
    ? 'تعذّر التحقق من سلامة سجل الاستعادة الخاص؛ لا يمكن عرض إيصالات غير موثوقة.'
    : 'Private recovery history could not be verified. No untrusted receipts are displayed.' }}</p>
@elseif($recoveryHistory['state'] === 'none')
<p class="blocked" role="status" data-testid="recovery-history-empty">{{ $locale === 'ar'
    ? 'لا توجد إيصالات استعادة موثقة في المخزن الخاص لهذا التثبيت.'
    : 'No recovery rehearsal receipts found in this installation’s private vault.' }}</p>
@else
<div class="migrations" role="region" aria-label="{{ $locale === 'ar' ? 'إيصالات الاستعادة' : 'Recovery receipts' }}" tabindex="0">
<table data-testid="recovery-history-table" style="width:100%;border-collapse:collapse;text-align:start">
<thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'وقت الاختبار (UTC)' : 'Restore drill time (UTC)' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الإصدار' : 'Version' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'ملفات' : 'Files' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'جداول' : 'Tables' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'صفوف' : 'Rows' }}</th>
</tr></thead>
<tbody>
@foreach($recoveryHistory['receipts'] as $receipt)
<tr>
<td><time datetime="{{ $receipt['created_at'] }}">{{ $receipt['created_at'] }}</time></td>
<td><code>{{ $receipt['version'] }}</code></td>
<td>{{ $receipt['files'] }}</td><td>{{ $receipt['tables'] }}</td><td>{{ $receipt['rows'] }}</td>
</tr>
@endforeach
</tbody></table>
</div>
@endif
</section>
<section class="panel" aria-labelledby="code-update-history-title" data-testid="owner-code-update-history">
<h3 id="code-update-history-title">{{ $locale === 'ar' ? 'سجل تحديثات الكود المكتملة' : 'Completed code update history' }}</h3>
<p class="small">{{ $locale === 'ar'
    ? 'يظهر فقط إيصال محلي حُفظ بعد نجاح التحديث الموقّع وفحص الصحة والاستعادة. لا يُعد السجل توقيعًا جديدًا من الناشر أو إذنًا لتحديث آخر، ولا يثبت صحة الموقع الحالية.'
    : 'Local integrity-checked receipts are recorded only after a signed code update reports verified cutover, recovery and health. Historical entries are not fresh publisher signatures, authorization for another update, or current runtime health proof.' }}</p>
@if($codeUpdateHistory['state'] === 'blocked')
<p class="error" role="alert" data-testid="owner-code-update-history-blocked">{{ $locale === 'ar'
    ? 'تعذّر التحقق من سجل تحديثات الكود الخاص. تم حجب بيانات السجل غير الموثوقة.'
    : 'Private code update history failed integrity checks. Untrusted entries are hidden.' }}</p>
@elseif($codeUpdateHistory['state'] === 'none')
<p class="blocked" role="status" data-testid="owner-code-update-history-empty">{{ $locale === 'ar'
    ? 'لا توجد إيصالات محفوظة لتحديثات كود مكتملة على هذه الاستضافة.'
    : 'No completed code update receipts have been recorded on this host.' }}</p>
@else
<div class="migrations" role="region" aria-label="{{ $locale === 'ar' ? 'سجل تحديث الكود' : 'Code update receipts' }}" tabindex="0">
<table data-testid="owner-code-update-history-table" style="width:100%;border-collapse:collapse;text-align:start">
<thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'الوقت (UTC)' : 'Installed at (UTC)' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'من' : 'Previous' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'إلى' : 'Installed' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'Git SHA' : 'Git SHA' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'إيصال الاستعادة' : 'Recovery receipt' }}</th>
</tr></thead>
<tbody>
@foreach($codeUpdateHistory['receipts'] as $receipt)
<tr>
<td><time datetime="{{ $receipt['created_at'] }}">{{ $receipt['created_at'] }}</time></td>
<td><code>{{ $receipt['from'] }}</code></td>
<td><code>{{ $receipt['version'] }}</code></td>
<td><code>{{ $receipt['source_sha'] }}</code></td>
<td><code>{{ substr($receipt['backup_id'], 0, 8) }}</code></td>
</tr>
@endforeach
</tbody></table>
</div>
@endif
</section>
</section>

<section class="panel" aria-labelledby="migration-title">
<h2 id="migration-title">{{ $locale === 'ar' ? 'ترحيلات قاعدة البيانات في الكود الموجود' : 'Database migrations from deployed code' }}</h2>
<p>{{ $locale === 'ar'
    ? 'هذه العملية لا تنزّل إصدارًا جديدًا ولا تستبدل ملفات PHP أو الواجهة. لا تعتبر نجاحها دليلاً على تحديث الموقع.'
    : 'This action neither downloads a new release nor replaces PHP or website files. A successful migration is not a code upgrade.' }}</p>
<a href="{{ route('admin.updates.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إعادة فحص الترحيلات' : 'Refresh migration status' }}</a>
@if($errors->any())<div role="alert" class="error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($status['history_mismatch'] ?? false)
<p role="alert" class="error">{{ $locale === 'ar'
    ? 'قاعدة البيانات تحتوي على ترحيلات غير موجودة في هذا الإصدار. تاريخ الترحيلات غير متوافق، وتم منع الترحيلات لحماية البيانات. لا تعِد المحاولة دون نسخة استعادة موثقة.'
    : 'Incompatible database migration history. The deployed code cannot safely apply migrations to this database; recover a compatible release or verified backup.' }}</p>
@elseif(!($status['ready'] ?? false))
<p role="alert" class="error">{{ $locale === 'ar' ? 'قاعدة البيانات غير متاحة؛ لا يمكن فحص الترحيلات.' : 'Database unavailable; migrations cannot be inspected.' }}</p>
@else
<h3>{{ $locale === 'ar' ? 'الترحيلات المنتظرة' : 'Pending migrations' }}: {{ count($status['pending']) }}</h3>
@if(count($status['pending']) === 0)
<p role="status">{{ $locale === 'ar' ? 'لا توجد ترحيلات معلقة في ملفات هذا الإصدار.' : 'No pending migrations in this deployed code release.' }}</p>
@else
<ul class="migrations">@foreach($status['pending'] as $migration)<li><code>{{ $migration }}</code></li>@endforeach</ul>
<p class="blocked" data-testid="installed-migrations-recovery-required">{{ $locale === 'ar' ? 'تم حظر ترحيلات قاعدة البيانات المعلقة. لا يوجد إثبات نسخة احتياطية واختبار استعادة مستقل وعزل للكتابة؛ لا يمكن تطبيق الترحيلات من هذه الصفحة أو عبر Cron.' : 'Pending database migrations are blocked. No independently verified database and file restore with write isolation is available; this page and Cron cannot apply these migrations.' }}</p>
@endif
<form method="post" action="{{ route('admin.updates.apply', ['lang' => $locale]) }}">@csrf
<button type="submit" disabled data-testid="installed-migration-apply-blocked">
{{ $locale === 'ar' ? 'تطبيق الترحيلات محجوب — إثبات الاستعادة مطلوب' : 'Apply blocked — verified recovery required' }}</button>
</form>
@endif
</section>
<p class="small">{{ $locale === 'ar' ? 'ترحيلات Cron التلقائية:' : 'Automatic migration scheduling:' }}
<strong>{{ config('site.auto_migrate') ? ($locale === 'ar' ? 'مُفعّل بشرط تشغيل Cron' : 'Enabled, requires working cron') : ($locale === 'ar' ? 'معطّل' : 'Disabled') }}</strong>.
{{ $locale === 'ar'
    ? 'تُحجب عمليات التحديث غير المتوافقة والمفقودة التوقيع أو التي لا تجتاز الاستعادة أو فحص HTTPS. لا ينفّذ Cron ترحيلات تلقائية.'
    : 'Unsigned/incompatible releases and hosts without verified restore or HTTPS health are blocked. Cron never applies unverified database migrations.' }}</p>
</main></body></html>
