@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'مكتبة أدلة التشغيل المعتمدة — وليد عطية' : 'Approved runbook library — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.lab.runbooks', ['lang' => $locale] + ($page > 1 ? ['page' => $page] : [])) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.lab.runbooks', ['lang' => 'ar'] + ($page > 1 ? ['page' => $page] : [])) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.lab.runbooks', ['lang' => 'en'] + ($page > 1 ? ['page' => $page] : [])) }}">
@endpush
@push('styles')
<style>
.runbooks-shell{max-width:960px;padding-block:clamp(30px,5vw,72px)}
.runbooks-shell h1{font-size:clamp(31px,4vw,48px);line-height:1.25;color:var(--wa-primary)}
.runbooks-intro{color:var(--wa-body);max-width:75ch}
.runbooks-nav,.runbooks-pages{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-block:18px}
.runbooks-nav a,.runbooks-pages a{display:inline-flex;align-items:center;min-height:44px;color:var(--wa-cta);font-weight:700}
.runbooks-list{list-style:none;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:18px}
.runbooks-list li,.runbooks-empty{border:1px solid var(--wa-soft-border);border-radius:18px;background:#fff;padding:22px}
.runbooks-list a{color:var(--wa-cta);display:inline-flex;align-items:center;min-height:44px;font-weight:800;overflow-wrap:anywhere}
.runbooks-meta{color:var(--wa-muted-text);font-size:14px;margin-block:7px 0}
.runbooks-pages{margin-block:28px}
.runbooks-pages a,.runbooks-pages span{border:1px solid var(--wa-soft-border);border-radius:10px;background:#fff;padding:10px 16px;min-height:44px}
.runbooks-pages a:focus-visible,.runbooks-nav a:focus-visible,.runbooks-list a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
</style>
@endpush
@section('content')
<section class="site-wrap runbooks-shell" aria-labelledby="runbooks-title">
    <nav class="runbooks-nav" aria-label="{{ $locale === 'ar' ? 'تنقل المكتبة' : 'Library navigation' }}">
        <a href="{{ route('public.lab', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'العودة إلى المختبر التقني' : 'Back to technology lab' }}</a>
        <a href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'جميع المقالات التقنية الموثقة' : 'All verified technical articles' }}</a>
    </nav>
    <h1 id="runbooks-title">{{ $locale === 'ar' ? 'مكتبة أدلة التشغيل المعتمدة' : 'Approved runbook library' }}</h1>
    <p class="runbooks-intro">{{ $locale === 'ar'
        ? 'تُعرض فقط النسخ التي اجتازت مراجعة النص والبصمة والموافقة دون اتصال. إعادة المراجعة مطلوبة بعد تغيير أي نسخة؛ الاعتماد ليس إثباتًا للتنفيذ الفعلي.'
        : 'Only offline-approved guides with currently matching reviewed titles, versions and checksums appear. A changed guide needs renewed approval; approval is not proof of live execution.' }}</p>
    @if($runbooks->isEmpty())
        <p class="runbooks-empty" role="status">{{ $locale === 'ar'
            ? 'لا توجد أدلة تشغيل معتمدة في هذه الصفحة حاليًا.'
            : 'No currently approved runbooks on this page.' }}</p>
    @else
        <ul class="runbooks-list">
            @foreach($runbooks as $runbook)
                <li>
                    <a href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => $locale, 'page' => $page]) }}">{{ $runbook['title'] }}</a>
                    <p class="runbooks-meta">{{ $locale === 'ar' ? 'النسخة المعتمدة' : 'Reviewed version' }} {{ $runbook['version'] }} ·
                        <time datetime="{{ $runbook['validated_at'] }}">{{ \Illuminate\Support\Carbon::parse($runbook['validated_at'])->format('Y-m-d') }}</time>
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
    @if($page > 1 || ($hasMore && $page < 10000))
        <nav class="runbooks-pages" aria-label="{{ $locale === 'ar' ? 'صفحات أدلة التشغيل' : 'Runbook library pages' }}">
            @if($page > 1)
                <a rel="prev" href="{{ route('public.lab.runbooks', ['lang' => $locale, 'page' => $page - 1]) }}">{{ $locale === 'ar' ? 'الصفحة السابقة' : 'Previous page' }}</a>
            @endif
            <span aria-current="page">{{ $locale === 'ar' ? 'صفحة' : 'Page' }} {{ $page }}</span>
            @if($hasMore && $page < 10000)
                <a rel="next" href="{{ route('public.lab.runbooks', ['lang' => $locale, 'page' => $page + 1]) }}">{{ $locale === 'ar' ? 'الصفحة التالية' : 'Next page' }}</a>
            @endif
        </nav>
    @endif
</section>
@endsection
