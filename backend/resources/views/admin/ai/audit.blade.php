@php
$locale = request()->query('lang') === 'ar' ? 'ar' : 'en';
$fieldLabels = [
    'display_name' => ['en'=>'Provider name','ar'=>'اسم المزود'],
    'provider_type' => ['en'=>'Adapter type','ar'=>'نوع المحول'],
    'base_url' => ['en'=>'Endpoint changed','ar'=>'تغيير عنوان الخدمة'],
    'enabled' => ['en'=>'Enabled state','ar'=>'حالة التفعيل'],
    'concurrent_limit' => ['en'=>'Concurrency limit','ar'=>'حد التزامن'],
    'timeout_seconds' => ['en'=>'Timeout','ar'=>'مهلة الاتصال'],
    'daily_budget_minor' => ['en'=>'Daily budget','ar'=>'الميزانية اليومية'],
    'currency' => ['en'=>'Currency','ar'=>'العملة'],
    'routing_priority' => ['en'=>'Routing priority','ar'=>'أولوية التوجيه'],
    'secret_rotated' => ['en'=>'Credential replaced','ar'=>'تغيير المفتاح السري'],
    'secret_removed' => ['en'=>'Credential revoked','ar'=>'إبطال المفتاح السري'],
];
$actionLabels = [
    'created' => ['en'=>'Created','ar'=>'إنشاء'],
    'updated' => ['en'=>'Updated','ar'=>'تحديث'],
    'tested' => ['en'=>'Connection tested','ar'=>'فحص الاتصال'],
    'deleted' => ['en'=>'Archived','ar'=>'أرشفة'],
    'other' => ['en'=>'Other recorded action','ar'=>'إجراء مسجل آخر'],
];
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'سجل تغييرات مزود AI — وليد سايت' : 'AI provider change history — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link)}
a:focus-visible,button:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:12px;padding:12px;background:white;z-index:4}.skip:focus{top:12px}
.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:white;padding:24px}aside a{color:white;display:block;min-height:44px;padding:8px 0}
main{padding:clamp(18px,4vw,48px);min-width:0}
.panel,.notice{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:20px}
table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid var(--border);padding:12px;text-align:start;vertical-align:top}
th{color:var(--nav)}.scroll{overflow-x:auto}.muted{color:#55718B}
button{min-height:44px;border:1px solid white;border-radius:9px;background:transparent;color:white;padding:10px 16px;cursor:pointer;font:inherit}
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
<a href="{{ route('admin.home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نظرة عامة' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'كل المزودين' : 'All providers' }}</a>
<a href="{{ route('admin.providers.show', ['provider' => $provider->id, 'lang' => $locale]) }}">{{ $locale === 'ar' ? 'تحرير المزود' : 'Provider editor' }}</a>
<a href="{{ route('admin.providers.audit', ['provider' => $provider->id, 'lang' => $locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'سجل التغييرات' : 'Change history' }}</a>
<a href="{{ route('admin.routing.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'توجيه AI' : 'AI routing' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<h1>{{ $locale === 'ar' ? 'سجل تغييرات مزود AI' : 'AI provider change history' }}</h1>
<p><a href="{{ route('admin.providers.show', ['provider' => $provider->id, 'lang' => $locale]) }}">{{ $locale === 'ar' ? 'عودة لمحرر المزود' : 'Back to provider editor' }}</a>
· <a href="{{ route('admin.providers.audit', ['provider' => $provider->id, 'lang' => $locale === 'ar' ? 'en' : 'ar', 'page' => $events->currentPage()]) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a></p>
<p class="muted">{{ $provider->display_name }}</p>
<p class="notice">{{ $locale === 'ar'
? 'هذا سجل للعمليات المحفوظة، وليس فحصًا مباشرًا لصحة المزود. يظهر نوع الإجراء وأسماء الحقول المعتمدة فقط؛ لا تُعرض قيم الأسرار أو بياناتها أو تفاصيل الطلبات.'
: 'This is persisted audit history, not a live provider health check. Only approved action and field names are displayed; credential values, request payloads and private details are never shown.' }}</p>
<section class="panel" aria-labelledby="audit-title">
<h2 id="audit-title">{{ $locale === 'ar' ? 'أحداث التغيير المسجلة' : 'Recorded change events' }} ({{ $events->total() }})</h2>
@if($events->total() === 0)
<p role="status">{{ $locale === 'ar' ? 'لا توجد أحداث تغيير مسجلة لهذا المزود.' : 'No recorded changes for this provider.' }}</p>
@else
<div class="scroll"><table>
<thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'الوقت' : 'Time' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الإجراء' : 'Action' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الحقول المتأثرة' : 'Affected settings' }}</th>
</tr></thead>
<tbody>
@foreach($events as $event)
<tr>
<td><time datetime="{{ $event->created_at }}">{{ $event->created_at }}</time></td>
<td>{{ $actionLabels[$event->action][$locale] }}</td>
<td>
@if(count($event->changed_fields) === 0)
<span class="muted">{{ $locale === 'ar' ? 'دون تغييرات إعدادات مسجلة' : 'No setting changes recorded' }}</span>
@else
<ul>@foreach($event->changed_fields as $field)
<li>{{ $fieldLabels[$field][$locale] }}</li>
@endforeach</ul>
@endif
</td>
</tr>
@endforeach
</tbody></table></div>
<nav aria-label="{{ $locale === 'ar' ? 'صفحات سجل التغييرات' : 'Change history pages' }}">
{{ $events->links() }}
</nav>
@endif
</section>
</main></div></body></html>
