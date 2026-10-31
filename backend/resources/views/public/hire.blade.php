@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'بوابة التوظيف — وليد عطية' : 'Recruiter Mode — Waleed Atiya')

@section('content')
<section class="site-wrap wa-page" aria-labelledby="hire-heading">
    <h1 id="hire-heading">{{ $locale === 'ar' ? 'بوابة التوظيف' : 'Recruiter mode' }}</h1>
    <p class="wa-page-lead">{{ $locale === 'ar'
        ? 'اختر المسار المهني لمراجعة المهارات المتحقق منها. لن تُعرض شهادات أو أرقام أو مشاريع سرية دون دليل وموافقة.'
        : 'Select a role track to review independently verified skills. Unreviewed certificates, metrics and confidential work stay private.' }}</p>

    <form action="{{ route('public.hire') }}" method="get" class="wa-form">
        <input type="hidden" name="lang" value="{{ $locale }}">
        <div>
            <label for="track">{{ $locale === 'ar' ? 'المسار المهني' : 'Role track' }}</label>
            <select id="track" name="track">
                <option value="database" @selected($track === 'database')>{{ $locale === 'ar' ? 'قواعد البيانات والاعتمادية' : 'Database & Reliability' }}</option>
                <option value="software" @selected($track === 'software')>{{ $locale === 'ar' ? 'تطوير البرمجيات والتكامل' : 'Software & Integration' }}</option>
                <option value="delivery" @selected($track === 'delivery')>{{ $locale === 'ar' ? 'إدارة المشاريع والتسليم' : 'Project Delivery' }}</option>
            </select>
        </div>
        <button type="submit">{{ $locale === 'ar' ? 'اختيار المسار' : 'Select track' }}</button>
    </form>

    <h2>{{ $tracks[$track] }}</h2>
    @if (! $profile || $proof->isEmpty())
        <div class="wa-notice" role="status">{{ $locale === 'ar'
            ? 'لم تُنشر بعد أدلة مهنية معتمدة لهذا المسار.'
            : 'Approved professional evidence is not yet available for this track.' }}</div>
    @else
        <div class="wa-grid">
            @foreach ($proof as $skill)
                <article class="wa-card"><h3>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</h3></article>
            @endforeach
        </div>
    @endif

    <p class="wa-meta">{{ $locale === 'ar'
        ? 'طلب التواصل ونسخة السيرة الذاتية العامة غير متاحين حتى تجهيز مسار الموافقة وحماية الخصوصية.'
        : 'Recruiter contact requests and public CV downloads are unavailable pending consent and privacy controls.' }}</p>
    <a class="wa-link" href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الاطلاع على مشاريع GitHub العامة' : 'Browse public GitHub evidence' }}</a>
</section>
@endsection