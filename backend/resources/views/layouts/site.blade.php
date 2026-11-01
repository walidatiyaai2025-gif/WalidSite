@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="@yield('meta_description', $locale === 'ar' ? 'الموقع المهني لوليد عطية — المعلومات العامة المتاحة بعد التحقق.' : 'Waleed Atiya engineering portfolio — evidence-reviewed public information.')">
    <title>@yield('title', 'Waleed Atiya — Engineering')</title>
    {{-- Preserve only validated, source-backed filters in canonical/hreflang
         URLs. WEB-03 expertise categories and WEB-12 recruiter tracks each
         have different allowlists; never echo raw or array-valued query data. --}}
    @php($seoPage = request()->route()?->getName())
    @if (in_array($seoPage, ['home', 'public.about', 'public.expertise', 'public.hire'], true))
        @php($seoRawTrack = request()->query()['track'] ?? null)
        @php($seoRawCategory = request()->query()['category'] ?? null)
        @php($seoFilters = $seoPage === 'public.hire' && is_string($seoRawTrack)
            && in_array($seoRawTrack, ['database', 'software', 'delivery'], true)
                ? ['track' => $seoRawTrack] : [])
        @if($seoPage === 'public.expertise' && is_string($seoRawCategory)
            && in_array($seoRawCategory, ['database', 'integration', 'security', 'delivery'], true))
            @php($seoFilters['category'] = $seoRawCategory)
        @endif
        <link rel="canonical" href="{{ route($seoPage, array_merge(['lang' => $locale], $seoFilters)) }}">
        <link rel="alternate" hreflang="en" href="{{ route($seoPage, array_merge(['lang' => 'en'], $seoFilters)) }}">
        <link rel="alternate" hreflang="ar" href="{{ route($seoPage, array_merge(['lang' => 'ar'], $seoFilters)) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route($seoPage, array_merge(['lang' => 'en'], $seoFilters)) }}">
    @endif
    <style>
        :root {
            color-scheme: light;
            --wa-primary: #163B66;
            --wa-cta: #126DE1;
            --wa-accent: #00A999;
            --wa-base: #FFFFFF;
            --wa-subtle: #F5F9FC;
            --wa-muted-surface: #EAF5FB;
            --wa-strong: #142E4D;
            --wa-body: #315471;
            --wa-muted-text: #55718B;
            --wa-soft-border: #D3E3ED;
            --wa-radius: 26px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; min-height: 100vh; background: var(--wa-subtle); color: var(--wa-strong);
            font-family: Inter, Arial, sans-serif; line-height: 1.6; }
        :lang(ar) body { font-family: Tajawal, Arial, sans-serif; }
        a { color: inherit; }
        a:focus-visible { outline: 3px solid var(--wa-cta); outline-offset: 4px; border-radius: 6px; }
        .skip-link { position: absolute; inset-inline-start: 12px; top: -100px; background: var(--wa-base);
            color: var(--wa-primary); padding: 12px 18px; z-index: 10; }
        .skip-link:focus { top: 12px; }
        .site-wrap { width: min(100% - 40px, 1260px); margin-inline: auto; }
        .site-header { background: rgba(255,255,255,.96); border-bottom: 1px solid var(--wa-soft-border); }
        .header-row { min-height: 78px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px 24px; padding-block: 10px; }
        .brand { display: inline-flex; align-items: center; gap: 12px; text-decoration: none;
            color: var(--wa-primary); font-weight: 800; letter-spacing: -.02em; }
        .brand-mark { display: inline-grid; place-items: center; width: 42px; height: 42px;
            border-radius: 12px; background: var(--wa-primary); color: white; font-size: 23px; }
        .header-links { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .language-link { display: inline-flex; min-height: 44px; align-items: center;
            padding: 8px 16px; border: 1px solid var(--wa-soft-border);
            background: var(--wa-base); border-radius: 999px; text-decoration: none;
            font-weight: 700; color: var(--wa-primary); }
        .site-main { min-height: calc(100vh - 154px); }
        .site-footer { border-top: 1px solid var(--wa-soft-border); color: var(--wa-muted-text);
            background: var(--wa-base); padding-block: 20px; font-size: 14px; }
        .footer-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 24px; }
        @media (max-width: 600px) {
            .site-wrap { width: min(100% - 28px, 1260px); }
            .header-row { min-height: 70px; }
            .brand { font-size: 15px; }
            .header-links { gap: 8px; }
            .language-link { padding: 6px 10px; font-size: 13px; }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
    <link rel="sitemap" type="application/xml" href="{{ route('public.sitemap') }}">
    @stack('head')
    @stack('styles')
    @include('partials.web-fonts')
</head>
<body>
    <a class="skip-link" href="#main-content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
    <header class="site-header">
        <div class="site-wrap header-row">
            <a class="brand" href="{{ route('home', ['lang' => $locale]) }}" aria-label="{{ $locale === 'ar' ? 'الصفحة الرئيسية — وليد عطية' : 'Home — Waleed Atiya' }}"@if(request()->routeIs('home')) aria-current="page"@endif>
                <span class="brand-mark" aria-hidden="true">W</span>
                <span>{{ $locale === 'ar' ? 'وليد عطية' : 'Waleed Atiya' }}</span>
            </a>
            <nav class="header-links" aria-label="{{ $locale === 'ar' ? 'التنقل واللغة' : 'Navigation and language' }}">
                <a class="language-link" href="{{ route('public.about', ['lang' => $locale]) }}"@if(request()->routeIs('public.about')) aria-current="page"@endif>{{ $locale === 'ar' ? 'نبذة' : 'About' }}</a>
                <a class="language-link" href="{{ route('public.expertise', ['lang' => $locale]) }}"@if(request()->routeIs('public.expertise')) aria-current="page"@endif>{{ $locale === 'ar' ? 'الخبرات' : 'Expertise' }}</a>
                <a class="language-link" href="{{ route('public.hire', ['lang' => $locale]) }}"@if(request()->routeIs('public.hire')) aria-current="page"@endif>{{ $locale === 'ar' ? 'التوظيف' : 'Recruiter' }}</a>
                <a class="language-link" href="{{ route('public.projects', ['lang' => $locale]) }}"@if(request()->routeIs('public.projects', 'public.project')) aria-current="page"@endif>{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</a>
                <a class="language-link" href="{{ route('public.blog', ['lang' => $locale]) }}"@if(request()->routeIs('public.blog', 'public.article')) aria-current="page"@endif>{{ $locale === 'ar' ? 'المدونة' : 'Blog' }}</a>
                <a class="language-link" href="{{ route('public.lab', ['lang' => $locale]) }}"@if(request()->routeIs('public.lab', 'public.lab.runbook')) aria-current="page"@endif>{{ $locale === 'ar' ? 'المختبر' : 'Lab' }}</a>
                <a class="language-link" href="{{ route('public.search', ['lang' => $locale]) }}"@if(request()->routeIs('public.search')) aria-current="page"@endif>{{ $locale === 'ar' ? 'البحث' : 'Search' }}</a>
                <a class="language-link" href="{{ route('public.resume', ['lang' => $locale]) }}"@if(request()->routeIs('public.resume')) aria-current="page"@endif>{{ $locale === 'ar' ? 'السيرة العامة' : 'Resume' }}</a>
                <a class="language-link" href="{{ route('public.contact', ['lang' => $locale]) }}"@if(request()->routeIs('public.contact')) aria-current="page"@endif>{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a>
                <a class="language-link" href="{{ route('public.github', ['lang' => $locale]) }}"@if(request()->routeIs('public.github')) aria-current="page"@endif>
                    {{ $locale === 'ar' ? 'مشاريع GitHub' : 'GitHub projects' }}
                </a>
                @php($languageRoute = request()->routeIs('public.github', 'public.about', 'public.expertise', 'public.hire', 'public.projects', 'public.project', 'public.resume', 'public.contact', 'public.blog', 'public.article', 'public.lab', 'public.lab.runbook', 'public.search') ? request()->route()->getName() : 'home')
                @php($languageArgs = ['lang' => $locale === 'ar' ? 'en' : 'ar'])
                @if(request()->routeIs('public.project', 'public.article'))
                    @php($languageArgs['slug'] = request()->route('slug'))
                @endif
                @if(request()->routeIs('public.lab.runbook'))
                    @php($languageArgs['id'] = request()->route('id'))
                @endif
                @if(request()->routeIs('public.blog'))
                    @php($languageArgs['q'] = request()->query('q', ''))
                    @php($languageArgs['category'] = request()->query('category', ''))
                @endif
                @if(request()->routeIs('public.search'))
                    @php($languageArgs['q'] = request()->query('q', ''))
                @endif
                @if(request()->routeIs('public.projects'))
                    {{-- Keep project search/filter context on AR/EN links without
                         reflecting array-valued or unsupported query inputs. --}}
                    @php($projectParams = request()->query())
                    @php($rawProjectSearch = $projectParams['q'] ?? '')
                    @php($projectSearch = is_string($rawProjectSearch) ? mb_substr(trim($rawProjectSearch), 0, 80) : '')
                    @php($rawProjectStatus = $projectParams['status'] ?? '')
                    @php($projectStatus = is_string($rawProjectStatus) && in_array($rawProjectStatus, ['completed', 'in_progress', 'research', 'archived'], true) ? $rawProjectStatus : '')
                    @if($projectSearch !== '')
                        @php($languageArgs['q'] = $projectSearch)
                    @endif
                    @if($projectStatus !== '')
                        @php($languageArgs['status'] = $projectStatus)
                    @endif
                @endif
                @if(request()->routeIs('public.expertise'))
                    {{-- Preserve the actual server-side expertise filter, not
                         arbitrary/array-valued category parameters. --}}
                    @php($categoryParams = request()->query())
                    @php($selectedCategory = $categoryParams['category'] ?? '')
                    @if(is_string($selectedCategory)
                        && in_array($selectedCategory, ['database', 'integration', 'security', 'delivery'], true))
                        @php($languageArgs['category'] = $selectedCategory)
                    @endif
                @endif
                @if(request()->routeIs('public.resume', 'public.hire'))
                    @php($trackParams = request()->query())
                    @php($resumeTrack = $trackParams['track'] ?? 'all')
                    @php($languageArgs['track'] = is_string($resumeTrack) && in_array($resumeTrack, ['all', 'database', 'software', 'delivery'], true) ? $resumeTrack : 'all')
                @endif
                <a class="language-link" href="{{ route($languageRoute, $languageArgs) }}"
                   lang="{{ $locale === 'ar' ? 'en' : 'ar' }}"
                   hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}">
                    {{ $locale === 'ar' ? 'English' : 'العربية' }}
                </a>
            </nav>
        </div>
    </header>
    <main class="site-main" id="main-content" tabindex="-1">@yield('content')</main>
    <footer class="site-footer">
        <div class="site-wrap footer-row">
            <span>{{ $locale === 'ar' ? 'وليد عطية — الموقع المهني' : 'Waleed Atiya — Engineering portfolio' }}</span>
            <span>{{ $locale === 'ar' ? 'قيد التطوير والتحقق' : 'In development and verification' }}</span>
        </div>
    </footer>
</body>
</html>
