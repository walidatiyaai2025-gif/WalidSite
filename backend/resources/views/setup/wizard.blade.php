@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'إعداد وليد سايت' : 'WalidSite first-run setup' }}</title>
<style>
:root{color-scheme:light;--ink:#142e4d;--accent:#176db7;--border:#d3e3ed}
*{box-sizing:border-box}body{margin:0;background:#f5f9fc;color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
main{max-width:720px;margin:5vh auto;padding:clamp(18px,4vw,40px);background:white;border:1px solid var(--border);border-radius:20px}
.steps{display:flex;gap:10px;flex-wrap:wrap;padding:0;list-style:none}.steps li{padding:8px 12px;background:#eaf5fb;border-radius:8px}
label{display:block;font-weight:700;margin-block:14px 5px}input{width:100%;min-height:46px;border:1px solid #8ca8bd;border-radius:10px;padding:10px;font:inherit}
button{min-height:46px;margin-top:18px;border:0;border-radius:10px;background:var(--accent);color:white;padding:10px 22px;font:inherit;cursor:pointer}
a,button:focus-visible,input:focus-visible{outline-offset:3px}a{color:var(--accent)}
.error{color:#a01826}code{overflow-wrap:anywhere}ul{padding-inline-start:22px}li{margin-block:6px}
</style>
@include('partials.web-fonts')
</head>
<body><main>
<h1>{{ $locale === 'ar' ? 'إعداد وليد سايت لأول مرة' : 'Set up WalidSite' }}</h1>
<nav aria-label="{{ $locale === 'ar' ? 'مراحل الإعداد' : 'Setup steps' }}">
<ol class="steps"><li>1 — {{ $locale === 'ar' ? 'التحقق' : 'Verify' }}</li><li>2 — {{ $locale === 'ar' ? 'قاعدة البيانات' : 'Database' }}</li><li>3 — {{ $locale === 'ar' ? 'الترحيلات' : 'Migrations' }}</li><li>4 — {{ $locale === 'ar' ? 'المالك' : 'Owner' }}</li></ol>
</nav>
<a href="{{ route('site.setup', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
@if($errors->any())<div role="alert" class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($step === 'unlock')
<h2>{{ $locale === 'ar' ? 'الخطوة 1: رمز الإعداد السري' : 'Step 1: Server setup token' }}</h2>
<p>{{ $locale === 'ar' ? 'رمز الأمان يتم توليده تلقائيًا. افتح File Manager ثم application/storage/app/site-first-run.json وانسخ قيمة token فقط. لا تعدّل أي ملف.' : 'Your private token is generated automatically. In cPanel File Manager open application/storage/app/site-first-run.json and copy only the token value. Do not edit any file.' }}</p>
<form method="post" action="{{ route('site.setup.unlock') }}">@csrf
<label for="token">{{ $locale === 'ar' ? 'رمز الإعداد' : 'Setup token' }}</label><input id="token" type="password" name="token" autocomplete="off" required maxlength="256">
<button type="submit">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</button></form>
@elseif($step === 'configure')
<h2>{{ $locale === 'ar' ? 'الخطوة 2: إعداد قاعدة البيانات' : 'Step 2: Configure database' }}</h2>
@if($connectionFailed)
<p role="alert" class="error">{{ $locale === 'ar' ? 'تعذر الاتصال بقاعدة البيانات المحفوظة. أدخل بيانات اتصال صحيحة لقاعدة فارغة لإعادة المحاولة دون تعديل ملفات الخادم.' : 'The saved database connection is unavailable. Enter working credentials for an empty database to retry without editing server files.' }}</p>
@endif
<p>{{ $locale === 'ar' ? 'أنشئ قاعدة بيانات فارغة ومستخدمًا لها من قسم MySQL Databases في cPanel، ثم أدخل البيانات هنا. سيتم إنشاء APP_KEY وحفظ ملف .env تلقائيًا دون Terminal.' : 'Create an empty database and user in cPanel MySQL Databases, then enter the credentials here. APP_KEY and private .env will be generated automatically; no Terminal is needed.' }}</p>
<form method="post" action="{{ route('site.setup.configure') }}">@csrf
<label for="site_url">{{ $locale === 'ar' ? 'الرابط الرسمي للموقع (HTTPS)' : 'Canonical website URL (HTTPS)' }}</label>
<input id="site_url" name="site_url" type="url" inputmode="url"
       placeholder="https://example.com" value="{{ old('site_url') }}"
       required maxlength="255" autocomplete="url" aria-describedby="site_url_help">
<p id="site_url_help"><small>{{ $locale === 'ar'
    ? 'أدخل رابط الدومين النهائي بنفسك (مثل https://example.com)، وليس رابط المعاينة أو لوحة cPanel. يُحفظ للرابط الدائم والبريد.'
    : 'Enter your final domain (for example https://example.com), not a preview or cPanel URL. This is used for permanent links and email.' }}</small></p>
<label for="db_host">{{ $locale === 'ar' ? 'خادم قاعدة البيانات' : 'Database host' }}</label>
<input id="db_host" name="db_host" value="{{ old('db_host', 'localhost') }}" required maxlength="253" autocomplete="off">
<label for="db_port">{{ $locale === 'ar' ? 'المنفذ' : 'Port' }}</label>
<input id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', 3306) }}" required>
<label for="db_database">{{ $locale === 'ar' ? 'اسم قاعدة البيانات' : 'Database name' }}</label>
<input id="db_database" name="db_database" value="{{ old('db_database') }}" required maxlength="64" autocomplete="off">
<label for="db_username">{{ $locale === 'ar' ? 'مستخدم قاعدة البيانات' : 'Database username' }}</label>
<input id="db_username" name="db_username" value="{{ old('db_username') }}" required maxlength="64" autocomplete="off">
<label for="db_password">{{ $locale === 'ar' ? 'كلمة مرور قاعدة البيانات' : 'Database password' }}</label>
<input id="db_password" name="db_password" type="password" required maxlength="255" autocomplete="new-password">
<button type="submit">{{ $locale === 'ar' ? 'فحص الاتصال وحفظ الإعدادات — التالي' : 'Test connection, save settings & Next' }}</button>
</form>
<p><small>{{ $locale === 'ar' ? 'تُحفظ كلمة المرور على الخادم فقط، ولا يتم عرضها مرة أخرى أو تسجيلها في السجلات.' : 'Credentials are only stored in private server configuration, never echoed or logged.' }}</small></p>
@elseif($step === 'database')
<h2>{{ $locale === 'ar' ? 'الخطوة 2: الاتصال وMigrations' : 'Step 2: Database and migrations' }}</h2>
<p>{{ $locale === 'ar' ? 'تم حفظ بيانات الاتصال. نفّذ الآن الترحيلات المطلوبة من خلال هذه الصفحة.' : 'Database settings saved. Apply the pending migrations directly from this page.' }}</p>
@if($historyMismatch)
<p role="alert" class="error">{{ $locale === 'ar'
    ? 'قاعدة البيانات تحتوي على ترحيلات من إصدار مختلف أو أحدث. حفاظًا على البيانات، لا يمكن تغيير الاتصال أو تشغيل ترحيلات جديدة. انشر إصدار الكود المتوافق أو استعد نسخة احتياطية موثقة.'
    : 'Database migration history belongs to a different or newer release. To protect your data, setup cannot switch databases or run migrations. Deploy compatible code or restore a verified backup.' }}</p>
@elseif(!$status['ready'])
<p role="alert" class="error">{{ $locale === 'ar' ? 'تعذر الاتصال بقاعدة البيانات. راجع الإعدادات الخاصة بالخادم.' : 'Database connection failed. Check the private server settings.' }}</p>
@else
<p>{{ $locale === 'ar' ? 'الاتصال ناجح. عدد الترحيلات المنتظرة:' : 'Connection passed. Pending migrations:' }} <strong>{{ count($status['pending']) }}</strong></p>
@if(count($status['pending']) > 0)<ul>@foreach($status['pending'] as $migration)<li><code>{{ $migration }}</code></li>@endforeach</ul>@endif
<form method="post" action="{{ route('site.setup.database') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'تنفيذ الترحيلات والتالي' : 'Apply migrations & Next' }}</button></form>
@endif
@else
<h2>{{ $locale === 'ar' ? 'الخطوة 3: تفعيل حساب المالك' : 'Step 3: Activate owner account' }}</h2>
<p>{{ $locale === 'ar' ? 'سيتم إدخال بيانات السيرة الذاتية المعتمدة وتفعيل بريد المالك التالي فقط:' : 'The approved CV will be seeded, and only its verified owner email will be activated:' }}</p>
<p><strong>{{ $ownerEmail }}</strong></p>
@if(!$status['ready'] || count($status['pending']) > 0)
<p role="alert" class="error">{{ $locale === 'ar' ? 'تم اكتشاف مشكلة في قاعدة البيانات أو ترحيلات جديدة. أعد خطوة القاعدة بعد إعادة فتح الإعداد.' : 'Database changed or migrations are pending; restart the database step.' }}</p>
@else
<form method="post" action="{{ route('site.setup.finish') }}">@csrf
<label for="password">{{ $locale === 'ar' ? 'كلمة مرور قوية (14 حرفًا على الأقل)' : 'Strong password (at least 14 characters)' }}</label>
<input id="password" type="password" name="password" minlength="14" maxlength="128" autocomplete="new-password" required>
<label for="password_confirmation">{{ $locale === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm password' }}</label>
<input id="password_confirmation" type="password" name="password_confirmation" minlength="14" maxlength="128" autocomplete="new-password" required>
<button type="submit">{{ $locale === 'ar' ? 'إكمال الإعداد' : 'Finish installation' }}</button></form>
@endif
@endif
<p><small>{{ $locale === 'ar' ? 'يُغلق المعالج نهائيًا بعد نجاح التثبيت، ولا يعيد تهيئة موقع يعمل بالفعل.' : 'The wizard is permanently locked after installation and cannot reset an existing site.' }}</small></p>
</main></body></html>
