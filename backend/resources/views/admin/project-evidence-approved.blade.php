@php($ar = $locale === 'ar')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'مراجعة الأدلة المعتمدة' : 'Approved evidence review' }} — WalidSite</title>
<style>
:root{--ink:#142E4D;--nav:#163B66;--edge:#D3E3ED;--bg:#F5F9FC;--muted:#55718B;--danger:#8B2C2C;--focus:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}a{color:#126DE1}
a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid var(--focus);outline-offset:3px}
.layout{display:grid;grid-template-columns:minmax(180px,220px) minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:22px}aside a{display:block;color:#fff;padding:9px 0;min-height:44px}
main{padding:clamp(16px,4vw,44px);min-width:0}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.panel{background:#fff;border:1px solid var(--edge);border-radius:16px;padding:clamp(16px,3vw,24px);margin-block:20px}
.muted{color:var(--muted)}.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:540px}
th,td{border-bottom:1px solid var(--edge);text-align:start;vertical-align:top;padding:12px}
code{white-space:normal;overflow-wrap:anywhere;user-select:text}
button{background:var(--danger);border:0;border-radius:9px;color:#fff;cursor:pointer;min-height:44px;padding:9px 16px;font:inherit}
fieldset{border:1px solid var(--edge);border-radius:10px}label{display:flex;gap:9px;align-items:start}
input[type=checkbox]{min-width:20px;min-height:20px}.skip{position:absolute;top:-100px;background:#fff;padding:12px}.skip:focus{top:10px}
@media(max-width:720px){.layout{grid-template-columns:1fr}aside{padding:12px}aside nav{display:flex;flex-wrap:wrap;gap:14px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $ar ? 'انتقل إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout">
<aside>
<h2>{{ $ar ? 'لوحة إدارة وليد سايت' : 'WalidSite Admin' }}</h2>
<nav aria-label="{{ $ar ? 'التنقل الإداري' : 'Admin navigation' }}">
<a href="{{ route('admin.home',['lang'=>$locale]) }}">{{ $ar ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.operations.projects',['lang'=>$locale]) }}">{{ $ar ? 'المشاريع والسيرة المهنية' : 'Projects & CV' }}</a>
<a href="{{ route('admin.audit.index',['lang'=>$locale]) }}">{{ $ar ? 'سجل التدقيق' : 'Audit & security' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $ar ? 'تسجيل الخروج' : 'Sign out' }}</button></form>
</aside>
<main id="content" tabindex="-1">
<div class="top">
<h1>{{ $ar ? 'إلغاء اعتماد الأدلة المنشورة' : 'Revoke approved public evidence' }}</h1>
<a lang="{{ $ar ? 'en' : 'ar' }}" hreflang="{{ $ar ? 'en' : 'ar' }}"
href="{{ route('admin.operations.projects.evidence.approved', ['project'=>$record->id,'lang'=>$ar ? 'en' : 'ar','page'=>$sources->currentPage()]) }}">{{ $ar ? 'English' : 'العربية' }}</a>
</div>
<p><a href="{{ route('admin.operations.projects',['lang'=>$locale]) }}">{{ $ar ? 'العودة إلى المشاريع' : 'Back to projects' }}</a></p>
<section class="panel" aria-labelledby="source-title">
<h2 id="source-title">{{ $record->title }} — {{ $ar ? 'أدلة عامة معتمدة' : 'Approved public sources' }} ({{ $sources->total() }})</h2>
<p class="muted">{{ $ar
? 'إلغاء الاعتماد يعيد المصدر إلى مراجعة خاصة، ولا يفتحه أو يتحقق منه عبر الشبكة. إذا كان آخر دليل صالح، سيُسحب المشروع المنشور تلقائياً دون حذف سجل المراجعة.'
: 'Revocation returns the source to private review without opening or fetching it. If no usable public proof remains, a published project is withdrawn automatically; review history is retained.' }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">{{ $ar ? 'لم يُحفظ القرار؛ راجع التأكيد.' : 'Decision not saved; verify confirmation.' }}</div>@endif
@if($sources->isEmpty())
<p role="status">{{ $ar ? 'لا توجد أدلة عامة معتمدة بهذه الصفحة.' : 'No approved public evidence on this page.' }}</p>
@else
<div class="scroll"><table>
<thead><tr><th scope="col">{{ $ar ? 'نوع الدليل' : 'Type' }}</th><th scope="col">{{ $ar ? 'المصدر الموافق عليه' : 'Approved source' }}</th><th scope="col">{{ $ar ? 'قرار الإلغاء' : 'Revoke decision' }}</th></tr></thead>
<tbody>
@foreach($sources as $source)
<tr>
<td>{{ $source->evidence_type }}</td>
<td><code>{{ $source->url }}</code></td>
<td><form method="post" action="{{ route('admin.operations.projects.evidence.revoke', ['project'=>$record->id,'evidence'=>$source->id,'lang'=>$locale,'page'=>$sources->currentPage()]) }}">
@csrf
<fieldset>
<legend>{{ $ar ? 'تأكيد إلغاء اعتماد المصدر' : 'Confirm source revocation' }}</legend>
<label><input type="checkbox" name="revocation_confirmed" value="1" required>
<span>{{ $ar ? 'أؤكد إلغاء صلاحية مشاركة هذا المصدر علناً؛ قد يؤدي إلى سحب المشروع.' : 'I confirm this source should no longer be shared publicly; the project may be withdrawn.' }}</span></label>
<button type="submit">{{ $ar ? 'إلغاء الاعتماد' : 'Revoke approved evidence' }}</button>
</fieldset>
</form></td>
</tr>
@endforeach
</tbody></table></div>
@endif
<nav aria-label="{{ $ar ? 'صفحات الأدلة المعتمدة' : 'Approved evidence pages' }}">{{ $sources->links() }}</nav>
</section>
</main>
</div>
</body>
</html>
