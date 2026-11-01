@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')

@section('title', $locale === 'ar' ? 'مشاريع GitHub — وليد عطية' : 'GitHub Portfolio — Waleed Atiya')

@push('styles')
<style>
    .portfolio-page { padding-block: clamp(32px, 5vw, 70px) 72px; }
    .portfolio-heading { max-width: 70ch; margin-bottom: 30px; }
    .portfolio-heading h1 { margin: 8px 0 12px; color: var(--wa-primary); font-size: clamp(32px, 4vw, 50px); line-height: 1.2; }
    .portfolio-heading p { color: var(--wa-body); font-size: 17px; }
    .portfolio-meta { font-size: 13px; color: var(--wa-muted-text); }
    .portfolio-filter { display: flex; align-items: end; flex-wrap: wrap; gap: 14px; margin-bottom: 30px;
        background: var(--wa-base); border: 1px solid var(--wa-soft-border);
        border-radius: 18px; padding: 18px; }
    .portfolio-field { flex: 1 1 210px; }
    .portfolio-field label { display: block; color: var(--wa-primary); font-weight: 700; margin-bottom: 6px; }
    .portfolio-field input, .portfolio-field select { width: 100%; min-height: 44px; padding: 10px 12px;
        color: var(--wa-strong); border: 1px solid #97B4C8; border-radius: 10px; background: #fff; font: inherit; }
    .portfolio-field :is(input,select):focus-visible { outline: 3px solid var(--wa-cta); outline-offset: 2px; }
    .portfolio-submit { border: none; border-radius: 10px; min-height: 44px; padding: 10px 22px; font: inherit;
        font-weight: 700; color: #fff; background: var(--wa-cta); cursor: pointer; }
    .portfolio-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px),1fr)); gap: 20px; }
    .repo-card { border: 1px solid var(--wa-soft-border); background: var(--wa-base);
        border-radius: 18px; padding: 24px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
    .repo-card h2 { font-size: 20px; overflow-wrap: anywhere; line-height: 1.4; margin: 0; color: var(--wa-primary); }
    .repo-card p { color: var(--wa-body); margin: 0; overflow-wrap: anywhere; }
    .repo-card .repo-evidence { color: var(--wa-muted-text); font-size: 13px; }
    .repo-card .repo-link { display: inline-flex; align-items: center; color: var(--wa-cta); font-weight: 800; min-height: 44px; margin-top: auto; }
    .repo-status { font-size: 13px; font-weight: 700; }
    .repo-status.verified { color: #126B4B; }
    .repo-status.unavailable { color: #8B4D09; }
    .portfolio-notice { padding: 28px; border: 1px solid var(--wa-soft-border); border-radius: 18px;
        background: var(--wa-base); color: var(--wa-body); }
    @media (max-width: 640px) { .portfolio-submit { width: 100%; } .repo-card { padding: 20px; } }
</style>
@endpush

@section('content')
<section class="site-wrap portfolio-page" aria-labelledby="portfolio-title">
    <div class="portfolio-heading">
        <p class="portfolio-meta">{{ $locale === 'ar' ? 'مستودعات عامة موثقة المصدر فقط' : 'Source-verified public repositories only' }}</p>
        <h1 id="portfolio-title">{{ $locale === 'ar' ? 'مشاريعي على GitHub' : 'GitHub portfolio' }}</h1>
        <p>{{ $locale === 'ar'
            ? 'تُعرض المشاريع العامة المسموح بها فقط بعد التحقق من بيانات GitHub. لا نعرض مستودعات خاصة أو أرقام مساهمات غير موثقة.'
            : 'Only allowlisted public repositories verified against GitHub are shown. Private repositories and unverified contribution counts are never displayed.' }}</p>
    </div>

    <form class="portfolio-filter" action="{{ route('public.github') }}" method="get" role="search" aria-label="{{ $locale === 'ar' ? 'البحث والتصفية في المشاريع' : 'Search and filter repositories' }}">
        <input type="hidden" name="lang" value="{{ $locale }}">
        <div class="portfolio-field">
            <label for="repo-query">{{ $locale === 'ar' ? 'البحث' : 'Search' }}</label>
            <input id="repo-query" name="q" type="search" maxlength="80" value="{{ $search }}"
                   placeholder="{{ $locale === 'ar' ? 'اسم المشروع أو وصفه' : 'Repository name or description' }}">
        </div>
        <div class="portfolio-field">
            <label for="repo-language">{{ $locale === 'ar' ? 'لغة البرمجة' : 'Language' }}</label>
            <select id="repo-language" name="language">
                <option value="">{{ $locale === 'ar' ? 'كل اللغات' : 'All languages' }}</option>
                @foreach ($languages as $languageOption)
                    <option value="{{ $languageOption }}" @selected($language === $languageOption)>{{ $languageOption }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="portfolio-submit">{{ $locale === 'ar' ? 'تطبيق البحث' : 'Apply filters' }}</button>
    </form>

    @if (count($repositories) === 0)
        <div class="portfolio-notice" role="status">
            {{ $locale === 'ar'
                ? 'لا توجد مستودعات عامة معتمدة للعرض حاليًا. سيُعرض المحتوى بعد التحقق وتفعيل القائمة المسموح بها.'
                : 'No approved public repositories to show yet. Content will appear when the allowlist is configured and public data is verified.' }}
        </div>
    @elseif (count($filtered) === 0)
        <div class="portfolio-notice" role="status">
            {{ $locale === 'ar' ? 'لا توجد نتائج تطابق خيارات البحث الحالية.' : 'No repositories match the selected filters.' }}
        </div>
    @else
        <div class="portfolio-grid">
            @foreach ($filtered as $repo)
                <article class="repo-card">
                    @if (($repo['status'] ?? null) === 'verified' && isset($repo['url']))
                        <h2>{{ $repo['full_name'] }}</h2>
                    @else
                        {{-- An unverified allowlist entry could be private or
                             deleted; never disclose its configured slug. --}}
                        <h2>{{ $locale === 'ar' ? 'مستودع غير متاح' : 'Repository unavailable' }}</h2>
                    @endif
                    @if (($repo['status'] ?? null) === 'verified' && isset($repo['url']))
                        <div class="repo-status verified">{{ $locale === 'ar' ? 'تم التحقق من ظهوره العام' : 'Public visibility verified' }}</div>
                        @if (! empty($repo['description']))
                            <p>{{ $repo['description'] }}</p>
                        @endif
                        @if (! empty($repo['language']))
                            <p class="repo-evidence">{{ $locale === 'ar' ? 'اللغة' : 'Language' }}: {{ $repo['language'] }}</p>
                        @endif
                        <p class="repo-evidence">{{ $locale === 'ar' ? 'آخر تحقق' : 'Last verified' }}:
                            <time datetime="{{ $repo['verified_at'] }}">{{ $repo['verified_at'] }}</time>
                        </p>
                        <a class="repo-link" href="{{ $repo['url'] }}" target="_blank" rel="noopener noreferrer"
                           aria-label="{{ $locale === 'ar' ? 'فتح المستودع العام على GitHub' : 'Open public GitHub repository' }}: {{ $repo['full_name'] }}">
                            {{ $locale === 'ar' ? 'فتح المستودع' : 'View repository' }}
                        </a>
                    @else
                        <div class="repo-status unavailable">{{ $locale === 'ar' ? 'التحقق غير متاح — الرابط مخفي' : 'Verification unavailable — link hidden' }}</div>
                        <p>{{ $locale === 'ar' ? 'لن يُعرض رابط قبل تأكيد ظهور المستودع العام.' : 'The link remains hidden until the repository is confirmed public.' }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
