@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'توجيه مزودي AI — وليد سايت' : 'AI provider routing — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link)}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:12px;padding:12px;background:white;z-index:4}.skip:focus{top:12px}
.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:white;padding:24px}aside a{color:white;display:block;min-height:44px;padding:8px 0}
main{padding:clamp(18px,4vw,48px);min-width:0}
.toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.card,.notice{background:white;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:20px}
.card h2{margin-top:0;color:var(--nav)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:14px}
.field label{display:block;font-weight:700;margin-bottom:6px}.field input:not([type=checkbox]),.field select{display:block;width:100%;min-height:44px;padding:9px 12px;border:1px solid #97B4C8;border-radius:10px;background:white;color:var(--ink);font:inherit}
.field .choice{display:flex;align-items:center;min-height:44px;gap:10px}.choice input{width:22px;height:22px}
button{min-height:44px;border:0;border-radius:9px;background:var(--link);color:white;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer}
.muted{color:#55718B}.summary{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.summary strong{font-size:20px}
@media(max-width:700px){.layout{grid-template-columns:1fr}aside{padding:16px}aside a{display:inline-block;margin-inline-end:14px}.card{padding:16px}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout">
<aside>
<h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نظرة عامة' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إعداد المزودين' : 'Provider settings' }}</a>
<a href="{{ route('admin.routing.index', ['lang' => $locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'توجيه AI' : 'AI routing' }}</a>
<a href="{{ route('admin.usage.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'سجل استخدام AI' : 'AI usage audit' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="toolbar">
<h1>{{ $locale === 'ar' ? 'توجيه مزودي الذكاء الاصطناعي' : 'AI provider routing' }}</h1>
<a href="{{ route('admin.routing.index', ['lang' => $locale === 'ar' ? 'en' : 'ar', 'page' => $providers->currentPage()]) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
</div>
<p class="notice">{{ $locale === 'ar'
? 'تعرض هذه الصفحة أولويات المزودين وحدودهم المحفوظة. الأقل رقمًا أولًا. الحالة الصحية هي آخر فحص مسجل فقط، وليست اتصالًا مباشرًا أو ضمان تنفيذ المهمة. الميزانيات حدود مهيأة، وليست رصيدًا متبقيًا مؤكّدًا.'
: 'This page displays saved provider priorities and limits; lower priority numbers come first. Health is the last recorded check, not live reachability or a task guarantee. Budgets are configured settings, not verified remaining spend.' }}</p>
<p><a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إضافة مزود أو تعديل عنوانه ومفتاحه' : 'Add a provider or change its endpoint and secret' }}</a>
· <a href="{{ route('admin.models.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تسجيل النماذج وربط المهمة بالأساسي والبدائل وحدود التكلفة' : 'Register models and configure primary/fallback task routes and cost limits' }}</a></p>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="notice" role="alert"><strong>{{ $locale === 'ar' ? 'لم يتم حفظ الإعدادات' : 'Settings were not saved' }}</strong>
<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section aria-labelledby="routing-title">
<h2 id="routing-title">{{ $locale === 'ar' ? 'إعدادات ترتيب المزودين' : 'Provider routing order' }} ({{ $providers->total() }})</h2>
@if($providers->isEmpty())
<p class="notice" role="status">{{ $locale === 'ar' ? 'لا يوجد مزودون مسجلون بعد. أضف مزودًا من صفحة الإعدادات.' : 'No providers configured. Add one from provider settings.' }}</p>
@else
@foreach($providers as $provider)
<section class="card" aria-labelledby="route-{{ $provider->id }}">
<div class="summary">
<h2 id="route-{{ $provider->id }}">{{ $provider->display_name }}</h2>
<span class="muted">{{ $provider->provider_type }} · {{ $provider->enabled ? ($locale === 'ar' ? 'مفعّل' : 'Enabled') : ($locale === 'ar' ? 'معطّل' : 'Disabled') }}</span>
<span class="muted">{{ $locale === 'ar' ? 'آخر حالة صحية' : 'Last recorded health' }}:
{{ match($provider->health_status) { 'healthy' => $locale === 'ar' ? 'سليم' : 'healthy', 'unhealthy' => $locale === 'ar' ? 'غير سليم' : 'unhealthy', default => $locale === 'ar' ? 'غير متحقق منه' : 'unverified' } }}</span>
</div>
<p><a href="{{ route('admin.providers.show', ['provider' => $provider->id, 'lang' => $locale]) }}">{{ $locale === 'ar' ? 'فتح محرر المزود وفحص الاتصال' : 'Open provider editor and health check' }}</a></p>
<form method="post" action="{{ route('admin.providers.update', [
    'provider' => $provider->id, 'lang' => $locale,
    'return' => 'routing', 'page' => $providers->currentPage(),
]) }}">
@csrf @method('PATCH')
<div class="grid">
<div class="field">
<label for="priority-{{ $provider->id }}">{{ $locale === 'ar' ? 'الأولوية (الأقل أولًا)' : 'Priority (lower first)' }}</label>
<input type="number" id="priority-{{ $provider->id }}" name="routing_priority" min="1" max="999" required value="{{ $provider->routing_priority }}">
</div>
<div class="field">
<label for="concurrent-{{ $provider->id }}">{{ $locale === 'ar' ? 'الطلبات المتزامنة' : 'Concurrent requests' }}</label>
<input type="number" id="concurrent-{{ $provider->id }}" name="concurrent_limit" min="1" max="20" required value="{{ $provider->concurrent_limit }}">
</div>
<div class="field">
<label for="timeout-{{ $provider->id }}">{{ $locale === 'ar' ? 'مهلة الاتصال (ثوان)' : 'Connection timeout (seconds)' }}</label>
<input type="number" id="timeout-{{ $provider->id }}" name="timeout_seconds" min="1" max="30" required value="{{ $provider->timeout_seconds }}">
</div>
<div class="field">
<label for="budget-{{ $provider->id }}">{{ $locale === 'ar' ? 'الميزانية اليومية (وحدات صغرى)' : 'Daily budget (minor units)' }}</label>
<input type="number" id="budget-{{ $provider->id }}" name="daily_budget_minor" min="0" max="1000000000" required value="{{ $provider->daily_budget_minor }}">
</div>
<div class="field">
<label for="currency-{{ $provider->id }}">{{ $locale === 'ar' ? 'عملة الميزانية' : 'Budget currency' }}</label>
<select id="currency-{{ $provider->id }}" name="currency">
@foreach(['USD', 'KWD', 'EUR'] as $currency)
<option value="{{ $currency }}" @selected($provider->currency === $currency)>{{ $currency }}</option>
@endforeach
</select>
</div>
<div class="field">
<label for="enabled-{{ $provider->id }}">{{ $locale === 'ar' ? 'حالة المزود' : 'Provider state' }}</label>
<div class="choice">
<input type="hidden" name="enabled" value="0">
<input type="checkbox" id="enabled-{{ $provider->id }}" name="enabled" value="1" @checked($provider->enabled)>
<span>{{ $locale === 'ar' ? 'مفعّل' : 'Enabled' }}</span>
</div>
</div>
</div>
<p><button type="submit">{{ $locale === 'ar' ? 'حفظ إعدادات التوجيه' : 'Save routing settings' }}</button></p>
</form>
</section>
@endforeach
{{ $providers->links() }}
@endif
</section>
</main>
</div></body></html>
