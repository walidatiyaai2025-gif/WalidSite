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
<ol class="steps"><li>1 — {{ $locale === 'ar' ? 'التحقق' : 'Verify' }}</li><li>2 — {{ $locale === 'ar' ? 'قاعدة البيانات' : 'Database' }}</li><li>3 — {{ $locale === 'ar' ? 'المالك' : 'Owner' }}</li></ol>
</nav>
<a href="{{ route('site.setup', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
@if($errors->any())<div role="alert" class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($step === 'unlock')
<h2>{{ $locale === 'ar' ? 'الخطوة 1: رمز الإعداد السري' : 'Step 1: Server setup token' }}</h2>
<p>{{ $locale === 'ar' ? 'أدخل رمز SITE_SETUP_TOKEN الذي وضعته داخل بيئة الخادم، وليس داخل GitHub.' : 'Enter SITE_SETUP_TOKEN from the private server configuration, never from GitHub.' }}</p>
<form method="post" action="{{ route('site.setup.unlock') }}">@csrf
<label for="token">{{ $locale === 'ar' ? 'رمز الإعداد' : 'Setup token' }}</label><input id="token" type="password" name="token" autocomplete="off" required maxlength="256">
<button type="submit">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</button></form>
@elseif($step === 'database')
<h2>{{ $locale === 'ar' ? 'الخطوة 2: الاتصال وMigrations' : 'Step 2: Database and migrations' }}</h2>
<p>{{ $locale === 'ar' ? 'يجب ضبط APP_KEY وقاعدة البيانات في إعدادات الخادم أولاً. لا تُعرض كلمات مرور قاعدة البيانات هنا.' : 'Configure APP_KEY and database credentials on the server first. Secrets are never displayed here.' }}</p>
@if(!$status['ready'])
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
