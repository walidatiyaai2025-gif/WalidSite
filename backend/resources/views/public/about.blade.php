@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'نبذة عني — وليد عطية' : 'About — Waleed Atiya')

@section('content')
<section class="site-wrap wa-page" aria-labelledby="about-heading">
    <h1 id="about-heading">{{ $locale === 'ar' ? 'نبذة عني' : 'About' }}</h1>
    <p class="wa-page-lead">{{ $locale === 'ar'
        ? 'الخبرات والمعلومات المهنية المعروضة أدناه تخضع لسياسة التحقق والموافقة قبل النشر.'
        : 'This professional profile publishes only information that has passed source review and publication checks.' }}</p>

    @if (! $profile)
        <div class="wa-notice" role="status">{{ $locale === 'ar'
            ? 'الملف المهني قيد المراجعة، ولا توجد بيانات عامة معتمدة للعرض حاليًا.'
            : 'Professional information is under review; no approved public profile is available yet.' }}</div>
    @else
        <div class="wa-card">
            <h2>{{ $profile->public_name }}</h2>
            @if ($locale === 'ar' && $profile->bio_ar)
                <p>{{ $profile->bio_ar }}</p>
            @elseif ($profile->bio_en)
                <p>{{ $profile->bio_en }}</p>
            @endif
        </div>
        <h2>{{ $locale === 'ar' ? 'الخبرة المهنية المثبتة' : 'Verified career experience' }}</h2>
        @if ($positions->isEmpty())
            <div class="wa-notice" role="status">{{ $locale === 'ar'
                ? 'لم تُعتمد أي فترة خبرة للنشر بعد.' : 'No career periods have been approved for publication yet.' }}</div>
        @else
            <div class="wa-grid">
                @foreach ($positions as $position)
                    <article class="wa-card">
                        <h3>{{ $locale === 'ar' ? $position->role_ar : $position->role_en }}</h3>
                        <p>{{ $position->org_name }}</p>
                        <p class="wa-meta">{{ $position->started_on->format('Y') }}
                            — {{ $position->ended_on?->format('Y') ?? ($locale === 'ar' ? 'حتى الآن' : 'Present') }}</p>
                    </article>
                @endforeach
            </div>
        @endif
        <h2>{{ $locale === 'ar' ? 'الشهادات المتحقق منها' : 'Externally verified credentials' }}</h2>
        @if ($certifications->isEmpty())
            <div class="wa-notice" role="status">{{ $locale === 'ar'
                ? 'لم يتم اعتماد شهادات ذات دليل تحقق قابل للنشر.'
                : 'No credentials with approved verification evidence are available yet.' }}</div>
        @else
            <div class="wa-grid">
                @foreach ($certifications as $certificate)
                    <article class="wa-card">
                        <h3>{{ $certificate->title }}</h3>
                        @if ($certificate->issuer)<p>{{ $certificate->issuer }}</p>@endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif
    <p>{{ $locale === 'ar' ? 'تحميل السيرة الذاتية غير متاح حتى اعتماد النسخة العامة.' : 'CV download is unavailable until the public version is approved.' }}</p>
    <a class="wa-link" href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub العامة' : 'Browse public GitHub projects' }}</a>
</section>
@endsection