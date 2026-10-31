@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'المختبر التقني — وليد عطية' : 'Technology lab — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ request()->url() }}">
<link rel="alternate" hreflang="ar" href="{{ request()->fullUrlWithQuery(['lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ request()->fullUrlWithQuery(['lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.editorial{padding-block:clamp(32px,5vw,72px)}.editorial h1{font-size:clamp(31px,4vw,48px);color:var(--wa-primary);line-height:1.2}
.editorial a{color:var(--wa-cta);overflow-wrap:anywhere}.ed-card{background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:24px;margin-block:16px}
.ed-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.ed-grid .ed-card{margin:0}.ed-card h2{color:var(--wa-primary)}
.ed-form{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.ed-form label{display:block;font-weight:700}
.ed-form input,.ed-form select{font:inherit;padding:10px;border:1px solid #97B4C8;border-radius:10px;min-height:44px;min-width:190px}
.ed-form button{min-height:44px;background:var(--wa-cta);color:#fff;border:0;border-radius:10px;padding:10px 18px;font:inherit;font-weight:700}
.article-content{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.9;color:var(--wa-body)}
</style>
@endpush
@section('content')
<section class="site-wrap editorial"><h1>{{ $locale === 'ar' ? 'المختبر التقني' : 'Technology lab' }}</h1>
<p>{{ $locale === 'ar' ? 'الأدوات التفاعلية غير مفعّلة حتى التحقق منها. المتاح أدلة منشورة ذات مصادر فقط.' : 'Interactive tools are not active until verified. Only published, evidence-reviewed guides can be opened.' }}</p>
<div class="ed-card">
@if($articles->isEmpty())<p role="status">{{ $locale === 'ar' ? 'لا توجد أدلة منشورة أو تجارب حية.' : 'No reviewed guides or live tool demos are available yet.' }}</p>
@else
<ul>@foreach($articles as $a)
<li><a href="{{ route('public.article', ['slug'=>$a->slug, 'lang'=>$locale]) }}">{{ $locale === 'ar' ? $a->title_ar : $a->title_en }}</a></li>
@endforeach</ul>
@endif
</div></section>
@endsection