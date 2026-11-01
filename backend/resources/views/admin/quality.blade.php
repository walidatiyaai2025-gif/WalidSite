@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $locale === 'ar' ? 'بوابات الجودة — وليد سايت' : 'Quality gates — Waleed Site' }}</title>
<style>
:root{--nav:#163B66;--ink:#142E4D;--link:#126DE1;--edge:#D3E3ED;--bg:#F5F9FC;--ok:#176B4D;--bad:#8B2C2C}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}.layout{display:grid;grid-template-columns:230px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:24px}aside a{display:block;color:#fff;min-height:44px;padding:8px 0}
main{padding:clamp(20px,4vw,48px);min-width:0}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.panel{background:#fff;border:1px solid var(--edge);border-radius:16px;padding:20px;margin:20px 0}.scroll{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:780px}th,td{padding:10px;border-bottom:1px solid var(--edge);text-align:start;vertical-align:top}
.pass{color:var(--ok);font-weight:700}.block{color:var(--bad);font-weight:700}.muted{color:#55718B}
button{background:var(--nav);color:#fff;border:0;border-radius:9px;min-height:44px;padding:9px 14px;font:inherit}
a{color:var(--link)}a:focus-visible,button:focus-visible{outline:3px solid #00A999;outline-offset:3px}
.skip{position:absolute;top:-100px;inset-inline-start:10px;background:#fff;padding:10px}.skip:focus{top:10px}
@media(max-width:720px){.layout{grid-template-columns:1fr}aside{padding:14px}aside a{display:inline-block;margin-inline-end:12px}}
</style>
</head><body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout"><aside>
<h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'Waleed Site Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Overview' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مراجعة المحتوى' : 'Editorial review' }}</a>
<a href="{{ route('admin.media.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'استوديو الصور' : 'Image studio' }}</a>
<a href="{{ route('admin.quality.index', ['lang'=>$locale]) }}" aria-current="page">{{ $locale === 'ar' ? 'بوابات الجودة' : 'Quality gates' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form>
</aside><main id="content" tabindex="-1">
<div class="top"><h1>{{ $locale === 'ar' ? 'بوابات الجودة' : 'Quality gates' }}</h1>
<a href="{{ route('admin.quality.index', ['lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a></div>
<p>{{ $locale === 'ar'
? 'تعرض هذه الصفحة حقائق المراجعة المحفوظة وحالات فشل مهام AI فقط. لا تعرض نصوص المسودات أو روابط المصادر أو مفاتيح التخزين أو أسرار المزودين.'
: 'This page shows persisted review facts and AI failure states only. Draft bodies, citation URLs, storage keys and provider secrets are never rendered.' }}</p>
@if(session('status'))<div class="panel" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="panel" role="alert"><strong>{{ $locale === 'ar' ? 'لم يتم تغيير المهمة' : 'The job was not changed' }}</strong>
<ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif

<section class="panel" aria-labelledby="editorial-title">
<h2 id="editorial-title">{{ $locale === 'ar' ? 'عوائق النشر للمسودات الحالية' : 'Current draft publish blockers' }} ({{ $qualityRows->total() }})</h2>
@if($qualityRows->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد مسودات حالية لفحص بوابات النشر.' : 'No current drafts require publication-gate review.' }}</p>
@else
<div class="scroll"><table>
<thead><tr>
<th>{{ $locale === 'ar' ? 'المقال' : 'Article' }}</th>
<th>{{ $locale === 'ar' ? 'المراجعة' : 'Revision' }}</th>
<th>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th>
<th>{{ $locale === 'ar' ? 'الاستشهادات' : 'Citations' }}</th>
<th>{{ $locale === 'ar' ? 'تقني' : 'Technical' }}</th>
<th>{{ $locale === 'ar' ? 'الدليل' : 'Evidence' }}</th>
<th>{{ $locale === 'ar' ? 'حقوق الصورة' : 'Image rights' }}</th>
<th>{{ $locale === 'ar' ? 'جاهز للنشر' : 'Publish ready' }}</th>
</tr></thead><tbody>
@foreach($qualityRows as $row)
@php($pass = fn(bool $value) => $value ? ($locale === 'ar' ? 'ناجح' : 'Pass') : ($locale === 'ar' ? 'محظور' : 'Blocked'))
<tr>
<td><a href="{{ route('admin.content.index', ['article' => $row['article_id'], 'lang' => $locale]) }}">{{ $locale === 'ar' ? $row['title_ar'] : $row['title_en'] }}</a></td>
<td>r{{ $row['revision_no'] }}</td>
<td>{{ $row['revision_status'] }}</td>
<td class="{{ $row['citations_passed'] ? 'pass' : 'block' }}">{{ $pass($row['citations_passed']) }}</td>
<td class="{{ $row['technical_passed'] ? 'pass' : 'block' }}">{{ $pass($row['technical_passed']) }}</td>
<td class="{{ $row['evidence_passed'] ? 'pass' : 'block' }}">{{ $pass($row['evidence_passed']) }}</td>
<td class="{{ $row['image_rights_passed'] ? 'pass' : 'block' }}">{{ $pass($row['image_rights_passed']) }}</td>
<td class="{{ $row['publication_ready'] ? 'pass' : 'block' }}">{{ $pass($row['publication_ready']) }}</td>
</tr>
@endforeach
</tbody></table></div>
<nav aria-label="{{ $locale === 'ar' ? 'صفحات مراجعة المسودات' : 'Editorial draft pages' }}">
{{ $qualityRows->links() }}
</nav>
@endif
</section>

<section class="panel" aria-labelledby="failures-title">
<h2 id="failures-title">{{ $locale === 'ar' ? 'فشل وإعادة محاولة مهام AI' : 'AI failures and retries' }} ({{ $failedJobs->total() }})</h2>
<p class="muted">{{ $locale === 'ar'
? 'إعادة المحاولة الآن تزيل مهلة الانتظار فقط من مهمة retry قابلة للاسترداد. لا تعيد إحياء failed ولا تزيد الحد الأقصى للمحاولات.'
: 'Retry now only removes the wait from an existing recoverable retry. It never revives failed work or increases the maximum attempt count.' }}</p>
@if($failedJobs->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد مهام فاشلة أو في انتظار إعادة المحاولة.' : 'No failed or retrying AI jobs are recorded.' }}</p>
@else
<div class="scroll"><table>
<thead><tr>
<th>{{ $locale === 'ar' ? 'المهمة' : 'Job' }}</th>
<th>{{ $locale === 'ar' ? 'النوع' : 'Type' }}</th>
<th>{{ $locale === 'ar' ? 'المزود' : 'Provider' }}</th>
<th>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th>
<th>{{ $locale === 'ar' ? 'المحاولات' : 'Attempts' }}</th>
<th>{{ $locale === 'ar' ? 'المحاولة التالية' : 'Next attempt' }}</th>
<th>{{ $locale === 'ar' ? 'رمز الخطأ' : 'Error code' }}</th>
<th>{{ $locale === 'ar' ? 'إجراء' : 'Action' }}</th>
</tr></thead><tbody>
@foreach($failedJobs as $job)
<tr>
<td><code>{{ $job->id }}</code></td><td>{{ $job->job_type }}</td><td>{{ $job->provider_name }}</td>
<td>{{ $job->status }}</td><td>{{ $job->attempt_count }}/{{ $job->max_attempts }}</td>
<td>{{ $job->next_attempt_at ?? '—' }}</td><td>{{ $job->last_error_code ?? '—' }}</td>
<td>
@if($job->status === 'retry' && $job->attempt_count < $job->max_attempts)
<form method="post" action="{{ route('admin.quality.retry', ['job'=>$job->id,'lang'=>$locale]) }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'أعد المحاولة الآن' : 'Retry now' }}</button></form>
@else
<span class="muted">{{ $locale === 'ar' ? 'نهائي / غير قابل للإعادة' : 'Terminal / not retryable' }}</span>
@endif
</td>
</tr>
@endforeach
</tbody></table></div>
<nav aria-label="{{ $locale === 'ar' ? 'صفحات مهام AI الفاشلة' : 'AI failure pages' }}">
{{ $failedJobs->links() }}
</nav>
@endif
</section>
</main></div></body></html>
