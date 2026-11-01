@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'استخدام مزودي AI — وليد سايت' : 'AI Provider Usage — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link)}
a:focus-visible,button:focus-visible{outline:3px solid #00A999;outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:12px;padding:10px;background:white}.skip:focus{top:12px}
.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:white;padding:24px}aside a{color:white;display:block;min-height:44px;padding:8px 0}
main{padding:clamp(18px,4vw,48px);min-width:0}.panel{background:white;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:20px}
table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid var(--border);text-align:start;vertical-align:top}
th{color:var(--nav)}.scroll{overflow-x:auto}.muted{color:#55718B}
button{min-height:44px;border-radius:8px;border:1px solid white;color:white;background:transparent;padding:8px 14px;font:inherit}
@media(max-width:700px){.layout{grid-template-columns:1fr}aside{padding:16px}}
</style>
    @include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout">
<aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'إعداد المزودين' : 'Provider settings' }}</a>
<a href="{{ route('admin.usage.index', ['lang' => $locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'سجل الاستخدام' : 'Usage audit' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content">
<h1>{{ $locale === 'ar' ? 'استخدام مزودي AI' : 'AI provider usage' }}</h1>
<a href="{{ route('admin.usage.index', ['lang' => $locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<p>{{ $locale === 'ar'
? 'إيصالات الاستهلاك المخزنة خلال اليوم بتوقيت UTC فقط. كل عملة معروضة بشكل منفصل، دون تحويل عملات أو تقديرات.'
: 'Only persisted usage receipts for today (UTC). Each billed currency is shown separately, without FX conversion or estimated costs.' }}</p>
<p class="muted">{{ $locale === 'ar'
? 'الميزانيات اليومية المعروضة إعدادات محفوظة، وليست إثباتًا لتطبيق حد الإنفاق. قد تختلف عملة الإيصال عن عملة ميزانية المزود.'
: 'Daily budgets shown are saved settings, not proof of runtime enforcement. Receipt currency may differ from the provider budget currency.' }}</p>
<section class="panel" aria-labelledby="usage-title">
<h2 id="usage-title">{{ $locale === 'ar' ? 'تفصيل الاستخدام بحسب المزود والعملة' : 'Usage by provider and currency' }}</h2>
@if($rows->total() === 0)
<p role="status">{{ $locale === 'ar' ? 'لا يوجد مزودون مسجلون.' : 'No providers configured.' }}</p>
@else
<div class="scroll"><table>
<thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'المزود' : 'Provider' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الميزانية اليومية المحفوظة' : 'Configured daily budget' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'عملة الإيصالات' : 'Billed currency' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'عدد الإيصالات اليوم' : 'Today’s receipts' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'تكلفة اليوم (وحدات صغرى)' : 'Today’s cost (minor units)' }}</th>
</tr></thead>
<tbody>
@foreach($rows as $row)
<tr>
<td>{{ $row->display_name }}</td>
<td>{{ $row->daily_budget_minor }} {{ $row->currency }}</td>
<td>{{ $row->cost_currency ?? '—' }}</td>
<td>{{ $row->receipt_count }}</td>
<td>{{ $row->spend_minor }}</td>
</tr>
@endforeach
</tbody></table></div>
{{ $rows->links() }}
@endif
</section>
</main></div></body></html>
