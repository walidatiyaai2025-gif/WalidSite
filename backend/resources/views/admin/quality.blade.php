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
.incident-filters{display:flex;flex-wrap:wrap;gap:14px;align-items:end;margin-block:18px}.incident-filters .filter-field{flex:1 1 210px;min-width:0}
.incident-filters label{display:block;font-weight:700;margin-bottom:6px}.incident-filters input,.incident-filters select{width:100%;min-height:44px;border:1px solid #97B4C8;border-radius:9px;padding:8px 10px;background:#fff;color:var(--ink);font:inherit}
.incident-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.incident-actions a{display:inline-flex;align-items:center;min-height:44px;font-weight:700}
.incident-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:12px}.incident-summary p{margin:0;overflow-wrap:anywhere}
.incident-steps{min-width:500px}a:focus-visible,button:focus-visible,.incident-filters :is(input,select):focus-visible{outline:3px solid #00A999;outline-offset:3px}
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
<a href="{{ route('admin.quality.index', array_filter([
    'lang' => $locale === 'ar' ? 'en' : 'ar',
    'job_status' => $jobStatus ?: null,
    'job_id' => $jobFilterId ?: null,
    'inspect_job' => $inspectJobId ?: null,
    'jobs_page' => request()->query('jobs_page'),
    'drafts_page' => request()->query('drafts_page'),
], static fn ($value) => $value !== null)) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a></div>
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
<td>{{ $locale === 'ar'
    ? match($row['revision_status']) {
        'draft' => 'مسودة',
        'validated' => 'تم التحقق',
        'rejected' => 'مرفوض',
        default => $row['revision_status'],
    }
    : $row['revision_status'] }}</td>
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
<form class="incident-filters" method="get" action="{{ route('admin.quality.index') }}" role="search" aria-label="{{ $locale === 'ar' ? 'البحث في أعطال AI' : 'Search AI incidents' }}">
<input type="hidden" name="lang" value="{{ $locale }}">
<div class="filter-field"><label for="incident-status">{{ $locale === 'ar' ? 'حالة المهمة' : 'Job status' }}</label>
<select id="incident-status" name="job_status">
<option value="" @selected($jobStatus === '')>{{ $locale === 'ar' ? 'كل الأعطال' : 'All incidents' }}</option>
<option value="retry" @selected($jobStatus === 'retry')>{{ $locale === 'ar' ? 'بانتظار إعادة المحاولة' : 'Retrying' }}</option>
<option value="failed" @selected($jobStatus === 'failed')>{{ $locale === 'ar' ? 'فشلت نهائيًا' : 'Failed' }}</option>
</select></div>
<div class="filter-field"><label for="incident-id">{{ $locale === 'ar' ? 'معرّف المهمة الدقيق (اختياري)' : 'Exact job ID (optional)' }}</label>
<input id="incident-id" name="job_id" type="text" inputmode="text" maxlength="36" value="{{ $jobFilterId }}" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
<div class="incident-actions"><button type="submit">{{ $locale === 'ar' ? 'ابحث عن العطل' : 'Find incident' }}</button>
<a href="{{ route('admin.quality.index', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'امسح المرشحات' : 'Clear filters' }}</a></div>
</form>
@if($inspectedJob)
<section class="panel" aria-labelledby="incident-inspection-title" id="incident-inspection">
<h3 id="incident-inspection-title">{{ $locale === 'ar' ? 'فحص مهمة AI المحفوظة' : 'Inspect persisted AI incident' }}</h3>
<p class="muted">{{ $locale === 'ar' ? 'معلومات تشخيص آمنة من قاعدة البيانات فقط. لا تعرض الخطوات نص الطلب أو المخرجات أو سر المزود.' : 'Read-only checkpoint metadata. Prompts, outputs, provider credentials and worker identities are never shown.' }}</p>
<div class="incident-summary">
<p><strong>{{ $locale === 'ar' ? 'المعرّف' : 'Job ID' }}:</strong> <code dir="ltr">{{ $inspectedJob->id }}</code></p>
<p><strong>{{ $locale === 'ar' ? 'نوع المهمة' : 'Job type' }}:</strong> {{ $inspectedJob->job_type }}</p>
<p><strong>{{ $locale === 'ar' ? 'المزود' : 'Provider' }}:</strong> {{ $inspectedJob->provider_name }}</p>
<p><strong>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}:</strong> {{ $locale === 'ar' ? ($inspectedJob->status === 'retry' ? 'بانتظار إعادة المحاولة' : 'فشلت') : $inspectedJob->status }}</p>
<p><strong>{{ $locale === 'ar' ? 'المحاولات' : 'Attempts' }}:</strong> {{ $inspectedJob->attempt_count }}/{{ $inspectedJob->max_attempts }}</p>
<p><strong>{{ $locale === 'ar' ? 'آخر تحديث' : 'Last update' }}:</strong> <time>{{ $inspectedJob->updated_at }}</time></p>
<p><strong>{{ $locale === 'ar' ? 'رمز الخطأ' : 'Error code' }}:</strong> <code dir="ltr">{{ $inspectedJob->last_error_code ?? '—' }}</code></p>
</div>
<h4>{{ $locale === 'ar' ? 'ملخص نقاط المعالجة المحفوظة' : 'Persisted checkpoint summary' }}</h4>
@if($checkpointRows->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد نقاط معالجة مسجلة لهذه المهمة.' : 'No checkpoint records for this job.' }}</p>
@else
<div class="scroll"><table class="incident-steps">
<thead><tr><th>{{ $locale === 'ar' ? 'الترتيب' : 'Order' }}</th><th>{{ $locale === 'ar' ? 'حالة النقطة' : 'Checkpoint state' }}</th><th>{{ $locale === 'ar' ? 'رقم المحاولة' : 'Attempt number' }}</th><th>{{ $locale === 'ar' ? 'وقت الاكتمال' : 'Completion time' }}</th></tr></thead>
<tbody>@foreach($checkpointRows as $step)
<tr><td>{{ $step->position + 1 }}</td><td>{{ $locale === 'ar' ? match($step->status) {
'pending' => 'لم تبدأ', 'running' => 'قيد التنفيذ', 'completed' => 'مكتملة', 'failed' => 'فشلت', default => 'غير معروفة'
} : (in_array($step->status, ['pending','running','completed','failed'], true) ? $step->status : 'unknown') }}</td>
<td>{{ $step->attempt_no }}</td><td>{{ $step->completed_at ?? '—' }}</td></tr>
@endforeach</tbody></table></div>
@endif
<div class="incident-actions">
@if($inspectedJob->status === 'retry' && $inspectedJob->attempt_count < $inspectedJob->max_attempts && ! $inspectionLeased)
<form method="post" action="{{ route('admin.quality.retry', ['job' => $inspectedJob->id, 'lang' => $locale, 'inspect_job' => $inspectedJob->id]) }}">@csrf
<button type="submit">{{ $locale === 'ar' ? 'إعادة محاولة هذه المهمة الآن' : 'Retry this job now' }}</button></form>
@else
<span class="muted">{{ $locale === 'ar' ? 'إعادة المحاولة غير مسموحة لهذه المهمة حاليًا.' : 'Retry is not available for this job.' }}</span>
@endif
<a href="{{ route('admin.quality.index', array_filter(['lang'=>$locale,'job_status'=>$jobStatus ?: null,'job_id'=>$jobFilterId ?: null], static fn($v) => $v !== null)) }}">{{ $locale === 'ar' ? 'العودة إلى نتائج البحث' : 'Back to incident results' }}</a>
</div>
</section>
@endif
@if($failedJobs->isEmpty())
<p role="status">{{ $jobStatus !== '' || $jobFilterId !== ''
    ? ($locale === 'ar' ? 'لا توجد أعطال تطابق هذه المرشحات.' : 'No incidents match these filters.')
    : ($locale === 'ar' ? 'لا توجد مهام فاشلة أو في انتظار إعادة المحاولة.' : 'No failed or retrying AI jobs are recorded.') }}</p>
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
<td><a href="{{ route('admin.quality.index', array_filter([
'lang' => $locale,
'inspect_job' => $job->id,
'job_status' => $jobStatus ?: null,
'job_id' => $jobFilterId ?: null,
'jobs_page' => $failedJobs->currentPage() > 1 ? $failedJobs->currentPage() : null,
'drafts_page' => $qualityRows->currentPage() > 1 ? $qualityRows->currentPage() : null,
], static fn($v) => $v !== null)) }}" aria-label="{{ $locale === 'ar' ? 'فحص تفاصيل المهمة ' : 'Inspect job ' }}{{ $job->id }}"><code dir="ltr">{{ $job->id }}</code></a></td><td>{{ $job->job_type }}</td><td>{{ $job->provider_name }}</td>
<td>{{ $locale === 'ar'
    ? match($job->status) {
        'retry' => 'بانتظار إعادة المحاولة',
        'failed' => 'فشلت',
        default => $job->status,
    }
    : $job->status }}</td><td>{{ $job->attempt_count }}/{{ $job->max_attempts }}</td>
<td>{{ $job->next_attempt_at ?? '—' }}</td><td><code dir="ltr">{{ $job->last_error_code ?? '—' }}</code></td>
<td>
@if($job->status === 'retry' && $job->attempt_count < $job->max_attempts)
<form method="post" action="{{ route('admin.quality.retry', ['job'=>$job->id,'lang'=>$locale]) }}">@csrf
<button type="submit" aria-label="{{ $locale === 'ar'
    ? 'إعادة محاولة المهمة '.$job->id
    : 'Retry job '.$job->id.' now' }}">{{ $locale === 'ar' ? 'أعد المحاولة الآن' : 'Retry now' }}</button></form>
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
