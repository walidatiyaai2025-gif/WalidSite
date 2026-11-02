@php($locale = $locale ?? (request()->query('lang') === 'ar' ? 'ar' : 'en'))
@php($ar = $locale === 'ar')
@php($domain = $filters['domain'] ?? 'all')
@php($allActions = array_values(array_unique(array_merge($providerActions ?? [], $editorialActions ?? [], $mediaActions ?? []))))
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'التدقيق والأمان — وليد سايت' : 'Audit & security — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999;--warn:#8A5A00}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link);overflow-wrap:anywhere}
a:focus-visible,button:focus-visible,select:focus-visible,input:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-120px;inset-inline-start:12px;padding:12px;background:white;z-index:5}.skip:focus{top:12px}
.shell{display:grid;grid-template-columns:230px minmax(0,1fr);min-height:100vh}aside{background:var(--nav);color:#fff;padding:24px}aside a{color:#fff;display:block;min-height:44px;padding:8px 0}
main{min-width:0;padding:clamp(18px,4vw,48px)}h1,h2{color:var(--nav)}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.panel{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:18px}.notice{border-inline-start:5px solid var(--warn)}
.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:14px}.field label{display:block;font-weight:700;margin-bottom:6px}
.field select,.field input{display:block;width:100%;min-height:44px;border:1px solid #97B4C8;border-radius:9px;padding:8px;background:#fff;color:var(--ink);font:inherit}
.actions{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:16px}button{background:var(--link);color:#fff;border:0;border-radius:9px;min-height:44px;padding:8px 18px;font:inherit;font-weight:700;cursor:pointer}
.actions a{display:inline-flex;align-items:center;min-height:44px;font-weight:700;text-underline-offset:3px}.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:700px}
th,td{padding:11px;text-align:start;vertical-align:top;border-bottom:1px solid var(--border)}code{font:14px/1.5 ui-monospace,monospace;overflow-wrap:anywhere}.muted{color:#55718B}
.field-tags{display:flex;flex-wrap:wrap;gap:6px}.field-tags code{padding:3px 6px;background:#EAF5FB;border-radius:6px}.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px}
@media(max-width:720px){.shell{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block;margin-inline-end:12px}.panel{padding:14px}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $ar ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell">
<aside><h2>{{ $ar ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $ar ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $ar ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $ar ? 'مزودو AI' : 'AI providers' }}</a>
<a href="{{ route('admin.routing.index', ['lang'=>$locale]) }}">{{ $ar ? 'توجيه AI' : 'AI routing' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $ar ? 'استوديو المحتوى' : 'Content studio' }}</a>
<a href="{{ route('admin.media.index', ['lang'=>$locale]) }}">{{ $ar ? 'استوديو الصور' : 'Image studio' }}</a>
<a href="{{ route('admin.quality.index', ['lang'=>$locale]) }}">{{ $ar ? 'بوابات الجودة' : 'Quality gates' }}</a>
<a href="{{ route('admin.approvals.index', ['lang'=>$locale]) }}">{{ $ar ? 'الموافقات' : 'Approvals' }}</a>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}" aria-current="page">{{ $ar ? 'التدقيق والأمان' : 'Audit & security' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $ar ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="top">
<div>
<h1>{{ $ar ? 'التدقيق والأمان' : 'Audit & security' }}</h1>
<p class="muted"><strong>{{ $ar ? 'سجل تدقيق إعدادات مزودي AI' : 'AI provider configuration audit' }}</strong> —
{{ $ar ? 'مع قرارات المحتوى وحقوق الوسائط المسجلة.' : 'plus recorded editorial and media-rights decisions.' }}</p>
</div>
<a href="{{ route('admin.audit.index', array_filter([
'lang'=>$ar ? 'en' : 'ar',
'domain'=>$domain !== 'all' ? $domain : null,
'provider'=>$filters['provider'],
'action'=>$filters['action'],
'day'=>$filters['day'],
])) }}">{{ $ar ? 'English' : 'العربية' }}</a>
</div>
<p class="muted">{{ $ar
? 'هذه أحداث محفوظة وليست مراقبة أمنية لحظية. لا نعرض المفاتيح أو قيم الحقول القديمة والجديدة أو البريد الإلكتروني للمستخدم أو نصوص المسودات أو الطلبات الخاصة. الأوقات بتوقيت UTC.'
: 'These are persisted events, not a live security monitor. Credentials, old/new values, user email, draft bodies and private request payloads are never shown. Times are UTC.' }}</p>

