@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'المختبر التقني — وليد عطية' : 'Technology lab — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.lab', ['lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.lab', ['lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.lab', ['lang' => 'en']) }}">
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
.lab-resource-list{padding-inline-start:24px}.lab-resource-list li{margin-block:5px}.lab-resource-list a{min-height:44px;display:inline-flex;align-items:center}
.lab-resource-more{min-height:44px;display:inline-flex;align-items:center;color:var(--wa-cta);font-weight:800;text-underline-offset:3px}
</style>
@endpush
@section('content')
<section class="site-wrap editorial"><h1>{{ $locale === 'ar' ? 'المختبر التقني' : 'Technology lab' }}</h1>
<p>{{ $locale === 'ar' ? 'الأدوات التفاعلية غير مفعّلة حتى التحقق منها. المتاح أدلة منشورة ذات مصادر فقط.' : 'Interactive tools are not active until verified. Only published, evidence-reviewed guides can be opened.' }}</p>
<div class="ed-card">
@if($articles->isEmpty() && $runbooks->isEmpty())
    <p role="status">{{ $locale === 'ar' ? 'لا توجد أدلة منشورة أو تجارب حية.' : 'No reviewed guides or live tool demos are available yet.' }}</p>
@else
    @if($articles->isNotEmpty())
    <section aria-labelledby="lab-articles-title">
        <h2 id="lab-articles-title">{{ $locale === 'ar' ? 'المقالات التقنية الموثقة' : 'Reviewed technical articles' }}</h2>
        <ul class="lab-resource-list">
            @foreach($articles as $article)
                <li><a href="{{ $article['url'] }}">{{ $article['title'] }}</a></li>
            @endforeach
        </ul>
        <a class="lab-resource-more" href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تصفح جميع المقالات الموثقة' : 'Browse all verified technical articles' }}</a>
    </section>
    @endif
    @if($runbooks->isNotEmpty())
    <section aria-labelledby="lab-runbooks-title">
        <h2 id="lab-runbooks-title">{{ $locale === 'ar' ? 'أدلة التشغيل المعتمدة' : 'Approved runbooks' }}</h2>
        <p>{{ $locale === 'ar'
            ? 'أدلة تمت مراجعة النسخة المنشورة منها؛ لا يُعد اعتمادها إثباتًا لتنفيذها في بيئة تشغيل فعلية.'
            : 'Version-reviewed guides; approval does not prove live execution.' }}</p>
        <ul class="lab-resource-list">
            @foreach($runbooks as $runbook)
                <li><a href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => $locale]) }}">
                    {{ $runbook['title'] }}
                </a></li>
            @endforeach
        </ul>
        <a class="lab-resource-more" href="{{ route('public.lab.runbooks', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تصفح مكتبة أدلة التشغيل كاملة' : 'Browse the full approved runbook library' }}</a>
    </section>
    @endif
@endif
</div></section>
@endsection