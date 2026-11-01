<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'سياسة النشر الآلي — وليد سايت' : 'Editorial automation — Waleed Site' }}</title>
<style>
:root{color-scheme:light;--nav:#163B66;--ink:#142E4D;--edge:#D3E3ED;--bg:#F5F9FC}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
a{color:#126DE1}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #00A999;outline-offset:3px}
.shell{max-width:1160px;margin:auto;padding:clamp(16px,4vw,40px)}
nav{display:flex;gap:18px;flex-wrap:wrap}nav a{min-height:44px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:18px}
.panel{background:white;border:1px solid var(--edge);border-radius:14px;padding:20px;margin:16px 0}
label{display:block;margin:12px 0}input,select{font:inherit;max-width:100%;padding:8px;border:1px solid #7795B0;border-radius:6px}
button{font:inherit;background:#126DE1;color:white;padding:10px 18px;border:0;border-radius:8px;min-height:44px;cursor:pointer}
table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:9px;overflow-wrap:anywhere;border-bottom:1px solid var(--edge)}
.muted{color:#426782}.warning{font-weight:650;color:#734300}
</style>
@include('partials.web-fonts')
</head>
<body><main class="shell">
<nav aria-label="{{ $locale === 'ar' ? 'التنقل' : 'Navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'لوحة الإدارة' : 'Dashboard' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مراجعة المحتوى' : 'Content review' }}</a>
<a href="{{ route('admin.editorial.index', ['lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
</nav>
<h1>{{ $locale === 'ar' ? 'سياسة النشر والتحديث' : 'Editorial publishing policy' }}</h1>
<p class="warning">{{ $locale === 'ar'
? 'النشر الآلي معطّل افتراضيًا. المحتوى عالي المخاطر يتطلب قرارًا يدويًا من المالك ولا يُنشر تلقائيًا. ينتهي عرض المحتوى المجدول بعد مهلة المراجعة.'
: 'Automatic publishing is disabled by default. High-risk content requires an owner decision and is never auto-published. Scheduled content expires into review at the configured refresh date.' }}</p>
@if(session('status'))<p class="panel" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="panel" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="grid">
<section class="panel" aria-labelledby="policy-title">
<h2 id="policy-title">{{ $locale === 'ar' ? 'إعدادات النشر' : 'Publishing settings' }}</h2>
<form method="post" action="{{ route('admin.editorial.policy', ['lang'=>$locale]) }}">
@csrf
<label for="enabled">{{ $locale === 'ar' ? 'حالة النشر التلقائي' : 'Automatic publishing' }}</label>
<select id="enabled" name="enabled">
<option value="0" @selected(!$policy?->enabled)>{{ $locale === 'ar' ? 'متوقف' : 'Disabled' }}</option>
<option value="1" @selected($policy?->enabled)>{{ $locale === 'ar' ? 'مفعل' : 'Enabled' }}</option>
</select>
<label for="max-per-tick">{{ $locale === 'ar' ? 'أقصى عدد بكل تشغيل (1–20)' : 'Maximum per tick (1–20)' }}</label>
<input id="max-per-tick" name="max_per_tick" type="number" min="1" max="20" required value="{{ $policy?->max_per_tick ?? 10 }}">
<label for="refresh-days">{{ $locale === 'ar' ? 'مدة صلاحية النشر بالأيام (1–365)' : 'Review refresh after days (1–365)' }}</label>
<input id="refresh-days" name="refresh_days" type="number" min="1" max="365" required value="{{ $policy?->refresh_days ?? 30 }}">
<p class="muted">{{ $locale === 'ar' ? 'لا يبدأ التشغيل إلا بعد إعداد cron على السيرفر؛ لا توجد مفاتيح أو نتائج مزيفة.' : 'A real server cron must be configured separately. No model calls or fabricated checks occur here.' }}</p>
<button>{{ $locale === 'ar' ? 'حفظ السياسة' : 'Save policy' }}</button></form>
</section>
<section class="panel" aria-labelledby="schedule-title">
<h2 id="schedule-title">{{ $locale === 'ar' ? 'جدولة مقال منخفض المخاطر' : 'Schedule reviewed low-risk article' }}</h2>
@if($candidates->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد مقالات موثقة مؤهلة للجدولة.' : 'No eligible, validated articles to schedule.' }}</p>
@else
<form method="post" action="{{ route('admin.editorial.schedule', ['lang'=>$locale]) }}">
@csrf
<label for="article">{{ $locale === 'ar' ? 'المقال' : 'Article' }}</label>
<select name="article_id" id="article" required>
@foreach($candidates as $item)
<option value="{{ $item->id }}">{{ $locale === 'ar' ? $item->title_ar : $item->title_en }}</option>
@endforeach
</select>
<label for="due-at">{{ $locale === 'ar' ? 'موعد النشر' : 'Publish no earlier than' }}</label>
<input type="datetime-local" name="due_at" id="due-at" required>
<label><input type="checkbox" name="confirmed" value="1" required> {{ $locale === 'ar' ? 'راجعت المصادقة على المخاطر والمصادر' : 'I verified the independent evidence and risk approval' }}</label>
<button>{{ $locale === 'ar' ? 'حفظ الموعد' : 'Schedule' }}</button>
</form>
@endif
</section>
<section class="panel" aria-labelledby="manual-title">
<h2 id="manual-title">{{ $locale === 'ar' ? 'مراجعة ونشر يدوي مع توثيق السبب' : 'Manual review with recorded reason' }}</h2>
<p class="muted">{{ $locale === 'ar' ? 'الحالات عالية المخاطر متاحة للمالك فقط، مع الالتزام بمراجعة المصادر والفحوص وحقوق الصور.' : 'Only the owner can approve high-risk/professional content. Independent citation, technical and rights gates still apply.' }}</p>
@if($candidates->isEmpty())
<p>{{ $locale === 'ar' ? 'لا توجد مقالات جاهزة للمراجعة.' : 'No verified articles ready for review.' }}</p>
@else
@foreach($candidates as $candidate)
<form method="post" action="{{ route('admin.editorial.manual', ['article'=>$candidate->id,'lang'=>$locale]) }}">
@csrf
<p><strong>{{ $locale === 'ar' ? $candidate->title_ar : $candidate->title_en }}</strong></p>
<label for="reason-{{ $candidate->id }}">{{ $locale === 'ar' ? 'سبب الموافقة' : 'Approval reason' }}</label>
<select name="reason" id="reason-{{ $candidate->id }}" required>
<option value="source_reviewed">{{ $locale === 'ar' ? 'تمت مراجعة المصادر' : 'Sources independently reviewed' }}</option>
<option value="technical_reviewed">{{ $locale === 'ar' ? 'تمت المراجعة الفنية' : 'Technical validation reviewed' }}</option>
<option value="legal_reviewed">{{ $locale === 'ar' ? 'تمت مراجعة حقوق النشر' : 'Rights and legal review completed' }}</option>
</select>
<label><input type="checkbox" name="confirmed" value="1" required> {{ $locale === 'ar' ? 'أؤكد مسؤوليتي عن قرار النشر' : 'I confirm this publication decision' }}</label>
<button>{{ $locale === 'ar' ? 'مراجعة ونشر يدوي' : 'Record decision and publish' }}</button>
</form>
@endforeach
@endif
</section></div>
<section class="panel" aria-labelledby="queues"><h2 id="queues">{{ $locale === 'ar' ? 'الجدول المحفوظ' : 'Persisted schedule' }}</h2>
@if($schedules->isEmpty())<p>{{ $locale === 'ar' ? 'لا توجد مهام مجدولة.' : 'No scheduled publications.' }}</p>
@else
<table><thead><tr><th>{{ $locale === 'ar' ? 'المقال' : 'Article' }}</th><th>{{ $locale === 'ar' ? 'الإصدار' : 'Revision' }}</th><th>{{ $locale === 'ar' ? 'الحالة' : 'State' }}</th><th>{{ $locale === 'ar' ? 'الموعد' : 'Due' }}</th></tr></thead>
<tbody>@foreach($schedules as $item)<tr><td>{{ $locale === 'ar' ? $item->title_ar : $item->title_en }}</td><td>{{ $item->revision_no }}</td><td>{{ $item->status }}</td><td>{{ $item->due_at }}</td></tr>@endforeach</tbody></table>
@endif</section>
<section class="panel" aria-labelledby="events"><h2 id="events">{{ $locale === 'ar' ? 'سجل القرارات (بدون محتوى خاص)' : 'Decision trail (redacted)' }}</h2>
@if($events->isEmpty())<p>{{ $locale === 'ar' ? 'لا توجد أحداث حتى الآن.' : 'No decisions recorded.' }}</p>
@else
<table><thead><tr><th>{{ $locale === 'ar' ? 'الحدث' : 'Action' }}</th><th>{{ $locale === 'ar' ? 'السبب' : 'Reason' }}</th><th>{{ $locale === 'ar' ? 'التاريخ' : 'Date' }}</th></tr></thead>
<tbody>@foreach($events as $event)<tr><td>{{ $event->action }}</td><td>{{ $event->reason ?? '—' }}</td><td>{{ $event->created_at }}</td></tr>@endforeach</tbody></table>
@endif</section>
</main></body></html>
