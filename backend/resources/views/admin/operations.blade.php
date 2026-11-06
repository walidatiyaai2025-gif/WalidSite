@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($ar = $locale === 'ar')
@php($titles = ['github' => ['GitHub Sync', 'مزامنة GitHub'], 'projects' => ['Projects & CV', 'المشاريع والسيرة المهنية'], 'audience' => ['Audience & consent', 'الجمهور والموافقة'], 'analytics' => ['Verified analytics', 'التحليلات المتحقّق منها']])
@php($visibilityLabels = ['public' => ['Public', 'عام'], 'private' => ['Private', 'خاص']])
@php($statusLabels = ['completed' => ['Completed', 'مكتمل'], 'in_progress' => ['In progress', 'قيد التنفيذ'], 'research' => ['Research', 'بحث'], 'archived' => ['Archived', 'مؤرشف']])
@php($purposeLabels = ['account_activity' => ['Account activity', 'نشاط الحساب'], 'editorial_updates' => ['Editorial updates', 'تحديثات المحتوى'], 'product_updates' => ['Product updates', 'تحديثات المنتج']])
@php($channelLabels = ['email' => ['Email', 'البريد الإلكتروني'], 'push' => ['Push notification', 'إشعار فوري']])
@php($title = $titles[$section][$ar ? 1 : 0])
@php($subtitles = [
    'github' => ['Verified public repository ingestion', 'مراجعة المستودعات العامة المتحقق منها'],
    'projects' => ['Portfolio, evidence and resume publishing', 'إدارة المشاريع والأدلة والسيرة المهنية'],
    'audience' => ['Segments, consent and notification rules', 'شرائح الجمهور والموافقات وقواعد الإشعارات'],
    'analytics' => ['Recorded technical and operational metrics', 'المؤشرات التقنية والتشغيلية المحفوظة'],
])
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $title }} — WalidSite</title>
<style>
:root{--nav:#1c4974;--nav-active:#194b58;--ink:#194976;--muted:#476583;--bg:#f3f8fc;--edge:#d8e6ee;--link:#126de1;--accent:#10a999}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
.layout{display:grid;grid-template-columns:300px minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:18px 15px 24px;min-width:0}
.brand{display:block;margin:0 15px 36px;color:var(--accent);font-size:27px;font-weight:800;letter-spacing:.025em;line-height:1.7}
.brand span{margin-inline-start:13px}
aside nav{display:flex;flex-direction:column;gap:2px}
aside a{display:flex;align-items:center;color:#eaf4fd;text-decoration:none;padding:10px 20px;min-height:52px;border-radius:14px;font-size:17px}
aside a:hover{background:#275d82}
aside a[aria-current=page]{font-weight:700;background:var(--nav-active);color:var(--accent)}
aside .signout{margin:18px 20px 0}
.workspace{min-width:0}
.workspace-header{height:90px;padding:15px 44px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px}
.workspace-header .workspace-name{margin:0;font-size:27px;font-weight:800;letter-spacing:.01em}
.health-unverified{border-radius:15px;padding:8px 14px;background:#eef4f7;color:#45617b;font-size:13px;font-weight:700;text-align:center}
main{padding:32px 42px 56px;min-width:0}
.top{display:flex;align-items:start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px}
.top h1{font-size:38px;line-height:1.2;font-weight:800;margin:0 0 8px}
.page-heading p{margin:0;font-size:17px}
.locale-switch{display:inline-flex;align-items:center;min-height:44px}
.panel{background:#fff;border:1px solid var(--edge);border-radius:20px;padding:clamp(18px,3vw,28px);margin:20px 0}
.panel h2{margin-top:0;font-size:22px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:20px}
.card{background:#f5f9fc;border:1px solid var(--edge);padding:20px;border-radius:16px;min-width:0;overflow-wrap:anywhere}
.card strong{display:block;font-size:25px;margin-top:10px}.muted{color:var(--muted)}.alert{border-inline-start:4px solid #bf7f12}
.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.filters>div{flex:1 1 180px}
label{font-weight:700;display:block;margin-bottom:5px}
input,select,textarea{width:100%;font:inherit;border:1px solid #97b4c8;border-radius:8px;padding:8px;min-height:44px;color:var(--ink);background:#fff}
textarea{min-height:90px}
button,.button{display:inline-flex;justify-content:center;align-items:center;background:var(--nav);color:#fff;min-height:44px;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer;text-decoration:none}
a{color:var(--link)}a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:580px}
th,td{padding:16px;text-align:start;border-bottom:1px solid var(--edge);vertical-align:top;overflow-wrap:anywhere}
th{background:var(--bg)}tbody tr:last-child td{border-bottom:0}
.skip{position:absolute;top:-120px;inset-inline-start:12px;background:#fff;padding:12px;z-index:10}.skip:focus{top:10px}
.inline-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.safe{color:#176b4d}.warn{color:#8b2c2c}
details{border:1px solid var(--edge);padding:10px;border-radius:8px}details[open]{background:var(--bg)}
summary{cursor:pointer;min-height:44px;padding:8px}
.field-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,245px),1fr));gap:12px;margin-block:12px}
.workflow-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px 32px;list-style:none;padding:0;margin:20px 0}
.workflow-steps li{background:#f1f8fa;border:1px solid #c0e0df;border-radius:16px;min-height:148px;padding:16px;min-width:0}
.workflow-steps .sequence{display:inline-block;background:#164952;color:var(--accent);border-radius:14px;padding:2px 14px;font-weight:800}
.workflow-steps strong{display:block;font-size:22px;margin:14px 0 4px}
.workflow-steps .state{font-size:14px;color:var(--muted)}
@media(max-width:1040px){.layout{grid-template-columns:230px minmax(0,1fr)}.workspace-header{padding-inline:24px}main{padding:24px}.workflow-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.layout{grid-template-columns:1fr}aside{padding:12px}.brand{font-size:22px;margin:0 10px 8px}aside nav{flex-direction:row;flex-wrap:wrap;gap:4px}aside a{padding:8px 12px;font-size:14px;min-height:44px}.workspace-header{height:auto;min-height:72px}.workspace-header .workspace-name{font-size:21px}.top h1{font-size:31px}.workflow-steps{grid-template-columns:1fr}.panel{padding:16px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;scroll-behavior:auto!important;transition:none!important}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $ar ? 'انتقل إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout"><aside>
<div class="brand" aria-label="{{ $ar ? 'لوحة تحكم وليد سايت' : 'WalidSite control' }}">WA <span>CONTROL</span></div>
<nav aria-label="{{ $ar ? 'التنقل الإداري' : 'Admin navigation' }}">
<a href="{{ route('admin.home', ['lang'=>$locale]) }}">{{ $ar ? 'نظرة عامة' : 'Overview' }}</a>
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $ar ? 'استوديو المحتوى' : 'Content Studio' }}</a>
<a href="{{ route('admin.providers.index', ['lang'=>$locale]) }}">{{ $ar ? 'مزودو الذكاء الاصطناعي' : 'AI Providers' }}</a>
<a href="{{ route('admin.routing.index', ['lang'=>$locale]) }}">{{ $ar ? 'توجيه الذكاء الاصطناعي' : 'AI Routing' }}</a>
<a href="{{ route('admin.media.index', ['lang'=>$locale]) }}">{{ $ar ? 'استوديو الصور' : 'Image Studio' }}</a>
<a href="{{ route('admin.quality.index', ['lang'=>$locale]) }}">{{ $ar ? 'بوابات الجودة' : 'Quality Gates' }}</a>
@foreach(['github','projects','audience','analytics'] as $nav)
<a href="{{ route('admin.operations.'.$nav, ['lang'=>$locale]) }}" @if($nav===$section) aria-current="page" @endif>{{ $nav==='audience' ? ($ar ? 'الجمهور والإشعارات' : 'Audience / Push') : ($nav==='analytics' ? ($ar ? 'التحليلات' : 'Analytics') : $titles[$nav][$ar ? 1 : 0]) }}</a>
@endforeach
<a href="{{ route('admin.approvals.index', ['lang'=>$locale]) }}">{{ $ar ? 'الموافقات' : 'Approvals' }}</a>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}">{{ $ar ? 'التدقيق والأمان' : 'Audit & Security' }}</a>
</nav>
<form class="signout" method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $ar ? 'تسجيل الخروج' : 'Sign out' }}</button></form>
</aside><div class="workspace">
<header class="workspace-header" role="banner">
<p class="workspace-name">{{ $ar ? 'عمليات وليد للذكاء الاصطناعي' : 'Waleed AI Operations' }}</p>
<span class="health-unverified" role="status">{{ $ar ? 'حالة النظام غير متحقّق منها' : 'SYSTEM STATUS UNVERIFIED' }}</span>
</header>
<main id="content" tabindex="-1">
<div class="top"><div class="page-heading"><h1>{{ $title }}</h1><p>{{ $subtitles[$section][$ar ? 1 : 0] }}</p></div><a class="locale-switch" href="{{ route('admin.operations.'.$section, array_merge(['lang'=>$ar ? 'en' : 'ar'], $section==='projects' ? array_filter(['status'=>$statusFilter ?? null, 'visibility'=>$visibilityFilter ?? null, 'q'=>$projectSearch ?? null, 'sort'=>($projectSort ?? 'recent')==='recent' ? null : $projectSort]) : ($section==='audience' ? ['purpose'=>$purpose,'channel'=>$channel] : ($section==='analytics' ? ['day'=>$day,'window'=>$window] : ($section==='github' ? array_filter(['repo'=>$selectedRepo['full_name'] ?? null]) : []))))) }}" lang="{{ $ar ? 'en' : 'ar' }}" hreflang="{{ $ar ? 'en' : 'ar' }}">{{ $ar ? 'English' : 'العربية' }}</a></div>
@if(session('status'))<div role="status" class="panel safe">{{ session('status') }}</div>@endif
@if(session('warning'))<div role="alert" class="panel alert">{{ $ar ? 'تعذر التحقق من بيانات GitHub العامة.' : session('warning') }}</div>@endif
@if($errors->any())<div role="alert" class="panel warn"><strong>{{ $ar ? 'تعذر حفظ العملية' : 'The operation was not saved' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if($section === 'github')
<section class="panel" aria-labelledby="github-review-title">
<h2 id="github-review-title">{{ $ar ? 'طلبات المستودعات ومراجعة المالك' : 'Repository proposals and owner review' }}</h2>
<p class="muted">{{ $ar ? 'إرسال الطلب لا ينشر المستودع. تعتمد الإضافة على قرار مالك مستقل ثم التحقق من كونه عامًا.' : 'Submission never publishes a repository. Independent owner approval and public verification are mandatory.' }}</p>
<form method="post" action="{{ route('admin.operations.github.propose', ['lang'=>$locale]) }}">
@csrf
<label for="repository-slug">{{ $ar ? 'مستودع GitHub (المالك/المستودع)' : 'GitHub repository (owner/repo)' }}</label>
<input id="repository-slug" name="repository_slug" type="text" maxlength="140" required
 autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+"
 value="{{ old('repository_slug','') }}" aria-describedby="repository-rule">
<p id="repository-rule" class="muted">{{ $ar ? 'أدخل الاسم فقط، بدون رابط أو رمز وصول.' : 'Enter a repository name only, not a URL or token.' }}</p>
<button type="submit">{{ $ar ? 'أرسل للمراجعة' : 'Submit for review' }}</button>
</form>
@if($githubProposals->isEmpty())
<p role="status">{{ $ar ? 'لا توجد طلبات مراجعة.' : 'No review proposals yet.' }}</p>
@else
<div class="scroll"><table>
<thead><tr>
<th scope="col">{{ $ar ? 'المستودع المقترح' : 'Proposed repository' }}</th>
<th scope="col">{{ $ar ? 'الحالة' : 'Status' }}</th>
<th scope="col">{{ $ar ? 'تاريخ الطلب' : 'Submitted' }}</th>
<th scope="col">{{ $ar ? 'قرار المالك' : 'Owner decision' }}</th>
</tr></thead><tbody>
@foreach($githubProposals as $proposal)
<tr>
<td>{{ $proposal->repository_slug }}</td>
<td>{{ $proposal->status }}</td>
<td><time datetime="{{ $proposal->created_at?->toIso8601String() }}">{{ $proposal->created_at?->toDateString() }}</time></td>
<td>
@if($canReviewGithub && $proposal->status === 'pending' && $proposal->submitted_by_user_id !== auth('web')->id())
<form method="post" action="{{ route('admin.operations.github.decide', ['id'=>$proposal->id,'lang'=>$locale]) }}" class="inline-actions">
@csrf
<label for="reason-{{ $proposal->id }}">{{ $ar ? 'سبب القرار' : 'Decision reason' }}</label>
<select id="reason-{{ $proposal->id }}" name="reason">
<option value="source_verified">{{ $ar ? 'تحقق المصدر' : 'Source verified' }}</option>
<option value="not_public">{{ $ar ? 'غير عام' : 'Not public' }}</option>
<option value="owner_policy">{{ $ar ? 'سياسة المالك' : 'Owner policy' }}</option>
<option value="out_of_scope">{{ $ar ? 'خارج النطاق' : 'Out of scope' }}</option>
</select>
<button type="submit" name="decision" value="approve">{{ $ar ? 'اعتماد بعد التحقق' : 'Verify and approve' }}</button>
<button type="submit" name="decision" value="reject">{{ $ar ? 'رفض' : 'Reject' }}</button>
</form>
@else
<span class="muted">{{ $ar ? 'لا يوجد إجراء متاح' : 'No action available' }}</span>
@endif
</td>
</tr>
@endforeach
</tbody></table></div>
<nav aria-label="{{ $ar ? 'صفحات الطلبات' : 'Proposal pages' }}">
@if($githubProposals->previousPageUrl())<a href="{{ $githubProposals->previousPageUrl() }}">{{ $ar ? 'السابق' : 'Previous' }}</a>@endif
<span>{{ $githubProposals->currentPage() }} / {{ $githubProposals->lastPage() }}</span>
@if($githubProposals->nextPageUrl())<a href="{{ $githubProposals->nextPageUrl() }}">{{ $ar ? 'التالي' : 'Next' }}</a>@endif
</nav>
@endif
</section>
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

@php($portfolioGroups = [
    'government' => ['Government', 'حكومي'],
    'education' => ['Education', 'تعليم'],
    'workflows' => ['Workflows', 'إجراءات'],
    'ai' => ['AI & Products', 'الذكاء والمنتجات'],
    'enterprise' => ['Enterprise', 'الأعمال'],
])
@if(auth()->user()?->hasRole('owner'))
<details class="panel" id="new-project-form">
<summary style="font-weight:800;cursor:pointer;min-height:44px">{{ $ar ? 'إضافة مشروع حقيقي جديد إلى قاعدة البيانات' : 'Create a real project in the database' }}</summary>
<p class="muted">{{ $ar
    ? 'يُحفظ المشروع مسودة خاصة دائمًا. لا يظهر علنًا إلا بعد الموافقة على أدلته ثم نشره بشكل مستقل.'
    : 'New records are private drafts. Evidence approval and explicit publication are always required before appearing publicly.' }}</p>
<form method="post" action="{{ route('admin.operations.projects.store', ['lang'=>$locale]) }}">
@csrf
<div class="field-grid">
<div><label for="new-project-slug">Slug (a-z, 0-9, -)</label><input id="new-project-slug" name="slug" required maxlength="140" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="my-real-project" value="{{ old('slug') }}"></div>
<div><label for="new-project-title">{{ $ar ? 'اسم المشروع' : 'Project title' }}</label><input id="new-project-title" name="title" maxlength="220" required value="{{ old('title') }}"></div>
<div><label for="new-project-title-ar">اسم المشروع بالعربية</label><input id="new-project-title-ar" name="title_ar" maxlength="220" value="{{ old('title_ar') }}"></div>
<div><label for="new-project-role-en">My role (EN)</label><input id="new-project-role-en" name="role_en" maxlength="200" required value="{{ old('role_en') }}"></div>
<div><label for="new-project-role-ar">دوري (AR)</label><input id="new-project-role-ar" name="role_ar" maxlength="200" required value="{{ old('role_ar') }}"></div>
<div><label for="new-project-category">{{ $ar ? 'التصنيف' : 'Category' }}</label><select id="new-project-category" name="portfolio_category" required>
@foreach($portfolioGroups as $key=>$labels)<option value="{{ $key }}" @selected(old('portfolio_category','enterprise')===$key)>{{ $labels[$ar ? 1 : 0] }}</option>@endforeach
</select></div>
<div><label for="new-project-status">{{ $ar ? 'الحالة' : 'Status' }}</label><select id="new-project-status" name="status" required>
@foreach(['completed','in_progress','research','archived'] as $key)<option value="{{ $key }}" @selected(old('status','in_progress')===$key)>{{ $statusLabels[$key][$ar ? 1 : 0] }}</option>@endforeach
</select></div>
<div><label for="new-project-featured">{{ $ar ? 'المميز' : 'Featured' }}</label><select name="portfolio_featured" id="new-project-featured"><option value="0">{{ $ar?'لا':'No' }}</option><option value="1">{{ $ar?'نعم':'Yes' }}</option></select></div>
<div><label for="new-project-summary-en">Summary (EN)</label><textarea id="new-project-summary-en" name="summary_en" maxlength="5000" required>{{ old('summary_en') }}</textarea></div>
<div><label for="new-project-summary-ar">الملخص (AR)</label><textarea id="new-project-summary-ar" name="summary_ar" maxlength="5000" required>{{ old('summary_ar') }}</textarea></div>
</div>
<button type="submit">{{ $ar ? 'حفظ المسودة الجديدة' : 'Save new private draft' }}</button>
</form>
</details>
@endif
{{-- ADMIN-09: review the exact published owner CV, never private drafts
     or self-reported claims from an unrelated/inactive account. The public
     preview and downloadable binary use the same evidence-gated routes as
     WEB-11; no second editable CV data store is invented here. --}}
@php($cvPublicResponse = app(\App\Http\Controllers\Api\PublicPortfolioController::class)->profile(request())->getData(true))
@php($publicCvAvailable = isset($cvPublicResponse['data']) && $cvPublicResponse['data'] !== null)
<section class="panel" aria-labelledby="cv-review-title">
<h3 id="cv-review-title">{{ $ar ? 'مراجعة السيرة المهنية المنشورة' : 'Published CV review' }}</h3>
<p class="muted">{{ $ar
    ? 'تستخدم المعاينة والتنزيل مسارات السيرة العامة نفسها مع بوابة التحقق من الأدلة. لن تُعرض مسودات المالك أو بيانات حساب آخر أو أي شهادات غير معتمدة.'
    : 'Preview and download use the same evidence-gated public CV endpoints. Owner drafts, other accounts and unverified credentials are never disclosed.' }}</p>
@if(!$publicCvAvailable)
<p role="status" class="muted">{{ $ar
    ? 'لم تُنشر سيرة مهنية معتمدة لمالك نشط بعد. المعاينات العامة تظهر حالة المراجعة؛ تنزيل PDF غير متاح.'
    : 'No published CV for an active owner is available. Public previews show the pending state; PDF downloads are unavailable.' }}</p>
@endif
<div class="cards" role="list">
@foreach([
'all' => $ar ? 'جميع المسارات' : 'All verified tracks',
'database' => $ar ? 'قواعد البيانات والموثوقية' : 'Database & reliability',
'software' => $ar ? 'البرمجيات والتكامل' : 'Software & integration',
'delivery' => $ar ? 'إدارة وتسليم المشاريع' : 'Project delivery',
] as $focus => $cvLabel)
<div class="card" role="listitem">
<strong>{{ $cvLabel }}</strong>
<div class="inline-actions" style="margin-top:12px">
<a class="button" href="{{ route('public.resume', ['lang' => $locale, 'track' => $focus]) }}">{{ $ar ? 'معاينة عامة' : 'Public preview' }}</a>
@if($publicCvAvailable)
<a class="button" href="{{ route('public.resume.pdf', ['lang' => $locale, 'track' => $focus]) }}">{{ $ar ? 'تنزيل PDF المعتمد' : 'Download verified PDF' }}</a>
@endif
</div>
</div>
@endforeach
</div>
<div class="inline-actions" style="margin-top:20px">
<a href="{{ route('admin.approvals.index', ['lang' => $locale]) }}">{{ $ar ? 'مراجعة الموافقات الفعلية' : 'Review recorded approvals' }}</a>
<a href="{{ route('admin.audit.index', ['lang' => $locale]) }}">{{ $ar ? 'فتح سجل التدقيق' : 'Open audit trail' }}</a>
</div>
</section>
<form method="get" action="{{ route('admin.operations.projects') }}" class="filters" role="search">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="project-search">{{ $ar ? 'ابحث عن مشروع' : 'Search projects' }}</label><input id="project-search" type="search" name="q" maxlength="100" value="{{ $projectSearch }}" placeholder="{{ $ar ? 'العنوان أو الرابط المختصر' : 'Title or slug' }}" autocomplete="off"></div>
<div><label for="visibility">{{ $ar ? 'الظهور' : 'Visibility' }}</label><select name="visibility" id="visibility"><option value="">{{ $ar ? 'الكل' : 'All' }}</option>@foreach(['public','private'] as $option)<option value="{{ $option }}" @selected($visibilityFilter===$option)>{{ $visibilityLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><label for="status">{{ $ar ? 'الحالة' : 'Status' }}</label><select name="status" id="status"><option value="">{{ $ar ? 'الكل' : 'All' }}</option>@foreach(['completed','in_progress','research','archived'] as $option)<option value="{{ $option }}" @selected($statusFilter===$option)>{{ $statusLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><label for="project-sort">{{ $ar ? 'الترتيب' : 'Sort by' }}</label><select name="sort" id="project-sort"><option value="recent" @selected($projectSort==='recent')>{{ $ar ? 'الأحدث تحديثًا' : 'Recently updated' }}</option><option value="title" @selected($projectSort==='title')>{{ $ar ? 'اسم المشروع' : 'Project title' }}</option></select></div>
<div class="inline-actions"><button type="submit">{{ $ar ? 'بحث وتصفية' : 'Search and filter' }}</button><a href="{{ route('admin.operations.projects', ['lang'=>$locale]) }}">{{ $ar ? 'إزالة المرشحات' : 'Clear filters' }}</a></div>
</form>
@if($projects->isEmpty())<p role="status">{{ $ar ? 'لا توجد مشاريع تطابق النتائج.' : 'No projects match these filters.' }} <a href="{{ route('admin.operations.projects', ['lang'=>$locale]) }}">{{ $ar ? 'عرض جميع المشاريع' : 'Show all projects' }}</a></p>@else
<div class="scroll"><table><thead><tr>
<th scope="col">{{ $ar ? 'المشروع' : 'Project' }}</th><th scope="col">{{ $ar ? 'الحالة' : 'Status' }}</th><th scope="col">{{ $ar ? 'الظهور' : 'Visibility' }}</th><th scope="col">{{ $ar ? 'دليل عام متحقق' : 'Verified public evidence' }}</th><th scope="col">{{ $ar ? 'المراجعة' : 'Review' }}</th>
</tr></thead><tbody>
@foreach($projects as $project)
<tr><td>{{ $project->title }}<p class="muted">{{ $project->slug }}</p><p class="muted">{{ $portfolioGroups[$project->portfolio_category ?? 'enterprise'][$ar ? 1 : 0] ?? ($ar ? 'أعمال' : 'Enterprise') }} — {{ $project->portfolio_source ?? ($ar?'سجل إداري':'Admin record') }}</p></td>
<td>{{ $statusLabels[$project->status][$ar ? 1 : 0] ?? $project->status }}</td><td>{{ $visibilityLabels[$project->visibility][$ar ? 1 : 0] ?? $project->visibility }}</td>
<td>{{ $project->verified_public_evidence_count }} / {{ $project->evidence_count }}
<p class="muted">{{ $ar ? 'روابط أدلة صالحة للعرض العام' : 'Usable public evidence links' }}: <strong>{{ $project->usable_public_evidence_count }}</strong></p>
@if(auth()->user()?->hasRole('owner') && $project->visibility==='private' && $project->published_at===null)
@if($project->usable_public_evidence_count === 0)
<p class="warn">{{ $ar ? 'لا يمكن النشر حتى اعتماد رابط دليل عام صالح ومسار مشروع صحيح.' : 'Publication blocked: review a safe public evidence link and route-safe project slug.' }}</p>
@elseif(!$project->publication_content_ready)
<p class="warn">{{ $ar ? 'يلزم إكمال العنوان والملخص والدور باللغتين قبل النشر.' : 'Complete title, summary and role in both languages before publication.' }}</p>
@else
<p class="safe">{{ $ar ? 'المشروع مؤهل لطلب النشر المنفصل بعد تأكيد المالك.' : 'Ready for a separate owner-confirmed publication request.' }}</p>
@endif
@endif
</td>
<td>
@if(auth()->user()?->hasRole('owner') && $project->visibility==='private' && $project->published_at===null)
<p><a href="{{ route('admin.operations.projects.evidence.review', ['project'=>$project->id,'lang'=>$locale]) }}">{{ $ar ? 'مراجعة جميع الأدلة الخاصة (صفحات)' : 'Review all private evidence (paginated)' }}</a></p>
<details><summary>{{ $ar ? 'تحرير مسودة خاصة' : 'Edit private draft' }}</summary>
<form method="post" action="{{ route('admin.operations.projects.update', ['project'=>$project->id,'lang'=>$locale]) }}">
@csrf @method('PATCH')
<div class="field-grid">
<div><label for="title-{{ $project->id }}">Title</label><input id="title-{{ $project->id }}" name="title" maxlength="220" required value="{{ $project->title }}"></div>
<div><label for="title-ar-{{ $project->id }}">اسم المشروع بالعربية</label><input id="title-ar-{{ $project->id }}" name="title_ar" maxlength="220" value="{{ $project->title_ar }}"></div>
<div><label for="role-en-{{ $project->id }}">Role (EN)</label><input id="role-en-{{ $project->id }}" name="role_en" maxlength="200" value="{{ $project->role_en }}"></div>
<div><label for="role-ar-{{ $project->id }}">الدور (AR)</label><input id="role-ar-{{ $project->id }}" name="role_ar" maxlength="200" value="{{ $project->role_ar }}"></div>
<div><label for="category-{{ $project->id }}">{{ $ar ? 'التصنيف' : 'Category' }}</label>
<select id="category-{{ $project->id }}" name="portfolio_category">
@foreach($portfolioGroups as $key=>$labels)<option value="{{ $key }}" @selected(($project->portfolio_category ?? 'enterprise')===$key)>{{ $labels[$ar ? 1 : 0] }}</option>@endforeach
</select></div>
<div><label for="project-status-{{ $project->id }}">{{ $ar ? 'الحالة' : 'Status' }}</label>
<select id="project-status-{{ $project->id }}" name="status">
@foreach(['completed','in_progress','research','archived'] as $key)<option value="{{ $key }}" @selected($project->status===$key)>{{ $statusLabels[$key][$ar ? 1 : 0] }}</option>@endforeach
</select></div>
<div><label for="featured-{{ $project->id }}">{{ $ar ? 'مشروع مميز' : 'Featured project' }}</label>
<select name="portfolio_featured" id="featured-{{ $project->id }}"><option value="0" @selected(!$project->portfolio_featured)>{{ $ar?'لا':'No' }}</option><option value="1" @selected($project->portfolio_featured)>{{ $ar?'نعم':'Yes' }}</option></select></div>
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
@elseif(auth()->user()?->hasRole('owner') && $project->visibility==='public' && $project->published_at!==null)
<details><summary>{{ $ar ? 'سحب المشروع المنشور' : 'Withdraw published project' }}</summary>
<p class="muted">{{ $ar ? 'سيتوقف ظهور المشروع فوراً في الصفحات العامة. تظل أدلته المعتمدة محفوظة، ولا يعاد نشره تلقائياً.' : 'This immediately removes the project from public pages. Previously approved evidence remains saved; it is never republished automatically.' }}</p>
<form method="post" action="{{ route('admin.operations.projects.withdraw', ['project'=>$project->id,'lang'=>$locale]) }}">
@csrf
<fieldset>
<legend>{{ $ar ? 'تأكيد السحب من العرض العام' : 'Confirm public withdrawal' }}</legend>
<label for="withdraw-{{ $project->id }}">
<input id="withdraw-{{ $project->id }}" type="checkbox" name="withdrawal_confirmed" value="1" required>
{{ $ar ? 'أؤكد سحب هذا المشروع المنشور من الموقع العام.' : 'I confirm this published project must be hidden from public view.' }}
</label>
<button type="submit">{{ $ar ? 'سحب المشروع من النشر' : 'Withdraw public project' }}</button>
</fieldset>
</form>
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
<ol class="workflow-steps" aria-label="{{ $ar ? 'مراحل الإشعار' : 'Notification workflow stages' }}">
@foreach([
  ['Segment', 'الشريحة', 'preview'],
  ['Message', 'الرسالة', 'unavailable'],
  ['Review', 'المراجعة', 'unavailable'],
  ['Schedule', 'الجدولة', 'unavailable'],
  ['Send', 'الإرسال', 'unavailable'],
  ['Metrics', 'القياس', 'unavailable'],
] as $index=>$stage)
<li>
<span class="sequence">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
<strong>{{ $stage[$ar ? 1 : 0] }}</strong>
<span class="state">{{ $stage[2] === 'preview' ? ($ar ? 'معاينة الموافقات فقط' : 'Consent preview only') : ($ar ? 'غير متاح، لم يُرسل شيء' : 'Unavailable — nothing sent') }}</span>
</li>
@endforeach
</ol>
<h2 id="audience-title">{{ $ar ? 'معاينة جمهور وافق بالفعل' : 'Consented audience preview' }}</h2>
<p class="muted">{{ $ar ? 'العدد من تفضيلات الموافقة المحفوظة لمستخدمين نشطين فقط. المستلمون غير الموافقين أو الملغين غير محسوبين. لا توجد خدمة إرسال متاحة من هذه الشاشة.' : 'Only active accounts with unrevoked explicit consent count. No emails or identifiers are listed, and this screen cannot send a push notification.' }}</p>
<form method="get" action="{{ route('admin.operations.audience') }}" class="filters" role="search">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="purpose">{{ $ar ? 'الغرض' : 'Purpose' }}</label><select id="purpose" name="purpose">@foreach(['account_activity','editorial_updates','product_updates'] as $option)<option value="{{ $option }}" @selected($purpose===$option)>{{ $purposeLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><label for="channel">{{ $ar ? 'القناة' : 'Channel' }}</label><select id="channel" name="channel">@foreach(['email','push'] as $option)<option value="{{ $option }}" @selected($channel===$option)>{{ $channelLabels[$option][$ar ? 1 : 0] }}</option>@endforeach</select></div>
<div><button type="submit">{{ $ar ? 'معاينة الموافقات' : 'Preview consented segment' }}</button></div>
</form>
<div class="cards" style="margin-top:20px" aria-label="{{ $ar ? 'ملخص أهلية الجمهور' : 'Audience eligibility summary' }}">
<div class="card"><span>{{ $ar ? 'حسابات نشطة' : 'Active accounts' }}</span><strong data-testid="audience-active">{{ $activeAccounts }}</strong></div>
<div class="card"><span>{{ $ar ? 'مستلمون موافقون' : 'Consented recipients' }}</span><strong data-testid="audience-consented">{{ $consented }}</strong></div>
<div class="card"><span>{{ $ar ? 'مستبعدون من هذا الجمهور' : 'Excluded from this audience' }}</span><strong data-testid="audience-suppressed">{{ $audienceStates['suppressed'] }}</strong></div>
</div>
<section class="panel" aria-labelledby="audience-exclusions">
<h3 id="audience-exclusions">{{ $ar ? 'لماذا لا يتلقى الآخرون إشعارًا؟' : 'Why other accounts are ineligible' }}</h3>
<p class="muted">{{ $ar ? 'فئات حصرية لا تشمل بيانات شخصية أو رموز أجهزة. الحسابات الموقوفة ليست ضمن عدد الحسابات النشطة. تظل هذه النتائج معاينة لا تُرسل إشعارات.' : 'Mutually exclusive counts, with no personal information or device tokens. Suspended accounts are outside the active total. These numbers are a preview, not notifications sent.' }}</p>
<dl class="cards">
<div class="card"><dt>{{ $ar ? 'ألغى الموافقة' : 'Consent revoked' }}</dt><dd><strong data-testid="audience-revoked">{{ $audienceStates['revoked'] }}</strong></dd></div>
<div class="card"><dt>{{ $ar ? 'التفضيل معطّل' : 'Preference disabled' }}</dt><dd><strong data-testid="audience-disabled">{{ $audienceStates['disabled'] }}</strong></dd></div>
<div class="card"><dt>{{ $ar ? 'موافقة مؤرخة بالمستقبل' : 'Future-dated consent' }}</dt><dd><strong data-testid="audience-future">{{ $audienceStates['future'] }}</strong></dd></div>
<div class="card"><dt>{{ $ar ? 'لم تُمنح موافقة فعّالة' : 'No effective consent granted' }}</dt><dd><strong data-testid="audience-missing">{{ $audienceStates['missing'] }}</strong></dd></div>
</dl>
</section>
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
</main></div></div>
</body></html>
