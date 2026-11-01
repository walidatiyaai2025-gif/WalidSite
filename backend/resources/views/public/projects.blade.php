@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'المشاريع الموثقة — وليد عطية' : 'Verified projects — Waleed Atiya')

@push('styles')
<style>
    .case-page { padding-block: clamp(32px, 5vw, 72px); }
    .case-page h1 { font-size: clamp(32px, 5vw, 52px); line-height: 1.2; color: var(--wa-primary); }
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
    .case-pagination { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:30px; }
    .case-pagination a, .case-pagination span { padding:9px 16px; border:1px solid var(--wa-soft-border); border-radius:10px; background:#fff; }
    .case-pagination a { color:var(--wa-cta); font-weight:800; }
</style>
@endpush

@php($statusLabels = $locale === 'ar'
    ? ['completed' => 'مكتمل', 'in_progress' => 'قيد التنفيذ', 'research' => 'بحث', 'archived' => 'مؤرشف']
    : ['completed' => 'Completed', 'in_progress' => 'In progress', 'research' => 'Research', 'archived' => 'Archived'])
@section('content')
<section class="site-wrap case-page" aria-labelledby="project-heading">
    <h1 id="project-heading">{{ $locale === 'ar' ? 'مشاريع ودراسات حالة موثقة' : 'Verified projects and case studies' }}</h1>
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

    @if ($projects->isEmpty())
        <div class="case-empty" role="status">{{ $locale === 'ar'
            ? 'لا توجد دراسات حالة عامة موثقة تطابق البحث. راجع معرض GitHub للمستودعات التي تم التحقق منها.'
            : 'No evidence-verified public case studies match your search. You can explore separately verified public GitHub repositories.' }}</div>
    @else
        <div class="case-grid">
            @foreach($projects as $project)
                <article class="case-card">
                    <p class="case-meta">{{ $statusLabels[$project['status']] ?? $project['status'] }}</p>
                    <h2>{{ $project['name'] }}</h2>
                    <p>{{ $project['summary'] }}</p>
                    @if (!empty($project['role']))
                        <p class="case-meta">{{ $locale === 'ar' ? 'الدور المعتمد' : 'Verified role' }}: {{ $project['role'] }}</p>
                    @endif
                    <a href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => $locale]) }}">
                        {{ $locale === 'ar' ? 'عرض دراسة الحالة والأدلة' : 'View case study and evidence' }}
                    </a>
                </article>
            @endforeach
        </div>
    @endif

    @if ($paginated->hasPages())
        <nav class="case-pagination" aria-label="{{ $locale === 'ar' ? 'صفحات المشاريع' : 'Project pages' }}">
            @if ($paginated->previousPageUrl())
                <a href="{{ $paginated->previousPageUrl() }}">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</a>
            @else
                <span aria-disabled="true">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</span>
            @endif
            <span>{{ $locale === 'ar' ? 'الصفحة' : 'Page' }} {{ $paginated->currentPage() }} / {{ $paginated->lastPage() }}</span>
            @if ($paginated->nextPageUrl())
                <a href="{{ $paginated->nextPageUrl() }}">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</a>
            @else
                <span aria-disabled="true">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</span>
            @endif
        </nav>
    @endif
</section>
@endsection
