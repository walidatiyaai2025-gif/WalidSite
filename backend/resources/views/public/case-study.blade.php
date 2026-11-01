@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $project['name'].' — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.study {padding-block:48px;max-width:940px}
.study h1{font-size:clamp(32px,5vw,50px);color:var(--wa-primary)}
.study section{background:#fff;padding:26px;border:1px solid var(--wa-soft-border);border-radius:18px;margin-block:20px}
.study a{color:var(--wa-cta);overflow-wrap:anywhere;min-height:44px}
.study p{color:var(--wa-body)}
.study-next{display:flex;flex-wrap:wrap;gap:14px;margin-block:24px}
.study-next a{display:inline-flex;align-items:center;min-height:44px;padding:10px 16px;border:1px solid var(--wa-soft-border);border-radius:10px;background:#fff;font-weight:700}
.study-next a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
</style>
@endpush
@php($statusLabels = $locale === 'ar'
    ? ['completed' => 'مكتمل', 'in_progress' => 'قيد التنفيذ', 'research' => 'بحث', 'archived' => 'مؤرشف']
    : ['completed' => 'Completed', 'in_progress' => 'In progress', 'research' => 'Research', 'archived' => 'Archived'])
@section('content')
<article class="site-wrap study">
<a href="{{ route('public.projects', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'كل المشاريع' : 'All projects' }}</a>
<h1>{{ $project['name'] }}</h1>
<p>{{ $project['summary'] }}</p>
<section aria-labelledby="case-facts">
<h2 id="case-facts">{{ $locale === 'ar' ? 'تفاصيل تم التحقق منها' : 'Verified facts' }}</h2>
<p>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}: {{ $statusLabels[$project['status']] ?? ($locale === 'ar' ? 'غير محددة' : 'Unavailable') }}</p>
@if(isset($statusLabels[$project['status']]))
<p><a href="{{ route('public.projects', ['lang' => $locale, 'status' => $project['status']]) }}">
{{ $locale === 'ar' ? 'عرض مشاريع أخرى بالحالة نفسها' : 'View projects with this status' }}</a></p>
@endif
@if(!empty($project['role']))
<p>{{ $locale === 'ar' ? 'الدور' : 'Role' }}: {{ $project['role'] }}</p>
@endif
<p>{{ $locale === 'ar' ? 'التفاصيل السرية والنتائج غير المثبتة غير منشورة.' : 'Confidential details and unverified results are not published.' }}</p>
</section>
<section aria-labelledby="case-evidence">
<h2 id="case-evidence">{{ $locale === 'ar' ? 'أدلة منشورة' : 'Published evidence' }}</h2>
<ul>
@foreach($project['evidence_urls'] as $url)
<li><a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a></li>
@endforeach
</ul>
</section>
<nav class="study-next" aria-label="{{ $locale === 'ar' ? 'خطوات تالية لدراسة الحالة' : 'Case study next steps' }}">
    <a href="{{ route('public.contact', ['lang' => $locale, 'project' => $project['slug']]) }}">{{ $locale === 'ar' ? 'استفسر عن دراسة الحالة الموثقة' : 'Ask about this verified case study' }}</a>
    <a href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'عرض السيرة المهنية الموثقة' : 'View verified public resume' }}</a>
</nav>
</article>
@endsection
