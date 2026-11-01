@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'مراجعة الصور — وليد سايت' : 'Media rights review — Waleed Site' }}</title>
<style>
:root{--nav:#163B66;--link:#126DE1;--ink:#142E4D;--edge:#D3E3ED;--bg:#F5F9FC}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
a{color:var(--link)}a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid #00A999;outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:10px;padding:12px;background:white}.skip:focus{top:10px}
.shell{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:22px}aside a{color:#fff;display:block;min-height:44px;padding:8px}
main{padding:clamp(20px,4vw,42px);min-width:0}
.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.panel{border-radius:16px;background:white;border:1px solid var(--edge);padding:20px;margin:20px 0}
.records{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,350px),1fr));gap:18px}
.record{border:1px solid var(--edge);border-radius:10px;padding:18px;min-width:0;overflow-wrap:anywhere}
.muted{color:#55718B}button{background:#163B66;color:white;border:0;border-radius:9px;min-height:44px;padding:10px 16px;font:inherit}
button:disabled{opacity:.6}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
@media(max-width:720px){.shell{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block}}
</style></head><body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell"><aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مراجعة المقالات' : 'Editorial review' }}</a>
<a href="{{ route('admin.media.index', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'حقوق الصور' : 'Media rights' }}</a>
</nav></aside><main id="content">
<div class="top"><h1>{{ $locale === 'ar' ? 'مراجعة حقوق الصور' : 'Media rights review' }}</h1>
<div class="actions">
<a href="{{ route('admin.media.index', ['lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</div></div>
<p>{{ $locale === 'ar' ? 'الحالات المعروضة محفوظة فعليًا. لا نعرض مفاتيح التخزين أو روابط الصور الخاصة، ولا نفترض أن الملكية أو الترخيص مثبت دون مراجعة.' : 'These are stored media records. Private storage paths and originals are never rendered; origin and licensing are not assumed to be verified.' }}</p>
<p class="muted">{{ $locale === 'ar' ? 'الصور المولدة بالذكاء الاصطناعي تبقى خاصة حتى التحقق من البصمة وملف الأصل ثم إقرار الترخيص وعدم انتحال هوية المالك. ويمكن إلغاء الإتاحة العامة فورًا في أي وقت.' : 'AI-generated artwork stays private until its bytes and provenance manifest are verified and an administrator attests publication licensing and no owner impersonation. Public eligibility can be revoked immediately at any time.' }}</p>
@if(session('status'))<p class="panel" role="status">{{ session('status') }}</p>@endif
<section class="panel" aria-labelledby="media-title">
<h2 id="media-title">{{ $locale === 'ar' ? 'الأصول المسجلة' : 'Registered assets' }} ({{ $assets->total() }})</h2>
@if($assets->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد صور محفوظة للمراجعة.' : 'No media assets to review.' }}</p>
@else
<div class="records">@foreach($assets as $asset)
<article class="record">
<h3>{{ $asset->asset_key }}</h3>
<p><strong>{{ $locale === 'ar' ? 'المنشأ' : 'Origin' }}:</strong> {{ $asset->origin }}</p>
<p><strong>{{ $locale === 'ar' ? 'النوع' : 'Type' }}:</strong> {{ $asset->mime_type }}</p>
<p><strong>{{ $locale === 'ar' ? 'حالة الحقوق' : 'Rights status' }}:</strong> {{ $asset->rights_status }}</p>
<p><strong>{{ $locale === 'ar' ? 'مسموح بالنشر' : 'Publicly usable' }}:</strong>
{{ $asset->isPubliclyUsable() ? ($locale === 'ar' ? 'نعم' : 'Yes') : ($locale === 'ar' ? 'لا' : 'No') }}</p>
@if($asset->origin === 'ai-generated' && $asset->rights_status === 'unverified' && ! $asset->publishable)
<details class="panel" style="padding:14px;margin:14px 0">
<summary>{{ $locale === 'ar' ? 'مراجعة الأصل واعتماد حقوق الصورة' : 'Verify provenance and approve rights' }}</summary>
<p class="muted">{{ $locale === 'ar'
    ? 'لن تتم الموافقة إلا إذا تطابقت الصورة الخاصة مع بصمتها وملف الأصل المحفوظ. لا تظهر بيانات المزود أو الطلب في هذه الصفحة.'
    : 'Approval succeeds only when the private artwork bytes match the stored hash and provenance manifest. Provider and prompt details are never displayed here.' }}</p>
<form method="post" action="{{ route('admin.media.approve', ['asset'=>$asset->id,'lang'=>$locale]) }}">@csrf
<label for="approve-key-{{ $asset->id }}"><strong>{{ $locale === 'ar' ? 'اكتب مفتاح الأصل للتأكيد' : 'Type the asset key to confirm' }}</strong></label>
<input id="approve-key-{{ $asset->id }}" name="confirm_asset_key" maxlength="180" required autocomplete="off" style="display:block;width:100%;min-height:44px;margin:8px 0 12px;padding:8px">
<label style="display:block;margin:8px 0"><input type="checkbox" name="licensed_for_publication" value="1" required>
{{ $locale === 'ar' ? 'أؤكد أن هذه الصورة مرخصة للنشر.' : 'I confirm this artwork is licensed for publication.' }}</label>
<label style="display:block;margin:8px 0 14px"><input type="checkbox" name="no_owner_impersonation" value="1" required>
{{ $locale === 'ar' ? 'أؤكد أن الصورة لا تنتحل هوية المالك.' : 'I confirm the artwork does not impersonate the owner.' }}</label>
<button type="submit">{{ $locale === 'ar' ? 'تحقق واعتمد الحقوق' : 'Verify and approve rights' }}</button>
</form>
</details>
@endif
@if($asset->rights_status !== 'revoked' || $asset->publishable)
<form method="post" action="{{ route('admin.media.revoke', ['asset'=>$asset->id,'lang'=>$locale]) }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'إلغاء حقوق النشر العام' : 'Revoke public media rights' }}</button>
</form>
@else
<p class="muted" role="status">{{ $locale === 'ar' ? 'الإتاحة العامة ملغاة' : 'Public use revoked' }}</p>
@endif
</article>@endforeach</div>
{{ $assets->links() }}
@endif</section>
</main></div></body></html>
