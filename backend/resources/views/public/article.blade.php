@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', ($locale === 'ar' ? $article->title_ar : $article->title_en).' — Waleed Atiya')
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
.article-content{overflow-wrap:anywhere;line-height:1.9;color:var(--wa-body)}
.article-content :is(h2,h3,h4){line-height:1.4;color:var(--wa-primary);margin-top:30px}
.article-content pre{background:#132C47;color:#fff;border-radius:10px;padding:20px;overflow-x:auto;white-space:pre;direction:ltr;text-align:left}
.article-content :not(pre)>code{background:var(--wa-muted-surface);padding:2px 5px;border-radius:4px;overflow-wrap:anywhere}
.article-content :is(a,blockquote){overflow-wrap:anywhere}
</style>
@endpush
@section('content')
<article class="site-wrap editorial">
<p><a href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'رجوع للمقالات' : 'Back to articles' }}</a></p>
<p><a href="{{ route('public.article', ['slug'=>$article->slug, 'lang'=>$locale === 'ar' ? 'en' : 'ar']) }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a></p>
<h1>{{ $locale === 'ar' ? $article->title_ar : $article->title_en }}</h1>
<p>{{ $locale === 'ar' ? 'مراجعة رقم' : 'Reviewed revision' }} {{ $article->published_revision_no }} —
{{ $locale === 'ar' ? 'موثق بالمصادر فقط، دون ضمان تجربة تنفيذية.' : 'Documented sources, not independently execution-tested.' }}</p>
<section class="ed-card" aria-labelledby="content-heading"><h2 id="content-heading">{{ $locale === 'ar' ? 'المحتوى' : 'Technical content' }}</h2>
<div class="article-content" id="article-content">{!! \Illuminate\Support\Str::markdown($revision->body_md, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div></section>
<section class="ed-card" aria-labelledby="source-heading"><h2 id="source-heading">{{ $locale === 'ar' ? 'المصادر العامة' : 'Public sources' }}</h2>
@if($sources->isEmpty())
<p role="status">{{ $locale === 'ar' ? 'لا توجد روابط مصادر خارجية عامة آمنة للعرض.' : 'No externally safe public citation links to show.' }}</p>
@else
<ul>@foreach($sources as $url)<li><a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a></li>@endforeach</ul>
@endif
</section>
</article>
@endsection