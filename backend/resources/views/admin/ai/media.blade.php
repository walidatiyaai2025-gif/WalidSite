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
a{color:var(--link)}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #00A999;outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:10px;padding:12px;background:white}.skip:focus{top:10px}
.shell{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:22px}aside a{color:#fff;display:block;min-height:44px;padding:8px}
main{padding:clamp(20px,4vw,42px);min-width:0}
.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.panel{border-radius:16px;background:white;border:1px solid var(--edge);padding:20px;margin:20px 0}
.records{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,350px),1fr));gap:18px}
.record{border:1px solid var(--edge);border-radius:10px;padding:18px;min-width:0;overflow-wrap:anywhere}
.muted{color:#55718B}.field{margin:12px 0}.field label{display:block;font-weight:700;margin-bottom:5px}.field select,.field input{width:100%;min-height:44px;border:1px solid #97B4C8;border-radius:9px;padding:8px 10px;font:inherit;background:white;color:var(--ink)}.jobs{width:100%;border-collapse:collapse}.jobs th,.jobs td{padding:9px;border-bottom:1px solid var(--edge);text-align:start;vertical-align:top}.scroll{overflow:auto}button{background:#163B66;color:white;border:0;border-radius:9px;min-height:44px;padding:10px 16px;font:inherit}
button:disabled{opacity:.6}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
@media(max-width:720px){.shell{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block}}
</style></head><body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell"><aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مراجعة المقالات' : 'Editorial review' }}</a>
<a href="{{ route('admin.media.index', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'حقوق الصور' : 'Media rights' }}</a>
</nav></aside><main id="content" tabindex="-1">
<div class="top"><h1>{{ $locale === 'ar' ? 'مراجعة حقوق الصور' : 'Media rights review' }}</h1>
<div class="actions">
<a href="{{ route('admin.media.index', ['lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</div></div>
<p>{{ $locale === 'ar' ? 'الحالات المعروضة محفوظة فعليًا. لا نعرض مفاتيح التخزين أو روابط الصور الخاصة، ولا نفترض أن الملكية أو الترخيص مثبت دون مراجعة.' : 'These are stored media records. Private storage paths and originals are never rendered; origin and licensing are not assumed to be verified.' }}</p>
<p class="muted">{{ $locale === 'ar' ? 'الصور المولدة بالذكاء الاصطناعي تبقى خاصة حتى التحقق من البصمة وملف الأصل ثم إقرار الترخيص وعدم انتحال هوية المالك. ويمكن إلغاء الإتاحة العامة فورًا في أي وقت.' : 'AI-generated artwork stays private until its bytes and provenance manifest are verified and an administrator attests publication licensing and no owner impersonation. Public eligibility can be revoked immediately at any time.' }}</p>
@if(session('status'))<p class="panel" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="panel" role="alert"><strong>{{ $locale === 'ar' ? 'تعذر تنفيذ الطلب' : 'The request could not be completed' }}</strong>
<ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif

<section class="panel" aria-labelledby="queue-title">
<h2 id="queue-title">{{ $locale === 'ar' ? 'توليد صورة تقنية' : 'Queue technical artwork' }}</h2>
<p class="muted">{{ $locale === 'ar'
    ? 'يتم الحجز في الطابور فقط هنا. التنفيذ الفعلي يستخدم المزود والنموذج المسجلين وميزانية المزود، وتبقى الصورة الناتجة خاصة وغير معتمدة حتى مراجعة الحقوق.'
    : 'This action only creates a durable queue reservation. Execution uses the registered provider/model and provider budget; generated artwork remains private and unapproved until rights review.' }}</p>
@if($drafts->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد مسودة حالية مؤهلة لتوليد صورة.' : 'No current draft is available for artwork generation.' }}</p>
@elseif($models->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا يوجد نموذج صور سليم ومفعّل وممول حاليًا.' : 'No healthy enabled funded image-generation model is currently available.' }}</p>
@else
<form method="post" action="{{ route('admin.media.queue', ['lang'=>$locale]) }}">@csrf
<div class="field"><label for="revision-id">{{ $locale === 'ar' ? 'المسودة الحالية' : 'Current draft' }}</label>
<select id="revision-id" name="revision_id" required>
@foreach($drafts as $draft)
<option value="{{ $draft->id }}">{{ $locale === 'ar' ? $draft->article->title_ar : $draft->article->title_en }} · r{{ $draft->revision_no }}</option>
@endforeach
</select></div>
<div class="field"><label for="model-id">{{ $locale === 'ar' ? 'النموذج المسجل' : 'Registered image model' }}</label>
<select id="model-id" name="model_id" required>
@foreach($models as $model)
<option value="{{ $model->id }}">{{ $model->provider->display_name }} · {{ $model->provider_model_id }} · {{ $model->provider->currency }}</option>
@endforeach
</select></div>
<div class="field"><label for="estimated-cost">{{ $locale === 'ar' ? 'حجز التكلفة بالوحدات الصغرى' : 'Cost reservation (minor units)' }}</label>
<p class="muted" id="reservation-help">{{ $locale === 'ar'
    ? 'أدخل تقديرًا واقعيًا لتكلفة تشغيل المزود بوحدات العملة الصغرى قبل الإرسال. هذا حجز ميزانية يدوي، وليس سعرًا مؤكدًا أو تسعيرًا آليًا؛ لا تدخل مبلغًا رمزيًا.'
    : 'Enter a realistic provider charge estimate in minor currency units before queueing. This is a manual budget reservation, not a quoted price or automatic pricing; do not enter a token amount.' }}</p>
<input id="estimated-cost" type="number" name="estimated_cost_minor" min="1" max="100000000" inputmode="numeric" aria-describedby="reservation-help" value="{{ old('estimated_cost_minor', '') }}" required autocomplete="off"></div>
<button type="submit">{{ $locale === 'ar' ? 'أضف مهمة توليد الصورة' : 'Queue artwork generation' }}</button>
</form>
@endif
</section>

<section class="panel" aria-labelledby="jobs-title">
<h2 id="jobs-title">{{ $locale === 'ar' ? 'حالة مهام الصور' : 'Artwork queue status' }} ({{ $imageJobs->total() }})</h2>
<p class="muted"><a href="{{ route('admin.quality.index', ['lang'=>$locale]) }}">{{ $locale === 'ar'
    ? 'راجع الأعطال وأعد محاولة المهام المؤهلة من بوابات الجودة'
    : 'Inspect failures and retry eligible jobs in Quality gates' }}</a></p>
@if($imageJobs->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد مهام توليد صور مسجلة.' : 'No artwork generation jobs are recorded.' }}</p>
@else
<div class="scroll"><table class="jobs">
<thead><tr>
<th>{{ $locale === 'ar' ? 'المهمة' : 'Job' }}</th>
<th>{{ $locale === 'ar' ? 'المزود' : 'Provider' }}</th>
<th>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th>
<th>{{ $locale === 'ar' ? 'المحاولات' : 'Attempts' }}</th>
<th>{{ $locale === 'ar' ? 'الحجز' : 'Reserved' }}</th>
<th>{{ $locale === 'ar' ? 'رمز الخطأ' : 'Error code' }}</th>
</tr></thead><tbody>
@foreach($imageJobs as $job)<tr>
<td><code dir="ltr">{{ $job->id }}</code></td>
<td>{{ $job->provider_name }}</td>
<td>{{ $locale === 'ar'
    ? match($job->status) {
        'queued' => 'في الطابور',
        'running' => 'قيد التنفيذ',
        'retry' => 'بانتظار إعادة المحاولة',
        'failed' => 'فشلت',
        'completed' => 'مكتملة',
        'cancelled' => 'ملغاة',
        default => $job->status,
    }
    : $job->status }}</td>
<td>{{ $job->attempt_count }}/{{ $job->max_attempts }}</td>
<td>{{ $job->reserved_budget_minor }} {{ $job->currency }}</td>
<td><code dir="ltr">{{ $job->last_error_code ?? '—' }}</code></td>
</tr>@endforeach
</tbody></table></div>
<nav aria-label="{{ $locale === 'ar' ? 'صفحات مهام توليد الصور' : 'Artwork queue pages' }}">
{{ $imageJobs->links() }}
</nav>
@endif
</section>

<section class="panel" aria-labelledby="media-title">
<h2 id="media-title">{{ $locale === 'ar' ? 'الأصول المسجلة' : 'Registered assets' }} ({{ $assets->total() }})</h2>
@if($assets->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد صور محفوظة للمراجعة.' : 'No media assets to review.' }}</p>
@else
<div class="records">@foreach($assets as $asset)
<article class="record">
<h3>{{ $asset->asset_key }}</h3>
<p><strong>{{ $locale === 'ar' ? 'المنشأ' : 'Origin' }}:</strong>
{{ $locale === 'ar'
    ? match($asset->origin) {
        'ai-generated' => 'مولدة بالذكاء الاصطناعي',
        'owner-supplied' => 'مقدمة من المالك',
        default => $asset->origin,
    }
    : $asset->origin }}</p>
<p><strong>{{ $locale === 'ar' ? 'النوع' : 'Type' }}:</strong> {{ $asset->mime_type }}</p>
<p><strong>{{ $locale === 'ar' ? 'حالة الحقوق' : 'Rights status' }}:</strong>
{{ $locale === 'ar'
    ? match($asset->rights_status) {
        'unverified' => 'غير متحقق منها',
        'cleared' => 'مراجعة ومعتمدة',
        'revoked' => 'ملغاة',
        default => $asset->rights_status,
    }
    : $asset->rights_status }}</p>
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
