@php($locale = $locale ?? (request()->query('lang') === 'ar' ? 'ar' : 'en'))
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'سجل تدقيق إعدادات AI — وليد سايت' : 'AI configuration audit — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}a{color:var(--link);overflow-wrap:anywhere}
a:focus-visible,button:focus-visible,select:focus-visible,input:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-120px;inset-inline-start:12px;padding:12px;background:white;z-index:5}.skip:focus{top:12px}
.shell{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:24px}aside a{color:#fff;display:block;min-height:44px;padding:8px 0}
main{min-width:0;padding:clamp(18px,4vw,48px)}h1,h2{color:var(--nav)}
.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.panel{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:18px}
.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:14px}
.field label{display:block;font-weight:700;margin-bottom:6px}.field select,.field input{display:block;width:100%;min-height:44px;border:1px solid #97B4C8;border-radius:9px;padding:8px;background:#fff;color:var(--ink);font:inherit}
.actions{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:16px}
button{background:var(--link);color:#fff;border:0;border-radius:9px;min-height:44px;padding:8px 18px;font:inherit;font-weight:700;cursor:pointer}
.actions a{display:inline-flex;align-items:center;min-height:44px;font-weight:700;text-underline-offset:3px}
.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:640px}
th,td{padding:11px;text-align:start;vertical-align:top;border-bottom:1px solid var(--border)}
code{font:14px/1.5 ui-monospace,monospace;overflow-wrap:anywhere}
.muted{color:#55718B}.field-tags{display:flex;flex-wrap:wrap;gap:6px}
.field-tags code{padding:3px 6px;background:#EAF5FB;border-radius:6px}
@media(max-width:720px){.shell{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block;margin-inline-end:12px}.panel{padding:14px}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell">
<aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مزودو AI' : 'AI providers' }}</a>
<a href="{{ route('admin.routing.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'توجيه AI' : 'AI routing' }}</a>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'تدقيق إعدادات AI' : 'AI settings audit' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="top">
<h1>{{ $locale === 'ar' ? 'سجل تدقيق إعدادات مزودي AI' : 'AI provider configuration audit' }}</h1>
<a href="{{ route('admin.audit.index', array_filter(['lang'=>$locale === 'ar' ? 'en' : 'ar','provider'=>$filters['provider'],'action'=>$filters['action'],'day'=>$filters['day']])) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
</div>
<p class="muted">{{ $locale === 'ar'
? 'هذه أحداث تغيير الإعدادات المحفوظة فقط، وليست سجل تسجيل دخول أو مراقبة أمنية لحظية. لا نعرض المفاتيح أو قيم الحقول القديمة والجديدة أو هوية المستخدم. الأوقات بتوقيت UTC.'
: 'These are recorded configuration-change events only, not a login history or live security monitor. Credentials, old/new values and actor identities are never shown. Times are UTC.' }}</p>

<form class="panel" action="{{ route('admin.audit.index') }}" method="get" role="search" aria-label="{{ $locale === 'ar' ? 'تصفية سجل الإعدادات' : 'Filter configuration audit' }}">
<input type="hidden" name="lang" value="{{ $locale }}">
<div class="fields">
<div class="field">
<label for="audit-provider">{{ $locale === 'ar' ? 'المزود' : 'Provider' }}</label>
<select name="provider" id="audit-provider">
<option value="">{{ $locale === 'ar' ? 'جميع المزودين' : 'All providers' }}</option>
@foreach($providers as $provider)
<option value="{{ $provider->id }}" @selected($filters['provider'] === $provider->id)>{{ $provider->display_name }}</option>
@endforeach
</select>
</div>
<div class="field">
<label for="audit-action">{{ $locale === 'ar' ? 'نوع التغيير' : 'Action' }}</label>
<select name="action" id="audit-action">
<option value="">{{ $locale === 'ar' ? 'كل الأحداث' : 'All events' }}</option>
@foreach(['created'=>'Created','updated'=>'Updated','tested'=>'Connection test','deleted'=>'Archived'] as $key => $english)
<option value="{{ $key }}" @selected($filters['action'] === $key)>{{ $locale === 'ar' ? match($key) {'created'=>'إنشاء','updated'=>'تحديث','tested'=>'اختبار اتصال',default=>'أرشفة'} : $english }}</option>
@endforeach
</select>
</div>
<div class="field">
<label for="audit-day">{{ $locale === 'ar' ? 'اليوم (UTC)' : 'UTC day' }}</label>
<input id="audit-day" type="date" name="day" max="{{ $today }}" value="{{ $filters['day'] }}">
</div>
</div>
<div class="actions">
<button type="submit">{{ $locale === 'ar' ? 'تطبيق التصفية' : 'Apply filters' }}</button>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مسح التصفية' : 'Clear filters' }}</a>
<a href="{{ route('admin.audit.index', array_filter(['lang'=>$locale,'provider'=>$filters['provider'],'action'=>$filters['action'],'day'=>$filters['day'],'format'=>'csv'])) }}" aria-label="{{ $locale === 'ar' ? 'تصدير أحداث الإعدادات المصفاة CSV' : 'Export filtered configuration events as CSV' }}">{{ $locale === 'ar' ? 'تصدير CSV' : 'Export CSV' }}</a>
</div>
<p class="muted">{{ $locale === 'ar' ? 'يقتصر كل ملف تصدير على أول 1000 حدث مطابق، ويظهر تنبيه تقني عند اقتطاع النتائج. لا يحتوي على قيم خاصة.' : 'Exports contain at most the newest 1000 matching events and report truncation in the response header. Private values are excluded.' }}</p>
</form>

<section class="panel" aria-labelledby="audit-records">
<h2 id="audit-records">{{ $locale === 'ar' ? 'الأحداث المسجلة' : 'Recorded events' }} ({{ $events->total() }})</h2>
@if($events->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد أحداث إعدادات مطابقة للتصفية الحالية.' : 'No configuration events match these filters.' }}</p>
@else
<div class="scroll">
<table>
<caption class="muted">{{ $locale === 'ar' ? 'تدقيق تغييرات إعدادات مزودي AI — دون أسرار' : 'AI provider settings changes — redacted' }}</caption>
<thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'الوقت (UTC)' : 'Time (UTC)' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'المزود' : 'Provider' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الحدث' : 'Action' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'أسماء الحقول المتغيرة' : 'Changed field names' }}</th>
</tr></thead>
<tbody>
@foreach($events as $event)
<tr>
<td><time datetime="{{ $event->created_at }}">{{ $event->created_at }}</time></td>
<td>
@if($event->deleted_at)
{{ $event->display_name }} <span class="muted">({{ $locale === 'ar' ? 'مؤرشف' : 'Archived' }})</span>
@else
<a href="{{ route('admin.providers.show', ['provider'=>$event->provider_id,'lang'=>$locale]) }}">{{ $event->display_name }}</a>
@endif
</td>
<td>{{ $locale === 'ar' ? match($event->action) {
'created'=>'إنشاء','updated'=>'تحديث','tested'=>'اختبار اتصال','deleted'=>'أرشفة',default=>'حدث آخر'
} : match($event->action) {
'created'=>'Created','updated'=>'Updated','tested'=>'Connection test','deleted'=>'Archived',default=>'Other'
} }}</td>
<td>
@if($event->fields === [])
<span class="muted">{{ $locale === 'ar' ? 'لا توجد أسماء حقول عامة' : 'No public field names' }}</span>
@else
<div class="field-tags">@foreach($event->fields as $field)<code dir="ltr">{{ $field }}</code>@endforeach</div>
@endif
</td>
</tr>
@endforeach
</tbody>
</table>
</div>
{{ $events->links() }}
@endif
</section>
</main></div>
</body></html>
