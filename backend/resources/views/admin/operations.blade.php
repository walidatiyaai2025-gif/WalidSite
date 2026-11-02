@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($ar = $locale === 'ar')
@php($titles = ['github' => ['GitHub Sync', 'مزامنة GitHub'], 'projects' => ['Projects & CV', 'المشاريع والسيرة المهنية'], 'audience' => ['Audience & consent', 'الجمهور والموافقة'], 'analytics' => ['Verified analytics', 'التحليلات المتحقّق منها']])
@php($visibilityLabels = ['public' => ['Public', 'عام'], 'private' => ['Private', 'خاص']])
@php($statusLabels = ['completed' => ['Completed', 'مكتمل'], 'in_progress' => ['In progress', 'قيد التنفيذ'], 'research' => ['Research', 'بحث'], 'archived' => ['Archived', 'مؤرشف']])
@php($purposeLabels = ['account_activity' => ['Account activity', 'نشاط الحساب'], 'editorial_updates' => ['Editorial updates', 'تحديثات المحتوى'], 'product_updates' => ['Product updates', 'تحديثات المنتج']])
@php($channelLabels = ['email' => ['Email', 'البريد الإلكتروني'], 'push' => ['Push notification', 'إشعار فوري']])
@php($title = $titles[$section][$ar ? 1 : 0])
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $title }} — WalidSite</title>
<style>
:root{--nav:#163B66;--ink:#142E4D;--muted:#55718B;--bg:#F5F9FC;--edge:#D3E3ED;--link:#126DE1;--accent:#00A999}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}.layout{display:grid;grid-template-columns:minmax(190px,230px) minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:24px}aside a{display:block;color:#fff;padding:8px 0;min-height:44px}
aside a[aria-current=page]{font-weight:700;text-decoration:underline;text-decoration-thickness:3px}
main{padding:clamp(16px,4vw,48px);min-width:0}.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.panel{background:white;border:1px solid var(--edge);border-radius:16px;padding:clamp(16px,3vw,24px);margin:20px 0}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:12px}
.card{background:var(--bg);border:1px solid var(--edge);padding:16px;border-radius:12px;min-width:0;overflow-wrap:anywhere}
.card strong{display:block;font-size:24px}.muted{color:var(--muted)}.alert{border-inline-start:4px solid #BF7F12}
.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.filters > div{flex:1 1 180px}
label{font-weight:700;display:block;margin-bottom:5px}
input,select,textarea{width:100%;font:inherit;border:1px solid #97B4C8;border-radius:8px;padding:8px;min-height:44px;color:var(--ink);background:#fff}
textarea{min-height:90px}button,.button{display:inline-flex;justify-content:center;align-items:center;background:var(--nav);color:#fff;min-height:44px;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer;text-decoration:none}
a{color:var(--link)}a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:580px}th,td{padding:11px;text-align:start;border-bottom:1px solid var(--edge);vertical-align:top;overflow-wrap:anywhere}
.skip{position:absolute;top:-100px;inset-inline-start:12px;background:white;padding:12px}.skip:focus{top:10px}
.inline-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.safe{color:#176B4D}.warn{color:#8B2C2C}
details{border:1px solid var(--edge);padding:10px;border-radius:8px}details[open]{background:var(--bg)}summary{cursor:pointer;min-height:44px;padding:8px}
.field-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,245px),1fr));gap:12px;margin-block:12px}
@media(max-width:720px){.layout{grid-template-columns:1fr}aside{padding:12px}aside nav{display:flex;flex-wrap:wrap;gap:8px}aside nav a{display:inline-flex;padding:8px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;scroll-behavior:auto!important;transition:none!important}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $ar ? 'انتقل إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout"><aside>
<h2>{{ $ar ? 'لوحة إدارة وليد سايت' : 'WalidSite Admin' }}</h2>
<nav aria-label="{{ $ar ? 'التنقل الإداري' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $ar ? 'الرئيسية' : 'Overview' }}</a>
@foreach(['github','projects','audience','analytics'] as $nav)
<a href="{{ route('admin.operations.'.$nav, ['lang'=>$locale]) }}" @if($nav===$section) aria-current="page" @endif>{{ $titles[$nav][$ar ? 1 : 0] }}</a>
@endforeach
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $ar ? 'المحتوى والموافقات' : 'Editorial & approvals' }}</a>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}">{{ $ar ? 'سجل التدقيق' : 'Audit & security' }}</a>
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $ar ? 'تسجيل الخروج' : 'Sign out' }}</button></form>
</aside><main id="content" tabindex="-1">
<div class="top"><h1>{{ $title }}</h1><a href="{{ route('admin.operations.'.$section, array_merge(['lang'=>$ar ? 'en' : 'ar'], $section==='projects' ? array_filter(['status'=>$statusFilter ?? null, 'visibility'=>$visibilityFilter ?? null]) : ($section==='audience' ? ['purpose'=>$purpose,'channel'=>$channel] : ($section==='analytics' ? ['day'=>$day,'window'=>$window] : ($section==='github' ? array_filter(['repo'=>$selectedRepo['full_name'] ?? null]) : []))))) }}" lang="{{ $ar ? 'en' : 'ar' }}" hreflang="{{ $ar ? 'en' : 'ar' }}">{{ $ar ? 'English' : 'العربية' }}</a></div>
@if(session('status'))<div role="status" class="panel safe">{{ session('status') }}</div>@endif
@if(session('warning'))<div role="alert" class="panel alert">{{ $ar ? 'تعذر التحقق من بيانات GitHub العامة.' : session('warning') }}</div>@endif
@if($errors->any())<div role="alert" class="panel warn"><strong>{{ $ar ? 'تعذر حفظ العملية' : 'The operation was not saved' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($section === 'github')
<section class="panel" aria-labelledby="github-title">
<h2 id="github-title">{{ $ar ? 'قائمة المستودعات العامة المسموح بها' : 'Allowlisted public repository verification' }}</h2>
<p class="muted">{{ $ar ? 'يتم التحقق من الحالة العامة عبر GitHub فقط. المستودعات الخاصة أو غير المتاحة لا تظهر أسماؤها ولا تُنشر.' : 'Only currently verified public GitHub repositories are displayed. Unavailable or private repository identifiers are never published.' }}</p>
<div class="cards">
<div class="card"><span>{{ $ar ? 'إعدادات القائمة' : 'Configured entries' }}</span><strong>{{ $configuredCount }}</strong></div>
<div class="card"><span>{{ $ar ? 'تم التحقق' : 'Verified public' }}</span><strong>{{ $repos->where('status','verified')->count() }}</strong></div>
<div class="card"><span>{{ $ar ? 'غير متاح' : 'Unverified/unavailable' }}</span><strong>{{ $repos->where('status','!=','verified')->count() }}</strong></div>
</div>
<form method="post" action="{{ route('admin.operations.github.refresh', ['lang'=>$locale]) }}" class="inline-actions" style="margin-top:20px">
@csrf<button type="submit">{{ $ar ? 'تحقق مجددًا من المستودعات' : 'Refresh public verification' }}</button>
</form>
<p class="muted">{{ $ar ? 'لا يغيّر التحقق الصلاحيات أو ينشر مستودعًا. يجب اعتماد أي تغيير على قائمة السماح بصورة منفصلة.' : 'Refreshing does not grant access or publish a repository. Allowlist changes require separate governance.' }}</p>
@if($repos->isEmpty())<p role="status">{{ $ar ? 'لا توجد مستودعات مهيأة.' : 'No repositories are configured.' }}</p>@else
<div class="scroll"><table><thead><tr><th scope="col">{{ $ar ? 'المستودع' : 'Repository' }}</th><th scope="col">{{ $ar ? 'الحالة' : 'Status' }}</th><th scope="col">{{ $ar ? 'آخر تحقق' : 'Verified at' }}</th><th scope="col">{{ $ar ? 'مراجعة' : 'Inspect' }}</th></tr></thead><tbody>
@foreach($repos as $repo)
<tr>@if($repo['status']==='verified' && is_string($repo['full_name'] ?? null))
<td><a href="{{ $repo['url'] }}" target="_blank" rel="noopener noreferrer">{{ $repo['full_name'] }}</a></td>
<td class="safe">{{ $ar ? 'عام ومتحقق منه' : 'Verified public' }}</td><td><time datetime="{{ $repo['verified_at'] }}">{{ $repo['verified_at'] }}</time></td><td><a href="{{ route('admin.operations.github', ['lang'=>$locale,'repo'=>$repo['full_name']]) }}">{{ $ar ? 'فحص البيانات' : 'Inspect metadata' }}</a></td>
@else
<td>{{ $ar ? 'مستودع غير متاح' : 'Repository unavailable' }}</td><td class="warn">{{ $ar ? 'تعذر التحقق' : 'Verification unavailable' }}</td><td>—</td><td>—</td>
@endif</tr>
@endforeach</tbody></table></div>
@endif
@if($selectedRepo !== null)
<section class="panel" aria-labelledby="selected-repository-title">
<h3 id="selected-repository-title">{{ $ar ? 'مراجعة بيانات مستودع عام متحقق منه' : 'Selected verified public repository' }}</h3>
<p><strong>{{ $selectedRepo['full_name'] }}</strong></p>
<p>{{ $selectedRepo['description'] ?? ($ar ? 'لا يوجد وصف عام' : 'No public description provided') }}</p>
<dl><dt>{{ $ar ? 'اللغة' : 'Language' }}</dt><dd>{{ $selectedRepo['language'] ?? '—' }}</dd>
<dt>{{ $ar ? 'مصدر الإثبات' : 'Verification source' }}</dt><dd>GitHub public REST</dd>
<dt>{{ $ar ? 'آخر تحقق' : 'Verified at' }}</dt><dd><time datetime="{{ $selectedRepo['verified_at'] }}">{{ $selectedRepo['verified_at'] }}</time></dd></dl>
<form method="post" action="{{ route('admin.operations.github.refresh', ['lang'=>$locale]) }}">
@csrf <input type="hidden" name="repo" value="{{ $selectedRepo['full_name'] }}">
<button type="submit">{{ $ar ? 'أعد فحص هذا المستودع فقط' : 'Recheck selected public repository' }}</button>
</form>
<p class="muted">{{ $ar ? 'المراجعة لا تنشر المستودع ولا تعدل قائمة السماح.' : 'Inspection never publishes a repository or changes its allowlist status.' }}</p>
</section>
@endif
</section>
@endif

@if($section === 'projects')
<section class="panel" aria-labelledby="projects-title">
<h2 id="projects-title">{{ $ar ? 'سجل المشاريع والدليل' : 'Project & evidence register' }} ({{ $projects->total() }})</h2>
<p class="muted">{{ $ar ? 'تعديل المسودات الخاصة فقط. لا يوجد زر نشر يتجاوز مراجعة الادعاءات والدليل.' : 'Only unpublished private drafts may be edited. Publication and professional claims require independent evidence review.' }}</p>
<form method="get" action="{{ route('admin.operations.projects') }}" class="filters" role="search">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="visibility">{{ $ar ? 'الظهور' : 'Visibility' }}</label><select name="visibility" id="visibility"><option value="">{{ $ar ? 'الكل' : 'All' }}</option>@foreach(['public','private'] as $option)<option value="{{ $option }}" @selected($visibilityFilter===$option)>{{ $visibilityLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><label for="status">{{ $ar ? 'الحالة' : 'Status' }}</label><select name="status" id="status"><option value="">{{ $ar ? 'الكل' : 'All' }}</option>@foreach(['completed','in_progress','research','archived'] as $option)<option value="{{ $option }}" @selected($statusFilter===$option)>{{ $statusLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><button type="submit">{{ $ar ? 'تطبيق المرشحات' : 'Apply filters' }}</button></div>
</form>
@if($projects->isEmpty())<p role="status">{{ $ar ? 'لا توجد مشاريع تطابق النتائج.' : 'No projects match these filters.' }}</p>@else
<div class="scroll"><table><thead><tr>
<th scope="col">{{ $ar ? 'المشروع' : 'Project' }}</th><th scope="col">{{ $ar ? 'الحالة' : 'Status' }}</th><th scope="col">{{ $ar ? 'الظهور' : 'Visibility' }}</th><th scope="col">{{ $ar ? 'دليل عام متحقق' : 'Verified public evidence' }}</th><th scope="col">{{ $ar ? 'المراجعة' : 'Review' }}</th>
</tr></thead><tbody>
@foreach($projects as $project)
<tr><td>{{ $project->title }}<p class="muted">{{ $project->slug }}</p></td>
<td>{{ $statusLabels[$project->status][$ar ? 1 : 0] ?? $project->status }}</td><td>{{ $visibilityLabels[$project->visibility][$ar ? 1 : 0] ?? $project->visibility }}</td>
<td>{{ $project->verified_public_evidence_count }} / {{ $project->evidence_count }}</td>
<td>
@if(auth()->user()?->hasRole('owner') && $project->visibility==='private' && $project->published_at===null)
<p><a href="{{ route('admin.operations.projects.evidence.review', ['project'=>$project->id,'lang'=>$locale]) }}">{{ $ar ? 'مراجعة جميع الأدلة الخاصة (صفحات)' : 'Review all private evidence (paginated)' }}</a></p>
<details><summary>{{ $ar ? 'تحرير مسودة خاصة' : 'Edit private draft' }}</summary>
<form method="post" action="{{ route('admin.operations.projects.update', ['project'=>$project->id,'lang'=>$locale]) }}">
@csrf @method('PATCH')
<div class="field-grid">
<div><label for="title-{{ $project->id }}">Title</label><input id="title-{{ $project->id }}" name="title" maxlength="220" required value="{{ $project->title }}"></div>
<div><label for="role-en-{{ $project->id }}">Role (EN)</label><input id="role-en-{{ $project->id }}" name="role_en" maxlength="200" value="{{ $project->role_en }}"></div>
<div><label for="role-ar-{{ $project->id }}">الدور (AR)</label><input id="role-ar-{{ $project->id }}" name="role_ar" maxlength="200" value="{{ $project->role_ar }}"></div>
<div><label for="summary-en-{{ $project->id }}">Summary (EN)</label><textarea id="summary-en-{{ $project->id }}" name="summary_en" maxlength="5000" required>{{ $project->summary_en }}</textarea></div>
<div><label for="summary-ar-{{ $project->id }}">الملخص (AR)</label><textarea id="summary-ar-{{ $project->id }}" name="summary_ar" maxlength="5000" required>{{ $project->summary_ar }}</textarea></div>
</div><button type="submit">{{ $ar ? 'حفظ المسودة الخاصة' : 'Save private draft' }}</button>
</form></details>
<details><summary>{{ $ar ? 'إضافة دليل خاص للمراجعة' : 'Add private evidence for review' }}</summary>
<p class="muted">{{ $ar ? 'يُحفظ الرابط كدليل خاص غير معتمد، ولا يُنشر أو يُفتح تلقائيًا. استخدم رابط HTTPS عامًا مباشرًا دون رموز وصول.' : 'Evidence stays private and unverified. No URL is fetched or published. Provide a direct public HTTPS link without access tokens.' }}</p>
<form method="post" action="{{ route('admin.operations.projects.evidence', ['project'=>$project->id,'lang'=>$locale]) }}">
@csrf
<div class="field-grid">
<div><label for="evidence-type-{{ $project->id }}">{{ $ar ? 'نوع الدليل' : 'Evidence type' }}</label>
<select id="evidence-type-{{ $project->id }}" name="evidence_type" required>
<option value="document">{{ $ar ? 'مستند' : 'Document' }}</option>
<option value="repository">{{ $ar ? 'مستودع' : 'Repository' }}</option>
<option value="publication">{{ $ar ? 'منشور' : 'Publication' }}</option>
</select></div>
<div><label for="evidence-url-{{ $project->id }}">{{ $ar ? 'رابط الدليل العام' : 'Public evidence URL' }}</label>
<input id="evidence-url-{{ $project->id }}" type="url" name="url" inputmode="url" maxlength="2048" placeholder="https://github.com/owner/repository" autocomplete="off" required></div>
</div><button type="submit">{{ $ar ? 'إضافة دليل خاص للمراجعة' : 'Queue private evidence for review' }}</button>
</form>
</details>
<details><summary>{{ $ar ? 'مراجعة الأدلة الخاصة المعلقة' : 'Review pending private evidence' }} ({{ $project->evidence->count() }})</summary>
<p class="muted">{{ $ar ? 'الأدلة خاصة وغير معتمدة ولن يتم فتحها أو نشرها تلقائياً. الرفض يزيل الدليل دون الموافقة على أي ادعاء.' : 'Evidence URLs are private and unverified, never opened or published automatically. Discard removes a candidate without approving a claim.' }}</p>
@if($project->evidence->isEmpty())<p role="status">{{ $ar ? 'لا يوجد دليل معلق.' : 'No pending evidence.' }}</p>@else
<ul>
@foreach($project->evidence as $candidate)
<li><span>{{ $candidate->evidence_type }}</span> — <code style="overflow-wrap:anywhere">{{ $candidate->url }}</code>
<form method="post" action="{{ route('admin.operations.projects.evidence.discard', ['project'=>$project->id,'evidence'=>$candidate->id,'lang'=>$locale]) }}">
@csrf @method('DELETE')
<button type="submit">{{ $ar ? 'رفض الدليل غير المعتمد' : 'Discard unverified evidence' }}</button>
</form></li>
@endforeach
</ul>
@if($project->evidence_count > $project->evidence->count())<p class="muted">{{ $ar ? 'تُعرض أحدث عشرة أدلة خاصة معلقة فقط.' : 'Only the ten newest pending private candidates are shown.' }}</p>@endif
@endif
</details>
@else <span class="muted">{{ $ar ? 'مراجعة دليل مطلوبة' : 'Evidence review required' }}</span>
@endif
</td></tr>
@endforeach</tbody></table></div>
<nav aria-label="{{ $ar ? 'صفحات المشاريع' : 'Project pages' }}">{{ $projects->links() }}</nav>
@endif
</section>
@endif

@if($section === 'audience')
<section class="panel" aria-labelledby="audience-title">
<h2 id="audience-title">{{ $ar ? 'معاينة جمهور وافق بالفعل' : 'Consented audience preview' }}</h2>
<p class="muted">{{ $ar ? 'العدد من تفضيلات الموافقة المحفوظة لمستخدمين نشطين فقط. المستلمون غير الموافقين أو الملغين غير محسوبين. لا توجد خدمة إرسال متاحة من هذه الشاشة.' : 'Only active accounts with unrevoked explicit consent count. No emails or identifiers are listed, and this screen cannot send a push notification.' }}</p>
<form method="get" action="{{ route('admin.operations.audience') }}" class="filters" role="search">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="purpose">{{ $ar ? 'الغرض' : 'Purpose' }}</label><select id="purpose" name="purpose">@foreach(['account_activity','editorial_updates','product_updates'] as $option)<option value="{{ $option }}" @selected($purpose===$option)>{{ $purposeLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><label for="channel">{{ $ar ? 'القناة' : 'Channel' }}</label><select id="channel" name="channel">@foreach(['email','push'] as $option)<option value="{{ $option }}" @selected($channel===$option)>{{ $channelLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><button type="submit">{{ $ar ? 'معاينة الموافقات' : 'Preview consented segment' }}</button></div>
</form>
<div class="cards" style="margin-top:20px">
<div class="card"><span>{{ $ar ? 'حسابات نشطة' : 'Active accounts' }}</span><strong>{{ $activeAccounts }}</strong></div>
<div class="card"><span>{{ $ar ? 'مستلمون موافقون' : 'Consented recipients' }}</span><strong>{{ $consented }}</strong></div>
</div>
<div class="panel alert" role="status">
<strong>{{ $ar ? 'الإرسال معطّل بأمان' : 'Delivery is disabled' }}</strong>
<p>{{ $ar ? 'لا توجد عملية إرسال اختبار أو حملة بدون خدمة دفع مرخصة وإيصالات تسليم. تغيير التفضيلات يتم من حساب المستخدم، وليس من هذه الشاشة.' : 'No test push or campaign is submitted without a configured delivery service and verified receipts. Users manage their own consent; admins cannot silently opt them in.' }}</p>
</div>
</section>
@endif

@if($section === 'analytics')
<section class="panel" aria-labelledby="analytics-title">
<h2 id="analytics-title">{{ $ar ? 'إحصاءات محفوظة وليست زيارات متخيلة' : 'Stored operational metrics — not estimated traffic' }}</h2>
<p class="muted">{{ $ar ? 'المشاريع والمقالات المعتمدة: أعداد حالية. المهام والتكاليف: الفترة المحددة حسب أيام UTC كاملة فقط. لا تتوفر بيانات زيارات أو احتفاظ حقيقية بعد.' : 'Approved content totals are current. AI jobs and receipts cover the selected full UTC calendar-day period only. Real visits and retention are not measured yet.' }}</p>
<form method="get" action="{{ route('admin.operations.analytics') }}" class="filters" role="search">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="day">{{ $ar ? 'نهاية الفترة (UTC)' : 'Period end (UTC)' }}</label><input id="day" name="day" type="date" min="{{ now('UTC')->subDays(365)->toDateString() }}" max="{{ now('UTC')->toDateString() }}" value="{{ $day }}" required></div>
<div><label for="window">{{ $ar ? 'عدد أيام التقويم (UTC)' : 'UTC calendar days' }}</label><select id="window" name="window">
@foreach([1,7,30] as $days)<option value="{{ $days }}" @selected($window===$days)>{{ $days }} {{ $ar ? 'يوم' : ($days===1 ? 'day' : 'days') }}</option>@endforeach
</select></div>
<div><button type="submit">{{ $ar ? 'عرض التقرير' : 'View report' }}</button></div>
<div><a class="button" href="{{ route('admin.operations.analytics', ['lang'=>$locale,'day'=>$day,'window'=>$window,'format'=>'csv']) }}">{{ $ar ? 'تصدير التكاليف CSV' : 'Export cost CSV' }}</a></div>
<div><a class="button" href="{{ route('admin.operations.analytics', ['lang'=>$locale,'day'=>$day,'window'=>$window,'format'=>'jobs-csv']) }}">{{ $ar ? 'تصدير حالات مهام الذكاء الاصطناعي CSV' : 'Export AI job status CSV' }}</a></div>
</form>
<p class="muted" role="status">{{ $ar ? 'الفترة الفعلية' : 'Actual window' }}: <time datetime="{{ $startDay }}">{{ $startDay }}</time> — <time datetime="{{ $day }}">{{ $day }}</time> (UTC, {{ $window }} {{ $ar ? 'يوم' : ($window===1 ? 'day' : 'days') }})</p>
<div class="cards" style="margin-top:20px">
<div class="card"><span>{{ $ar ? 'المشاريع المعتمدة' : 'Verified public projects' }}</span><strong>{{ $publicProjects }}</strong></div>
<div class="card"><span>{{ $ar ? 'مقالات مرخصة منشورة' : 'Published articles (status)' }}</span><strong>{{ $publicArticles }}</strong></div>
</div>
<h3>{{ $ar ? 'مهام AI في الفترة' : 'AI jobs in the UTC period' }}</h3>
@if($jobs->isEmpty())<p role="status">{{ $ar ? 'لا توجد مهام مسجلة لهذه الفترة.' : 'No jobs recorded for this period.' }}</p>
@else <div class="cards">@foreach($jobs as $job)<div class="card"><span>{{ $job->status }}</span><strong>{{ $job->total }}</strong></div>@endforeach</div>@endif
<h3>{{ $ar ? 'إيصالات التكاليف حسب العملة (وحدات صغرى)' : 'Recorded cost receipts by currency (minor units)' }}</h3>
@if($usage->isEmpty())<p role="status">{{ $ar ? 'لا توجد إيصالات تكاليف لهذه الفترة.' : 'No usage receipts recorded for this period.' }}</p>
@else <div class="scroll"><table><thead><tr><th>{{ $ar ? 'العملة' : 'Currency' }}</th><th>{{ $ar ? 'الإيصالات' : 'Receipts' }}</th><th>{{ $ar ? 'التكلفة بالوحدات الصغرى' : 'Cost minor units' }}</th></tr></thead><tbody>
@foreach($usage as $line)<tr><td>{{ $line->cost_currency }}</td><td>{{ $line->receipts }}</td><td>{{ $line->cost_minor }}</td></tr>@endforeach
</tbody></table></div>@endif
</section>
@endif
</main></div>
</body></html>