<form class="panel" action="{{ route('admin.audit.index') }}" method="get" role="search" aria-label="{{ $ar ? 'تصفية سجل التدقيق' : 'Filter audit history' }}">
<input type="hidden" name="lang" value="{{ $locale }}">
<div class="fields">
<div class="field"><label for="audit-domain">{{ $ar ? 'المجال' : 'Domain' }}</label>
<select id="audit-domain" name="domain">
@foreach(['all'=>($ar?'الكل':'All domains'),'provider'=>($ar?'إعدادات المزود':'Provider settings'),'editorial'=>($ar?'قرارات المحتوى':'Editorial decisions'),'media'=>($ar?'حقوق الوسائط':'Media rights')] as $key=>$label)
<option value="{{ $key }}" @selected($domain===$key)>{{ $label }}</option>
@endforeach
</select></div>
<div class="field"><label for="audit-provider">{{ $ar ? 'المزود' : 'Provider' }}</label>
<select name="provider" id="audit-provider">
<option value="">{{ $ar ? 'جميع المزودين' : 'All providers' }}</option>
@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($filters['provider'] === $provider->id)>{{ $provider->display_name }}</option>@endforeach
</select></div>
<div class="field"><label for="audit-action">{{ $ar ? 'نوع الحدث' : 'Action' }}</label>
<select name="action" id="audit-action">
<option value="">{{ $ar ? 'كل الأحداث' : 'All events' }}</option>
@foreach($allActions as $action)<option value="{{ $action }}" @selected($filters['action'] === $action)>{{ str_replace('_', ' ', $action) }}</option>@endforeach
</select></div>
<div class="field"><label for="audit-day">{{ $ar ? 'اليوم (UTC)' : 'UTC day' }}</label>
<input id="audit-day" type="date" name="day" max="{{ $today }}" value="{{ $filters['day'] }}"></div>
</div>
<div class="actions">
<button type="submit">{{ $ar ? 'تطبيق التصفية' : 'Apply filters' }}</button>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}">{{ $ar ? 'مسح التصفية' : 'Clear filters' }}</a>
<a href="{{ route('admin.audit.index', array_filter([
'lang'=>$locale,'domain'=>$domain !== 'all' ? $domain : null,'provider'=>$filters['provider'],
'action'=>$filters['action'],'day'=>$filters['day'],'format'=>'csv'
])) }}" aria-label="{{ $ar ? 'تصدير أحداث التدقيق المصفاة CSV' : 'Export filtered audit events as CSV' }}">{{ $ar ? 'تصدير CSV' : 'Export CSV' }}</a>
</div>
<p class="muted">{{ $ar ? 'التصدير محدود بأحدث 1000 حدث من كل مصدر ويعلن الاقتطاع في ترويسة الاستجابة.' : 'Export is bounded to the newest 1000 events per source and reports truncation in the response header.' }}</p>
</form>

