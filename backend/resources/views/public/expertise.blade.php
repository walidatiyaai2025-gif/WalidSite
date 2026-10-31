@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'الخبرات — وليد عطية' : 'Expertise — Waleed Atiya')

@section('content')
<section class="site-wrap wa-page" aria-labelledby="expertise-heading">
    <h1 id="expertise-heading">{{ $locale === 'ar' ? 'الخبرات' : 'Expertise' }}</h1>
    <p class="wa-page-lead">{{ $locale === 'ar'
        ? 'الخبرات المُعروضة مرتبطة بملف مهني معتمد وبأدلة تم التحقق منها، وليست قائمة ادعاءات تلقائية.'
        : 'Only evidence-reviewed skills associated with an approved public profile appear here.' }}</p>
    <form action="{{ route('public.expertise') }}" method="get" class="wa-form" role="search">
        <input type="hidden" name="lang" value="{{ $locale }}">
        <div>
            <label for="category">{{ $locale === 'ar' ? 'المجال' : 'Discipline' }}</label>
            <select name="category" id="category">
                <option value="">{{ $locale === 'ar' ? 'كل المجالات' : 'All disciplines' }}</option>
                @foreach ($categories as $option)
                    <option value="{{ $option }}" @selected($category === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit">{{ $locale === 'ar' ? 'عرض النتائج' : 'Apply filter' }}</button>
    </form>
    @if ($filtered->isEmpty())
        <div class="wa-notice" role="status">{{ $locale === 'ar'
            ? 'لا توجد مهارات معتمدة تطابق التصفية الحالية.' : 'No verified skills match the selected filter.' }}</div>
    @else
        <div class="wa-grid">
            @foreach ($filtered as $skill)
                <article class="wa-card">
                    <h2>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</h2>
                    <p class="wa-meta">{{ $skill->category }}</p>
                </article>
            @endforeach
        </div>
    @endif
    <a class="wa-link" href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'تصفح المشاريع العامة الموثقة' : 'Explore verified public projects' }}</a>
</section>
@endsection