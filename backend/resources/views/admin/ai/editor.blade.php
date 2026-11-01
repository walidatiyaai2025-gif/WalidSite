@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'تحرير مزود AI — وليد سايت' : 'Edit AI provider — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link)}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:12px;padding:12px;background:white;z-index:4}.skip:focus{top:12px}
.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}aside{background:var(--nav);color:white;padding:24px}aside a{color:white;display:block;min-height:44px;padding:8px 0}
main{padding:clamp(18px,4vw,48px);min-width:0}.toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
.panel,.notice{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,225px),1fr));gap:14px}
.field{margin-block:12px}.field label{display:block;font-weight:700;margin-bottom:5px}
.field input:not([type=checkbox]),.field select{display:block;width:100%;min-height:44px;padding:9px 12px;border:1px solid #97B4C8;border-radius:9px;font:inherit;color:var(--ink);background:white}
.check{display:flex;align-items:center;gap:10px;min-height:44px}.check input{height:22px;width:22px}
button{min-height:44px;background:var(--link);color:white;border:0;border-radius:9px;padding:10px 16px;cursor:pointer;font:inherit;font-weight:700}
button.secondary{background:var(--nav)}button.danger{background:#A52F3B}.muted{color:#55718B}.actions{display:flex;flex-wrap:wrap;gap:12px}
@media(max-width:700px){.layout{grid-template-columns:1fr}aside{padding:16px}aside a{display:inline-block;margin-inline-end:14px}.panel{padding:16px}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout">
<aside>
<h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'كل المزودين' : 'All providers' }}</a>
<a href="{{ route('admin.providers.show', ['provider' => $provider->id, 'lang' => $locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'تحرير المزود' : 'Provider editor' }}</a>
<a href="{{ route('admin.routing.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'توجيه AI' : 'AI routing' }}</a>
<a href="{{ route('admin.usage.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'سجل الاستخدام' : 'Usage audit' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit" class="secondary">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="toolbar">
<h1>{{ $locale === 'ar' ? 'تحرير مزود AI' : 'Edit AI provider' }}</h1>
<a href="{{ route('admin.providers.show', ['provider' => $provider->id, 'lang' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
</div>
<p><a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عودة لجميع المزودين' : 'Back to providers' }}</a></p>
<p class="muted">{{ $locale === 'ar'
? 'المفتاح المخزن مشفّر ولا يمكن عرضه من هذه الشاشة. أي تعديل يلغي نتيجة فحص الاتصال القديمة. حدود الإنفاق إعدادات محفوظة وليست ضمانًا للتنفيذ.'
: 'Stored credentials are encrypted and cannot be revealed here. Any edit invalidates an older connection check. Spending limits are saved configuration, not a task execution guarantee.' }}</p>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if(session('error'))<p class="notice" role="alert">{{ session('error') }}</p>@endif
@if($errors->any())<div class="notice" role="alert"><strong>{{ $locale === 'ar' ? 'تحقق من المدخلات' : 'Check the submitted fields' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="panel" aria-labelledby="editor-title">
<h2 id="editor-title">{{ $provider->display_name }}</h2>
<p class="muted">{{ $locale === 'ar' ? 'حالة المزود' : 'Provider status' }}:
{{ $provider->enabled ? ($locale === 'ar' ? 'مفعّل' : 'Enabled') : ($locale === 'ar' ? 'معطّل' : 'Disabled') }}
· {{ $locale === 'ar' ? 'آخر نتيجة فحص' : 'Last health check' }}:
{{ match($provider->health_status) { 'healthy' => $locale === 'ar' ? 'سليم' : 'healthy', 'unhealthy' => $locale === 'ar' ? 'غير سليم' : 'unhealthy', default => $locale === 'ar' ? 'غير متحقق منه' : 'unverified' } }}
</p>
@if($provider->last_checked_at)<p class="muted"><time datetime="{{ $provider->last_checked_at->toIso8601String() }}">{{ $provider->last_checked_at->toIso8601String() }}</time></p>@endif
<form method="post" action="{{ route('admin.providers.update', ['provider' => $provider->id, 'lang' => $locale, 'return' => 'editor']) }}">
@csrf @method('PATCH')
<div class="grid">
<div class="field"><label for="provider-name">{{ $locale === 'ar' ? 'اسم المزود' : 'Provider name' }}</label>
<input id="provider-name" name="display_name" maxlength="160" required value="{{ old('display_name', $provider->display_name) }}"></div>
<div class="field"><label for="provider-type">{{ $locale === 'ar' ? 'نوع المحول' : 'Adapter type' }}</label>
<select id="provider-type" name="provider_type">
<option value="ollama-compatible" @selected(old('provider_type', $provider->provider_type) === 'ollama-compatible')>Ollama / self-hosted</option>
<option value="openai-compatible" @selected(old('provider_type', $provider->provider_type) === 'openai-compatible')>OpenAI-compatible / managed</option>
</select></div>
<div class="field"><label for="provider-endpoint">{{ $locale === 'ar' ? 'عنوان الخدمة' : 'Endpoint URL' }}</label>
<input id="provider-endpoint" name="base_url" maxlength="2048" required value="{{ old('base_url', $provider->base_url) }}"></div>
<div class="field"><label for="provider-secret">{{ $locale === 'ar' ? 'مفتاح API بديل' : 'Replace API secret' }}</label>
<input id="provider-secret" name="secret" type="password" maxlength="4096" autocomplete="new-password" value="">
<small class="muted">{{ $provider->secret_ciphertext !== null
? ($locale === 'ar' ? 'مفتاح مخزن ومخفي. اترك الحقل فارغًا للاحتفاظ به.' : 'Stored and hidden. Leave blank to keep it.')
: ($locale === 'ar' ? 'لا يوجد مفتاح محفوظ.' : 'No saved secret.') }}</small></div>
<div class="field"><label for="provider-budget">{{ $locale === 'ar' ? 'الميزانية اليومية (وحدات صغرى)' : 'Daily budget (minor units)' }}</label>
<input id="provider-budget" type="number" name="daily_budget_minor" min="0" max="1000000000" value="{{ old('daily_budget_minor', $provider->daily_budget_minor) }}" required></div>
<div class="field"><label for="provider-currency">{{ $locale === 'ar' ? 'العملة' : 'Currency' }}</label>
<select id="provider-currency" name="currency">
@foreach(['USD','KWD','EUR'] as $currency)
<option value="{{ $currency }}" @selected(old('currency', $provider->currency) === $currency)>{{ $currency }}</option>
@endforeach</select></div>
<div class="field"><label for="provider-priority">{{ $locale === 'ar' ? 'أولوية التوجيه' : 'Routing priority' }}</label>
<input id="provider-priority" type="number" name="routing_priority" min="1" max="999" value="{{ old('routing_priority', $provider->routing_priority) }}" required></div>
<div class="field"><label for="provider-concurrent">{{ $locale === 'ar' ? 'الطلبات المتزامنة' : 'Concurrent requests' }}</label>
<input id="provider-concurrent" type="number" name="concurrent_limit" min="1" max="20" value="{{ old('concurrent_limit', $provider->concurrent_limit) }}" required></div>
<div class="field"><label for="provider-timeout">{{ $locale === 'ar' ? 'المهلة (ثوان)' : 'Timeout (seconds)' }}</label>
<input id="provider-timeout" type="number" name="timeout_seconds" min="1" max="30" value="{{ old('timeout_seconds', $provider->timeout_seconds) }}" required></div>
</div>
<div class="check"><input id="provider-enabled-hidden" type="hidden" name="enabled" value="0">
<input id="provider-enabled" type="checkbox" name="enabled" value="1" @checked((bool) old('enabled', $provider->enabled))>
<label for="provider-enabled">{{ $locale === 'ar' ? 'تفعيل المزود' : 'Enable provider' }}</label></div>
<div class="check"><input id="provider-clear-secret" type="checkbox" name="clear_secret" value="1">
<label for="provider-clear-secret">{{ $locale === 'ar' ? 'حذف مفتاح API المحفوظ' : 'Remove stored API secret' }}</label></div>
<button type="submit">{{ $locale === 'ar' ? 'حفظ إعدادات المزود' : 'Save provider settings' }}</button>
</form>
</section>
<section class="panel" aria-labelledby="health-title">
<h2 id="health-title">{{ $locale === 'ar' ? 'اختبار الاتصال' : 'Connection check' }}</h2>
<p>{{ $locale === 'ar' ? 'يتم الاتصال بالمزود فقط عند الضغط على زر الاختبار.' : 'The provider is contacted only when you run this explicit test.' }}</p>
<form method="post" action="{{ route('admin.providers.test', ['provider' => $provider->id, 'lang' => $locale, 'return' => 'editor']) }}">
@csrf <button type="submit">{{ $locale === 'ar' ? 'اختبار الاتصال الآن' : 'Test connection now' }}</button></form>
</section>
<section class="panel" aria-labelledby="archive-title">
<h2 id="archive-title">{{ $locale === 'ar' ? 'إدارة الحذف الآمن' : 'Secure archival' }}</h2>
<details><summary>{{ $locale === 'ar' ? 'أرشفة المزود وإبطال المفتاح' : 'Archive provider and revoke credential' }}</summary>
<p>{{ $locale === 'ar' ? 'سيُعطل المزود ويُحذف مفتاحه مع الاحتفاظ بسجل التدقيق. أكد بالاسم الدقيق.' : 'This disables the provider and erases its credential while retaining the audit trail. Confirm its exact name.' }}</p>
<form method="post" action="{{ route('admin.providers.delete', ['provider' => $provider->id, 'lang' => $locale]) }}">
@csrf @method('DELETE')
<div class="field"><label for="archive-confirm">{{ $locale === 'ar' ? 'اكتب اسم المزود للتأكيد' : 'Type provider name to confirm' }}</label>
<input id="archive-confirm" name="confirm_name" maxlength="160" autocomplete="off" required aria-describedby="archive-name">
</div><p id="archive-name">{{ $provider->display_name }}</p>
<button type="submit" class="danger">{{ $locale === 'ar' ? 'أرشفة المزود' : 'Archive provider' }}</button>
</form>
</details></section>
</main></div></body></html>
