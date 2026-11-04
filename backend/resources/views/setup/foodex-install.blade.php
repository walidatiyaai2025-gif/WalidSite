@php($ar = $locale === 'ar')
@php($currentTitle = $steps[$stage][$ar ? 1 : 0])
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'تثبيت وليد سايت — معالج 15 خطوة' : 'WalidSite — 15-step installer' }}</title>
<style>
:root{color-scheme:light;--ink:#18304b;--muted:#47647b;--line:#d7e4ed;--accent:#16855a;--soft:#f3f8fb;--bad:#ab2637}
*{box-sizing:border-box}body{font-family:Inter,Tajawal,Arial,sans-serif;color:var(--ink);background:#edf3f6;margin:0;line-height:1.6}
main{max-width:1140px;margin:3vh auto;padding:clamp(16px,3vw,34px);background:white;border:1px solid var(--line);border-radius:20px}
h1{font-size:clamp(24px,3.5vw,34px);margin:0 0 4px}h2{font-size:clamp(19px,2.5vw,25px);margin:0 0 14px}
p{margin:10px 0}.muted{color:var(--muted)}.top{display:flex;justify-content:space-between;flex-wrap:wrap;gap:15px;align-items:center}
a{color:#0864a8;font-weight:700}.progress{height:9px;background:#e4edf1;border-radius:99px;overflow:hidden;margin:18px 0}
.progress div{height:100%;background:var(--accent);transition:width .2s}
.layout{display:grid;grid-template-columns:minmax(200px,270px) minmax(0,1fr);gap:30px}
.steps{list-style:none;padding:0;margin:0}.steps li{padding:7px 10px;margin:3px 0;border-radius:8px;font-size:13px;display:flex;gap:9px;align-items:center}
.steps li[aria-current=step]{background:#e0f4e9;color:#146543;font-weight:800}.steps li.done{color:#23744e}
.number{display:inline-grid;place-items:center;min-width:24px;height:24px;border:1px solid #a9c9b6;border-radius:50%;font-size:12px}
.panel{border:1px solid var(--line);border-radius:14px;padding:clamp(16px,3vw,26px);min-width:0}
form{margin:0}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
label{display:block;font-weight:700;font-size:14px;margin:9px 0 3px}
input,select{display:block;width:100%;min-height:44px;padding:9px 11px;border:1px solid #91aabe;border-radius:8px;font:inherit;color:var(--ink)}
input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #2986b9;outline-offset:2px}
input[type=password]{direction:ltr}.wide{grid-column:1/-1}.notice{padding:12px 14px;background:var(--soft);border:1px solid var(--line);border-radius:10px}
.error{color:var(--bad);background:#fff0f2;padding:10px 14px;border-radius:8px;border-inline-start:4px solid var(--bad)}
.checks{padding:0;list-style:none}.checks li{padding:10px 0;border-bottom:1px solid var(--line);display:flex;gap:10px}
.checks li:last-child{border:0}.yes{color:#147b4c;font-weight:800}.no{color:var(--bad);font-weight:800}
small{display:block;color:var(--muted);font-weight:400}.status{font-size:14px;color:var(--muted)}
button{background:var(--accent);border:0;border-radius:9px;padding:12px 26px;color:white;min-height:46px;font:inherit;font-weight:800;cursor:pointer;margin-top:18px}
button:disabled{opacity:.45;cursor:not-allowed}
.counter{font-weight:700;font-size:14px;color:var(--muted)}
@media(max-width:750px){main{margin:0;border:0;border-radius:0}.layout{grid-template-columns:1fr}.steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:3px}.steps li{font-size:11px}.number{display:none}.panel{padding:16px}}
@media(max-width:480px){.fields{grid-template-columns:1fr}.steps{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@include('partials.web-fonts')
</head>
<body><main>
<div class="top">
<div>
<h1>{{ $ar ? 'معالج تثبيت وليد سايت' : 'WalidSite Installation' }}</h1>
<p class="muted">{{ $ar ? 'تثبيت أول مرة — ZIP واحد — بدون SSH أو أوامر Terminal' : 'First install — One ZIP — No SSH or Terminal commands' }}</p>
</div>
<a href="{{ route('site.install', $ar ? [] : ['lang' => 'ar']) }}">{{ $ar ? 'English' : 'العربية' }}</a>
</div>
<div class="progress" role="progressbar" aria-label="{{ $ar ? 'تقدم التثبيت' : 'Installation progress' }}" aria-valuemin="1" aria-valuemax="15" aria-valuenow="{{ $stage }}">
<div style="width:{{ round(100 * $stage / 15) }}%"></div></div>
<div class="layout">
<nav aria-label="{{ $ar ? 'مراحل التثبيت' : 'Installation stages' }}">
<ol class="steps">
@foreach($steps as $number => $titles)
<li @if($stage === $number) aria-current="step" @elseif($number < $stage) class="done" @endif>
<span class="number" aria-hidden="true">{{ $number }}</span>
<span>{{ $titles[$ar ? 1 : 0] }}</span>
</li>
@endforeach
</ol>
</nav>
<section class="panel" aria-labelledby="step-title">
<p class="counter">{{ $ar ? 'المرحلة' : 'Step' }} {{ $stage }} / 15</p>
<h2 id="step-title">{{ $currentTitle }}</h2>
@if($errors->any())
<div role="alert" class="error"><strong>{{ $ar ? 'تعذر إكمال المرحلة:' : 'Step could not be completed:' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<form method="post" action="{{ route('site.install.next', $ar ? ['step'=>$stage,'lang'=>'ar'] : ['step'=>$stage]) }}" autocomplete="off" data-testid="foodex-install-form">
@csrf
@if($stage === 1)
<p>{{ $ar ? 'تم استخراج الملف في حساب الاستضافة. فعّل ملكية هذا التثبيت قبل البدء؛ هذه الخطوة تمنع أي زائر من إنشاء حساب المدير.' : 'The archive is extracted on your hosting account. Prove possession before proceeding; a random visitor cannot claim the Super Admin.' }}</p>
<p class="notice">{{ $ar ? 'من cPanel File Manager افتح الملف الخاص application/storage/app/site-first-run.json وانسخ قيمة token فقط. لا تعدّل الملف ولا تشارك الرمز.' : 'Use cPanel File Manager to read only the token field in private application/storage/app/site-first-run.json. Never edit or share the secret.' }}</p>
<label for="token">{{ $ar ? 'رمز ملكية التثبيت الخاص' : 'Private setup possession token' }}</label>
<input name="token" id="token" type="password" maxlength="256" autocomplete="off" required>

@elseif($stage === 2 || $stage === 3)
<p>{{ $stage === 2
? ($ar ? 'نفحص إصدار PHP والإضافات المطلوبة ومكتبات Laravel قبل الكتابة في قاعدة البيانات.' : 'Checking PHP, required extensions and Laravel production files before DB changes.')
: ($ar ? 'نفحص الفصل بين الملفات العامة والخاصة، صلاحيات التخزين وHTTPS.' : 'Checking public/private document roots, private storage permissions and HTTPS.') }}</p>
<ul class="checks" data-testid="foodex-host-checks">
@foreach($checks as $name => $check)
@if(($stage === 2 && in_array($name,['php','extensions','dependencies','zip'],true))
 || ($stage === 3 && in_array($name,['private_root','permissions','https'],true)))
<li><span class="{{ $check['ok'] ? 'yes' : 'no' }}" aria-label="{{ $check['ok'] ? 'Pass' : 'Fail' }}">{{ $check['ok'] ? '✓' : '✗' }}</span>
<div><strong>{{ $check[$ar ? 'label_ar' : 'label_en'] }}</strong>
@if(! $check['ok'])<small>{{ $check[$ar ? 'hint_ar' : 'hint_en'] }}</small>@endif
</div></li>
@endif
@endforeach
</ul>
@if(! $checksReady)<p class="error">{{ $ar ? 'يوجد شرط أساسي غير متحقق. صححه من لوحة cPanel قبل الضغط على التالي.' : 'A mandatory check failed. Fix it in cPanel before continuing.' }}</p>@endif

@elseif($stage === 4)
<p class="notice">{{ $ar ? 'أنشئ قاعدة بيانات فارغة ومستخدمها من MySQL Databases في cPanel إذا كانت الاستضافة لا تتيح إنشاءها تلقائيًا.' : 'Create an empty database and its user in cPanel MySQL Databases if your host does not allow application provisioning.' }}</p>
@if(config('site.setup_database_ready'))
<p>{{ $ar ? 'تم حفظ اتصال قاعدة البيانات مسبقًا، ولن نغيّر وجهتها أو كلمة مرورها. اضغط التالي لاختبارها.' : 'An existing database connection is pinned. Continue to verify it without changing its credentials.' }}</p>
@else
<div class="fields">
<div class="wide"><label for="site_url">{{ $ar ? 'الرابط النهائي للموقع HTTPS' : 'Canonical HTTPS site URL' }}</label><input id="site_url" name="site_url" type="url" value="{{ old('site_url', 'https://walidatiya.com') }}" required></div>
<div><label for="db_host">Database host</label><input id="db_host" name="db_host" value="{{ old('db_host','localhost') }}" required></div>
<div><label for="db_port">Port</label><input id="db_port" name="db_port" type="number" value="{{ old('db_port',3306) }}" required></div>
<div><label for="db_database">Database name</label><input id="db_database" name="db_database" value="{{ old('db_database') }}" required></div>
<div><label for="db_username">Database username</label><input id="db_username" name="db_username" value="{{ old('db_username') }}" required></div>
<div class="wide"><label for="db_password">Database password</label><input id="db_password" name="db_password" type="password" required autocomplete="new-password"></div>
</div>
@endif

@elseif($stage === 5)
<p class="notice">{{ $ar ? 'يتم اختبار الوصول إلى MySQL/MariaDB دون حذف أو إعادة تهيئة أي جدول موجود.' : 'Testing MySQL/MariaDB reachability without dropping or resetting existing tables.' }}</p>
<p role="status">{{ ($status['ready'] ?? false) ? ($ar ? 'الاتصال ناجح.' : 'Connection passed.') : ($ar ? 'الاتصال غير جاهز.' : 'Connection unavailable.') }}</p>

@elseif($stage === 6)
<div class="fields">
<div class="wide"><label for="app_name">{{ $ar ? 'اسم الموقع' : 'Website name' }}</label><input id="app_name" name="app_name" value="{{ old('app_name','WalidSite') }}" maxlength="100" required></div>
<div><label for="app_locale">{{ $ar ? 'اللغة الأساسية' : 'Primary locale' }}</label>
<select name="app_locale" id="app_locale"><option value="en">English</option><option value="ar">العربية</option></select></div>
</div>

@elseif($stage === 7)
<p>{{ $ar ? 'سيكون المدير العام هو صاحب السيرة الذاتية المعتمدة. يتم حفظ تجزئة كلمة المرور فقط حتى مرحلة النشر النهائية.' : 'The approved CV owner becomes Super Admin. Only the password hash is stored in the protected session before final activation.' }}</p>
<div class="fields">
<div class="wide"><label for="admin_email">{{ $ar ? 'بريد المالك المعتمد' : 'Verified owner email' }}</label><input id="admin_email" name="admin_email" type="email" value="{{ $ownerEmail }}" required readonly></div>
<div><label for="admin_password">{{ $ar ? 'كلمة مرور قوية — 14 حرفًا على الأقل' : 'Strong password — at least 14 characters' }}</label><input id="admin_password" name="admin_password" type="password" minlength="14" maxlength="128" required></div>
<div><label for="admin_password_confirmation">{{ $ar ? 'تأكيد كلمة المرور' : 'Confirm password' }}</label><input id="admin_password_confirmation" name="admin_password_confirmation" type="password" minlength="14" maxlength="128" required></div>
</div>

@elseif($stage === 8)
<p>{{ $ar ? 'اختر مساحة تخزين الملفات. اختيار public ينشئ رابط storage تلقائيًا عندما تسمح الاستضافة؛ وإلا لن نتجاوز الخطأ.' : 'Choose storage. Public storage creates its link automatically when the host supports it; unsupported hosts fail closed.' }}</p>
<label for="disk">{{ $ar ? 'التخزين الافتراضي' : 'Default storage disk' }}</label>
<select id="disk" name="disk"><option value="local">local — private</option><option value="public">public — linked uploads</option></select>

@elseif($stage === 9)
<p class="notice">{{ $ar ? 'الإعداد الافتراضي متوافق مع Shared Hosting بدون Redis أو عملية Queue Worker مستقلة.' : 'Shared Hosting defaults run without Redis or a separate daemon/worker.' }}</p>
<div class="fields">
<div><label for="cache_store">Cache</label><select id="cache_store" name="cache_store"><option value="file">file</option></select></div>
<div><label for="queue_connection">Queue</label><select id="queue_connection" name="queue_connection"><option value="sync">sync</option></select></div>
</div>

@elseif($stage === 10)
<p>{{ $ar ? 'اضبط البريد، ويمكنك اختيار log مؤقتًا إلى أن تصبح إعدادات SMTP متاحة.' : 'Set up mail. Select log transport until your SMTP credentials are available.' }}</p>
<div class="fields">
<div><label for="mail_mailer">Mail transport</label><select id="mail_mailer" name="mail_mailer"><option value="log">log</option><option value="smtp">smtp</option></select></div>
<div><label for="mail_host">SMTP host (only if smtp)</label><input id="mail_host" name="mail_host" placeholder="smtp.example.com"></div>
<div><label for="mail_port">SMTP port (only if smtp)</label><input id="mail_port" name="mail_port" type="number" value="587"></div>
<div><label for="mail_username">SMTP username</label><input id="mail_username" name="mail_username"></div>
<div><label for="mail_password">SMTP password</label><input id="mail_password" name="mail_password" type="password"></div>
<div><label for="mail_from_address">From email</label><input id="mail_from_address" name="mail_from_address" type="email" placeholder="noreply@walidatiya.com" required></div>
<div><label for="mail_from_name">From name</label><input id="mail_from_name" name="mail_from_name" value="WalidSite" required></div>
</div>

@elseif($stage === 11)
<p class="notice">{{ $ar ? 'سيقوم Laravel بتنفيذ الترحيلات المعتمدة من الحزمة فقط. لن يتم تحميل أي ملفات PHP غير موثوقة.' : 'Only reviewed migrations bundled inside the installed Laravel source are executed. No uploaded PHP is accepted.' }}</p>
@if($status !== null)<p>{{ $ar ? 'الترحيلات المتبقية:' : 'Pending migrations:' }} <strong>{{ count($status['pending']) }}</strong></p>@endif

@elseif($stage === 12)
<p>{{ $ar ? 'سيتم إدخال السيرة الذاتية والصفحات والمشاريع المعتمدة في قاعدة البيانات وربطها بإدارة المحتوى.' : 'Importing approved biography, page content and portfolio through their existing trusted, managed CMS seeders.' }}</p>
<p class="notice">{{ $ar ? 'البيانات المحظورة أو غير المعتمدة لا تتحول إلى محتوى عام عند هذه المرحلة.' : 'Private or unapproved content is not published at this stage.' }}</p>

@elseif($stage === 13)
<p>{{ $ar ? 'تم توليد APP_KEY آمن في ذاكرة التهيئة الأولى، وسيتم فحص حفظه في .env الخاص دون تغييره أو إظهاره.' : 'A cryptographic APP_KEY was created during first boot. The wizard verifies its safe private persistence without revealing or rotating it.' }}</p>

@elseif($stage === 14)
<p>{{ $ar ? 'نفحص قاعدة البيانات والكاش والتخزين قبل قفل التثبيت. وضع Queue: sync يعمل مباشرة دون Worker.' : 'Checking database, file cache and private storage before locking installation. Sync queue needs no separate worker.' }}</p>
<p class="notice">{{ $ar ? 'مجدول المهام Scheduler: إذا لم تسمح الاستضافة للتطبيق بإنشاء Cron آمن، ستحتاج إعداده لاحقًا من cPanel → Cron Jobs. لن نعتبر Cron يعمل بدون فحص حقيقي.' : 'Scheduler: when the host prevents secure automatic Cron registration, use cPanel → Cron Jobs later. The wizard never falsely marks Cron as active.' }}</p>

@elseif($stage === 15)
<p class="notice">{{ $ar ? 'سيتم تفعيل حساب المدير ونشر البيانات العامة المعتمدة وتسجيل قفل التثبيت نهائيًا. بعدها يمكنك فتح الموقع ولوحة الإدارة.' : 'The approved owner will be activated, reviewed public content published and the installer permanently locked. The live site and Admin login are then available.' }}</p>
<p>{{ $ar ? 'لن يتم إعادة توليد APP_KEY أو حذف ملفات المستخدم أو قاعدة البيانات.' : 'APP_KEY, user files and database content will not be reset.' }}</p>
@endif
<button type="submit" @disabled(($stage === 2 || $stage === 3 || $stage === 4) && ! $checksReady) data-testid="foodex-install-next">
{{ $stage === 15 ? ($ar ? 'إنهاء ونشر الموقع' : 'Finish & Publish')
  : ($ar ? 'التالي' : 'Next') }}
</button>
</form>
<p class="status">{{ $ar ? 'يمكن استئناف العملية بعد أي فشل؛ لا توجد أوامر Terminal مطلوبة على الاستضافة.' : 'The process can resume after a failed step; no terminal commands are required on the hosting account.' }}</p>
</section></div>
</main></body></html>
