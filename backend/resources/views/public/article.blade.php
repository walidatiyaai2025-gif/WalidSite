{{-- Preserve only validated, on-site browse context. Never accept caller
     supplied return URLs, extra query keys, arrays or unapproved content. --}}
@php
    $locale = request()->query('lang') === 'ar' ? 'ar' : 'en';
    $rawBrowse = request()->query();
    $rawSearch = $rawBrowse['q'] ?? null;
    $browseSearch = is_string($rawSearch) && mb_strlen(trim($rawSearch)) <= 80
        ? trim($rawSearch) : '';
    $articleContextQuery = [];
    $articleBackRoute = 'public.blog';
    $articleBackArgs = ['lang' => $locale];
    if (($rawBrowse['source'] ?? null) === 'search' && $browseSearch !== '') {
        $articleBackRoute = 'public.search';
        $articleContextQuery = ['source' => 'search', 'q' => $browseSearch];
        $articleBackArgs['q'] = $browseSearch;
        $rawType = $rawBrowse['type'] ?? 'all';
        if (is_string($rawType) && in_array($rawType, ['all', 'articles'], true)) {
            $articleContextQuery['type'] = $rawType;
            $articleBackArgs['type'] = $rawType;
        }
    } elseif (($rawBrowse['source'] ?? null) === 'blog') {
        $articleContextQuery = ['source' => 'blog'];
        if ($browseSearch !== '') {
            $articleBackArgs['q'] = $browseSearch;
            $articleContextQuery['q'] = $browseSearch;
        }
        $rawCategory = $rawBrowse['category'] ?? null;
        if (is_string($rawCategory) && mb_strlen($rawCategory) <= 110
            && preg_match('/\A[a-z0-9-]+\z/D', $rawCategory) === 1) {
            $articleBackArgs['category'] = $rawCategory;
            $articleContextQuery['category'] = $rawCategory;
        }
        $rawPage = $rawBrowse['page'] ?? null;
        if ((is_int($rawPage) || is_string($rawPage))
            && ctype_digit((string) $rawPage) && (int) $rawPage > 1
            && (int) $rawPage <= 10000) {
            $articleBackArgs['page'] = (int) $rawPage;
            $articleContextQuery['page'] = (int) $rawPage;
        }
    }
@endphp
@extends('layouts.site')
@section('title', ($locale === 'ar' ? $article->title_ar : $article->title_en).' — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.article', ['slug' => $article->slug, 'lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.article', ['slug' => $article->slug, 'lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.article', ['slug' => $article->slug, 'lang' => 'en']) }}">
{{-- Public SEO describes only the already reviewed, currently authorized revision.
     Never turn drafts or unverified professional claims into crawler metadata. --}}