<section class="panel" aria-labelledby="provider-records">
<h2 id="provider-records">{{ $ar ? 'الأحداث المسجلة' : 'Recorded events' }} ({{ $events->total() }})</h2>
<p class="muted">{{ $ar ? 'تغييرات إعدادات مزودي AI فقط — دون أسرار.' : 'AI provider configuration changes only — redacted.' }}</p>
@if($events->isEmpty())
<p role="status">{{ $ar ? 'لا توجد أحداث إعدادات مطابقة للتصفية الحالية.' : 'No configuration events match these filters.' }}</p>
@else
<div class="scroll"><table>
<caption class="muted">{{ $ar ? 'تدقيق تغييرات إعدادات مزودي AI — دون أسرار' : 'AI provider settings changes — redacted' }}</caption>
<thead><tr><th scope="col">{{ $ar ? 'الوقت (UTC)' : 'Time (UTC)' }}</th><th scope="col">{{ $ar ? 'المزود' : 'Provider' }}</th><th scope="col">{{ $ar ? 'الحدث' : 'Action' }}</th><th scope="col">{{ $ar ? 'أسماء الحقول المتغيرة' : 'Changed field names' }}</th></tr></thead>
<tbody>
@foreach($events as $event)
<tr><td><time datetime="{{ $event->created_at }}">{{ $event->created_at }}</time></td>
<td>@if($event->deleted_at){{ $event->display_name }} <span class="muted">({{ $ar ? 'مؤرشف' : 'Archived' }})</span>@else<a href="{{ route('admin.providers.show', ['provider'=>$event->provider_id,'lang'=>$locale]) }}">{{ $event->display_name }}</a>@endif</td>
<td>{{ $ar ? match($event->action){'created'=>'إنشاء','updated'=>'تحديث','tested'=>'اختبار اتصال','deleted'=>'أرشفة',default=>'حدث آخر'} : match($event->action){'created'=>'Created','updated'=>'Updated','tested'=>'Connection test','deleted'=>'Archived',default=>'Other'} }}</td>
<td>@if($event->fields===[])<span class="muted">{{ $ar ? 'لا توجد أسماء حقول عامة' : 'No public field names' }}</span>@else<div class="field-tags">@foreach($event->fields as $field)<code dir="ltr">{{ $field }}</code>@endforeach</div>@endif</td></tr>
@endforeach
</tbody></table></div>
{{ $events->links() }}
@endif
</section>

<section class="panel" aria-labelledby="decision-records">
<h2 id="decision-records">{{ $ar ? 'قرارات المحتوى وحقوق الوسائط' : 'Editorial & media decisions' }} ({{ $editorialEvents->total() }})</h2>
@if($editorialEvents->isEmpty())
<p role="status">{{ $ar ? 'لا توجد قرارات محتوى أو حقوق وسائط مطابقة.' : 'No editorial or media-rights decisions match these filters.' }}</p>
@else
<div class="scroll"><table>
<caption class="muted">{{ $ar ? 'أحداث قرار آمنة بلا نصوص مسودات أو مسارات ملفات' : 'Safe decision events without draft text or file paths' }}</caption>
<thead><tr><th scope="col">{{ $ar ? 'الوقت (UTC)' : 'Time (UTC)' }}</th><th scope="col">{{ $ar ? 'المجال' : 'Domain' }}</th><th scope="col">{{ $ar ? 'العنصر' : 'Subject' }}</th><th scope="col">{{ $ar ? 'الحدث' : 'Action' }}</th><th scope="col">{{ $ar ? 'السبب' : 'Reason' }}</th></tr></thead>
<tbody>
@foreach($editorialEvents as $event)
@php($isMedia = in_array($event->action, $mediaActions, true))
<tr>
<td><time datetime="{{ $event->created_at }}">{{ $event->created_at }}</time></td>
<td><span class="badge">{{ $isMedia ? ($ar ? 'حقوق الوسائط' : 'Media rights') : ($ar ? 'تحرير' : 'Editorial') }}</span></td>
<td>@if($isMedia)<code dir="ltr">{{ $event->reason }}</code>@elseif($event->article_id)<a href="{{ route('admin.content.index', ['article'=>$event->article_id,'lang'=>$locale]) }}">{{ $ar ? ($event->title_ar ?: $event->title_en) : ($event->title_en ?: $event->title_ar) }}</a>@else<span class="muted">{{ $ar ? 'محتوى محذوف أو غير متاح' : 'Deleted or unavailable content' }}</span>@endif</td>
<td><code dir="ltr">{{ $event->action }}</code></td>
<td>{{ $event->reason ?: '—' }}</td>
</tr>
@endforeach
</tbody></table></div>
{{ $editorialEvents->links() }}
@endif
</section>

<section class="panel notice" aria-labelledby="access-history">
<h2 id="access-history">{{ $ar ? 'سجل الدخول والوصول' : 'Login and access history' }}</h2>
<p>{{ $ar
? 'غير متاح في مخطط البيانات الحالي. لا تعرض هذه الشاشة سجل دخول أو عناوين IP غير محفوظة، ولا تخترع أحداثًا أمنية.'
: 'Unavailable in the current data schema. This screen does not invent login events or IP history that the application does not persist.' }}</p>
</section>
</main></div>
</body></html>
