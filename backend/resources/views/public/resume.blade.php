@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'السيرة العامة — وليد عطية' : 'Public resume — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.resume', ['lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.resume', ['lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.resume', ['lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.resume{max-width:980px;padding-block:45px}.resume h1,.resume h2{color:var(--wa-primary)}
.resume section{background:#fff;border:1px solid var(--wa-soft-border);padding:24px;border-radius:18px;margin-block:18px}
.resume article{padding:13px 0;border-top:1px solid var(--wa-soft-border)}
.resume button{background:var(--wa-cta);border:0;border-radius:10px;color:#fff;padding:12px 18px;font:inherit;min-height:44px;cursor:pointer}
.resume-tracks{display:flex;gap:8px;flex-wrap:wrap;margin-block:22px}.resume-track{min-height:44px;display:inline-flex;align-items:center;padding:8px 16px;border:1px solid var(--wa-soft-border);border-radius:999px;background:#fff;color:var(--wa-primary);text-decoration:none;font-weight:700}
.resume-track.is-selected{background:var(--wa-primary);color:#fff;border-color:var(--wa-primary)}.resume-track:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
@media print{.site-header,.site-footer,.resume-actions,.resume-tracks{display:none!important}.resume{padding:0}.resume section{break-inside:avoid}body{background:#fff}}
</style>
@endpush
@section('content')
<div class="site-wrap resume">
<h1>{{ $locale === 'ar' ? 'السيرة المهنية العامة' : 'Public resume' }}</h1>
<p>{{ $locale === 'ar' ? 'لا تظهر سوى الخبرات والشهادات المعتمدة للنشر بعد التحقق من الأدلة.' : 'Only evidence-verified experience and approved credentials are included.' }}</p>
<div class="resume-actions"><button type="button" onclick="window.print()">{{ $locale === 'ar' ? 'طباعة أو حفظ PDF' : 'Print or save PDF' }}</button>
<a href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a></div>
<nav class="resume-tracks" aria-label="{{ $locale === 'ar' ? 'تصفية السيرة حسب المسار' : 'Resume focus by track' }}">
@foreach([
    'all' => $locale === 'ar' ? 'جميع المسارات' : 'All tracks',
    'database' => $locale === 'ar' ? 'قواعد البيانات' : 'Database',
    'software' => $locale === 'ar' ? 'البرمجيات والبنية التحتية' : 'Software & infrastructure',
    'delivery' => $locale === 'ar' ? 'إدارة المشاريع' : 'Project delivery',
] as $value => $label)
<a class="resume-track {{ $track === $value ? 'is-selected' : '' }}"
   href="{{ route('public.resume', ['lang' => $locale, 'track' => $value]) }}"
   @if($track === $value) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
@if(!$profile)
<section role="status">{{ $locale === 'ar' ? 'السيرة العامة قيد المراجعة؛ لن ننشر بيانات خاصة أو غير موثقة.' : 'Public CV review is pending; private and unverified details remain hidden.' }}</section>
@else
<section><h2>{{ $profile->public_name }}</h2></section>
<section><h2>{{ $locale === 'ar' ? 'الخبرات المعتمدة' : 'Verified experience' }}</h2>
@forelse($positions as $position)
<article><h3>{{ $locale === 'ar' ? $position->role_ar : $position->role_en }}</h3>
<p>{{ $position->org_name }} — {{ $position->started_on?->format('Y') }} / {{ $position->ended_on?->format('Y') ?? ($locale === 'ar' ? 'حتى الآن' : 'Present') }}</p></article>
@empty
<p>{{ $locale === 'ar' ? 'لا توجد خبرات منشورة ومعتمدة بعد.' : 'No verified career periods are published yet.' }}</p>
@endforelse
</section>
<section><h2>{{ $locale === 'ar' ? 'الشهادات المتحقق منها' : 'Verified credentials' }}</h2>
@forelse($certifications as $certificate)
<article><h3>{{ $certificate->title }}</h3>
@if($certificate->issuer)<p>{{ $certificate->issuer }}</p>@endif</article>
@empty
<p>{{ $locale === 'ar' ? 'لا توجد شهادات مؤكدة للنشر حاليًا.' : 'No approved credential evidence is available yet.' }}</p>
@endforelse
</section>
<section><h2>{{ $locale === 'ar' ? 'المهارات المهنية الموثقة' : 'Verified professional skills' }}</h2>
@forelse($skills as $skill)
<article><h3>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</h3>
<p>{{ $skill->category }}</p></article>
@empty
<p>{{ $locale === 'ar' ? 'لا توجد مهارات موثقة منشورة لهذا المسار بعد.' : 'No verified published skills for this track yet.' }}</p>
@endforelse
</section>
@endif
</div>
@endsection
