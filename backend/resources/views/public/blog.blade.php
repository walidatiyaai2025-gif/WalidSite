@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'المقالات الموثقة — وليد عطية' : 'Source-verified articles — Waleed Atiya')
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
<h1>{{ $locale === 'ar' ? 'المقالات التقنية الموثقة' : 'Source-verified technical articles' }}</h1>
<p>{{ $locale === 'ar' ? 'مقالات منشورة مع مراجعة المصادر، دون ادعاء اختبارات غير مثبتة.' : 'Published, source-reviewed articles. Citation review does not imply execution testing.' }}</p>
<form action="{{ route('public.blog') }}" method="get" role="search" class="ed-form ed-card">
<input type="hidden" name="lang" value="{{ $locale }}">
<div><label for="blog-q">{{ $locale === 'ar' ? 'بحث' : 'Search' }}</label>
<input id="blog-q" name="q" type="search" maxlength="80" value="{{ $search }}"></div>
<div><label for="blog-category">{{ $locale === 'ar' ? 'التصنيف' : 'Category' }}</label>
<select id="blog-category" name="category"><option value="">{{ $locale === 'ar' ? 'الكل' : 'All categories' }}</option>
@foreach($categories as $cat)
<option value="{{ $cat->slug }}" @selected($category === $cat->slug)>{{ $locale === 'ar' ? $cat->name_ar : $cat->name_en }}</option>
@endforeach
</select></div>
<button type="submit">{{ $locale === 'ar' ? 'تطبيق' : 'Apply filters' }}</button>
</form>
@if($articles->isEmpty())
<div role="status" class="ed-card">{{ $locale === 'ar' ? 'لا توجد مقالات موثقة عامة مطابقة.' : 'No verified public articles match.' }}</div>
@else
<div class="ed-grid">@foreach($articles as $article)
<article class="ed-card"><h2>{{ $locale === 'ar' ? $article->title_ar : $article->title_en }}</h2>
<p>{{ \Illuminate\Support\Str::limit($article->publicRevision()?->body_md, 180) }}</p>
<p><a href="{{ route('public.article', ['slug' => $article->slug, 'lang' => $locale]) }}">{{ $locale === 'ar' ? 'قراءة ومراجعة المصادر' : 'Read article and sources' }}</a></p>
</article>@endforeach</div>
@endif
@if($paginator->hasPages())<nav aria-label="Article pages" class="ed-card">{{ $paginator->links() }}</nav>@endif
</section>
@endsection