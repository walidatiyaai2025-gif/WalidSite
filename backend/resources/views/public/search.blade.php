@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'البحث — وليد عطية' : 'Search — Waleed Atiya')
@push('head')
<meta name="robots" content="noindex,follow">
@endpush
@push('styles')
<style>
.editorial{padding-block:clamp(32px,5vw,72px)}.editorial h1{font-size:clamp(31px,4vw,48px);color:var(--wa-primary);line-height:1.2}
.editorial a{color:var(--wa-cta);overflow-wrap:anywhere}.ed-card{background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:24px;margin-block:16px}
.ed-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.ed-grid .ed-card{margin:0}.ed-card h2{color:var(--wa-primary)}
.ed-form{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.ed-form label{display:block;font-weight:700}
.ed-form input,.ed-form select{font:inherit;padding:10px;border:1px solid #97B4C8;border-radius:10px;min-height:44px;min-width:190px}
.ed-form .ed-clear{display:inline-flex;align-items:center;min-height:44px;padding:10px 12px;font-weight:700;text-decoration:underline;text-underline-offset:3px}.ed-form .ed-clear:focus-visible{outline:3px solid var(--wa-primary);outline-offset:3px}.ed-form button{min-height:44px;background:var(--wa-cta);color:#fff;border:0;border-radius:10px;padding:10px 18px;font:inherit;font-weight:700}
.article-content{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.9;color:var(--wa-body)}
.ed-form :is(input,select,button):focus-visible{outline:3px solid var(--wa-primary);outline-offset:3px}
.ed-more{display:inline-flex;align-items:center;min-height:44px;margin-top:12px;font-weight:800;text-underline-offset:3px}.ed-preview-note{display:block;color:var(--wa-body);font-size:14px;margin-top:6px}
</style>
@endpush
@section('content')
@php
    // At most three source-verified cards appear in the reference-size stage.
    // An 'all' search must not hide a real project behind an empty article slot.
    $web13ArticlePreviewCount = $type === 'projects' ? 0
        : min($articles->count(), $type === 'all' && $projects->isNotEmpty() ? 2 : 3);
    $web13ProjectPreviewCount = $type === 'articles' ? 0
        : min($projects->count(), 3 - $web13ArticlePreviewCount);
    if ($type === 'all' && $web13ArticlePreviewCount + $web13ProjectPreviewCount < 3) {
        $web13ArticlePreviewCount = min($articles->count(), 3 - $web13ProjectPreviewCount);
    }
@endphp
@include('public.t012-reference-stage', ['screen' => 'search'])
<section class="site-wrap editorial t012-functional" id="t012-functional">
<h1>{{ $locale === 'ar' ? 'البحث في المعلومات الموثقة' : 'Search verified content' }}</h1>
<form method="get" action="{{ route('public.search') }}" role="search" class="ed-form ed-card">
<input type="hidden" name="lang" value="{{ $locale }}">
<label for="all-q">{{ $locale === 'ar' ? 'كلمات البحث' : 'Search terms' }}</label>
<input id="all-q" name="q" maxlength="80" type="search" value="{{ $search }}">
<label for="search-type">{{ $locale === 'ar' ? 'نوع النتائج' : 'Result type' }}</label>
<select id="search-type" name="type">
    <option value="all" @selected($type === 'all')>{{ $locale === 'ar' ? 'الكل' : 'All content' }}</option>
    <option value="articles" @selected($type === 'articles')>{{ $locale === 'ar' ? 'المقالات' : 'Articles' }}</option>
    <option value="projects" @selected($type === 'projects')>{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</option>
</select>
<button type="submit">{{ $locale === 'ar' ? 'بحث' : 'Search' }}</button>
@if($search !== '' || $type !== 'all')
<a class="ed-clear" href="{{ route('public.search', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مسح البحث' : 'Clear search' }}</a>
@endif
</form>
@if($search === '')
<div class="ed-card" role="status">{{ $locale === 'ar' ? 'اكتب كلمة للبحث في المقالات والمشاريع العامة الموثقة.' : 'Enter a query to search published, evidence-verified articles and projects.' }}</div>
@elseif($articles->isEmpty() && $projects->isEmpty())
<div class="ed-card" role="status">{{ $locale === 'ar' ? 'لا توجد نتائج عامة موثقة.' : 'No verified public matches.' }}</div>
@else
<p class="ed-card" role="status" aria-live="polite" data-testid="verified-search-summary">
@if($type === 'all')
{{ $locale === 'ar' ? 'نتائج موثقة: ' : 'Verified results: ' }}{{ $articles->count() }} {{ $locale === 'ar' ? ($articles->count() === 1 ? 'مقال،' : ($articles->count() === 2 ? 'مقالان،' : 'مقالات،')) : ($articles->count() === 1 ? 'article,' : 'articles,') }} {{ $projects->count() }} {{ $locale === 'ar' ? ($projects->count() === 1 ? 'مشروع.' : ($projects->count() === 2 ? 'مشروعان.' : 'مشاريع.')) : ($projects->count() === 1 ? 'project.' : 'projects.') }}
@elseif($type === 'articles')
{{ $locale === 'ar' ? 'نتائج موثقة: ' : 'Verified results: ' }}{{ $articles->count() }} {{ $locale === 'ar' ? ($articles->count() === 1 ? 'مقال.' : ($articles->count() === 2 ? 'مقالان.' : 'مقالات.')) : ($articles->count() === 1 ? 'article.' : 'articles.') }}
@else
{{ $locale === 'ar' ? 'نتائج موثقة: ' : 'Verified results: ' }}{{ $projects->count() }} {{ $locale === 'ar' ? ($projects->count() === 1 ? 'مشروع.' : ($projects->count() === 2 ? 'مشروعان.' : 'مشاريع.')) : ($projects->count() === 1 ? 'project.' : 'projects.') }}
@endif
@if($articles->count() === 10 || $projects->count() === 10)
<span class="ed-preview-note">{{ $locale === 'ar' ? 'تُعرض أول 10 نتائج موثقة من كل نوع كحد أقصى؛ استخدم روابط جميع النتائج لاستكشاف الباقي.' : 'Showing at most 10 verified matches per type. Use the full-results links to explore further.' }}</span>
@endif
</p>
@if($articles->isNotEmpty())
<section class="ed-card" aria-labelledby="articles-h" data-testid="search-article-results"><h2 id="articles-h">{{ $locale === 'ar' ? 'المقالات' : 'Articles' }}</h2><ul>
@foreach($articles->skip($web13ArticlePreviewCount) as $a)<li data-testid="search-article-result"><a href="{{ route('public.article', ['slug' => $a->slug, 'lang' => $locale, 'source' => 'search', 'q' => $search, 'type' => $type]) }}">{{ $locale === 'ar' ? $a->title_ar : $a->title_en }}</a></li>@endforeach
</ul>
<a class="ed-more" href="{{ route('public.blog', ['lang' => $locale, 'q' => $search]) }}">{{ $locale === 'ar' ? 'استعراض جميع المقالات المطابقة' : 'Browse all matching articles' }}</a>
</section>
@endif
@if($projects->isNotEmpty())
<section class="ed-card" aria-labelledby="projects-h" data-testid="search-project-results"><h2 id="projects-h">{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</h2><ul>
@foreach($projects->skip($web13ProjectPreviewCount) as $p)<li data-testid="search-project-result"><a href="{{ route('public.project', ['slug'=>$p['slug'],'lang'=>$locale]) }}">{{ $p['name'] }}</a></li>@endforeach
</ul>
<a class="ed-more" href="{{ route('public.projects', ['lang' => $locale, 'q' => $search]) }}">{{ $locale === 'ar' ? 'استعراض جميع المشاريع المطابقة' : 'Browse all matching projects' }}</a>
</section>
@endif
@endif
</section>
@endsection