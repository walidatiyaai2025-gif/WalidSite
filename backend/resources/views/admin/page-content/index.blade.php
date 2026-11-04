@php($ar = $locale === 'ar')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ar ? 'محتوى الصفحات — الإدارة' : 'Page content — Admin' }}</title>
<style>
:root{color-scheme:light;--nav:#153b64;--green:#008b75;--ink:#153052;--edge:#d2e4ed}
*{box-sizing:border-box}body{margin:0;background:#f0f8fc;color:var(--ink);font:16px/1.5 Inter,Arial,sans-serif}
:lang(ar) body{font-family:Tajawal,Arial,sans-serif}
a{color:#0874ad}button,select,textarea,input{font:inherit}button,select{min-height:42px}button{background:#007d6f;color:#fff;border:0;border-radius:9px;padding:8px 16px;cursor:pointer}
a:focus-visible,button:focus-visible,select:focus-visible,textarea:focus-visible,input:focus-visible{outline:3px solid #02b7a2;outline-offset:3px}
.skip{position:absolute;top:-100px;background:#fff;padding:9px}.skip:focus{top:10px}
.layout{display:grid;grid-template-columns:minmax(190px,230px) minmax(0,1fr);min-height:100vh}
aside{background:var(--nav);color:#fff;padding:22px}aside a{color:#fff;display:block;padding:10px;border-radius:8px}
main{padding:clamp(14px,3vw,36px);min-width:0;max-width:1400px}
h1,h2,h3{line-height:1.3}header{display:flex;gap:16px;flex-wrap:wrap;justify-content:space-between;align-items:center}
.tabs{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.tabs a{text-decoration:none;border:1px solid #bedbe6;background:#fff;border-radius:10px;padding:9px 13px;min-height:42px;display:flex;align-items:center}
.tabs a[aria-current="page"]{background:#00566d;color:#fff}
.panel{padding:20px;background:white;border:1px solid var(--edge);border-radius:15px;margin:16px 0}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,310px),1fr));gap:14px}
.card{border:1px solid var(--edge);border-radius:13px;padding:15px;min-width:0}
.card h3{overflow-wrap:anywhere;font-size:16px;margin:0 0 12px}
label{display:block;font-weight:750;margin:10px 0 4px}
textarea{width:100%;min-height:120px;padding:10px;border:1px solid #9db9cd;border-radius:9px;resize:vertical}
input[type=text],input[type=number]{width:100%;min-height:42px;padding:8px;border:1px solid #9db9cd;border-radius:9px}
.field-state{color:#385773;font-size:13px}.checkbox{display:flex;align-items:start;gap:9px;font-weight:500}
.checkbox input{margin-top:6px}.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
.draft{background:#fff9eb;border:1px solid #efdcb0;border-radius:9px;padding:10px}
.published{background:#eaf9f4;border-radius:9px;padding:10px}
.status{border-inline-start:4px solid var(--green);padding:10px;background:#e5f8f3}
.error{border-inline-start:4px solid #aa3343;padding:10px;background:#fff0f2}
small{font-size:12px}.locale{display:inline-flex;align-items:center;padding:4px 8px;background:#eaf4fb;border-radius:99px;font-size:12px}
select{max-width:100%;padding:7px;border:1px solid #9db9cd;border-radius:9px}
@media(max-width:750px){.layout{grid-template-columns:1fr}aside{padding:12px}aside a{display:inline-block}main{padding:14px}}
</style>
@include('partials.web-fonts')
</head>
<body>
<a href="#content" class="skip">{{ $ar ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
<div class="layout"><aside>
<h2>{{ $ar ? 'إدارة وليد سايت' : 'WalidSite Admin' }}</h2>
<nav aria-label="{{ $ar ? 'التنقل الإداري' : 'Admin navigation' }}">
<a href="{{ route('admin.home',['lang'=>$locale]) }}">{{ $ar ? 'الرئيسية' : 'Dashboard' }}</a>
<a href="{{ route('admin.page-content.index',['lang'=>$locale]) }}" aria-current="page">{{ $ar ? 'محتوى صفحات الموقع' : 'Site page content' }}</a>
<a href="{{ route('admin.content.index',['lang'=>$locale]) }}">{{ $ar ? 'مراجعة المقالات' : 'Article review' }}</a>
<a href="{{ route('admin.operations.projects',['lang'=>$locale]) }}">{{ $ar ? 'إدارة المشاريع' : 'Projects & evidence' }}</a>
</nav>
</aside><main id="content" tabindex="-1">
<header>
<h1>{{ $ar ? 'إدارة نصوص الصفحات' : 'Manage site page content' }}</h1>
<a href="{{ route('admin.page-content.index',['page_key'=>$selectedPage,'section_key'=>$selectedSection,'lang'=>$ar?'en':'ar']) }}">{{ $ar ? 'English' : 'العربية' }}</a>
</header>
<p>{{ $ar ? 'المحتوى الافتراضي يأتي من التصاميم المعتمدة أو المسودات المقدمة. تعديل النص لا ينشره حتى يراجعه المالك ويعتمده.' : 'Default copy is seeded from approved designs or supplied drafts. Editing saves privately; explicit owner review and publication are required before anything changes publicly.' }}</p>
<p><strong>{{ $ar ? 'المهم' : 'Note' }}:</strong> {{ $ar ? 'المشاريع والمقالات والسيرة المهنية لها نظام أدلة ونشر منفصل لا يمكن تجاوزه هنا.' : 'Projects, articles, and the professional CV keep their separate evidence and publishing gates; this editor cannot bypass them.' }}</p>
@if(session('status'))<p role="status" class="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="error">@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
<nav class="tabs" aria-label="{{ $ar ? 'اختيار الصفحة' : 'Select page' }}">
@foreach(array_keys($catalog) as $pageName)
<a @if($pageName===$selectedPage) aria-current="page" @endif href="{{ route('admin.page-content.index',['page_key'=>$pageName,'lang'=>$locale]) }}">{{ ucfirst($pageName) }}</a>
@endforeach
</nav>
<nav class="tabs" aria-label="{{ $ar ? 'اختيار القسم' : 'Select section' }}">
@foreach(array_keys($catalog[$selectedPage]) as $sectionName)
<a @if($sectionName===$selectedSection) aria-current="page" @endif href="{{ route('admin.page-content.index',['page_key'=>$selectedPage,'section_key'=>$sectionName,'lang'=>$locale]) }}">{{ ucfirst($sectionName) }}</a>
@endforeach
</nav>
<section class="panel" aria-labelledby="slots-heading">
<h2 id="slots-heading">{{ $ar ? 'إضافة حقل تصميم مفقود' : 'Add a missing design slot' }}</h2>
<p>{{ $ar ? 'إضافة حقل معروف من التصميم فقط. لا يمكن إضافة ادعاءات مهنية أو مشاريع غير موثقة من هذه الصفحة.' : 'Restore an allowed design field only. Unverified career claims/projects cannot be created through this surface.' }}</p>
<form action="{{ route('admin.page-content.add',['lang'=>$locale]) }}" method="post">@csrf
<input type="hidden" name="page_key" value="{{ $selectedPage }}"><input type="hidden" name="section_key" value="{{ $selectedSection }}">
<label for="field">{{ $ar ? 'الحقل' : 'Field' }}</label>
<select id="field" name="field_key" required>
@foreach(array_keys($catalog[$selectedPage][$selectedSection]) as $field)
<option value="{{ $field }}">{{ $field }}</option>
@endforeach
</select>
<label for="slot-locale">{{ $ar ? 'اللغة' : 'Language' }}</label><select name="locale" id="slot-locale"><option value="en">English</option><option value="ar">العربية</option></select>
<div class="controls"><button type="submit">{{ $ar ? 'إضافة أو استعادة الحقل' : 'Add / restore field' }}</button></div>
</form>
</section>
<section aria-labelledby="entries-heading">
<h2 id="entries-heading">{{ $ar ? 'الحقول القابلة للتحرير' : 'Editable text slots' }} ({{ $rows->total() }})</h2>
<div class="cards">
@forelse($rows as $entry)
<article class="card">
<h3>{{ $entry->field_key }} <span class="locale" lang="{{ $entry->locale }}" dir="{{ $entry->locale==='ar'?'rtl':'ltr' }}">{{ strtoupper($entry->locale) }}</span></h3>
<p class="field-state">{{ $ar ? 'آخر مراجعة' : 'Revision' }}: {{ $entry->revision }} · {{ $entry->published_at ? ($ar ? 'منشور' : 'Published') : ($entry->published_text === '' ? ($ar ? 'مسحوب' : 'Withdrawn') : ($ar ? 'غير منشور' : 'Unpublished')) }}</p>
@if($entry->published_at && $entry->published_text!==null)<p class="published"><strong>{{ $ar ? 'النص المنشور' : 'Live text' }}:</strong> {{ $entry->published_text }}</p>@endif
<form action="{{ route('admin.page-content.draft',['entry'=>$entry->id,'lang'=>$locale]) }}" method="post">@csrf
<input type="hidden" name="revision" value="{{ $entry->revision }}">
<label for="text-{{ $entry->id }}">{{ $ar ? 'تعديل المسودة' : 'Edit private draft' }}</label>
<textarea id="text-{{ $entry->id }}" name="draft_text" dir="{{ $entry->locale==='ar'?'rtl':'ltr' }}" lang="{{ $entry->locale }}" maxlength="1500" required>{{ old('draft_text',$entry->draft_text ?? $entry->published_text ?? '') }}</textarea>
<div class="controls"><button type="submit">{{ $ar ? 'حفظ المسودة' : 'Save draft' }}</button></div>
</form>
@if($entry->draft_text!==null)
<form action="{{ route('admin.page-content.publish',['entry'=>$entry->id,'lang'=>$locale]) }}" method="post" class="draft">@csrf
<input type="hidden" name="revision" value="{{ $entry->revision }}">
<p><strong>{{ $ar ? 'المسودة المحفوظة' : 'Saved draft' }}:</strong> {{ $entry->draft_text }}</p>
<label for="source-{{ $entry->id }}">{{ $ar ? 'مرجع مراجعة النص' : 'Review source / evidence note' }}</label>
<input type="text" name="source_note" id="source-{{ $entry->id }}" minlength="12" maxlength="240" placeholder="{{ $ar ? 'مصدر النص الأصلي أو مرجع المالك' : 'Approved owner design / source reference' }}" required>
<label class="checkbox"><input type="checkbox" name="source_reviewed" value="1" required>{{ $ar ? 'راجعت المصدر وصحة المحتوى' : 'I verified the source and factual accuracy' }}</label>
<label class="checkbox"><input type="checkbox" name="publication_rights_confirmed" value="1" required>{{ $ar ? 'تحققت من صلاحية النشر وحقوق المحتوى' : 'I confirmed publication rights and clearance' }}</label>
<div class="controls"><button type="submit">{{ $ar ? 'اعتماد ونشر هذا النص' : 'Review & publish text' }}</button></div>
</form>
@endif
@if($entry->published_at && $entry->published_text !== null && $entry->published_text !== '')
<form action="{{ route('admin.page-content.retract',['entry'=>$entry->id,'lang'=>$locale]) }}" method="post" class="draft" data-testid="page-copy-retract-{{ $entry->id }}">@csrf
<input type="hidden" name="revision" value="{{ $entry->revision }}">
<p>{{ $ar ? 'سحب النص يوقف عرضه فورًا دون إعادة النص الافتراضي. يمكن نشر مسودة لاحقًا بعد مراجعة المصدر والحقوق.' : 'Withdrawal immediately hides this copy without restoring default fallback text. A new reviewed draft may be published later.' }}</p>
<label for="retract-reason-{{ $entry->id }}">{{ $ar ? 'سبب سحب النشر' : 'Reason for withdrawal' }}</label>
<input type="text" name="source_note" id="retract-reason-{{ $entry->id }}" minlength="12" maxlength="240" required>
<div class="controls"><button type="submit">{{ $ar ? 'سحب النص من الموقع' : 'Withdraw published text' }}</button></div>
</form>
@endif
</article>
@empty
<p role="status">{{ $ar ? 'لا توجد بيانات في هذا القسم. استعد الحقول من التصميم.' : 'No content slots in this section. Restore missing fields above.' }}</p>
@endforelse
</div>
{{ $rows->links() }}
</section>


@if($selectedPage === 'about')
<section class="panel" id="career-manager" aria-labelledby="career-editor-heading">
<h2 id="career-editor-heading">{{ $ar ? 'إدارة الخبرات المهنية (مسودة ← مراجعة ← نشر)' : 'Career timeline (draft → review → publish)' }}</h2>
<p>{{ $ar ? 'لا تظهر أي وظيفة جديدة أو تعديل قبل اعتماد المالك وحقوق النشر. لا تُغيّر الفترات المنسوخة من السيرة إلا بعد مراجعة المصدر.' : 'Changes stay private until explicit owner source and publication-rights review. Existing CV periods stay live until a reviewed replacement is published.' }}</p>
<div class="cards">
@foreach($careerRows as $position)
@php($draft = $position->draft_json ?? [])
<article class="card">
<h3>{{ $position->org_name }} · {{ $position->role_en }}</h3>
<p class="field-state">{{ $position->started_on?->format('Y') }} – {{ $position->ended_on?->format('Y') ?? 'Present' }} · {{ $position->verified && $position->publishable ? ($ar ? 'منشورة' : 'Published') : ($ar ? 'غير منشورة' : 'Unpublished') }} · {{ $ar ? 'المراجعة' : 'Revision' }} {{ $position->cms_revision }}</p>
<form action="{{ route('admin.about-career.draft',['position'=>$position->id,'lang'=>$locale]) }}" method="post">
@csrf<input type="hidden" name="revision" value="{{ $position->cms_revision }}">
<label>{{ $ar ? 'جهة العمل' : 'Organization' }}<input type="text" name="org_name" required maxlength="200" value="{{ old('org_name',$draft['org_name'] ?? $position->org_name) }}"></label>
<label>{{ $ar ? 'المسمى الإنجليزي' : 'English role' }}<input type="text" name="role_en" required maxlength="200" value="{{ old('role_en',$draft['role_en'] ?? $position->role_en) }}"></label>
<label>{{ $ar ? 'المسمى العربي' : 'Arabic role' }}<input type="text" name="role_ar" required maxlength="200" value="{{ old('role_ar',$draft['role_ar'] ?? $position->role_ar) }}"></label>
<label>{{ $ar ? 'سنة البداية' : 'Start year' }}<input type="number" min="1900" max="2100" name="start_year" value="{{ $draft['start_year'] ?? $position->started_on?->format('Y') }}" required></label>
<label>{{ $ar ? 'سنة النهاية (فارغة للحالي)' : 'End year (blank for current)' }}<input type="number" min="1900" max="2100" name="end_year" value="{{ $draft['end_year'] ?? $position->ended_on?->format('Y') }}"></label>
<div class="controls"><button type="submit">{{ $ar ? 'حفظ تعديل كمسودة' : 'Save private revision' }}</button></div>
</form>
@if($position->draft_json)
<form action="{{ route('admin.about-career.publish',['position'=>$position->id,'lang'=>$locale]) }}" method="post" class="draft">
@csrf<input type="hidden" name="revision" value="{{ $position->cms_revision }}">
<p>{{ $ar ? 'المسودة في انتظار المراجعة' : 'Private career revision pending review' }}</p>
<label>{{ $ar ? 'المصدر والتوثيق' : 'Career source/evidence note' }}<input type="text" name="source_note" required minlength="12" maxlength="240"></label>
<label class="checkbox"><input type="checkbox" name="source_reviewed" value="1" required>{{ $ar ? 'راجعت مصدر الخبرة والتواريخ' : 'I verified employer, role, and dates' }}</label>
<label class="checkbox"><input type="checkbox" name="publication_rights_confirmed" value="1" required>{{ $ar ? 'أؤكد حق نشر بيانات الوظيفة' : 'I confirm rights to publish this professional record' }}</label>
<div class="controls"><button type="submit">{{ $ar ? 'اعتماد ونشر الفترة' : 'Review & publish career period' }}</button></div>
</form>
@endif
@if($position->publishable)
<form action="{{ route('admin.about-career.retract',['position'=>$position->id,'lang'=>$locale]) }}" method="post">
@csrf<input type="hidden" name="revision" value="{{ $position->cms_revision }}">
<div class="controls"><button type="submit">{{ $ar ? 'سحب الوظيفة من الموقع' : 'Unpublish career period' }}</button></div>
</form>
@endif
</article>
@endforeach
</div>
<h3>{{ $ar ? 'إضافة خبرة مهنية جديدة كمسودة خاصة' : 'Add a private career period' }}</h3>
<form action="{{ route('admin.about-career.create',['lang'=>$locale]) }}" method="post" class="card">
@csrf
<div class="cards">
<div><label for="career-org">{{ $ar ? 'جهة العمل' : 'Organization' }}</label><input id="career-org" type="text" name="org_name" required maxlength="200"></div>
<div><label for="career-en">{{ $ar ? 'المسمى الإنجليزي' : 'English role' }}</label><input id="career-en" type="text" name="role_en" required maxlength="200"></div>
<div><label for="career-ar">{{ $ar ? 'المسمى العربي' : 'Arabic role' }}</label><input id="career-ar" type="text" name="role_ar" required maxlength="200"></div>
<div><label for="career-start">{{ $ar ? 'سنة البداية' : 'Start year' }}</label><input id="career-start" type="number" name="start_year" min="1900" max="2100" required></div>
<div><label for="career-end">{{ $ar ? 'سنة النهاية' : 'End year' }}</label><input id="career-end" type="number" name="end_year" min="1900" max="2100"></div>
</div>
<div class="controls"><button type="submit">{{ $ar ? 'إضافة مسودة جديدة' : 'Add private draft' }}</button></div>
</form>
</section>
@endif
@if($selectedPage === 'about')
<section class="panel" aria-labelledby="media-heading">
<h2 id="media-heading">{{ $ar ? 'الشعارات والصور والأيقونات' : 'About images, employer logos & technology icons' }}</h2>
<p>{{ $ar ? 'تظهر الأيقونات الافتراضية إلى أن ترفع صورة أصلية مصرحًا بنشرها وتراجعها ثم تنشرها. الصورة الشخصية الافتراضية هي الصورة الأصلية المعتمدة فقط.' : 'Built-in icons remain until you upload a genuine rights-cleared logo, preview the draft and explicitly publish. The default portrait is the approved owner original.' }}</p>
<div class="cards">
@foreach($mediaRows as $asset)
<article class="card">
<h3>{{ $asset->label }} <small>({{ $asset->slot }})</small></h3>
<p class="field-state">{{ ucfirst($asset->kind) }} · {{ $asset->published_path ? ($ar ? 'صورة منشورة' : 'Published image') : ($ar ? 'أيقونة افتراضية' : 'Built-in icon') }} · {{ $ar ? 'مراجعة' : 'Revision' }} {{ $asset->revision }}</p>
<img src="{{ route('public.about-media',['slot'=>$asset->slot]) }}" alt="{{ $asset->label }}" loading="lazy" width="72" height="72" style="display:block;object-fit:contain;max-width:100%;margin-bottom:8px">
@if($asset->draft_path)
<p class="draft">{{ $ar ? 'معاينة المسودة الجديدة:' : 'Unpublished draft preview:' }}</p>
<img src="{{ route('admin.about-media.preview',['slot'=>$asset->slot,'lang'=>$locale]) }}" alt="{{ $asset->label.' draft' }}" width="72" height="72" style="display:block;object-fit:contain;max-width:100%">
@endif
<form action="{{ route('admin.about-media.draft',['slot'=>$asset->slot,'lang'=>$locale]) }}" method="post" enctype="multipart/form-data">
@csrf
<input type="hidden" name="revision" value="{{ $asset->revision }}">
<label for="media-{{ $asset->slot }}">{{ $ar ? 'ملف الصورة (PNG/JPEG/WebP حتى 1.5MB)' : 'Upload image (PNG/JPEG/WebP, max 1.5 MB)' }}</label>
<input id="media-{{ $asset->slot }}" type="file" name="image" accept="image/png,image/jpeg,image/webp" required>
<div class="controls"><button type="submit">{{ $ar ? 'حفظ مسودة الصورة' : 'Save image draft' }}</button></div>
</form>
@if($asset->draft_path)
<form action="{{ route('admin.about-media.publish',['slot'=>$asset->slot,'lang'=>$locale]) }}" method="post" class="draft">
@csrf
<input type="hidden" name="revision" value="{{ $asset->revision }}">
<label for="media-note-{{ $asset->slot }}">{{ $ar ? 'مرجع المصدر وصلاحية النشر' : 'Image provenance / source note' }}</label>
<input type="text" id="media-note-{{ $asset->slot }}" name="source_note" required minlength="12" maxlength="240">
<label class="checkbox"><input type="checkbox" name="source_reviewed" value="1" required>{{ $ar ? 'راجعت الصورة ومصدرها' : 'I reviewed the image and source' }}</label>
<label class="checkbox"><input type="checkbox" name="publication_rights_confirmed" value="1" required>{{ $ar ? 'لدي حق نشر هذه الصورة' : 'I have publication rights' }}</label>
<div class="controls"><button type="submit">{{ $ar ? 'اعتماد ونشر الصورة' : 'Review & publish image' }}</button></div>
</form>
@endif
@if($asset->published_path)
<form action="{{ route('admin.about-media.retract',['slot'=>$asset->slot,'lang'=>$locale]) }}" method="post">
@csrf<input type="hidden" name="revision" value="{{ $asset->revision }}">
<div class="controls"><button type="submit">{{ $ar ? 'سحب الصورة المنشورة' : 'Retract published image' }}</button></div>
</form>
@endif
</article>
@endforeach
</div>
</section>
@endif
</main></div></body></html>
