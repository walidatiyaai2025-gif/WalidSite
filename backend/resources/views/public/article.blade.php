@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', ($locale === 'ar' ? $article->title_ar : $article->title_en).' — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.article', ['slug' => $article->slug, 'lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.article', ['slug' => $article->slug, 'lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.article', ['slug' => $article->slug, 'lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.article-shell{max-width:920px;padding-block:clamp(30px,6vw,75px)}
.article-shell h1{font-size:clamp(34px,5vw,50px);line-height:1.3;color:var(--wa-primary)}
.article-meta{color:var(--wa-muted-text);font-size:14px}
.article-body{margin-block:26px;background:#fff;padding:clamp(18px,4vw,42px);border:1px solid var(--wa-soft-border);border-radius:20px;overflow-wrap:anywhere}
.article-body :is(h2,h3,h4){line-height:1.4;color:var(--wa-primary);margin-top:30px}
.article-body pre{background:#132C47;color:#fff;border-radius:10px;padding:20px;overflow-x:auto;white-space:pre;direction:ltr;text-align:left}
.article-body :not(pre)>code{background:var(--wa-muted-surface);padding:2px 5px;border-radius:4px;overflow-wrap:anywhere}
.article-body :is(a,blockquote){overflow-wrap:anywhere}
.article-sources{background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:24px}
.article-sources h2{color:var(--wa-primary)}.article-sources a{color:var(--wa-cta);overflow-wrap:anywhere;min-height:44px;display:inline-flex;align-items:center}
.article-nav{display:flex;flex-wrap:wrap;gap:18px;margin-bottom:18px}.article-nav a{min-height:44px;display:inline-flex;align-items:center;color:var(--wa-cta);font-weight:700}
.copy-action{margin-top:16px;margin-bottom:5px;background:var(--wa-cta);border:0;border-radius:8px;color:#fff;padding:9px 14px;min-height:44px;cursor:pointer;font:inherit;font-weight:700}
.article-note{font-size:13px;color:var(--wa-muted-text)}
.article-related{margin-block:26px;background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:24px}
.article-related h2{color:var(--wa-primary);margin-top:0}
.article-related-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:12px;list-style:none;padding:0}
.article-related-link{display:flex;align-items:center;min-height:44px;padding:12px 15px;border:1px solid var(--wa-soft-border);border-radius:12px;color:var(--wa-cta);font-weight:700;text-decoration:none}
.article-related-link:hover,.article-related-link:focus-visible{background:var(--wa-muted-surface)}
@media print{.site-header,.site-footer,.article-nav,.copy-action{display:none!important}.article-body{border:0;padding:0}}
</style>
@endpush
@section('content')
<article class="site-wrap article-shell" aria-labelledby="article-title">
    <nav class="article-nav" aria-label="{{ $locale === 'ar' ? 'تنقل المقال' : 'Article navigation' }}">
        <a href="{{ route('public.blog', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'العودة إلى المدونة' : 'Back to technical blog' }}</a>
        <a href="{{ route('public.contact', ['lang'=>$locale]) }}">{{ $locale === 'ar' ? 'التبليغ عن مشكلة بالمحتوى' : 'Report a content issue' }}</a>
    </nav>
    <p class="article-meta">{{ $locale === 'ar' ? 'محتوى منشور ومدعوم بمصادر موثقة' : 'Published with verified cited sources' }}</p>
    <h1 id="article-title">{{ $locale === 'ar' ? $article->title_ar : $article->title_en }}</h1>
    <p class="article-meta"><time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->format('Y-m-d') }}</time></p>
    <div class="article-body" id="article-content">
        {!! \Illuminate\Support\Str::markdown($revision->body_md, ['html_input'=>'escape', 'allow_unsafe_links'=>false]) !!}
    </div>
    <section class="article-sources" aria-labelledby="sources-title">
        <h2 id="sources-title">{{ $locale === 'ar' ? 'المصادر التي تمت مراجعتها' : 'Reviewed sources' }}</h2>
        <ol>
            @foreach($revision->citations_json as $citation)
                <li><a href="{{ $citation['source_url'] }}" target="_blank" rel="noopener noreferrer">
                    {{ $citation['source_url'] }}</a></li>
            @endforeach
        </ol>
        <p class="article-note">{{ $locale === 'ar'
            ? 'التحقق من المصادر لا يعني إثبات تجربة تشغيل فعلية. حفظ المقال للحسابات سيُفعّل بعد اكتمال الميزة.'
            : 'Source validation does not prove runtime execution. Account-based article saving is not yet available.' }}</p>
    </section>
    @if($related->isNotEmpty())
    <section class="article-related" aria-labelledby="related-articles-title">
        <h2 id="related-articles-title">{{ $locale === 'ar' ? 'مقالات ذات صلة' : 'Related articles' }}</h2>
        <ul class="article-related-list">
            @foreach($related as $suggestion)
                <li><a class="article-related-link"
                    href="{{ route('public.article', ['slug' => $suggestion->slug, 'lang' => $locale]) }}">
                    {{ $locale === 'ar' ? $suggestion->title_ar : $suggestion->title_en }}
                </a></li>
            @endforeach
        </ul>
    </section>
    @endif
    <p id="copy-status" class="article-note" aria-live="polite"></p>
</article>
<script>
(() => {
    const status = document.getElementById('copy-status');
    document.querySelectorAll('#article-content pre').forEach((pre) => {
        const code = pre.querySelector('code');
        if (!code) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'copy-action';
        button.textContent = document.documentElement.lang === 'ar' ? 'نسخ الكود' : 'Copy code';
        button.addEventListener('click', async () => {
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                status.textContent = document.documentElement.lang === 'ar' ? 'نسخ الكود غير متاح في هذا المتصفح' : 'Code copying is unavailable in this browser';
                return;
            }
            try {
                await navigator.clipboard.writeText(code.textContent || '');
                status.textContent = document.documentElement.lang === 'ar' ? 'تم نسخ الكود' : 'Code copied';
            } catch (_) {
                status.textContent = document.documentElement.lang === 'ar' ? 'تعذر نسخ الكود' : 'Could not copy code';
            }
        });
        pre.parentNode.insertBefore(button, pre);
    });
})();
</script>
@endsection
