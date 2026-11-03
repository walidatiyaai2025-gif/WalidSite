@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($ar = $locale === 'ar')
@extends('layouts.site')

@section('title', $ar ? 'وليد عطية | التقنية والمشاريع الموثقة' : 'Waleed Atiya | Engineering & Verified Work')
@section('meta_description', $ar
    ? 'استكشف خبرات وليد عطية التقنية والمشاريع والمقالات التي اجتازت مراجعة النشر والأدلة.'
    : 'Explore Waleed Atiya engineering expertise, projects and articles after evidence and publication review.')

@push('styles')
<style>
    /* WEB-01 only: end the page at the six-capability ribbon.
       Keep other pages' footer, privacy, terms and data-rights links intact.
       The shared layout is checksum-pinned by release safety tests. */
    body:has(.w26-landing) .site-main { min-height: 0; }
    body:has(.w26-landing) .site-footer { display: none; }
</style>
@endpush

@section('content')
@include('public.owner-landing-2026')
@endsection
