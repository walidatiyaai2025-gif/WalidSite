@php($ar = $locale === 'ar')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'مراجعة الأدلة الخاصة' : 'Private evidence review' }} — WalidSite</title>
<style>
:root{--nav:#163B66;--ink:#142E4D;--muted:#55718B;--bg:#F5F9FC;--edge:#D3E3ED;--focus:#00A999;--danger:#8B2C2C}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
.layout{display:grid;grid-template-columns:minmax(190px,230px) minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:white;padding:24px}aside a{display:block;color:white;padding:10px 0;min-height:44px}
main{padding:clamp(16px,4vw,48px);min-width:0}.top{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px}
.panel{border:1px solid var(--edge);border-radius:16px;background:white;padding:clamp(16px,3vw,24px);margin-block:20px}
.muted{color:var(--muted)}.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:550px}
th,td{border-bottom:1px solid var(--edge);text-align:start;vertical-align:top;padding:12px}
code{white-space:normal;overflow-wrap:anywhere;user-select:text}
button,.button{background:var(--nav);border:0;border-radius:8px;color:white;cursor:pointer;min-height:44px;padding:8px 16px;font:inherit}
a{color:#126DE1}a:focus-visible,button:focus-visible{outline:3px solid var(--focus);outline-offset:3px}
.skip{position:absolute;top:-100px;background:white;padding:12px}.skip:focus{top:10px}
.danger{background:var(--danger)}.inline{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
@media(max-width:720px){.layout{grid-template-columns:1fr}aside{padding:12px}aside nav{display:flex;gap:12px;flex-wrap:wrap}}
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
<h1>{{ $ar ? 'مراجعة الأدلة الخاصة المعلقة' : 'Pending private evidence review' }}</h1>
<a href="{{ route('admin.operations.projects.evidence.review',['project'=>$draft->id,'lang'=>$ar ? 'en' : 'ar','page'=>$evidence->currentPage()]) }}" lang="{{ $ar ? 'en' : 'ar' }}" hreflang="{{ $ar ? 'en' : 'ar' }}">{{ $ar ? 'English' : 'العربية' }}</a>
</div>
<p><a href="{{ route('admin.operations.projects',['lang'=>$locale]) }}">{{ $ar ? 'العودة إلى المشاريع' : 'Back to projects' }}</a></p>
<section class="panel" aria-labelledby="queue-title">
<h2 id="queue-title">{{ $draft->title }} — {{ $ar ? 'أدلة تحتاج إلى مراجعة' : 'Evidence awaiting review' }} ({{ $evidence->total() }})</h2>
<p class="muted">{{ $ar ? 'هذه روابط خاصة غير متحقق منها. لا تُفتح تلقائياً ولا تُمثّل إثباتاً أو موافقة للنشر. الرفض يحذف العنصر فقط.' : 'These are private, unverified URLs. They are never fetched or treated as proof or publication approval. Discard only removes the candidate.' }}</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert"><strong>{{ $ar ? 'لم تُنفذ العملية' : 'Action not saved' }}</strong></div>@endif
@if($evidence->isEmpty())
<p role="status">{{ $ar ? 'لا توجد أدلة معلقة في هذه الصفحة.' : 'No pending evidence on this page.' }}</p>
@else
<div class="scroll"><table>
<thead><tr><th scope="col">{{ $ar ? 'نوع الدليل' : 'Evidence type' }}</th><th scope="col">{{ $ar ? 'الرابط الخاص' : 'Private URL' }}</th><th scope="col">{{ $ar ? 'المراجعة' : 'Review action' }}</th></tr></thead>
<tbody>
@foreach($evidence as $candidate)
<tr>
<td>{{ $candidate->evidence_type }}</td>
<td><code>{{ $candidate->url }}</code></td>
<td><form method="post" action="{{ route('admin.operations.projects.evidence.discard',['project'=>$draft->id,'evidence'=>$candidate->id,'lang'=>$locale,'return'=>'review','page'=>$evidence->currentPage()]) }}">
@csrf @method('DELETE')
<button class="danger" type="submit">{{ $ar ? 'رفض الدليل غير المعتمد' : 'Discard unverified evidence' }}</button>
</form></td>
</tr>
@endforeach
</tbody></table></div>
@endif
<nav aria-label="{{ $ar ? 'صفحات مراجعة الأدلة' : 'Evidence review pages' }}">{{ $evidence->links() }}</nav>
</section>
</main></div>
</body></html>
