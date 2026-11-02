@php($activeRoute = 'admin.'.$section.'.index')
@php($languageArgs = ['lang' => $locale === 'ar' ? 'en' : 'ar'])
@if($section === 'projects')
    @php($languageArgs = array_merge($languageArgs, $filters))
@elseif($section === 'analytics')
    @php($languageArgs['period'] = $period)
@endif
@php($names = [
    'github' => ['en'=>'GitHub verification','ar'=>'التحقق من GitHub'],
    'projects' => ['en'=>'Projects & CV evidence','ar'=>'المشاريع وأدلة السيرة'],
    'audience' => ['en'=>'Audience & notification consent','ar'=>'الجمهور وموافقات الإشعارات'],
    'analytics' => ['en'=>'Persisted analytics','ar'=>'التحليلات المسجلة'],
])
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $names[$section][$locale] }} — WalidSite Admin</title>
<style>
:root{--primary:#163B66;--cta:#126DE1;--accent:#00A999;--surface:#F5F9FC;--text:#142E4D;--muted:#55718B;--line:#D3E3ED}
*{box-sizing:border-box}body{margin:0;background:var(--surface);color:var(--text);font:16px/1.6 Inter,Arial,sans-serif}html[lang=ar] body{font-family:Tajawal,Arial,sans-serif}
a{color:var(--cta)}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,main:focus-visible{outline:3px solid var(--cta);outline-offset:3px}
@media(forced-colors:active){.button,.button-outline,aside a[aria-current=page],.notice,.warning,.error{border:2px solid CanvasText}.button{forced-color-adjust:auto}}
.skip{position:absolute;top:-100px;inset-inline-start:12px;background:#fff;padding:12px;z-index:4}.skip:focus{top:12px}
.shell{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:100vh}aside{background:var(--primary);padding:24px 16px;color:white}aside h2{margin:0 0 20px}aside nav{display:grid;gap:3px}
aside a{display:flex;align-items:center;min-height:44px;padding:8px 12px;color:white;text-decoration:none;border-radius:10px}aside a[aria-current=page]{color:var(--primary);background:white;font-weight:700}
main{padding:clamp(16px,3vw,44px);min-width:0;width:100%;max-width:1380px;margin-inline:auto}
h1{color:var(--primary);font-size:clamp(28px,4vw,42px);line-height:1.24}h2{color:var(--primary);font-size:clamp(20px,2.5vw,26px)}
.top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,185px),1fr));gap:14px}.card,.panel{background:#fff;border:1px solid var(--line);border-radius:18px;padding:clamp(16px,2.5vw,26px);margin-block:18px}
.card strong{display:block;font-size:clamp(26px,4vw,38px);color:var(--primary)}.muted{color:var(--muted)}
.notice{background:#fff;border-inline-start:4px solid var(--accent);padding:14px 18px}.warning{background:#fff8eb;border-inline-start:4px solid #BF7F12;padding:14px 18px}.error{background:#fff;border-inline-start:4px solid #C53F4B;padding:14px 18px}
.button{min-height:44px;padding:9px 18px;border:1px solid var(--cta);background:var(--cta);color:white;border-radius:10px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.button-outline{background:white;color:var(--cta)}.filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.filters>div{min-width:180px}
label{display:block;font-weight:700;margin-bottom:6px}select{min-height:44px;width:100%;max-width:330px;background:white;border:1px solid #97B4C8;border-radius:8px;padding:8px;font:inherit}
.scroll{overflow-x:auto;max-width:100%}table{border-collapse:collapse;width:100%;min-width:570px}th,td{border-bottom:1px solid var(--line);padding:12px;text-align:start;vertical-align:top}th{background:#EAF5FB;color:var(--primary)}
code{font-size:.86em;overflow-wrap:anywhere}.external{overflow-wrap:anywhere}.nav-bottom{display:flex;flex-wrap:wrap;gap:18px}.nav-bottom a{display:inline-flex;align-items:center;min-height:44px}
aside button{background:transparent;border:1px solid #fff;color:#fff;padding:8px 15px;min-height:44px;border-radius:8px;font:inherit;margin-top:18px;cursor:pointer}
@media(max-width:740px){.shell{grid-template-columns:1fr}aside{padding:15px}aside nav{grid-template-columns:repeat(auto-fit,minmax(130px,1fr))}main{padding:18px 14px}.filters>div{width:100%}}
@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a class="skip" href="#content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="shell">
<aside><h2>{{ $locale === 'ar' ? 'إدارة وليد سايت' : 'WalidSite Admin' }}</h2>
<nav aria-label="{{ $locale === 'ar' ? 'قائمة الإدارة' : 'Admin navigation' }}">
@foreach([
'admin.home'=>['en'=>'Overview','ar'=>'الرئيسية'],
'admin.github.index'=>['en'=>'GitHub sync','ar'=>'مزامنة GitHub'],
'admin.projects.index'=>['en'=>'Projects & CV','ar'=>'المشاريع والسيرة'],
'admin.audience.index'=>['en'=>'Audience & consent','ar'=>'الجمهور والموافقات'],
'admin.analytics.index'=>['en'=>'Analytics','ar'=>'التحليلات'],
'admin.content.index'=>['en'=>'Editorial review','ar'=>'مراجعة المحتوى'],
'admin.media.index'=>['en'=>'Image rights','ar'=>'حقوق الصور'],
'admin.quality.index'=>['en'=>'Quality gates','ar'=>'بوابات الجودة'],
'admin.audit.index'=>['en'=>'Security audit','ar'=>'تدقيق الأمان']
] as $route=>$label)
<a href="{{ route($route, ['lang'=>$locale]) }}" @if($route===$activeRoute) aria-current="page"@endif>{{ $label[$locale] }}</a>
@endforeach
</nav>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">{{ $locale === 'ar' ? 'خروج' : 'Sign out' }}</button></form></aside>
<main id="content" tabindex="-1">
<div class="top"><div><h1>{{ $names[$section][$locale] }}</h1>
<p class="muted">{{ $locale === 'ar' ? 'المعلومات المعروضة من سجلات مثبتة أو من مصدر عام متحقق، دون بيانات وهمية.' : 'Recorded or independently verified data only; no simulated metrics.' }}</p>
</div><a class="button button-outline" href="{{ route($activeRoute, $languageArgs) }}" lang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a></div>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if(session('notice'))<p role="status" class="warning">{{ session('notice') }}</p>@endif
@if(session('error'))<p role="alert" class="error">{{ session('error') }}</p>@endif
@if($errors->any())<div class="error" role="alert"><strong>{{ $locale === 'ar' ? 'طلب غير صالح' : 'Invalid request' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($section === 'github')
<section class="cards" aria-label="{{ $locale === 'ar' ? 'إحصاءات المستودعات' : 'Repository counts' }}">
<div class="card"><span>{{ $locale === 'ar' ? 'إدخالات القائمة المسموحة' : 'Allowlist entries' }}</span><strong>{{ $configuredCount }}</strong></div>
<div class="card"><span>{{ $locale === 'ar' ? 'عامة ومتحقق منها' : 'Verified public' }}</span><strong>{{ $repositories->count() }}</strong></div>
<div class="card"><span>{{ $locale === 'ar' ? 'غير متاحة' : 'Unavailable' }}</span><strong>{{ $unavailableCount }}</strong></div></section>
<section class="panel" aria-labelledby="github-refresh"><h2 id="github-refresh">{{ $locale === 'ar' ? 'تحديث بيانات GitHub' : 'Refresh GitHub verification' }}</h2>
<p class="muted">{{ $locale === 'ar' ? 'تحديث القراءة للبيانات العامة فقط. لا تغيّر هذه العملية إعدادات القائمة أو المستودعات نفسها، ولا تعرض أسماء المستودعات غير المؤكدة.' : 'Read-only public metadata verification. This does not edit allowlist configuration or repository data. Unverified repository names remain hidden.' }}</p>
<form method="post" action="{{ route('admin.github.sync', ['lang'=>$locale]) }}">@csrf
<button class="button" type="submit">{{ $locale === 'ar' ? 'إعادة التحقق الآن' : 'Verify public metadata' }}</button></form></section>
<section class="panel" aria-labelledby="github-list"><h2 id="github-list">{{ $locale === 'ar' ? 'المستودعات المتحققة' : 'Verified public repositories' }}</h2>
@if($repositories->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد مستودعات عامة متحققة حاليًا.' : 'No independently verified public repositories are available.' }}</p>
@else<div class="scroll"><table><thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'المستودع' : 'Repository' }}</th><th scope="col">{{ $locale === 'ar' ? 'اللغة' : 'Language' }}</th><th scope="col">{{ $locale === 'ar' ? 'آخر تحقق (UTC)' : 'Last verified (UTC)' }}</th></tr></thead><tbody>
@foreach($repositories as $repo)<tr><td><a target="_blank" rel="noopener noreferrer" class="external" href="{{ $repo['url'] }}">{{ $repo['full_name'] }}</a>
@if(is_string($repo['description'] ?? null) && $repo['description'] !== '')<p class="muted">{{ $repo['description'] }}</p>@endif</td><td>{{ $repo['language'] ?? '—' }}</td>
<td><time datetime="{{ $repo['verified_at'] }}">{{ $repo['verified_at'] }}</time></td></tr>@endforeach</tbody></table></div>@endif
@if($unavailableCount > 0)<p class="warning" role="status">{{ $locale === 'ar' ? 'تعذر التحقق من بعض الإدخالات؛ لم يتم كشفها أو نشرها.' : 'Some entries could not be verified and remain hidden.' }}</p>@endif
</section>
@elseif($section === 'projects')
<section class="panel" aria-labelledby="projects-filter"><h2 id="projects-filter">{{ $locale === 'ar' ? 'تصفية المشاريع' : 'Filter projects' }}</h2>
<form method="get" class="filters" action="{{ route('admin.projects.index') }}">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="project-status">{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</label><select id="project-status" name="status">
<option value="">{{ $locale === 'ar' ? 'كل الحالات' : 'All statuses' }}</option>
@foreach(['completed'=>['en'=>'Completed','ar'=>'مكتمل'],'in_progress'=>['en'=>'In progress','ar'=>'قيد التنفيذ'],'research'=>['en'=>'Research','ar'=>'بحث'],'archived'=>['en'=>'Archived','ar'=>'مؤرشف']] as $key=>$labels)
<option value="{{ $key }}" @selected(($filters['status'] ?? null)===$key)>{{ $labels[$locale] }}</option>@endforeach
</select></div><div><label for="project-visibility">{{ $locale === 'ar' ? 'الظهور' : 'Visibility' }}</label><select id="project-visibility" name="visibility">
<option value="">{{ $locale === 'ar' ? 'الكل' : 'All' }}</option><option value="public" @selected(($filters['visibility'] ?? null)==='public')>{{ $locale === 'ar' ? 'عام' : 'Public' }}</option>
<option value="private" @selected(($filters['visibility'] ?? null)==='private')>{{ $locale === 'ar' ? 'خاص' : 'Private' }}</option>
</select></div><button type="submit" class="button">{{ $locale === 'ar' ? 'تطبيق' : 'Apply filters' }}</button>
<a class="button button-outline" href="{{ route('admin.projects.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مسح' : 'Clear filters' }}</a></form>
</section><section class="panel" aria-labelledby="projects-list"><h2 id="projects-list">{{ $locale === 'ar' ? 'سجلات المشاريع' : 'Project records' }} ({{ $projects->total() }})</h2>
@if($projects->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد سجلات مطابقة.' : 'No matching projects.' }}</p>
@else<div class="scroll"><table><thead><tr>
<th scope="col">{{ $locale === 'ar' ? 'المشروع' : 'Project' }}</th><th scope="col">{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th><th scope="col">{{ $locale === 'ar' ? 'الظهور' : 'Visibility' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الأدلة الموثقة/الإجمالي' : 'Verified/total evidence' }}</th><th scope="col">{{ $locale === 'ar' ? 'المنشور' : 'Public proof' }}</th></tr></thead><tbody>
@foreach($projects as $project)
@php($ready = $project->verified_public_ready === true)
<tr><td><strong>{{ $project->title }}</strong><p class="muted"><code dir="ltr">{{ $project->slug }}</code></p></td>
<td>{{ $project->status }}</td><td>{{ $project->visibility === 'public' ? ($locale === 'ar' ? 'عام' : 'Public') : ($locale === 'ar' ? 'خاص' : 'Private') }}</td>
<td>{{ $project->verified_public_evidence_count }} / {{ $project->evidence_count }}</td>
<td>@if($ready)<a href="{{ route('public.project', ['slug'=>$project->slug,'lang'=>$locale]) }}">{{ $locale === 'ar' ? 'عرض الحالة العامة' : 'Open verified case' }}</a>
@else<span class="muted">{{ $locale === 'ar' ? 'غير جاهز للنشر' : 'Not public-ready' }}</span>@endif</td></tr>
@endforeach</tbody></table></div><nav aria-label="{{ $locale === 'ar' ? 'صفحات المشاريع' : 'Project pages' }}">{{ $projects->links() }}</nav>@endif
<p class="warning" role="status">{{ $locale === 'ar' ? 'يمكن للمالك تعديل مسودات المشاريع الخاصة عبر صفحة التحرير المرتبطة أدناه. النشر ومراجعة ادعاءات السيرة يتطلبان مسار الموافقة والتحقق المستقل؛ هذه الشاشة للعرض فقط.' : 'Owners can edit private project drafts through the linked editor below. Publishing and CV claim approval require separate verification and review; this screen remains read-only.' }}</p>
<a href="{{ route('public.resume', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'عرض السيرة العامة المنشورة' : 'View published resume' }}</a>
</section>
@elseif($section === 'audience')
<section class="cards" aria-label="{{ $locale === 'ar' ? 'إجمالي الجمهور' : 'Audience totals' }}">
<div class="card"><span>{{ $locale === 'ar' ? 'الحسابات النشطة' : 'Active accounts' }}</span><strong>{{ $accounts['active'] }}</strong></div>
<div class="card"><span>{{ $locale === 'ar' ? 'غير النشطة' : 'Inactive accounts' }}</span><strong>{{ $accounts['inactive'] }}</strong></div>
<div class="card"><span>{{ $locale === 'ar' ? 'أصحاب موافقة حالية' : 'Currently consented recipients' }}</span><strong>{{ $consented }}</strong></div></section>
<section class="panel" aria-labelledby="audience-delivery"><h2 id="audience-delivery">{{ $locale === 'ar' ? 'حالة الإشعارات' : 'Notification delivery' }}</h2>
<p role="status" class="warning">{{ $locale === 'ar' ? 'الإرسال متوقف: لا توجد خدمة دفع مفعلة أو إيصال مزود مُتحقق منه. لا يرسل العرض أي إشعارات ولا ينشئ شرائح.' : 'Delivery disabled: no verified push sender or provider receipt. This view sends no notifications and creates no segments.' }}</p>
@if(!$consentAvailable)<p role="alert" class="error">{{ $locale === 'ar' ? 'مخزن موافقات الإشعارات غير متاح؛ تبقى الخدمة متوقفة.' : 'Consent storage is unavailable; delivery remains fail-closed.' }}</p>@endif
<p>{{ $locale === 'ar' ? 'تجميع العدد يعتمد على موافقة مسجلة ولم يتم إلغاؤها لحساب نشط؛ إنشاء الشرائح وإلغاء الاشتراك من اللوحة لا يزالان غير متاحين.' : 'Counts require current unrevoked consent for an active account. Segment creation and dashboard unsubscribe management are not yet available.' }}</p>
<a href="{{ route('public.privacy', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'معلومات الخصوصية' : 'Privacy information' }}</a></section>
<section class="panel" aria-labelledby="role-counts"><h2 id="role-counts">{{ $locale === 'ar' ? 'حسابات حسب الدور' : 'Accounts by role' }}</h2>
@if($roles->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد أدوار مسجلة.' : 'No stored roles.' }}</p>
@else<div class="scroll"><table><thead><tr><th scope="col">{{ $locale === 'ar' ? 'الدور' : 'Role' }}</th><th scope="col">{{ $locale === 'ar' ? 'الحسابات' : 'Accounts' }}</th></tr></thead><tbody>
@foreach($roles as $role)<tr><td>{{ $role->name }}</td><td>{{ $role->users }}</td></tr>@endforeach
</tbody></table></div>@endif</section>
@elseif($section === 'analytics')
<section class="panel" aria-labelledby="analytics-period"><h2 id="analytics-period">{{ $locale === 'ar' ? 'فترة التقرير بالتوقيت العالمي' : 'Report window (UTC)' }}</h2>
<form method="get" class="filters" action="{{ route('admin.analytics.index') }}">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="usage-period">{{ $locale === 'ar' ? 'مهام AI والتكلفة' : 'AI jobs and cost' }}</label><select id="usage-period" name="period">
@foreach(['today'=>['en'=>'Today UTC','ar'=>'اليوم UTC'],'7d'=>['en'=>'Last 7 days','ar'=>'آخر ٧ أيام'],'30d'=>['en'=>'Last 30 days','ar'=>'آخر ٣٠ يومًا'],'all'=>['en'=>'All history','ar'=>'كل السجلات']] as $key=>$labels)
<option value="{{ $key }}" @selected($period===$key)>{{ $labels[$locale] }}</option>@endforeach
</select></div><button class="button" type="submit">{{ $locale === 'ar' ? 'تطبيق' : 'Apply' }}</button>
<a class="button button-outline" href="{{ route('admin.analytics.index', ['lang'=>$locale,'period'=>$period,'format'=>'csv']) }}">{{ $locale === 'ar' ? 'تصدير CSV' : 'Export CSV' }}</a>
</form></section>
<section class="panel" aria-labelledby="content-counts"><h2 id="content-counts">{{ $locale === 'ar' ? 'المحتوى الحالي — غير مفلتر بالفترة' : 'Current content snapshot — not period filtered' }}</h2>
<div class="cards"><div class="card"><span>{{ $locale === 'ar' ? 'مقالات موثقة منشورة' : 'Published verified articles' }}</span><strong>{{ $content['articles'] }}</strong></div>
<div class="card"><span>{{ $locale === 'ar' ? 'مشاريع موثقة منشورة' : 'Published verified projects' }}</span><strong>{{ $content['projects'] }}</strong></div>
<div class="card"><span>{{ $locale === 'ar' ? 'مسودات' : 'Drafts' }}</span><strong>{{ $content['drafts'] }}</strong></div></div></section>
<section class="panel" aria-labelledby="job-status"><h2 id="job-status">{{ $locale === 'ar' ? 'حالات المهام بالفترة' : 'AI job states in period' }}</h2>
@if($jobs->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد مهام في هذه الفترة.' : 'No AI jobs in this period.' }}</p>
@else<div class="scroll"><table><thead><tr><th scope="col">{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th><th scope="col">{{ $locale === 'ar' ? 'العدد' : 'Count' }}</th></tr></thead><tbody>
@foreach($jobs as $job)<tr><td>{{ $job->status }}</td><td>{{ $job->total }}</td></tr>@endforeach
</tbody></table></div>@endif</section>
<section class="panel" aria-labelledby="cost-status"><h2 id="cost-status">{{ $locale === 'ar' ? 'التكلفة لكل عملة' : 'Cost by currency' }}</h2>
<p class="muted">{{ $locale === 'ar' ? 'الأرقام بوحدات العملة الصغرى. لا تجمع عملات مختلفة ولا تمثل زيارات حية.' : 'Amounts are minor currency units. Currencies are never combined; this is not live traffic.' }}</p>
@if($usage->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد إيصالات استخدام للفترة.' : 'No usage receipts in this window.' }}</p>
@else<div class="scroll"><table><thead><tr><th scope="col">{{ $locale === 'ar' ? 'العملة' : 'Currency' }}</th>
<th scope="col">{{ $locale === 'ar' ? 'الإيصالات' : 'Receipts' }}</th><th scope="col">{{ $locale === 'ar' ? 'التكلفة (وحدات صغرى)' : 'Cost (minor units)' }}</th></tr></thead><tbody>
@foreach($usage as $row)<tr><td>{{ $row->cost_currency }}</td><td>{{ $row->receipts }}</td><td>{{ $row->cost_minor }}</td></tr>@endforeach
</tbody></table></div>@endif</section>
@endif
<nav class="nav-bottom" aria-label="{{ $locale === 'ar' ? 'إجراءات الإدارة المرتبطة' : 'Related admin actions' }}">
<a href="{{ route('admin.content.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'مراجعة المقالات' : 'Editorial review' }}</a>
<a href="{{ route('admin.audit.index', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'السجل الأمني' : 'Security audit' }}</a>
<a href="{{ route('admin.operations.projects', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'تحرير مسودات المشاريع' : 'Edit private project drafts' }}</a>
</nav>
</main></div></body></html>
