@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'إدارة AI — وليد سايت' : 'AI Providers — Waleed Site' }}</title>
<style>
:root{--primary:#163B66;--accent:#00A999;--cta:#126DE1;--bg:#F5F9FC;--ink:#142E4D;--line:#D3E3ED}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}a{color:var(--cta)}
a:focus-visible,input:focus-visible,select:focus-visible,button:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:10px;background:white;padding:12px;z-index:4}.skip:focus{top:10px}
.shell{display:grid;grid-template-columns:210px minmax(0,1fr);min-height:100vh}
aside{background:var(--primary);color:white;padding:26px 16px}
aside a{color:white;display:block;margin:12px 0}.body{padding:clamp(18px,4vw,45px);min-width:0}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.note{margin:18px 0;padding:14px 18px;background:#EAF5FB;border:1px solid var(--line);border-radius:10px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:18px}
.card{background:white;border:1px solid var(--line);border-radius:18px;padding:24px;margin-bottom:20px;min-width:0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:12px}
.field{margin-block:12px}.field label{display:block;font-weight:700;margin-bottom:5px}
.field input:not([type=checkbox]),.field select{width:100%;min-height:44px;border:1px solid #97B4C8;border-radius:10px;padding:9px 12px;font:inherit;background:white;color:var(--ink)}
button{min-height:44px;padding:9px 16px;background:var(--cta);border:0;border-radius:9px;color:white;cursor:pointer;font:inherit;font-weight:700}
button.secondary{background:var(--primary)}.muted{color:#55718B}.actions{display:flex;gap:15px;align-items:center;flex-wrap:wrap}
.status{padding:12px 16px;border:1px solid var(--line);background:white;border-radius:8px}
@media(max-width:700px){.shell{grid-template-columns:1fr}aside{padding:14px}aside h2{display:inline}aside a{display:inline-block;margin-inline:10px}.card{padding:18px}}
</style>
    @include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell">
<aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نظرة عامة' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'مزودو AI' : 'AI providers' }}</a>
<a href="{{ route('admin.routing.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'توجيه AI' : 'AI routing' }}</a>
<a href="{{ route('admin.usage.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'سجل استخدام AI' : 'AI usage audit' }}</a>
<a href="{{ route('home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الموقع العام' : 'Public website' }}</a></nav></aside>
<main id="content" class="body" tabindex="-1">
<div class="top">
  <h1>{{ $locale === 'ar' ? 'مزودو الذكاء الاصطناعي' : 'AI providers' }}</h1>
  <div class="actions">
    <a href="{{ route('admin.providers.index', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
    <form method="post" action="{{ route('admin.logout') }}">@csrf
      <button type="submit" class="secondary">{{ $locale === 'ar' ? 'تسجيل الخروج' : 'Sign out' }}</button>
    </form>
  </div>
</div>
<p>{{ $locale === 'ar' ? 'تعديل مزودات AI المحلية والمدفوعة من لوحة الإدارة. المفاتيح تبقى مشفرة على الخادم ولا تُعرض بعد حفظها.' : 'Manage local and paid AI providers here. API secrets remain encrypted on the server and are never shown after saving.' }}</p>
<p class="note">{{ $locale === 'ar' ? 'الميزانيات والتزامن إعدادات محفوظة، ولا يُفترض تطبيق حدود الإنفاق قبل التحقق من جدولة AI (T011). سجل الاستخدام يعرض إيصالات التكلفة المحفوظة بالفعل حسب مزود الخدمة والعملة ويوم UTC، وليس بيانات افتراضية أو فاتورة نهائية.' : 'Budgets and concurrency are saved settings; do not assume runtime budget enforcement until AI orchestration (T011) is verified. The usage audit shows persisted UTC-day receipts grouped by provider and currency, not simulated metrics or a final invoice.' }}</p>
<p><a href="{{ route('admin.usage.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عرض سجل الاستخدام الموثق' : 'View persisted usage audit' }}</a></p>
@if(session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
@if(session('error'))<p class="status" role="alert">{{ session('error') }}</p>@endif
@if($errors->any())<div role="alert" class="status"><strong>{{ $locale === 'ar' ? 'خطأ في البيانات' : 'Check the submitted fields' }}</strong>
<ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif

<section class="card" aria-labelledby="create-title">
<h2 id="create-title">{{ $locale === 'ar' ? 'إضافة مزود جديد' : 'Add provider' }}</h2>
<form method="post" action="{{ route('admin.providers.create', ['lang' => $locale]) }}">@csrf
<div class="grid">
<div class="field"><label for="name-new">{{ $locale === 'ar' ? 'اسم المزود' : 'Name' }}</label>
<input id="name-new" name="display_name" maxlength="160" minlength="2" required value="{{ old('display_name') }}"></div>
<div class="field"><label for="type-new">{{ $locale === 'ar' ? 'نوع المزود' : 'Provider type' }}</label>
<select id="type-new" name="provider_type">
<option value="ollama-compatible">Ollama / self-hosted</option><option value="openai-compatible">OpenAI-compatible / managed</option>
</select></div>
<div class="field"><label for="url-new">{{ $locale === 'ar' ? 'عنوان الخدمة' : 'Endpoint URL' }}</label>
<input id="url-new" name="base_url" maxlength="2048" required placeholder="https://provider.example/v1" value="{{ old('base_url') }}"></div>
<div class="field"><label for="secret-new">{{ $locale === 'ar' ? 'مفتاح API اختياري' : 'Optional API secret' }}</label>
<input type="password" id="secret-new" name="secret" maxlength="4096" autocomplete="new-password" value=""></div>
<div class="field"><label for="budget-new">{{ $locale === 'ar' ? 'الميزانية اليومية (وحدات صغرى)' : 'Daily budget (minor units)' }}</label>
<input type="number" id="budget-new" name="daily_budget_minor" min="0" max="1000000000" value="0"></div>
<div class="field"><label for="currency-new">{{ $locale === 'ar' ? 'العملة' : 'Currency' }}</label>
<select id="currency-new" name="currency"><option>USD</option><option>KWD</option><option>EUR</option></select></div>
<div class="field"><label for="concurrent-new">{{ $locale === 'ar' ? 'الطلبات المتزامنة' : 'Concurrency' }}</label>
<input type="number" id="concurrent-new" name="concurrent_limit" min="1" max="20" value="1"></div>
<div class="field"><label for="timeout-new">{{ $locale === 'ar' ? 'المهلة (ثوان)' : 'Timeout (seconds)' }}</label>
<input type="number" id="timeout-new" name="timeout_seconds" min="1" max="30" value="5"></div>
<div class="field"><label for="priority-new">{{ $locale === 'ar' ? 'أولوية التوجيه (الأقل أولاً)' : 'Routing priority (lower first)' }}</label>
<input type="number" id="priority-new" name="routing_priority" min="1" max="999" required value="{{ old('routing_priority', 100) }}"></div>
</div>
<p class="muted">{{ $locale === 'ar' ? 'الأولوية تحدد ترتيب المزودين المؤهلين في التوجيه؛ لا تضمن التشغيل دون فحص الصحة وحدود الاستخدام.' : 'Priority orders eligible providers in routing; it does not bypass health or budget eligibility checks.' }}</p>
<input type="hidden" name="enabled" value="0">
<button type="submit">{{ $locale === 'ar' ? 'إضافة معطّلاً افتراضيًا' : 'Create disabled provider' }}</button>
</form>
</section>

<h2>{{ $locale === 'ar' ? 'المزودون المسجلون' : 'Configured providers' }} ({{ $providers->total() }})</h2>
@if($providers->isEmpty())
<p class="status" role="status">{{ $locale === 'ar' ? 'لا يوجد مزود مسجل بعد.' : 'No providers configured yet.' }}</p>
@else
<div class="cards">
@foreach($providers as $provider)
<section class="card" aria-labelledby="provider-{{ $provider->id }}">
<h3 id="provider-{{ $provider->id }}">{{ $provider->display_name }}</h3>
<p><a href="{{ route('admin.providers.show', ['provider' => $provider->id, 'lang' => $locale]) }}">{{ $locale === 'ar' ? 'فتح محرر المزود' : 'Open provider editor' }}</a></p>
<p class="muted">{{ $provider->provider_type }} · {{ $provider->enabled ? ($locale === 'ar' ? 'مفعّل' : 'Enabled') : ($locale === 'ar' ? 'معطّل' : 'Disabled') }} · {{ $locale === 'ar' ? 'آخر حالة فحص' : 'Last recorded health' }}: {{ match($provider->health_status) {
'healthy' => $locale === 'ar' ? 'سليم' : 'healthy',
'unhealthy' => $locale === 'ar' ? 'غير سليم' : 'unhealthy',
default => $locale === 'ar' ? 'غير متحقق منه' : 'unverified'
} }}</p>
<p class="muted">{{ $locale === 'ar' ? 'المفتاح المحفوظ' : 'Saved credential' }}: {{ $provider->secret_ciphertext === null ? ($locale === 'ar' ? 'غير موجود' : 'Not saved') : ($locale === 'ar' ? 'محفوظ ومخفي' : 'Stored, hidden') }}</p>
@if($provider->last_checked_at)<p class="muted">{{ $locale === 'ar' ? 'آخر فحص' : 'Last health check' }}: <time datetime="{{ $provider->last_checked_at->toIso8601String() }}">{{ $provider->last_checked_at->toIso8601String() }}</time></p>@endif
<form method="post" action="{{ route('admin.providers.update', ['provider' => $provider->id, 'lang' => $locale]) }}">@csrf @method('PATCH')
<div class="field"><label for="name-{{ $provider->id }}">{{ $locale === 'ar' ? 'الاسم' : 'Name' }}</label>
<input id="name-{{ $provider->id }}" name="display_name" value="{{ $provider->display_name }}" required maxlength="160"></div>
<div class="field"><label for="type-{{ $provider->id }}">{{ $locale === 'ar' ? 'النوع' : 'Type' }}</label>
<select id="type-{{ $provider->id }}" name="provider_type"><option value="ollama-compatible" @selected($provider->provider_type === 'ollama-compatible')>Ollama</option><option value="openai-compatible" @selected($provider->provider_type === 'openai-compatible')>OpenAI compatible</option></select></div>
<div class="field"><label for="url-{{ $provider->id }}">{{ $locale === 'ar' ? 'عنوان الخدمة' : 'Endpoint' }}</label>
<input id="url-{{ $provider->id }}" name="base_url" value="{{ $provider->base_url }}" required maxlength="2048"></div>
<div class="field"><label for="secret-{{ $provider->id }}">{{ $locale === 'ar' ? 'مفتاح بديل (اتركه فارغًا للاحتفاظ بالسابق)' : 'Replace secret (blank to preserve)' }}</label>
<input id="secret-{{ $provider->id }}" type="password" name="secret" autocomplete="new-password" value=""></div>
<label><input type="checkbox" name="clear_secret" value="1"> {{ $locale === 'ar' ? 'حذف المفتاح المحفوظ' : 'Remove stored secret' }}</label>
<div class="grid">
<div class="field"><label for="budget-{{ $provider->id }}">{{ $locale === 'ar' ? 'الميزانية اليومية بوحدات صغرى' : 'Daily budget (minor units)' }}</label>
<input type="number" id="budget-{{ $provider->id }}" name="daily_budget_minor" min="0" max="1000000000" value="{{ $provider->daily_budget_minor }}"></div>
<div class="field"><label for="limit-{{ $provider->id }}">{{ $locale === 'ar' ? 'الطلبات المتزامنة' : 'Concurrency' }}</label>
<input type="number" id="limit-{{ $provider->id }}" name="concurrent_limit" min="1" max="20" value="{{ $provider->concurrent_limit }}"></div>
<div class="field"><label for="timeout-{{ $provider->id }}">{{ $locale === 'ar' ? 'مهلة الاتصال' : 'Timeout' }}</label>
<input type="number" id="timeout-{{ $provider->id }}" name="timeout_seconds" min="1" max="30" value="{{ $provider->timeout_seconds }}"></div>
<div class="field"><label for="priority-{{ $provider->id }}">{{ $locale === 'ar' ? 'أولوية التوجيه (الأقل أولاً)' : 'Routing priority (lower first)' }}</label>
<input type="number" id="priority-{{ $provider->id }}" name="routing_priority" min="1" max="999" required value="{{ $provider->routing_priority }}"></div>
<div class="field"><label for="currency-{{ $provider->id }}">{{ $locale === 'ar' ? 'العملة' : 'Currency' }}</label>
<select id="currency-{{ $provider->id }}" name="currency">@foreach(['USD','KWD','EUR'] as $currency)
<option value="{{ $currency }}" @selected($provider->currency === $currency)>{{ $currency }}</option>@endforeach</select></div>
</div>
<input type="hidden" name="enabled" value="0">
<label><input type="checkbox" name="enabled" value="1" @checked($provider->enabled)> {{ $locale === 'ar' ? 'تفعيل المزود' : 'Enable provider' }}</label>
<div style="margin-top:15px"><button type="submit">{{ $locale === 'ar' ? 'حفظ التعديلات' : 'Save changes' }}</button></div>
</form>
<form method="post" action="{{ route('admin.providers.test', ['provider' => $provider->id, 'lang' => $locale]) }}" style="margin-top:12px">@csrf
<button type="submit" class="secondary">{{ $locale === 'ar' ? 'اختبار الاتصال الآن' : 'Check connection now' }}</button></form>
<details class="note" style="margin-bottom:0">
  <summary>{{ $locale === 'ar' ? 'أرشفة المزود وإبطال المفتاح' : 'Archive provider and revoke credential' }}</summary>
  <p>{{ $locale === 'ar'
      ? 'سيتم تعطيل المزود وحذف مفتاحه المشفر مع الاحتفاظ بسجل التدقيق. اكتب الاسم كما هو للتأكيد.'
      : 'The provider will be disabled and its encrypted credential erased while audit history is preserved. Type the exact name to confirm.' }}</p>
  <form method="post" action="{{ route('admin.providers.delete', ['provider' => $provider->id, 'lang' => $locale]) }}">
    @csrf @method('DELETE')
    <div class="field">
      <label for="delete-name-{{ $provider->id }}">{{ $locale === 'ar' ? 'تأكيد اسم المزود' : 'Confirm provider name' }}</label>
      <input id="delete-name-{{ $provider->id }}" name="confirm_name" required
             maxlength="160" autocomplete="off" aria-describedby="delete-warning-{{ $provider->id }}">
    </div>
    <p class="muted" id="delete-warning-{{ $provider->id }}">{{ $provider->display_name }}</p>
    <button type="submit" style="background:#A52F3B">{{ $locale === 'ar' ? 'أرشفة المزود نهائيًا' : 'Archive provider' }}</button>
  </form>
</details>
</section>
@endforeach
</div>
{{ $providers->links() }}
@endif
</main></div></body></html>