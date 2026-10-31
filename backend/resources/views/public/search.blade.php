@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'البحث — وليد عطية' : 'Search — Waleed Atiya')
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
<section class="site-wrap editorial">
<h1>{{ $locale === 'ar' ? 'البحث في المعلومات الموثقة' : 'Search verified content' }}</h1>
<form method="get" action="{{ route('public.search') }}" role="search" class="ed-form ed-card">
<input type="hidden" name="lang" value="{{ $locale }}">
<label for="all-q">{{ $locale === 'ar' ? 'كلمات البحث' : 'Search terms' }}</label>
<input id="all-q" name="q" maxlength="80" type="search" value="{{ $search }}">
<button type="submit">{{ $locale === 'ar' ? 'بحث' : 'Search' }}</button>
</form>
@if($search === '')
<div class="ed-card" role="status">{{ $locale === 'ar' ? 'اكتب كلمة للبحث في المقالات والمشاريع العامة الموثقة.' : 'Enter a query to search published, evidence-verified articles and projects.' }}</div>
@elseif($articles->isEmpty() && $projects->isEmpty())
<div class="ed-card" role="status">{{ $locale === 'ar' ? 'لا توجد نتائج عامة موثقة.' : 'No verified public matches.' }}</div>
@else
<section class="ed-card" aria-labelledby="articles-h"><h2 id="articles-h">{{ $locale === 'ar' ? 'المقالات' : 'Articles' }}</h2><ul>
@foreach($articles as $a)<li><a href="{{ route('public.article', ['slug'=>$a->slug,'lang'=>$locale]) }}">{{ $locale === 'ar' ? $a->title_ar : $a->title_en }}</a></li>@endforeach
</ul></section>
<section class="ed-card" aria-labelledby="projects-h"><h2 id="projects-h">{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</h2><ul>
@foreach($projects as $p)<li><a href="{{ route('public.project', ['slug'=>$p['slug'],'lang'=>$locale]) }}">{{ $p['name'] }}</a></li>@endforeach
</ul></section>
@endif
</section>
@endsection