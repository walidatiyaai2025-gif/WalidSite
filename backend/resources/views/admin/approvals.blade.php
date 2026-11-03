@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($ar = $locale === 'ar')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'الموافقات — وليد سايت' : 'Approvals — Waleed Site' }}</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--link:#126DE1;--border:#D3E3ED;--bg:#F5F9FC;--accent:#00A999;--warn:#8A5A00;--danger:#9D2530}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}
a{color:var(--link);overflow-wrap:anywhere}a:focus-visible,button:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.skip{position:absolute;top:-120px;inset-inline-start:12px;padding:12px;background:white;z-index:5}.skip:focus{top:12px}
.shell{display:grid;grid-template-columns:230px minmax(0,1fr);min-height:100vh}aside{background:var(--nav);color:#fff;padding:24px}aside a{color:#fff;display:block;min-height:44px;padding:8px 0}
main{min-width:0;padding:clamp(18px,4vw,48px)}h1,h2{color:var(--nav)}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.panel{background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-block:18px}.safe{border-inline-start:5px solid var(--accent)}.error{border-inline-start:5px solid var(--danger)}
.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:900px}th,td{padding:11px;text-align:start;vertical-align:top;border-bottom:1px solid var(--border)}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 9px;margin:2px}.bad{color:var(--danger);border-color:#E8B9BE}.warn{color:var(--warn);border-color:#E5CD9C}
.actions{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}.actions label{font-weight:700;display:block}.actions select{min-height:44px;padding:8px;border:1px solid #97B4C8;border-radius:9px;background:#fff;font:inherit}
button{min-height:44px;border:0;border-radius:9px;padding:8px 14px;font:inherit;font-weight:700;color:#fff;background:var(--link);cursor:pointer}button[value=reject]{background:var(--danger)}button[value=defer]{background:#6B7280}button:disabled{opacity:.55;cursor:not-allowed}
.muted{color:#55718B}.pager{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:16px}
@media(max-width:760px){.shell{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block;margin-inline-end:12px}.panel{padding:14px}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $ar ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell">
<aside>
<h2>{{ $ar ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $ar ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $ar ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $ar ? 'استوديو المحتوى' : 'Content studio' }}</a>
<a href="{{ route('admin.media.index', ['lang'=>$locale]) }}">{{ $ar ? 'استوديو الصور' : 'Image studio' }}</a>
<a href="{{ route('admin.quality.index', ['lang'=>$locale]) }}">{{ $ar ? 'بوابات الجودة' : 'Quality gates' }}</a>
<a href="{{ route('admin.approvals.index', ['lang'=>$locale]) }}" aria-current="page">{{ $ar ? 'الموافقات' : 'Approvals' }}</a>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}">{{ $ar ? 'التدقيق والأمان' : 'Audit & security' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf
<button type="submit">{{ $ar ? 'خروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="top">
<div>
<h1>{{ $ar ? 'الموافقات والمراجعة الاستثنائية' : 'Approvals and exception review' }}</h1>
<p class="muted">{{ $ar
? 'تعرض هذه الشاشة أحدث مراجعة فقط لكل مسودة. لا تعرض نص المسودة أو روابط الاستشهاد الخاصة أو أسرار المزود.'
: 'This screen shows only the latest review state for each draft. Draft bodies, private citation URLs and provider secrets are never displayed.' }}</p>
</div>
<a href="{{ route('admin.approvals.index', ['lang'=>$ar ? 'en' : 'ar']) }}" lang="{{ $ar ? 'en' : 'ar' }}" hreflang="{{ $ar ? 'en' : 'ar' }}">{{ $ar ? 'English' : 'العربية' }}</a>
</div>

@if(session('status'))<div role="status" class="panel safe">{{ session('status') }}</div>@endif
@if($errors->any())<div role="alert" class="panel error"><strong>{{ $ar ? 'لم يُنفذ القرار.' : 'Decision was not applied.' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="panel" aria-labelledby="pending-title">
<h2 id="pending-title">{{ $ar ? 'المراجعات المعلقة' : 'Pending reviews' }} ({{ $approvals->total() }})</h2>
@if($approvals->isEmpty())
<p role="status">{{ $ar ? 'لا توجد مسودات حالية بانتظار قرار.' : 'No current drafts are awaiting a decision.' }}</p>
@else
<div class="scroll"><table>
<caption class="muted">{{ $ar ? 'مراجعة آمنة للأدلة والجودة قبل النشر' : 'Safe evidence and quality review before publication' }}</caption>
<thead><tr>
<th scope="col">{{ $ar ? 'المحتوى' : 'Content' }}</th>
<th scope="col">{{ $ar ? 'حالة البوابات' : 'Gate state' }}</th>
<th scope="col">{{ $ar ? 'آخر قرار' : 'Last decision' }}</th>
<th scope="col">{{ $ar ? 'إجراء' : 'Action' }}</th>
</tr></thead>
<tbody>
@foreach($approvals as $item)
<tr>
<td>
<strong>{{ $ar ? ($item->title_ar ?: $item->title_en) : ($item->title_en ?: $item->title_ar) }}</strong><br>
<span class="muted">{{ $ar ? 'الإصدار' : 'Revision' }} {{ $item->revision_no }}</span>
</td>
<td>
<span class="badge {{ $item->evidence_passed ? '' : 'bad' }}">{{ $ar ? 'الدليل' : 'Evidence' }}: {{ $item->evidence_passed ? ($ar ? 'مكتمل' : 'passed') : ($ar ? 'ناقص' : 'blocked') }}</span>
<span class="badge {{ $item->technical_passed ? '' : 'bad' }}">{{ $ar ? 'الفحص التقني' : 'Technical' }}: {{ $item->technical_passed ? ($ar ? 'مكتمل' : 'passed') : ($ar ? 'ناقص' : 'blocked') }}</span>
<span class="badge {{ $item->image_rights_passed ? '' : 'bad' }}">{{ $ar ? 'حقوق الصورة' : 'Image rights' }}: {{ $item->image_rights_passed ? ($ar ? 'مكتملة' : 'passed') : ($ar ? 'ناقصة' : 'blocked') }}</span>
@if($item->requires_owner)<span class="badge warn">{{ $ar ? 'اعتماد المالك مطلوب' : 'Owner approval required' }}</span>@endif
</td>
<td>{{ $item->last_decision === 'manual_rejected' ? ($ar ? 'مرفوض' : 'Rejected') : ($item->last_decision === 'requires_review' ? ($ar ? 'مؤجل للمراجعة' : 'Deferred') : ($ar ? 'معلق' : 'Pending')) }}</td>
<td>
<form method="post" action="{{ route('admin.approvals.decision', ['article'=>$item->article_id, 'lang'=>$locale]) }}">
@csrf
<div class="actions">
<div><label for="reason-{{ $item->article_id }}">{{ $ar ? 'سبب موثّق' : 'Reviewed basis' }}</label>
<select id="reason-{{ $item->article_id }}" name="reason" required>
<option value="">{{ $ar ? 'اختر السبب' : 'Choose basis' }}</option>
<option value="source_reviewed">{{ $ar ? 'تمت مراجعة المصدر' : 'Source reviewed' }}</option>
<option value="technical_reviewed">{{ $ar ? 'تمت المراجعة التقنية' : 'Technical review complete' }}</option>
<option value="legal_reviewed">{{ $ar ? 'تمت المراجعة القانونية' : 'Legal review complete' }}</option>
</select></div>
<button type="submit" name="decision" value="approve"
    @disabled(! $item->independent_review_allowed || ($item->requires_owner && !auth()->user()?->hasRole('owner')))
    @if(! $item->independent_review_allowed) aria-describedby="independent-review-{{ $item->article_id }}" @endif>{{ $ar ? 'اعتماد ونشر' : 'Approve & publish' }}</button>
@if(! $item->independent_review_allowed)
    <span id="independent-review-{{ $item->article_id }}" role="status" class="muted">{{ $ar
        ? 'الاعتماد محجوب: يلزم مراجع مستقل عن كاتب المسودة وآخر محرر لها، ولا يجوز اعتماد إصدارات بلا سجل مؤلف.'
        : 'Approval blocked: independent reviewer required. The author, last editor, or unknown legacy author cannot approve.' }}</span>
@endif
<button type="submit" name="decision" value="reject">{{ $ar ? 'رفض' : 'Reject' }}</button>
<button type="submit" name="decision" value="defer">{{ $ar ? 'تأجيل' : 'Defer' }}</button>
</div>
</form>
</td>
</tr>
@endforeach
</tbody></table></div>
<div class="pager">{{ $approvals->links() }}</div>
@endif
</section>
<section class="panel">
<h2>{{ $ar ? 'حدود هذه الشاشة' : 'Scope boundary' }}</h2>
<p>{{ $ar
? 'الاعتماد لا يتجاوز بوابات الأدلة والحقوق. الإجراء عالي المخاطر أو الذي يتضمن ادعاءات مهنية يتطلب دور المالك. التأجيل يسجل حدث مراجعة ولا ينشر شيئًا.'
: 'Approval cannot bypass evidence or rights gates. High-risk or professional-claim content requires the owner role. Deferring records a review event and publishes nothing.' }}</p>
</section>
</main></div>
</body></html>
