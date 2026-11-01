@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', $locale === 'ar' ? 'المدونة التقنية — وليد عطية' : 'Technical Blog — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.blog', ['lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.blog', ['lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.blog', ['lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.blog-shell{padding-block:clamp(30px,5vw,70px) 75px}.blog-shell h1{color:var(--wa-primary);font-size:clamp(34px,5vw,52px);line-height:1.2}
.blog-intro{color:var(--wa-body);max-width:72ch;font-size:17px}
.blog-search{display:flex;align-items:end;flex-wrap:wrap;gap:12px;background:#fff;border:1px solid var(--wa-soft-border);padding:20px;border-radius:18px;margin:24px 0 32px}
.blog-field{flex:1 1 220px}.blog-field label{display:block;font-weight:700;margin-bottom:6px}
.blog-field :is(input,select){width:100%;min-height:44px;padding:9px 12px;border:1px solid #97B4C8;border-radius:10px;color:var(--wa-strong);background:#fff;font:inherit}
.blog-action{min-height:44px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;background:var(--wa-cta);color:#fff;font:inherit;font-weight:800;padding:10px 22px;cursor:pointer}
.blog-field :is(input,select):focus-visible,.blog-action:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
.blog-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,310px),1fr));gap:18px}
.blog-card{background:#fff;border:1px solid var(--wa-soft-border);border-radius:20px;display:flex;flex-direction:column;gap:10px;padding:22px}
.blog-card h2{margin:0;font-size:23px;line-height:1.4;color:var(--wa-primary)}
.blog-card p{margin:0;color:var(--wa-body);overflow-wrap:anywhere}
.blog-stamp{font-size:13px;color:var(--wa-muted-text)}.blog-card a{display:inline-flex;min-height:44px;align-items:center;margin-top:auto;font-weight:800;color:var(--wa-cta)}
.blog-empty{padding:25px;border:1px solid var(--wa-soft-border);border-radius:16px;background:#fff}
.blog-pages{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:28px}
.blog-pages :is(a,span){min-height:44px;display:inline-flex;align-items:center;padding:8px 15px;border:1px solid var(--wa-soft-border);border-radius:10px;background:#fff}
.blog-pages a{color:var(--wa-cta);font-weight:700}
</style>
@endpush
@section('content')
<section class="site-wrap blog-shell" aria-labelledby="blog-title">
    <h1 id="blog-title">{{ $locale === 'ar' ? 'المدونة التقنية' : 'Technical blog' }}</h1>
    <p class="blog-intro">{{ $locale === 'ar'
        ? 'مقالات منشورة بعد مراجعة مصادرها وصحة محتواها. لا تعرض المسودات أو التجارب غير المثبتة.'
        : 'Source-reviewed technical articles only. Unverified drafts and unpublished experiments stay private.' }}</p>
    <form class="blog-search" method="get" action="{{ route('public.blog') }}" role="search" aria-label="{{ $locale === 'ar' ? 'تصفية المدونة' : 'Filter articles' }}">
        <input type="hidden" name="lang" value="{{ $locale }}">
        <div class="blog-field">
            <label for="blog-query">{{ $locale === 'ar' ? 'بحث في العناوين' : 'Search titles' }}</label>
            <input type="search" id="blog-query" name="q" value="{{ $search }}" maxlength="80" placeholder="{{ $locale === 'ar' ? 'عنوان المقال' : 'Article title' }}">
        </div>
        <div class="blog-field">
            <label for="blog-category">{{ $locale === 'ar' ? 'التصنيف' : 'Category' }}</label>
            <select id="blog-category" name="category">
                <option value="">{{ $locale === 'ar' ? 'كل التصنيفات' : 'All categories' }}</option>
                @foreach($categories as $item)
                    <option value="{{ $item->slug }}" @selected($category === $item->slug)>{{ $locale === 'ar' ? $item->name_ar : $item->name_en }}</option>
                @endforeach
            </select>
        </div>
        <button class="blog-action" type="submit">{{ $locale === 'ar' ? 'تطبيق التصفية' : 'Apply filters' }}</button>
    </form>
    @if($entries->isEmpty())
        <div class="blog-empty" role="status">{{ $locale === 'ar'
            ? 'لا توجد مقالات منشورة وموثقة تطابق البحث حاليًا.'
            : 'No source-verified published articles match the current filters.' }}</div>
    @else
        <div class="blog-grid">
            @foreach($entries as $entry)
                @php($article = $entry['article'])
                @php($revision = $entry['revision'])
                <article class="blog-card">
                    <p class="blog-stamp">{{ $locale === 'ar' ? 'مقال موثق المصدر' : 'Source-reviewed article' }}
                        <time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->format('Y-m-d') }}</time>
                    </p>
                    <h2>{{ $locale === 'ar' ? $article->title_ar : $article->title_en }}</h2>
                    <p>{{ \Illuminate\Support\Str::limit(trim(preg_replace('/[#*>]+/u', '', $revision->body_md)), 190) }}</p>
                    <a href="{{ route('public.article', ['slug'=>$article->slug, 'lang'=>$locale]) }}">
                        {{ $locale === 'ar' ? 'قراءة المقال والمصادر' : 'Read article and sources' }}
                    </a>
                </article>
            @endforeach
        </div>
    @endif
    @if($paginated->hasPages())
        <nav class="blog-pages" aria-label="{{ $locale === 'ar' ? 'تصفح صفحات المدونة' : 'Blog pagination' }}">
            @if($paginated->previousPageUrl())
                <a href="{{ $paginated->previousPageUrl() }}">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</a>
            @else
                <span aria-disabled="true">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</span>
            @endif
            <span>{{ $locale === 'ar' ? 'الصفحة' : 'Page' }} {{ $paginated->currentPage() }} / {{ $paginated->lastPage() }}</span>
            @if($paginated->nextPageUrl())
                <a href="{{ $paginated->nextPageUrl() }}">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</a>
            @else
                <span aria-disabled="true">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</span>
            @endif
        </nav>
    @endif
</section>
@endsection
