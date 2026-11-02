@php($t007Locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($t007Current = request()->route()?->getName())
<header class="t007-nav-shell" aria-label="{{ $t007Locale === 'ar' ? 'التنقل الرئيسي' : 'Primary navigation' }}">
    <a class="t007-brand" href="{{ route('home', ['lang' => $t007Locale]) }}">
        <span aria-hidden="true">WA</span><strong>Waleed Atiya</strong>
    </a>
    <nav class="t007-nav-links">
        <a @class(['active' => $t007Current === 'home']) @if($t007Current === 'home') aria-current="page" @endif href="{{ route('home', ['lang' => $t007Locale]) }}">Home</a>
        <a @class(['active' => $t007Current === 'public.about']) @if($t007Current === 'public.about') aria-current="page" @endif href="{{ route('public.about', ['lang' => $t007Locale]) }}">About</a>
        <a @class(['active' => $t007Current === 'public.expertise']) @if($t007Current === 'public.expertise') aria-current="page" @endif href="{{ route('public.expertise', ['lang' => $t007Locale]) }}">Expertise</a>
        <a href="{{ route('public.projects', ['lang' => $t007Locale]) }}">Projects</a>
        <a @class(['active' => $t007Current === 'public.ai-engineering']) @if($t007Current === 'public.ai-engineering') aria-current="page" @endif href="{{ route('public.ai-engineering', ['lang' => $t007Locale]) }}">AI Lab</a>
        <a href="{{ route('public.blog', ['lang' => $t007Locale]) }}">Blog</a>
        <a href="{{ route('public.github', ['lang' => $t007Locale]) }}">GitHub</a>
        <a href="{{ route('public.resume', ['lang' => $t007Locale]) }}">Resume</a>
    </nav>
    <a class="t007-contact" href="{{ route('public.contact', ['lang' => $t007Locale]) }}">Get in touch</a>
    <a class="t007-sr-only" href="{{ url()->current().'?lang='.($t007Locale === 'ar' ? 'en' : 'ar') }}">
        {{ $t007Locale === 'ar' ? 'English' : 'العربية' }}
    </a>
</header>
