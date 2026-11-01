@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', e($runbook['title']).' — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.runbook-shell{max-width:920px;padding-block:clamp(30px,6vw,75px)}
.runbook-shell h1{font-size:clamp(32px,5vw,48px);line-height:1.3;color:var(--wa-primary);overflow-wrap:anywhere}
.runbook-nav{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:18px}
.runbook-nav a{color:var(--wa-cta);font-weight:700;display:inline-flex;min-height:44px;align-items:center}
.runbook-meta,.runbook-note{color:var(--wa-muted-text);font-size:14px}
.runbook-body{margin-block:24px;padding:clamp(18px,4vw,40px);background:#fff;border:1px solid var(--wa-soft-border);border-radius:20px;overflow-wrap:anywhere}
.runbook-body :is(h2,h3,h4){line-height:1.4;color:var(--wa-primary)}
.runbook-body pre{background:#132C47;color:#fff;border-radius:10px;padding:20px;overflow-x:auto;direction:ltr;text-align:left}
.runbook-body :not(pre)>code{background:var(--wa-muted-surface);padding:2px 5px;border-radius:4px}
.runbook-body a{color:var(--wa-cta);overflow-wrap:anywhere}
</style>
@endpush
@section('content')
<article class="site-wrap runbook-shell" aria-labelledby="runbook-title">
    <nav class="runbook-nav" aria-label="{{ $locale === 'ar' ? 'تنقل الدليل' : 'Runbook navigation' }}">
        <a href="{{ route('public.lab', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'العودة إلى المختبر التقني' : 'Back to technology lab' }}</a>
        <a href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التبليغ عن مشكلة بالمحتوى' : 'Report a content issue' }}</a>
    </nav>
    <p class="runbook-meta">{{ $locale === 'ar' ? 'دليل تشغيل معتمد بنسخة محددة' : 'Version-reviewed operational guide' }}</p>
    <h1 id="runbook-title">{{ $runbook['title'] }}</h1>
    <p class="runbook-meta">
        {{ $locale === 'ar' ? 'النسخة' : 'Version' }} {{ $runbook['version'] }}
        · <time datetime="{{ $runbook['validated_at'] }}">{{ \Illuminate\Support\Carbon::parse($runbook['validated_at'])->format('Y-m-d') }}</time>
    </p>
    <div class="runbook-body" id="runbook-content">
        {!! \Illuminate\Support\Str::markdown($runbook['markdown'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
    </div>
    <p class="runbook-note">{{ $locale === 'ar'
        ? 'هذا الدليل حاصل على مراجعة للنص والنسخة، وليس إثباتًا لإجراء اختبار تشغيل في بيئة حقيقية.'
        : 'Review covers the published text and version; it is not proof of a live execution test.' }}</p>
</article>
@endsection
