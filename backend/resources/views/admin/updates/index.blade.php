@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($releaseRef = trim((string) config('site.release_ref', '')))
@php($trustedCheck = session('trusted_release_check'))
@php($trustedState = is_array($trustedCheck) ? ($trustedCheck['state'] ?? null) : null)
@php($signedCandidateVerified = $trustedState === 'signed_release_available')
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
<div><dt>{{ $locale === 'ar' ? 'الإصدار المثبّت فعليًا' : 'Current code version' }}</dt>
<dd data-testid="installed-package-version"><code>{{ $installedVersion ?? ($locale === 'ar' ? 'غير معروف' : 'Unknown') }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'معرّف الإصدار الموجود' : 'Configured deployed release' }}</dt>
<dd><code>{{ $identifiedRelease ? $releaseRef : ($locale === 'ar' ? 'غير محدد' : 'Unidentified') }}</code></dd></div>
<div><dt>{{ $locale === 'ar' ? 'الإصدار المتاح' : 'Available version' }}</dt>
<dd>{{ $signedCandidateVerified ? $trustedCheck['version'] : ($locale === 'ar' ? 'غير متحقق منه' : 'Not verified') }}</dd></div>
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
    ? 'يمكن فحص كتالوج الإصدارات الموقّعة بمفتاح ناشر مثبت على الخادم. لكن تثبيت الكود يتطلب إثبات النسخ الاحتياطي والاستعادة والتبديل الآمن وفحص السلامة؛ لذلك لا يوجد زر تثبيت غير آمن.'
    : 'You can check the publisher-signed release catalog using server-pinned trust settings. Full-code Apply remains unavailable until recoverable backups, controlled cutover and health checks are proven.' }}</p>
</div>
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
<p class="blocked">{{ $locale === 'ar'
    ? 'Backup Now وInstall Update سيظلان متوقفين حتى يتوفر إثبات نسخ احتياطي قابل للاسترجاع وفحص توافق الاستضافة والتبديل الآمن وإيقاف الكتابة أثناء التحديث.'
    : 'Backup Now and Install Update remain blocked until independently restorable backups, hosting-compatible atomic cutover, write quiescence and verified recovery are available.' }}</p>
<button type="button" disabled aria-describedby="code-updates-title">{{ $locale === 'ar' ? 'تثبيت التحديث — غير متاح بعد' : 'Install Update — Hosting/Recovery Blocked' }}</button>
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
<li><strong>{{ $locale === 'ar' ? 'فحص إصدار موثوق' : 'Check authenticated release' }}</strong><span class="status">{{ $locale === 'ar' ? 'اضغط فحص الإصدار للتحقق من كتالوج الناشر المثبت على الخادم؛ النتيجة تظهر أعلاه.' : 'Use Check to verify the server-pinned publisher catalog; the result appears above.' }}</span></li>
<li><strong>{{ $locale === 'ar' ? 'فحص التوافق والنسخ الاحتياطي' : 'Preflight and recoverable backup' }}</strong><span class="status">{{ $locale === 'ar' ? 'لم تُنفّذ نسخة احتياطية أو تجربة استعادة.' : 'Backup and restore have not been verified.' }}</span></li>
<li><strong>{{ $locale === 'ar' ? 'تطبيق الملفات والترحيلات' : 'Install code and migrations' }}</strong><span class="status">{{ $locale === 'ar' ? 'استبدال الكود الكامل غير متاح؛ الترحيلات المحلية منفصلة أدناه.' : 'Full code replacement is not available; local migrations are handled separately below.' }}</span></li>
<li><strong>{{ $locale === 'ar' ? 'التحقق من الصحة والاستعادة' : 'Verify health and recovery' }}</strong><span class="status">{{ $locale === 'ar' ? 'لا توجد نتيجة تحقق أو سجل تحديث كود.' : 'No code-update health receipt or version history exists.' }}</span></li>
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
{{ $locale === 'ar' ? 'يُراجع سجل Laravel الخاص عند حدوث خطأ، ولا يُعد تشغيل Cron تحديثًا لكود الموقع.' : 'Errors are held in private Laravel logs. Cron does not install new application code.' }}</p>
</main></body></html>
