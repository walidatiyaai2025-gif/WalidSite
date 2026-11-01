@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'مراجعة المحتوى — وليد سايت' : 'Editorial review — Waleed Site' }}</title>
<style>
:root{color-scheme:light;--nav:#163B66;--ink:#142E4D;--link:#126DE1;--edge:#D3E3ED;--bg:#F5F9FC}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
a{color:var(--link)}a:focus-visible,button:focus-visible{outline:3px solid #00A999;outline-offset:3px}
.skip{position:absolute;top:-90px;inset-inline-start:10px;padding:12px;background:white}.skip:focus{top:10px}
.shell{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:22px}aside a{color:#fff;display:block;padding:9px;min-height:44px}
main{padding:clamp(18px,4vw,44px);min-width:0}.top{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px}
.panel{background:#fff;border:1px solid var(--edge);border-radius:15px;padding:20px;margin:20px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:16px}
.status{font-weight:700}.muted{color:#55718B}.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
button{font:inherit;min-height:44px;border:0;border-radius:9px;padding:10px 16px;background:var(--link);color:white;cursor:pointer}
button.secondary{background:var(--nav)}.article{border-bottom:1px solid var(--edge);padding:12px 0}
table{width:100%;border-collapse:collapse}td,th{text-align:start;border-bottom:1px solid var(--edge);padding:10px;overflow-wrap:anywhere}
@media(max-width:720px){.shell{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block}table{font-size:14px}}
</style>    @include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell"><aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'التنقل الإداري' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مزودو AI' : 'AI providers' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'مراجعة المحتوى' : 'Editorial review' }}</a>
</nav></aside><main id="content">
<div class="top"><h1>{{ $locale === 'ar' ? 'استوديو مراجعة المحتوى' : 'Content review studio' }}</h1>
<div class="actions">
<a href="{{ route('admin.content.index', array_filter(['lang'=>$locale === 'ar' ? 'en' : 'ar', 'article'=>$selected?->id])) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button class="secondary">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</div></div>
<p class="muted">{{ $locale === 'ar' ? 'النشر يدوي ويتطلب مراجعة موثّقة للمصادر والفحوص الفنية وحقوق الصور. لا يتم إرسال أي مادة إلى نموذج AI من هذه الشاشة.' : 'Publishing is a manual admin decision, allowed only after verified citations, technical checks and cleared image rights. This page does not invoke an AI model.' }}</p>
@if(session('status'))<p class="panel" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="panel">@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
<div class="grid"><section class="panel" aria-labelledby="articles-title">
<h2 id="articles-title">{{ $locale === 'ar' ? 'المقالات' : 'Articles' }} ({{ $articles->total() }})</h2>
@if($articles->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد مقالات للمراجعة.' : 'No articles to review.' }}</p>@else
@foreach($articles as $item)
<div class="article"><a href="{{ route('admin.content.index', ['article'=>$item->id,'lang'=>$locale]) }}">{{ $locale === 'ar' ? $item->title_ar : $item->title_en }}</a>
<div class="muted">{{ $item->status }} · {{ $locale === 'ar' ? 'الإصدارات' : 'Revisions' }}: {{ $item->revisions_count }}</div></div>
@endforeach
{{ $articles->links() }}
@endif</section>
<section class="panel" aria-labelledby="review-title">
<h2 id="review-title">{{ $locale === 'ar' ? 'المراجعة والاعتماد' : 'Review and decision' }}</h2>
@if(!$selected)<p>{{ $locale === 'ar' ? 'اختر مقالًا لمراجعة حالة الاعتماد والإصدارات.' : 'Select an article to inspect its revision and approval gates.' }}</p>
@else
<h3>{{ $locale === 'ar' ? $selected->title_ar : $selected->title_en }}</h3>
<p><strong>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}:</strong> {{ $selected->status }}</p>
<p><strong>{{ $locale === 'ar' ? 'جاهز للنشر' : 'Publication ready' }}:</strong> {{ $selected->isReadyToPublish() ? ($locale === 'ar' ? 'نعم' : 'Yes') : ($locale === 'ar' ? 'لا' : 'No') }}</p>
<p><strong>{{ $locale === 'ar' ? 'حقوق الصورة الرئيسية' : 'Hero image rights' }}:</strong>
{{ $selected->hero_asset_id ? ($selected->heroAsset?->isPubliclyUsable() ? ($locale === 'ar' ? 'مجازة' : 'Cleared') : ($locale === 'ar' ? 'غير مجازة' : 'Not cleared')) : ($locale === 'ar' ? 'لا توجد صورة' : 'No image') }}</p>
@if($selected->status === 'published')
<form method="post" action="{{ route('admin.content.unpublish', ['article'=>$selected->id,'lang'=>$locale]) }}">@csrf
<button type="submit" class="secondary">{{ $locale === 'ar' ? 'إلغاء النشر' : 'Unpublish article' }}</button></form>
@elseif($selected->isReadyToPublish())
<form method="post" action="{{ route('admin.content.publish', ['article'=>$selected->id,'lang'=>$locale]) }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'اعتماد ونشر الإصدار الموثّق' : 'Approve and publish verified revision' }}</button></form>
@else
<p role="status">{{ $locale === 'ar' ? 'النشر محجوب حتى اجتياز فحوص المصادر والمحتوى وحقوق الصور.' : 'Publishing blocked until citation, technical and image-rights gates pass.' }}</p>
@endif
<h3>{{ $locale === 'ar' ? 'سجل الإصدارات' : 'Revision history' }}</h3>
@if($revisions->isEmpty())<p>{{ $locale === 'ar' ? 'لم تُحفظ إصدارات بعد.' : 'No revisions saved.' }}</p>
@else
<table><thead><tr><th scope="col">{{ $locale === 'ar' ? 'الإصدار' : 'Revision' }}</th><th scope="col">{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th><th scope="col">{{ $locale === 'ar' ? 'التحقق' : 'Evidence gate' }}</th></tr></thead>
<tbody>@foreach($revisions as $revision)<tr><th scope="row">{{ $revision->revision_no }}</th><td>{{ $revision->status }}</td>
<td>{{ $revision->hasVerifiedEvidence() ? ($locale === 'ar' ? 'اجتاز' : 'Passed') : ($locale === 'ar' ? 'غير مكتمل' : 'Not passed') }}</td></tr>@endforeach</tbody></table>
@endif
<p class="muted">{{ $locale === 'ar' ? 'المسودات ومحتوى الإصدارات وروابط المصادر الخاصة لا تُعرض في هذه الصفحة. الموافقة لا تتجاوز فحوص النشر.' : 'This review summary omits draft bodies and private source URLs. Approval never bypasses publication gates.' }}</p>
@endif
</section></div>
</main></div></body></html>
