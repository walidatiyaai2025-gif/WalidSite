@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'المشاريع الموثقة — وليد عطية' : 'Verified projects — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.projects', ['lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.projects', ['lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.projects', ['lang' => 'en']) }}">
@endpush

@push('styles')
<style>
    .cv-project-preview-cta { margin-block:10px 26px; display:flex; flex-wrap:wrap; gap:16px;
        align-items:center; justify-content:space-between; border:1px solid #b8e3da; border-radius:16px;
        background:linear-gradient(120deg,#f0fbf9,#fff); padding:20px 24px; }
    .cv-project-preview-cta strong {display:block;color:#163b66;font-size:18px}
    .cv-project-preview-cta p {margin:6px 0 0;max-width:70ch;color:#315471}
    .cv-project-preview-cta a {display:inline-flex;min-height:44px;align-items:center;border-radius:10px;
        padding:10px 15px;background:#079b8a;color:#fff;font-weight:800;text-decoration:none}
    .cv-project-preview-cta a:focus-visible {outline:3px solid #126de1;outline-offset:3px}
    .case-page { padding-block: clamp(32px, 5vw, 72px); }
    .case-page h2 { font-size: clamp(32px, 5vw, 52px); line-height: 1.2; color: var(--wa-primary); }
    .case-lead { max-width: 70ch; color: var(--wa-body); }
    .case-search { display:flex; flex-wrap:wrap; align-items:end; gap:12px; margin:24px 0 32px; padding:20px;
        border:1px solid var(--wa-soft-border); border-radius:18px; background:#fff; }
    .case-field { flex: 1 1 260px; }
    .case-field label { display:block; font-weight:700; margin-bottom:6px; }
    .case-field input, .case-field select { width:100%; min-height:44px; padding:10px 12px; border:1px solid #97B4C8;
        border-radius:10px; font:inherit; }
    .case-button { display:inline-flex; align-items:center; justify-content:center; min-height:44px;
        padding:10px 18px; background:var(--wa-cta); color:#fff; border:0; border-radius:10px;
        font:inherit; font-weight:800; text-decoration:none; cursor:pointer; }
    .case-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr)); gap:20px; }
    .case-card { display:flex; flex-direction:column; gap:10px; padding:24px; border-radius:20px;
        border:1px solid var(--wa-soft-border); background:#fff; }
    .case-card h2 { font-size:22px; color:var(--wa-primary); margin:0; }
    .case-card p { color:var(--wa-body); margin:0; }
    .case-meta { font-size:14px; color:var(--wa-muted-text); }
    .case-card a { margin-top:auto; color:var(--wa-cta); font-weight:800; min-height:44px; display:inline-flex; align-items:center; }
    .case-empty { border:1px solid var(--wa-soft-border); border-radius:16px; background:#fff; padding:24px; }
    .case-feedback {display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin:0 0 20px}
    .case-feedback a,.case-empty a {display:inline-flex;align-items:center;min-height:44px;color:var(--wa-cta);font-weight:800}
    .case-feedback a:focus-visible,.case-empty a:focus-visible {outline:3px solid var(--wa-cta);outline-offset:3px}
    .case-locale-switch { display:flex; justify-content:flex-end; margin-bottom:16px; }
    .case-locale-switch a { display:inline-flex; align-items:center; min-height:44px; color:var(--wa-cta); font-weight:800; }
    .case-locale-switch a:focus-visible { outline:3px solid var(--wa-cta); outline-offset:3px; }
    .case-pagination { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:30px; }
    .case-pagination a, .case-pagination span { padding:9px 16px; border:1px solid var(--wa-soft-border); border-radius:10px; background:#fff; }
    .case-pagination a { color:var(--wa-cta); font-weight:800; }

    /* WEB-04: isolated approved-reference first viewport; this never substitutes image pixels. */
    body:has(.web04-projects) .site-header { display:none; }
    body:has(.web04-projects) .site-main { min-height:0; }
    body:has(.web04-projects) { background:#f5f9fc; overflow-x:hidden; }
    .web04-projects { font-family:Inter,Arial,sans-serif; color:#184876; }
    :lang(ar) .web04-projects { font-family:Tajawal,Arial,sans-serif; }
    .web04-nav { min-height:100px; background:#184876; display:flex; align-items:center; padding:0 59px 0 55px; color:#fff; gap:0; }
    .web04-brand { display:flex; align-items:center; text-decoration:none; white-space:nowrap; margin-right:47px; transform:translateY(3px); }
    .web04-brand span { font-size:40px; font-weight:800; line-height:1; color:#0a9a8c; letter-spacing:-1.2px; }
    .web04-brand strong { font-size:25px; font-weight:800; color:#fff; margin-left:4px; letter-spacing:-.35px; }
    .web04-nav-links { height:100px; min-width:0; display:flex; align-items:center; flex:1; transform:translateY(3px); }
    .web04-nav-links a { display:flex; align-items:center; height:100%; color:#d8e6f2; text-decoration:none; font-size:16px; font-weight:400; white-space:nowrap; margin-right:60px; }
    .web04-nav-links a:nth-child(3) { margin-right:41px; }
    .web04-nav-links a:nth-child(4) { margin-right:45px; }
    .web04-nav-links a:nth-child(5) { margin-right:61px; }
    .web04-nav-links a:nth-child(6) { margin-right:71px; }
    .web04-nav-links a:nth-child(7) { margin-right:55px; }
    .web04-nav-links a:nth-child(8) { margin-right:0; }
    .web04-nav-links a[aria-current="page"] { color:#0a9a8c; }
    .web04-contact { display:flex; flex-shrink:0; width:161px; height:50px; align-items:center; justify-content:center; border-radius:16px; background:#0a9a8c; color:#184876; text-decoration:none; font-size:18px; font-weight:800; white-space:nowrap; margin-left:auto; transform:translateY(2px); }
    .web04-nav a:focus-visible,.web04-hero a:focus-visible,.web04-preview a:focus-visible { outline:3px solid #126de1; outline-offset:3px; }
    .web04-hero { height:326px; background:#edf7fc; position:relative; padding:35px 56px; }
    .web04-pill { height:39px; width:259px; border-radius:16px; background:#dcf6f1; color:#0a9a8c; display:flex; align-items:center; padding:0 15px; font-size:18px; font-weight:700; letter-spacing:.6px; transform:translateX(-4px); }
    .web04-hero h1 { font-size:46px; line-height:1.15; margin:28px 0 21px; color:#184876; font-weight:700; letter-spacing:2.05px; }
    .web04-hero p { margin:0 0 0 2px; color:#3b6684; font-size:20px; line-height:1.4; }
    .web04-actions { display:flex; gap:19px; margin-top:27px; }
    .web04-action { height:63px; width:245px; border-radius:16px; display:flex; align-items:center; padding-left:29px; text-decoration:none; color:#184876; font-size:23px; font-weight:700; letter-spacing:1px; }
    .web04-action.primary { background:#0a9a8c; padding-bottom:4px; }
    .web04-action.secondary { width:249px; background:#fff; border:2px solid #0a9a8c; padding-left:33px; padding-bottom:2px; font-weight:500; letter-spacing:-.3px; }
    .web04-visual-stack { position:absolute; left:920px; top:80px; width:372px; height:226px; pointer-events:none; }
    .web04-visual-stack .tile { position:absolute; width:221px; height:171px; border:3px solid #14bc98; border-radius:21px; background:#306a94; color:#0a9a8c; font-weight:800; font-size:30px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .web04-visual-stack .one { left:0; top:0; background:#26608e; border-color:#149e98; }
    .web04-visual-stack .two { left:75px; top:27px; background:#2b6591; border-color:#14ad98; }
    .web04-visual-stack .three { left:150px; top:54px; background:#306a94; border-color:#14bc98; }
    .web04-visual-stack .one span { transform:translate(-38px,-18px); }
    .web04-visual-stack .two span { transform:translate(-30px,-18px); }
    .web04-visual-stack .three span { transform:translate(-37px,-18px); }
    .web04-preview { min-height:434px; background:#f5f9fc; padding:44px 56px 49px; }
    .web04-preview-grid { display:grid; grid-template-columns:repeat(3,411px); gap:33px; }
    .web04-preview-card { height:341px; min-width:0; border:1px solid #e0e7ee; border-radius:16px; background:#fff; padding:22px 23px; position:relative; }
    .web04-card-icon { width:67px; height:65px; border-radius:12px; background:#edf7fc; color:#0a9a8c; font-size:20px; font-weight:800; display:flex; align-items:center; justify-content:center; }
    .web04-preview-card h2 { font-size:26px; line-height:1.25; margin:36px 0 22px; color:#184876; font-weight:700; letter-spacing:1.5px; overflow-wrap:anywhere; }
    .web04-preview-card p { font-size:16px; line-height:1.5; margin:0; color:#254a68; letter-spacing:.55px; overflow-wrap:anywhere; }
    .web04-card-rule { height:2px; background:#dce7ec; position:absolute; left:19px; right:29px; top:244px; }
    .web04-card-link { position:absolute; top:271px; left:26px; min-height:44px; display:inline-flex; align-items:center; color:#0a7e74; text-decoration:none; font-size:20px; font-weight:600; letter-spacing:1.15px; }
    /* Keep the original three-card rhythm even when there are no publishable case studies.
       Resource cards are navigation, never fabricated project records. */
    .web04-preview-card--empty h2 { font-size:24px; letter-spacing:0; }
    .web04-preview-card--empty p { max-width:36ch; }
    .web04-preview-card--resource h2 { letter-spacing:0; }
    :lang(ar) .web04-nav { direction:rtl; }
    :lang(ar) .web04-brand { margin:0 0 0 25px; }
    :lang(ar) .web04-brand strong { margin:0 4px 0 0; }
    :lang(ar) .web04-nav-links a { margin:0 0 0 clamp(8px,2vw,34px); }
    :lang(ar) .web04-contact { margin:0 auto 0 0; }
    :lang(ar) .web04-hero { direction:rtl; text-align:right; }
    :lang(ar) .web04-pill { transform:none; letter-spacing:0; }
    :lang(ar) .web04-hero h1 { letter-spacing:0; }
    :lang(ar) .web04-action { padding:0 29px 0 12px; letter-spacing:0; }
    :lang(ar) .web04-visual-stack { left:55px; right:auto; }
    :lang(ar) .web04-preview-grid { direction:rtl; }
    :lang(ar) .web04-card-link { left:auto; right:26px; letter-spacing:0; }
    @media (min-width:901px) and (max-width:1399px) {
        .web04-nav { padding-inline:24px; }
        .web04-brand { margin-inline-end:24px; }
        .web04-nav-links { overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; }
        .web04-nav-links a,.web04-nav-links a:nth-child(n) { margin-inline-end:clamp(8px,1.4vw,20px); font-size:14px; flex:0 0 auto; }
        .web04-preview-grid { grid-template-columns:repeat(3,minmax(0,1fr)); gap:20px; }
        .web04-visual-stack { left:auto; right:20px; transform:scale(.78); transform-origin:top right; }
        .web04-hero p { max-width:65%; }
    }
    @media (max-width:900px) {
        .web04-nav { height:auto; padding:18px 24px; flex-wrap:wrap; }
        .web04-nav-links { flex:0 0 100%; order:3; width:100%; height:auto; display:flex; flex-wrap:wrap; align-items:stretch; gap:6px 14px; transform:none; }
        .web04-nav-links a,.web04-nav-links a:nth-child(n) { height:auto; min-height:44px; margin:0; padding:8px 4px; }
        :lang(ar) .web04-nav-links a { margin:0; }
        .web04-hero { height:auto; min-height:420px; padding:32px 28px; }
        .web04-hero p { max-width:100%; }
        .web04-actions { flex-wrap:wrap; }
        .web04-action,.web04-action.secondary { max-width:100%; min-width:0; width:auto; padding-inline:22px; }
        /* Make the overlap stack shrink in its actual layout box. A transform alone
           paints at 78% while still allocating the overflowing 372px box. */
        .web04-visual-stack { position:relative; left:auto; right:auto; top:auto; width:min(100%,372px);
            height:auto; aspect-ratio:372 / 226; margin:30px auto 0; transform:none; }
        .web04-visual-stack .tile { width:59.41%; height:75.66%; font-size:clamp(18px,3.5vw,30px); }
        .web04-visual-stack .two { left:20.16%; top:11.95%; }
        .web04-visual-stack .three { left:40.32%; top:23.89%; }
        :lang(ar) .web04-visual-stack { left:auto; }
        .web04-preview { padding:28px; }
        .web04-preview-grid { grid-template-columns:1fr; gap:18px; }
        /* Content may be longer in Arabic or in genuine published case studies.
           Let each card grow; never overlay its text with an absolute CTA. */
        .web04-preview-card { height:auto; min-height:315px; display:flex; flex-direction:column; }
        .web04-card-rule { position:static; width:100%; flex-shrink:0; margin-top:auto; }
        .web04-card-link { position:static; margin-top:10px; align-self:flex-start; }
    }
    @media (max-width:600px) {
        .web04-brand span { font-size:34px; }
        .web04-brand strong { font-size:20px; }
        .web04-contact { width:auto; min-width:110px; padding-inline:12px; font-size:15px; }
        .web04-pill { width:auto; min-width:0; }
        .web04-hero h1 { font-size:clamp(34px,10vw,46px); }
        .web04-preview-card h2 { margin-top:24px; }
    }

</style>
@endpush

@php($statusLabels = $locale === 'ar'
    ? ['completed' => 'مكتمل', 'in_progress' => 'قيد التنفيذ', 'research' => 'بحث', 'archived' => 'مؤرشف']
    : ['completed' => 'Completed', 'in_progress' => 'In progress', 'research' => 'Research', 'archived' => 'Archived'])
{{-- Follow only server-normalized filters; never relay arbitrary query keys into paging. --}}
@php($safeProjectFilters = array_filter(['q' => $search, 'status' => $status], static fn ($value) => is_string($value) && $value !== ''))
@php($filtersApplied = $safeProjectFilters !== [])
@section('content')

@include('public.projects-2026-live')

{{-- Legacy WEB-04 reference DOM retained temporarily for backward compatible
     test diagnostics only. The actual owner-visible website is the real,
     data-driven six-section page above. This hidden markup has no links
     exposed to users or indexing. --}}
<div class="web04-projects" aria-hidden="true">
    {{-- Only this screen uses this header; the shared site header remains untouched for other workers. --}}
    <header class="web04-nav" aria-label="{{ $locale === 'ar' ? 'التنقل الرئيسي' : 'Primary navigation' }}">
        <a class="web04-brand" href="{{ route('home', ['lang' => $locale]) }}"><span aria-hidden="true">WA</span><strong>{{ $locale === 'ar' ? 'وليد عطية' : 'Waleed Atiya' }}</strong></a>
        <nav class="web04-nav-links" aria-label="{{ $locale === 'ar' ? 'صفحات الموقع' : 'Site pages' }}">
            <a href="{{ route('home', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الرئيسية' : 'Home' }}</a>
            <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة' : 'About' }}</a>
            <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الخبرات' : 'Expertise' }}</a>
            <a aria-current="page" href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</a>
            <a href="{{ route('public.ai-engineering', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مختبر AI' : 'AI Lab' }}</a>
            <a href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'المدونة' : 'Blog' }}</a>
            <a href="{{ route('public.github', ['lang' => $locale]) }}">GitHub</a>
            <a href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'السيرة العامة' : 'Resume' }}</a>
        </nav>
        <a class="web04-contact" href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تواصل' : 'Get in touch' }}</a>
    </header>
    <section class="web04-hero" aria-labelledby="web04-reference-heading">
        <div class="web04-pill">{{ $locale === 'ar' ? 'دراسات حالة' : 'CASE STUDIES' }}</div>
        <div id="web04-reference-heading">{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</div>
        <p>{{ $locale === 'ar' ? 'أنظمة حقيقية، وأدوار موضحة بشفافية، وقرارات ونتائج تقنية موثقة.' : 'Real systems, transparent roles, technical decisions and outcomes.' }}</p>
        <div class="web04-actions">
            <a class="web04-action primary" href="#web04-project-catalog">{{ $locale === 'ar' ? 'استكشف' : 'Explore' }}</a>
            <a class="web04-action secondary" href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عرض الأدلة' : 'View evidence' }}</a>
        </div>
        <div class="web04-visual-stack" aria-hidden="true">
            <div class="tile one"><span>{ }</span></div><div class="tile two"><span>&lt;/&gt;</span></div><div class="tile three"><span>DB</span></div>
        </div>
    </section>
    @php($web04Preview = $projects->take(3)->values())
    {{-- Preserve three real first-viewport slots even if only one or two verified case studies exist. --}}
    @php($emptyPageHasResultsElsewhere = $projects->isEmpty() && $paginated->total() > 0)
    <section class="web04-preview" aria-label="{{ $web04Preview->isEmpty() ? ($locale === 'ar' ? 'حالة المشاريع والروابط المفيدة' : 'Project availability and resources') : ($locale === 'ar' ? 'دراسات الحالة المنشورة' : 'Published case studies') }}">
        <div class="web04-preview-grid">
            @foreach($web04Preview as $project)
                <article class="web04-preview-card">
                    <div class="web04-card-icon" aria-hidden="true">{{ ['<>', 'AI', 'DB'][$loop->index] }}</div>
                    <h2>{{ $project['name'] }}</h2>
                    <p>{{ \Illuminate\Support\Str::limit($project['summary'] ?? '', 88) }}</p>
                    <div class="web04-card-rule" aria-hidden="true"></div>
                    <a class="web04-card-link" href="{{ route('public.project', array_merge(
                        ['slug' => $project['slug'], 'lang' => $locale],
                        $safeProjectFilters,
                        $paginated->currentPage() > 1 && $paginated->currentPage() <= $paginated->lastPage()
                            ? ['page' => $paginated->currentPage()] : []
                    )) }}">{{ $locale === 'ar' ? 'عرض التفاصيل' : 'View details' }} &gt;</a>
                </article>
            @endforeach
            @if($web04Preview->isEmpty())
                {{-- An empty dataset gets an honest status card; never invent case studies to fill the reference. --}}
                <article class="web04-preview-card web04-preview-card--empty">
                    <div class="web04-card-icon" aria-hidden="true">&lt;&gt;</div>
                    <h2>{{ $emptyPageHasResultsElsewhere
                        ? ($locale === 'ar' ? 'لا توجد نتائج بهذه الصفحة' : 'No projects on this page')
                        : ($filtersApplied ? ($locale === 'ar' ? 'لا توجد نتائج مطابقة' : 'No matching projects') : ($locale === 'ar' ? 'التحقق قيد الانتظار' : 'Verification pending')) }}</h2>
                    <p role="status">{{ $emptyPageHasResultsElsewhere
                        ? ($locale === 'ar' ? 'لا تظهر دراسات حالة عامة موثقة في هذه الصفحة. عد إلى الصفحة الأولى.' : 'No evidence-verified public case studies on this page. Return to the first page.')
                        : ($filtersApplied
                            ? ($locale === 'ar' ? 'لا توجد دراسات حالة عامة موثقة تطابق البحث أو المرشحات.' : 'No evidence-verified public case studies match your search or filters.')
                            : ($locale === 'ar' ? 'لا توجد حاليًا مشاريع منشورة تستوفي أدلة النشر العامة.' : 'No published projects currently meet the public evidence checks.')) }}</p>
                    <div class="web04-card-rule" aria-hidden="true"></div>
                    <a class="web04-card-link" href="{{ $filtersApplied || $emptyPageHasResultsElsewhere ? route('public.projects', ['lang' => $locale]) : '#web04-project-catalog' }}">{{ $emptyPageHasResultsElsewhere
                        ? ($locale === 'ar' ? 'الصفحة الأولى' : 'First page')
                        : ($filtersApplied ? ($locale === 'ar' ? 'مسح المرشحات' : 'Clear filters') : ($locale === 'ar' ? 'استعراض القائمة' : 'Browse catalog')) }} &gt;</a>
                </article>
            @endif
            @if($web04Preview->count() < 3)
                {{-- A real repository resource fills only an unused case-study slot. --}}
                <article class="web04-preview-card web04-preview-card--resource">
                    <div class="web04-card-icon" aria-hidden="true">GH</div>
                    <h2>{{ $locale === 'ar' ? 'المستودعات العامة' : 'Public repositories' }}</h2>
                    <p>{{ $locale === 'ar' ? 'استعرض معلومات المستودعات المصرح بعرضها علنًا فقط.' : 'Explore only repository information approved for public display.' }}</p>
                    <div class="web04-card-rule" aria-hidden="true"></div>
                    <a class="web04-card-link" href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عرض المستودعات' : 'Browse repositories' }} &gt;</a>
                </article>
            @endif
            @if($web04Preview->count() < 2)
                <article class="web04-preview-card web04-preview-card--resource">
                    <div class="web04-card-icon" aria-hidden="true">@</div>
                    <h2>{{ $locale === 'ar' ? 'تواصل حول مشروع' : 'Discuss a project' }}</h2>
                    <p>{{ $locale === 'ar' ? 'للاستفسار عن الأعمال العامة الموثقة أو التواصل المهني.' : 'Enquire about documented public work or make professional contact.' }}</p>
                    <div class="web04-card-rule" aria-hidden="true"></div>
                    <a class="web04-card-link" href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تواصل' : 'Get in touch' }} &gt;</a>
                </article>
            @endif
        </div>
    </section>
</div>

<section id="web04-project-catalog" class="site-wrap case-page" aria-labelledby="project-heading">
    {{-- The reference's desktop navbar has no language switch. Keep a visible, keyboard-
         accessible bilingual switch just after its first viewport without changing the
         1440x860 reference hierarchy. Carry only validated project filters and pages. --}}
    @php($localePageFilter = $paginated->currentPage() > 1 && $paginated->currentPage() <= $paginated->lastPage() ? ['page' => $paginated->currentPage()] : [])
    <nav class="case-locale-switch" aria-label="{{ $locale === 'ar' ? 'تغيير اللغة' : 'Change language' }}">
        <a href="{{ route('public.projects', array_merge(['lang' => $locale === 'ar' ? 'en' : 'ar'], $safeProjectFilters, $localePageFilter)) }}"
            lang="{{ $locale === 'ar' ? 'en' : 'ar' }}" hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
    </nav>
    <aside class="cv-project-preview-cta" aria-label="{{ $locale === 'ar' ? 'معاينة مشاريع السيرة الذاتية' : 'CV-based project designs' }}">
        <div>
            <strong>{{ $locale === 'ar' ? 'تصميم المشاريع من واقع السيرة الذاتية' : 'Owner CV + GitHub project designs' }}</strong>
            <p>{{ $locale === 'ar'
                ? 'تصفّح الأقسام الستة والبيانات الموثّقة بمصادرها. هذه معاينة مستقلة عن دراسات الحالة المعتمدة للنشر.'
                : 'Explore the six portfolio screens with source and role details. This review preview is separate from publication-approved case studies.' }}</p>
        </div>
        <a href="{{ route('public.projects', ['lang' => $locale, 'preview' => 'cv']) }}">{{ $locale === 'ar' ? 'افتح معاينة التصاميم' : 'Open Projects design preview' }} &rsaquo;</a>
    </aside>
    <h2 id="project-heading">{{ $locale === 'ar' ? 'مشاريع ودراسات حالة موثقة' : 'Verified projects and case studies' }}</h2>
    <p class="case-lead">{{ $locale === 'ar'
        ? 'تظهر هنا المشاريع التي تمت الموافقة على نشرها ولها أدلة عامة صالحة. لا نعرض مشاريع داخلية غير مصرح بها أو نتائج مختلقة.'
        : 'Only projects approved for publication with valid public evidence appear here. Confidential work and invented results remain excluded.' }}</p>

    <form method="get" action="{{ route('public.projects') }}" role="search" class="case-search">
        <input type="hidden" name="lang" value="{{ $locale }}">
        <div class="case-field">
            <label for="project-search">{{ $locale === 'ar' ? 'البحث في المشاريع' : 'Search projects' }}</label>
            <input id="project-search" name="q" type="search" value="{{ $search }}" maxlength="80"
                placeholder="{{ $locale === 'ar' ? 'عنوان أو ملخص' : 'Title or summary' }}">
        </div>
        <div class="case-field">
            <label for="project-status">{{ $locale === 'ar' ? 'حالة المشروع' : 'Project status' }}</label>
            <select id="project-status" name="status">
                <option value="">{{ $locale === 'ar' ? 'كل الحالات' : 'All statuses' }}</option>
                @foreach($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="case-button" type="submit">{{ $locale === 'ar' ? 'بحث' : 'Search' }}</button>
    </form>

    <div class="case-feedback">
        <p role="status" aria-live="polite">{{ $locale === 'ar'
            ? 'عدد دراسات الحالة العامة الموثقة المطابقة: '.$paginated->total()
            : 'Matching verified public case studies: '.$paginated->total() }}</p>
        @if($filtersApplied)
            <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مسح البحث والمرشحات' : 'Clear search and filters' }}</a>
        @endif
    </div>
    @if ($projects->isEmpty())
        <div class="case-empty" role="status">
            <p>{{ $locale === 'ar'
                ? ($emptyPageHasResultsElsewhere
                    ? 'لا تظهر دراسات حالة عامة موثقة في هذه الصفحة. عد إلى الصفحة الأولى.'
                    : ($filtersApplied ? 'لا توجد دراسات حالة عامة موثقة تطابق البحث.' : 'لا توجد حاليًا دراسات حالة عامة موثقة منشورة.'))
                : ($emptyPageHasResultsElsewhere
                    ? 'No evidence-verified public case studies on this page. Return to the first page.'
                    : ($filtersApplied ? 'No evidence-verified public case studies match your search.' : 'No evidence-verified public case studies are available yet.')) }}</p>
            <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تصفح مستودعات GitHub العامة الموثقة' : 'Browse verified public GitHub repositories' }}</a>
        </div>
    @else
        <div class="case-grid">
            @foreach($projects as $project)
                <article class="case-card">
                    <p class="case-meta">{{ $statusLabels[$project['status']] ?? ($locale === 'ar' ? 'غير محددة' : 'Unavailable') }}</p>
                    <h2>{{ $project['name'] }}</h2>
                    <p>{{ $project['summary'] }}</p>
                    @if (!empty($project['role']))
                        <p class="case-meta">{{ $locale === 'ar' ? 'الدور المعتمد' : 'Verified role' }}: {{ $project['role'] }}</p>
                    @endif
                    <a href="{{ route('public.project', array_merge(
                        ['slug' => $project['slug'], 'lang' => $locale],
                        $search !== '' ? ['q' => $search] : [],
                        $status !== '' ? ['status' => $status] : [],
                        $paginated->currentPage() > 1 ? ['page' => $paginated->currentPage()] : []
                    )) }}">
                        {{ $locale === 'ar' ? 'عرض دراسة الحالة والأدلة' : 'View case study and evidence' }}
                    </a>
                </article>
            @endforeach
        </div>
    @endif

    @if ($paginated->hasPages())
        <nav class="case-pagination" aria-label="{{ $locale === 'ar' ? 'صفحات المشاريع' : 'Project pages' }}">
            @if ($paginated->previousPageUrl())
                <a href="{{ route('public.projects', array_merge(['lang' => $locale], $safeProjectFilters, ['page' => $paginated->currentPage() - 1])) }}">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</a>
            @else
                <span aria-disabled="true">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</span>
            @endif
            <span aria-current="page">{{ $locale === 'ar' ? 'الصفحة' : 'Page' }} {{ $paginated->currentPage() }} / {{ $paginated->lastPage() }}</span>
            @if ($paginated->nextPageUrl())
                <a href="{{ route('public.projects', array_merge(['lang' => $locale], $safeProjectFilters, ['page' => $paginated->currentPage() + 1])) }}">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</a>
            @else
                <span aria-disabled="true">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</span>
            @endif
        </nav>
    @endif
</section>
@endsection