@php($structuredArticle = [
    '@context' => 'https://schema.org',
    '@type' => 'TechArticle',
    'headline' => $locale === 'ar' ? $article->title_ar : $article->title_en,
    'inLanguage' => $locale,
    'mainEntityOfPage' => route('public.article', ['slug' => $article->slug, 'lang' => $locale]),
    'datePublished' => $article->published_at?->toIso8601String(),
    'citation' => array_values(array_unique(array_column($revision->citations_json, 'source_url'))),
])
<script type="application/ld+json">{!! json_encode($structuredArticle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
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

.article-reading-list{margin-block:26px;background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:24px}
.article-reading-list h2{color:var(--wa-primary);margin-top:0}
.reading-list-actions{display:flex;flex-wrap:wrap;gap:12px;margin-block:14px}
.reading-list-actions button{font:inherit;font-weight:700;min-height:44px;border-radius:10px;padding:10px 16px;border:1px solid var(--wa-cta);color:var(--wa-primary);background:#fff;cursor:pointer}
.reading-list-actions button[aria-pressed="true"]{background:var(--wa-cta);color:#fff}
.reading-list-actions button:disabled{opacity:.6;cursor:not-allowed}
.reading-list-actions button:focus-visible,.article-reading-list a:focus-visible{outline:3px solid var(--wa-cta);outline-offset:3px}
.article-reading-list ul{padding-inline-start:24px}.article-reading-list li{margin-block:8px;overflow-wrap:anywhere}
.article-reading-list a{color:var(--wa-cta);min-height:44px;display:inline-flex;align-items:center}
@media print{.article-reading-list{display:none!important}}
.article-related{margin-block:26px;background:#fff;border:1px solid var(--wa-soft-border);border-radius:18px;padding:24px}
.article-related h2{color:var(--wa-primary);margin-top:0}
.article-related-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:12px;list-style:none;padding:0}
.article-related-link{display:flex;align-items:center;min-height:44px;padding:12px 15px;border:1px solid var(--wa-soft-border);border-radius:12px;color:var(--wa-cta);font-weight:700;text-decoration:none}
.article-related-link:hover,.article-related-link:focus-visible{background:var(--wa-muted-surface)}
@media print{.site-header,.site-footer,.article-nav,.copy-action{display:none!important}.article-body{border:0;padding:0}}
</style>
@endpush
@section('content')
@include('public.t012-reference-stage', ['screen' => 'article'])
<article class="site-wrap article-shell t012-functional" id="t012-functional" aria-labelledby="article-title">
    <nav class="article-nav" aria-label="{{ $locale === 'ar' ? 'تنقل المقال' : 'Article navigation' }}">
        <a href="{{ route($articleBackRoute, $articleBackArgs) }}">{{ $locale === 'ar'
            ? ($articleBackRoute === 'public.search' ? 'العودة إلى نتائج البحث' : 'العودة إلى نتائج المدونة')
            : ($articleBackRoute === 'public.search' ? 'Back to search results' : 'Back to technical blog results') }}</a>
        <a href="{{ route('public.contact', ['lang'=>$locale, 'article'=>$article->slug]) }}">{{ $locale === 'ar' ? 'التبليغ عن مشكلة بالمحتوى' : 'Report a content issue' }}</a>
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

    <section class="article-reading-list" aria-labelledby="reading-list-heading">
        <h2 id="reading-list-heading">{{ $locale === 'ar' ? 'القراءة لاحقًا على هذا الجهاز' : 'Read later on this device' }}</h2>
        <p class="article-note">{{ $locale === 'ar'
            ? 'احفظ رابط المقال العام على هذا المتصفح فقط. لا يُرسل سجل القراءة إلى الخادم ولا يتزامن مع الحساب أو الأجهزة الأخرى. يخضع كل فتح جديد لفحص النشر والمصادر؛ قد يتعذر فتح مقال سُحبت الموافقة عليه.'
            : 'Save public article links only in this browser. Your reading list is not uploaded or synced with an account or another device. Every visit rechecks publication and evidence, so revoked articles can become unavailable.' }}</p>
        <div class="reading-list-actions">
            <button id="article-save-toggle" type="button" aria-pressed="false" disabled>{{ $locale === 'ar' ? 'حفظ هذا المقال محليًا' : 'Save this article locally' }}</button>
            <button id="article-reading-clear" type="button" disabled>{{ $locale === 'ar' ? 'حذف كل الروابط المحفوظة' : 'Clear saved links' }}</button>
        </div>
        <p id="article-reading-empty" class="article-note">{{ $locale === 'ar' ? 'لا توجد روابط محفوظة.' : 'No saved links yet.' }}</p>
        <ul id="article-reading-links" aria-label="{{ $locale === 'ar' ? 'روابط المقالات المحفوظة محليًا' : 'Locally saved article links' }}"></ul>
        <p id="article-reading-status" class="article-note" role="status" aria-live="polite" aria-atomic="true">{{ $locale === 'ar' ? 'يتطلب الحفظ تشغيل JavaScript وتفعيل التخزين المحلي للمتصفح.' : 'Saving requires JavaScript and browser local storage.' }}</p>
    </section>
    @if($related->isNotEmpty())
    <section class="article-related" aria-labelledby="related-articles-title">
        <h2 id="related-articles-title">{{ $locale === 'ar' ? 'مقالات ذات صلة' : 'Related articles' }}</h2>
        <ul class="article-related-list">
            @foreach($related as $suggestion)
                <li><a class="article-related-link"
                    href="{{ route('public.article', array_merge(['slug' => $suggestion->slug, 'lang' => $locale], $articleContextQuery)) }}">
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

<script>
(() => {
    'use strict';
    const locale = @json($locale);
    const current = {
        slug: @json($article->slug),
        en: @json($article->title_en),
        ar: @json($article->title_ar),
    };
    // The route template comes from Laravel, not a stored or third-party URL.
    @php($localArticlePath = route('public.article', ['slug' => 'reading-list-placeholder', 'lang' => $locale], false))
    const articlePath = @json($localArticlePath);
    const storageKey = 'walidsite:public-reading-list:v1';
    const limit = 30;
    const toggle = document.getElementById('article-save-toggle');
    const clear = document.getElementById('article-reading-clear');
    const links = document.getElementById('article-reading-links');
    const empty = document.getElementById('article-reading-empty');
    const feedback = document.getElementById('article-reading-status');
    if (!toggle || !clear || !links || !empty || !feedback) return;

    const words = locale === 'ar' ? {
        save: 'حفظ هذا المقال محليًا',
        remove: 'إزالة هذا المقال من المحفوظات',
        saved: 'حُفظ رابط المقال على هذا الجهاز.',
        removed: 'حُذف رابط المقال من هذا الجهاز.',
        cleared: 'حُذفت الروابط المحفوظة من هذا الجهاز.',
        unavailable: 'تعذر استخدام التخزين المحلي؛ لم يُحفظ شيء.',
        full: 'قائمة القراءة ممتلئة (30 مقالًا). احذف رابطًا قبل إضافة آخر.',
        confirm: 'هل تريد حذف جميع روابط القراءة المحفوظة على هذا الجهاز؟',
    } : {
        save: 'Save this article locally',
        remove: 'Remove this locally saved article',
        saved: 'Article link saved on this device.',
        removed: 'Article link removed from this device.',
        cleared: 'Locally saved links cleared from this device.',
        unavailable: 'Browser local storage is unavailable; nothing was saved.',
        full: 'Reading list is full (30 articles). Remove a link before adding another.',
        confirm: 'Clear every locally saved article link from this device?',
    };

    const validSlug = value => typeof value === 'string'
        && /^[A-Za-z0-9-]{1,220}$/.test(value);
    const validTitle = value => typeof value === 'string'
        && value.length > 0 && value.length <= 500;
    const validEntry = value => value && typeof value === 'object'
        && validSlug(value.slug) && validTitle(value.en) && validTitle(value.ar);

    function load() {
        const raw = window.localStorage.getItem(storageKey);
        if (raw === null) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) throw new Error('Invalid local reading list');
        const unique = new Set();
        return parsed.filter(value => {
            if (!validEntry(value) || unique.has(value.slug)) return false;
            unique.add(value.slug);
            return true;
        }).slice(0, limit);
    }

    let saved;
    try {
        saved = load();
    } catch (_) {
        feedback.textContent = words.unavailable;
        return;
    }

    function render() {
        const hasCurrent = saved.some(item => item.slug === current.slug);
        toggle.textContent = hasCurrent ? words.remove : words.save;
        toggle.setAttribute('aria-pressed', hasCurrent ? 'true' : 'false');
        toggle.disabled = false;
        clear.disabled = saved.length === 0;
        empty.hidden = saved.length !== 0;
        links.replaceChildren();
        saved.forEach(item => {
            const row = document.createElement('li');
            const anchor = document.createElement('a');
            // Never trust a saved URL or insert stored text as HTML.
            anchor.href = articlePath.replace('reading-list-placeholder', encodeURIComponent(item.slug));
            anchor.textContent = locale === 'ar' ? item.ar : item.en;
            row.appendChild(anchor);
            links.appendChild(row);
        });
    }

    function persist(next) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(next));
            saved = next;
            render();
            return true;
        } catch (_) {
            feedback.textContent = words.unavailable;
            return false;
        }
    }

    toggle.addEventListener('click', () => {
        const alreadySaved = saved.some(item => item.slug === current.slug);
        if (alreadySaved) {
            if (persist(saved.filter(item => item.slug !== current.slug))) {
                feedback.textContent = words.removed;
            }
        } else {
            if (!validEntry(current) || saved.length >= limit) {
                feedback.textContent = words.full;
                return;
            }
            if (persist([current, ...saved])) feedback.textContent = words.saved;
        }
    });
    clear.addEventListener('click', () => {
        if (saved.length && window.confirm(words.confirm) && persist([])) {
            feedback.textContent = words.cleared;
        }
    });
    // Keep simultaneous tabs consistent without writing on passive storage events.
    // A removed entry must not be resurrected by an older tab's next click.
    window.addEventListener('storage', event => {
        if (event.key !== storageKey && event.key !== null) return;
        try {
            saved = load();
            feedback.textContent = '';
            render();
        } catch (_) {
            saved = [];
            toggle.disabled = true;
            clear.disabled = true;
            links.replaceChildren();
            empty.hidden = false;
            feedback.textContent = words.unavailable;
        }
    });
    feedback.textContent = '';
    render();
})();
</script>
@endsection
