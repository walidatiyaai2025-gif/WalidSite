@if(!request()->routeIs('home'))
@php($t007Locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($t007Current = request()->route()?->getName())
<header class="t007-nav-shell" aria-label="{{ $t007Locale === 'ar' ? 'التنقل الرئيسي' : 'Primary navigation' }}">
    <a class="t007-brand" @if($t007Current === 'home') aria-current="page" @endif href="{{ route('home', ['lang' => $t007Locale]) }}">
        <span aria-hidden="true">WA</span><strong>{{ $t007Locale === 'ar' ? 'وليد عطية' : 'Waleed Atiya' }}</strong>
    </a>
    <nav class="t007-nav-links" aria-label="{{ $t007Locale === 'ar' ? 'روابط صفحات الموقع' : 'Site pages' }}">
        <a @class(['active' => $t007Current === 'home']) href="{{ route('home', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'الرئيسية' : 'Home' }}</a>
        <a @class(['active' => $t007Current === 'public.about']) @if($t007Current === 'public.about') aria-current="page" @endif href="{{ route('public.about', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'نبذة' : 'About' }}</a>
        <a @class(['active' => $t007Current === 'public.expertise']) @if($t007Current === 'public.expertise') aria-current="page" @endif href="{{ route('public.expertise', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'الخبرات' : 'Expertise' }}</a>
        <a href="{{ route('public.projects', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'المشاريع' : 'Projects' }}</a>
        <a @if($t007Current === 'public.ai-engineering') aria-current="page" @endif href="{{ route('public.ai-engineering', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'مختبر AI' : 'AI Lab' }}</a>
        <a href="{{ route('public.blog', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'المدونة' : 'Blog' }}</a>
        <a href="{{ route('public.github', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'GitHub' : 'GitHub' }}</a>
        <a href="{{ route('public.resume', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'السيرة العامة' : 'Resume' }}</a>
    </nav>
    <a class="t007-contact" href="{{ route('public.contact', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'تواصل' : 'Get in touch' }}</a>
    @php($t007SwitchArgs = ['lang' => $t007Locale === 'ar' ? 'en' : 'ar'])
    @php($t007SwitchRoute = $t007Current ?? 'home')
    @if($t007Current === 'public.hire')
        @php($t007RawTrack = request()->query()['track'] ?? 'all')
        @php($t007SwitchArgs['track'] = is_string($t007RawTrack) && in_array($t007RawTrack, ['all', 'database', 'software', 'delivery'], true) ? $t007RawTrack : 'all')
    @endif
    @if($t007Current === 'public.expertise')
        {{-- Mirror canonical SEO's allowlist: invalid or all means an unfiltered URL. --}}
        @php($t007RawCategory = request()->query()['category'] ?? null)
        @if(is_string($t007RawCategory) && in_array($t007RawCategory, ['database', 'integration', 'security', 'delivery'], true))
            @php($t007SwitchArgs['category'] = $t007RawCategory)
        @endif
    @endif
    {{-- The visible locale switch is rendered in the page footer so the
         reference header remains exact while bilingual access stays visible,
         keyboard-operable and available after the primary screen content. --}}
    @if($t007Current === 'public.hire')
        <a class="t007-sr-only" aria-current="page" href="{{ route('public.hire', ['lang' => $t007Locale, 'track' => $t007SwitchArgs['track'] ?? 'all']) }}">{{ $t007Locale === 'ar' ? 'التوظيف' : 'Recruiter' }}</a>
    @endif
    <a class="t007-sr-only" href="{{ route('public.lab', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'المختبر التقني' : 'Tech lab' }}</a>
    <a class="t007-sr-only" href="{{ route('public.search', ['lang' => $t007Locale]) }}">{{ $t007Locale === 'ar' ? 'البحث في الموقع' : 'Search' }}</a>
</header>

@endif
