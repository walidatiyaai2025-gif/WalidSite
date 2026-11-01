@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="{{ $locale === 'ar' ? 'الموقع المهني لوليد عطية — تحت التطوير.' : 'The engineering portfolio of Waleed Atiya, currently in development.' }}">
    <title>@yield('title', 'Waleed Atiya — Engineering')</title>
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
    @stack('head')
    @stack('styles')
</head>
<body>
    <a class="skip-link" href="#main-content">{{ $locale === 'ar' ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
    <header class="site-header">
        <div class="site-wrap header-row">
            <a class="brand" href="{{ route('home', ['lang' => $locale]) }}" aria-label="{{ $locale === 'ar' ? 'الصفحة الرئيسية — وليد عطية' : 'Home — Waleed Atiya' }}">
                <span class="brand-mark" aria-hidden="true">W</span>
                <span>{{ $locale === 'ar' ? 'وليد عطية' : 'Waleed Atiya' }}</span>
            </a>
            <nav class="header-links" aria-label="{{ $locale === 'ar' ? 'التنقل واللغة' : 'Navigation and language' }}">
                <a class="language-link" href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة' : 'About' }}</a>
                <a class="language-link" href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الخبرات' : 'Expertise' }}</a>
                <a class="language-link" href="{{ route('public.hire', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التوظيف' : 'Recruiter' }}</a>
                <a class="language-link" href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'المشاريع' : 'Projects' }}</a>
                <a class="language-link" href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'المدونة' : 'Blog' }}</a>
                <a class="language-link" href="{{ route('public.lab', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'المختبر' : 'Lab' }}</a>
                <a class="language-link" href="{{ route('public.search', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'البحث' : 'Search' }}</a>
                <a class="language-link" href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'السيرة العامة' : 'Resume' }}</a>
                <a class="language-link" href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a>
                <a class="language-link" href="{{ route('public.github', ['lang' => $locale]) }}">
                    {{ $locale === 'ar' ? 'مشاريع GitHub' : 'GitHub projects' }}
                </a>
                @php($languageRoute = request()->routeIs('public.github', 'public.about', 'public.expertise', 'public.hire', 'public.projects', 'public.project', 'public.resume', 'public.contact', 'public.blog', 'public.article', 'public.lab', 'public.search') ? request()->route()->getName() : 'home')
                @php($languageArgs = ['lang' => $locale === 'ar' ? 'en' : 'ar'])
                @if(request()->routeIs('public.project', 'public.article'))
                    @php($languageArgs['slug'] = request()->route('slug'))
                @endif
                @if(request()->routeIs('public.blog'))
                    @php($languageArgs['q'] = request()->query('q', ''))
                    @php($languageArgs['category'] = request()->query('category', ''))
                @endif
                @if(request()->routeIs('public.search'))
                    @php($languageArgs['q'] = request()->query('q', ''))
                @endif
                @if(request()->routeIs('public.resume', 'public.hire'))
                    @php($localeTrack = request()->query('track', 'all'))
                    @php($languageArgs['track'] = is_string($localeTrack) && in_array($localeTrack, ['all', 'database', 'software', 'delivery'], true) ? $localeTrack : 'all')
                @endif
                <a class="language-link" href="{{ route($languageRoute, $languageArgs) }}"
                   lang="{{ $locale === 'ar' ? 'en' : 'ar' }}"
                   hreflang="{{ $locale === 'ar' ? 'en' : 'ar' }}">
                    {{ $locale === 'ar' ? 'English' : 'العربية' }}
                </a>
            </nav>
        </div>
    </header>
    <main class="site-main" id="main-content">@yield('content')</main>
    <footer class="site-footer">
        <div class="site-wrap footer-row">
            <span>{{ $locale === 'ar' ? 'وليد عطية — الموقع المهني' : 'Waleed Atiya — Engineering portfolio' }}</span>
            <span>{{ $locale === 'ar' ? 'قيد التطوير والتحقق' : 'In development and verification' }}</span>
        </div>
    </footer>
</body>
</html>
