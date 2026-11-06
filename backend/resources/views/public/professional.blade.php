@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')

@php($titles = [
    'about' => ['en' => 'About and experience', 'ar' => 'نبذة وخبرات'],
    'expertise' => ['en' => 'Verified expertise', 'ar' => 'الخبرات التقنية الموثقة'],
    'hire' => ['en' => 'Recruiter overview', 'ar' => 'للجهات الباحثة عن الخبرات'],
])
@section('title', $titles[$page][$locale].' — Waleed Atiya')
@php($seoDescriptions = [
    'about' => [
        'en' => 'Explore Waleed Atiya public career history and credentials available after evidence review.',
        'ar' => 'اطّلع على الخبرات المهنية والشهادات المنشورة لوليد عطية بعد مراجعة الأدلة.',
    ],
    'expertise' => [
        'en' => 'Explore Waleed Atiya engineering skills approved for public display after evidence verification.',
        'ar' => 'استكشف مهارات وليد عطية الهندسية المعتمدة للنشر بعد التحقق من أدلتها.',
    ],
    'hire' => [
        'en' => 'Filter evidence-reviewed engineering specialties and open the verified public resume by career track.',
        'ar' => 'صفِّ مجالات الخبرة الهندسية المتحقق منها وافتح السيرة العامة حسب المسار المهني.',
    ],
])
@section('meta_description', $seoDescriptions[$page][$locale])

@push('styles')
@if($page === 'expertise')
    @include('public.expertise-mobile-overflow-fix')
@endif
@endpush

@section('content')
{{-- WEB-03 owner-approved four-state design replaces the obsolete single reference stage.
     About / Recruiter keep their pre-existing reference headers unchanged. --}}
@if($page === 'about')
    @include('public.t007-reference-stage', ['screen' => 'about', 'styleOnly' => true])
@elseif($page !== 'expertise')
    @include('public.t007-reference-stage', ['screen' => $page])
@endif
<div class="site-wrap t007-functional" aria-label="Evidence-gated public profile">
    @if($page === 'about')
        @include('public.about-screen')
    @elseif($page === 'expertise')
        @include('public.expertise-screen')
    @elseif($page === 'hire')
        @include('public.recruiter-screen')
    @endif
</div>
@endsection
