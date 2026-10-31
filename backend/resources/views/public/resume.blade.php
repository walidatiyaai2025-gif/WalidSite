@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'السيرة العامة — وليد عطية' : 'Public resume — Waleed Atiya')
@push('styles')
<style>
.resume{max-width:980px;padding-block:45px}.resume h1,.resume h2{color:var(--wa-primary)}
.resume section{background:#fff;border:1px solid var(--wa-soft-border);padding:24px;border-radius:18px;margin-block:18px}
.resume article{padding:13px 0;border-top:1px solid var(--wa-soft-border)}
.resume button{background:var(--wa-cta);border:0;border-radius:10px;color:#fff;padding:12px 18px;font:inherit;min-height:44px;cursor:pointer}
@media print{.site-header,.site-footer,.resume-actions{display:none!important}.resume{padding:0}.resume section{break-inside:avoid}body{background:#fff}}
</style>
@endpush
@section('content')
<div class="site-wrap resume">
<h1>{{ $locale === 'ar' ? 'السيرة المهنية العامة' : 'Public resume' }}</h1>
<p>{{ $locale === 'ar' ? 'لا تظهر سوى الخبرات والشهادات المعتمدة للنشر بعد التحقق من الأدلة.' : 'Only evidence-verified experience and approved credentials are included.' }}</p>
<div class="resume-actions"><button type="button" onclick="window.print()">{{ $locale === 'ar' ? 'طباعة أو حفظ PDF' : 'Print or save PDF' }}</button>
<a href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a></div>
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
@endif
</div>
@endsection
