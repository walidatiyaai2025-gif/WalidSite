@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</title>
<style>
:root{color-scheme:light;--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED}
*{box-sizing:border-box}body{margin:0;background:#F5F9FC;color:var(--ink);font:16px/1.6 Arial,sans-serif}
a:focus-visible,button:focus-visible{outline:3px solid var(--link);outline-offset:3px}
.skip{position:absolute;top:-100px;left:15px;padding:12px;background:#fff}.skip:focus{top:10px}
.layout{display:grid;grid-template-columns:230px 1fr;min-height:100vh}
aside{background:var(--nav);padding:26px;color:#fff}aside a{display:block;color:#fff;min-height:44px;padding:8px 0}
main{padding:clamp(20px,4vw,52px);min-width:0}h1{color:var(--nav);font-size:clamp(30px,4vw,46px)}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:14px}
.card,.panel{background:#fff;border:1px solid var(--border);padding:22px;border-radius:16px}
.card strong{display:block;font-size:38px;color:var(--nav)}.panel{margin-top:22px}
.panel a{color:var(--link);display:inline-block;min-height:44px}th,td{padding:12px;text-align:start;border-bottom:1px solid var(--border)}
button{background:transparent;color:#fff;border:1px solid #fff;border-radius:8px;min-height:44px;padding:8px;font:inherit}
@media(max-width:700px){.layout{grid-template-columns:1fr}aside{padding:16px}}
</style></head>
<body><a class="skip" href="#content">{{ $locale === 'ar' ? 'اذهب للمحتوى' : 'Skip to content' }}</a>
<div class="layout"><aside>
<h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin menu' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مزودو AI' : 'AI providers' }}</a>
<a href="{{ route('home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الموقع العام' : 'Public website' }}</a>
</nav><form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form></aside>
<main id="content">
<h1>{{ $locale === 'ar' ? 'نظرة عامة' : 'Administration overview' }}</h1>
<a href="{{ route('admin.home', ['lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<p>{{ $locale === 'ar'
? 'هذه أعداد الإعدادات المحفوظة فعليًا، وليست إحصاءات استخدام أو تكلفة.'
: 'These values count saved configurations, not AI usage or costs.' }}</p>
<section aria-labelledby="provider-counters">
<h2 id="provider-counters">{{ $locale === 'ar' ? 'حالة المزودين' : 'Provider status' }}</h2>
<div class="cards">
@foreach ([
'configured' => ['en'=>'Configured providers', 'ar'=>'مزودون مسجلون'],
'enabled' => ['en'=>'Enabled', 'ar'=>'مفعّل'],
'healthy' => ['en'=>'Healthy', 'ar'=>'سليم'],
'unverified' => ['en'=>'Unverified', 'ar'=>'غير متحقق منه']
] as $key => $labels)
<div class="card"><span>{{ $labels[$locale] }}</span><strong>{{ $stats[$key] }}</strong></div>
@endforeach
</div></section>
<section class="panel" aria-labelledby="ai-activity-title">
<h2 id="ai-activity-title">{{ $locale === 'ar' ? 'مهام الذكاء الاصطناعي المسجلة' : 'Persisted AI jobs' }}</h2>
<p>{{ $locale === 'ar' ? 'أعداد المهام المخزنة في قاعدة البيانات، وليست تقديرات أو مؤشرات معالجة حية.' : 'Stored job totals, not estimated or live processing metrics.' }}</p>
@if ($jobs->isEmpty())
<p>{{ $locale === 'ar' ? 'لا توجد مهام مسجلة بعد.' : 'No AI jobs recorded yet.' }}</p>
@else
<div class="cards">
@foreach ($jobs as $job)
<div class="card"><span>{{ $job->status }}</span><strong>{{ $job->job_count }}</strong></div>
@endforeach
</div>
@endif
<h2>{{ $locale === 'ar' ? 'استخدام اليوم (UTC)' : 'Today’s usage (UTC)' }}</h2>
<p>{{ $locale === 'ar' ? 'التكلفة بوحدات العملة الصغرى لكل عملة على حدة. لا يتم جمع العملات المختلفة أو تخمين التحويل.' : 'Costs are stored in minor currency units, separated by currency; no FX conversion is inferred.' }}</p>
@if ($usage->isEmpty())
<p>{{ $locale === 'ar' ? 'لم تسجل إيصالات استخدام اليوم.' : 'No usage receipts recorded today.' }}</p>
@else
<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;text-align:start">
<thead><tr><th scope="col">{{ $locale === 'ar' ? 'العملة' : 'Currency' }}</th><th scope="col">{{ $locale === 'ar' ? 'الإيصالات' : 'Receipts' }}</th><th scope="col">{{ $locale === 'ar' ? 'التكلفة بالوحدات الصغرى' : 'Cost (minor units)' }}</th></tr></thead>
<tbody>@foreach ($usage as $entry)
<tr><td>{{ $entry->cost_currency }}</td><td>{{ $entry->request_count }}</td><td>{{ $entry->spend_minor }}</td></tr>
@endforeach</tbody>
</table></div>
@endif
</section>
<section class="panel">
<h2>{{ $locale === 'ar' ? 'إدارة المزودين' : 'Provider management' }}</h2>
<p>{{ $locale === 'ar' ? 'الإعدادات محفوظة على الخادم.' : 'Provider configuration is managed on the server.' }}</p>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'فتح الإدارة' : 'Open settings' }}</a>
</section>
</main></div></body></html>
