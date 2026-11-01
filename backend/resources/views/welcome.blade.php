@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($ar = $locale === 'ar')
@extends('layouts.site')

@section('title', $ar ? 'وليد عطية | التقنية والمشاريع الموثقة' : 'Waleed Atiya | Engineering & Verified Work')

@push('styles')
<style>
    .landing { overflow: hidden; background: linear-gradient(145deg, #f5f9fc 0%, #fff 56%, #edf7fa 100%); }
    .landing-hero { position: relative; padding-block: clamp(28px,4vw,64px) 0; isolation: isolate; }
    .landing-hero::before { content: ''; position: absolute; z-index: -1; width: min(65vw,770px); aspect-ratio: 1;
        border-radius: 50%; inset-inline-end: -24vw; top: -28%; opacity: .42; pointer-events: none;
        background: repeating-radial-gradient(circle,transparent 0 24px,#c9e3ee 25px 26px,transparent 27px 52px); }
    .landing-grid { display: grid; grid-template-columns: minmax(260px,.88fr) minmax(0,1.12fr);
        gap: clamp(18px,4vw,58px); align-items: center; }
    .landing-portrait { position: relative; align-self: stretch; margin: 0; display: flex; align-items: flex-end; }
    .landing-portrait::before { content: ''; position: absolute; z-index: -1; inset: 10% 4% 0 4%;
        border-radius: 45% 45% 20px 20px; background: linear-gradient(150deg,#d6ecf4,#eaf6f9);
        box-shadow: 0 18px 55px rgba(22,59,102,.12); }
    .landing-portrait img { display: block; width: 100%; max-height: 580px; object-fit: contain; object-position: bottom center; }
    .landing-copy { padding-block: 14px 40px; }
    .landing-kicker { display: inline-flex; align-items: center; gap: 10px; color: #146a86;
        letter-spacing: .04em; font-size: 14px; font-weight: 800; }
    .landing-kicker::before { content: ''; width: 11px; height: 11px; border-radius: 50%; background: var(--wa-accent); }
    .landing-copy h1 { font-size: clamp(46px,6.5vw,88px); line-height: 1.05;
        letter-spacing: -.05em; color: #142e4d; margin: 18px 0 10px; }
    .landing-copy h1 span { color: #008cb6; }
    .landing-subtitle { margin: 0; font-size: clamp(21px,2.4vw,32px); line-height: 1.28;
        font-weight: 800; color: #183751; max-width: 21ch; }
    .landing-description { max-width: 56ch; color: var(--wa-body); font-size: 17px; line-height: 1.8; margin: 18px 0 22px; }
    .landing-accent-line { width: 72px; height: 4px; border-radius: 99px;
        background: linear-gradient(90deg,var(--wa-accent),var(--wa-cta)); }
    .landing-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-block: 25px 22px; }
    .landing-button { display: inline-flex; align-items: center; justify-content: center;
        min-height: 46px; border-radius: 999px; padding: 10px 22px;
        text-decoration: none; font-weight: 800; font-size: 15px; border: 2px solid var(--wa-primary); }
    .landing-button--primary { color: #fff; background: #126de1; border-color: #126de1; }
    .landing-button--secondary { color: #163b66; background: #fff; }
    .landing-button:hover,.landing-button:focus-visible { box-shadow: 0 7px 20px rgba(22,59,102,.15); }
    .landing-topics { display: flex; flex-wrap: wrap; gap: 9px; margin: 0; padding: 0; list-style: none; }
    .landing-topics li { padding: 7px 13px; border-radius: 10px; font-size: 13px; font-weight: 700;
        color: #24516f; border: 1px solid #d3e3ed; background: #fff; }
    .landing-workspace { position: relative; z-index: 1; margin-top: -18px; padding-bottom: 30px; }
    .device-row { display: grid; grid-template-columns: minmax(0,1.7fr) minmax(140px,.62fr) minmax(0,1.1fr);
        align-items: end; gap: clamp(12px,2vw,26px); }
    .device { display: block; text-decoration: none; color: #f2faff;
        border: 7px solid #263e52; background: #091e33; box-shadow: 0 15px 35px rgba(18,46,70,.18); overflow: hidden; }
    .device:focus-visible { outline: 4px solid var(--wa-cta); outline-offset: 5px; }
    .device--laptop { min-height: 216px; border-radius: 18px 18px 8px 8px; }
    .device--phone { border-radius: 25px; min-height: 248px; border-width: 8px; }
    .device--tablet { border-radius: 18px; min-height: 200px; }
    .device-top { display: flex; justify-content: space-between; gap: 10px; padding: 10px 14px;
        background: #102c45; font-size: 12px; font-weight: 800; }
    .device-mark { color: #30c6d2; font-weight: 900; letter-spacing: -.05em; }
    .device-screen { padding: clamp(14px,2vw,24px); }
    .device-screen h2 { font-size: clamp(17px,2.2vw,28px); line-height: 1.22; margin: 0 0 10px; color: white; }
    .device-screen p { margin: 0 0 16px; font-size: 13px; color: #bcd8e6; }
    .device-link { display: inline-block; border-radius: 999px; padding: 7px 14px; color: #07273a;
        background: #41d7ac; font-weight: 800; font-size: 12px; }
    .device--phone .device-screen { padding: 14px 12px; text-align: center; }
    .device--phone .device-screen h2 { font-size: 18px; }
    .device--phone .device-link { background: #49bfd9; color: #062739; }
    .device--tablet .device-link { background: #d9ecfb; }
    .capability-strip { background: #102d4d; border-radius: 20px; display: grid;
        grid-template-columns: repeat(5,minmax(0,1fr)); color: white; padding: 12px;
        box-shadow: 0 17px 35px rgba(17,44,73,.17); }
    .capability-strip a { padding: 15px 13px; color: white; text-decoration: none; min-height: 78px;
        display: flex; align-items: flex-start; gap: 9px; border-inline-end: 1px solid #406281; }
    .capability-strip a:last-child { border-inline-end: 0; }
    .capability-strip a:hover, .capability-strip a:focus-visible { background: #1f466f; border-radius: 12px; }
    .capability-symbol { font-size: 25px; line-height: 1.1; color: #6ce4e8; }
    .capability-strip strong { display: block; font-size: 14px; line-height: 1.35; }
    .capability-strip small { color: #c7dce8; font-size: 12px; line-height: 1.45; display: block; margin-top: 4px; }
    .landing-section { padding-block: clamp(42px,6vw,84px); }
    .landing-section--white { background: white; }
    .landing-section-heading { display: flex; justify-content: space-between; align-items: end;
        gap: 12px; margin-bottom: 25px; }
    .landing-section h2 { font-size: clamp(25px,3vw,38px); line-height: 1.2; margin: 0; color: var(--wa-primary); }
    .landing-section-heading a { color: #126de1; font-weight: 800; }
    .landing-cards { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 17px; }
    .landing-card { display: flex; flex-direction: column; gap: 10px; padding: 23px;
        border: 1px solid #d3e3ed; background: #fff; border-radius: 18px; min-height: 170px;
        box-shadow: 0 6px 20px rgba(22,59,102,.04); }
    .landing-card h3 { font-size: 20px; line-height: 1.3; margin: 0; color: #142e4d; }
    .landing-card p { color: var(--wa-body); margin: 0; }
    .landing-card a { margin-top: auto; color: #0965d2; font-weight: 800; }
    .landing-empty { border: 1px dashed #97b4c8; border-radius: 16px; padding: 22px;
        color: #315471; background: #f5f9fc; }
    .landing-evidence { max-width: 900px; }
    .landing-evidence li { margin-block: 13px; line-height: 1.6; }
    .landing-evidence a { color: #0965d2; font-weight: 700; }
    @media (max-width: 900px) {
        .landing-grid { grid-template-columns: minmax(180px,.8fr) minmax(0,1.2fr); }
        .landing-portrait img { max-height: 460px; }
        .device-row { grid-template-columns: minmax(0,1.4fr) minmax(125px,.65fr); }
        .device--tablet { display: none; }
        .capability-strip { grid-template-columns: repeat(2,minmax(0,1fr)); }
        .capability-strip a:nth-child(even) { border-inline-end: 0; }
        .landing-cards { grid-template-columns: repeat(2,minmax(0,1fr)); }
    }
    @media (max-width: 650px) {
        .landing-grid { grid-template-columns: 1fr; }
        .landing-copy { order: 1; padding-bottom: 8px; }
        .landing-portrait { order: 2; max-width: 330px; margin-inline: auto; width: 100%; }
        .landing-portrait img { max-height: 340px; }
        .landing-copy h1 { font-size: clamp(44px,12vw,64px); }
        .landing-workspace { margin-top: 0; }
        .device-row { grid-template-columns: 1fr minmax(100px,.55fr); gap: 8px; }
        .device { border-width: 4px; }
        .device--laptop { min-height: 192px; }
        .device--phone { min-height: 192px; border-radius: 17px; }
        .device-screen { padding: 10px; }
        .device-screen h2 { font-size: 16px; }
        .device--phone .device-screen h2 { font-size: 14px; }
        .device-screen p { font-size: 11px; }
        .device-top { padding: 8px; font-size: 10px; }
        .capability-strip { grid-template-columns: 1fr; padding: 9px; }
        .capability-strip a { border: 0; border-bottom: 1px solid #406281; min-height: 60px; }
        .capability-strip a:last-child { border-bottom: 0; }
        .landing-cards { grid-template-columns: 1fr; }
        .landing-section-heading { align-items: start; flex-direction: column; }
    }
    @media (prefers-reduced-motion: reduce) { .landing-button { transition: none; } }
</style>
@endpush

@section('content')
<div class="landing">
    <section class="landing-hero" aria-labelledby="landing-title">
        <div class="site-wrap landing-grid">
            <figure class="landing-portrait">
                <img src="{{ route('public.portrait') }}"
                    width="640" height="760" decoding="async" fetchpriority="high"
                    alt="{{ $ar ? 'الصورة الشخصية الأصلية المعتمدة لوليد عطية' : 'Approved real portrait of Waleed Atiya' }}">
            </figure>
            <div class="landing-copy">
                <span class="landing-kicker">{{ $ar ? 'وليد عطية | منصة تقنية شخصية' : 'Waleed Atiya | Personal technology platform' }}</span>
                <h1 id="landing-title"><span lang="en">Waleed</span> <span lang="en">Atiya</span></h1>
                <p class="landing-subtitle">{{ $ar ? 'هندسة البرمجيات وقواعد البيانات وحلول الذكاء الاصطناعي' : 'Software, Database & AI Engineering' }}</p>
                <p class="landing-description">{{ $ar
                    ? 'اكتشف مشاريع تقنية موثقة، ودراسات حالة، ومحتوى هندسي منشور بعد التحقق من المصادر. منصة تجمع الخبرات العملية والتعلم والتطوير.'
                    : 'Explore evidence-verified projects, engineering case studies and source-checked technical insights, with practical paths into software, data and AI.' }}</p>
                <div class="landing-accent-line" aria-hidden="true"></div>
                <div class="landing-actions">
                    <a class="landing-button landing-button--primary" href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $ar ? 'استكشف المشاريع' : 'Explore projects' }}</a>
                    <a class="landing-button landing-button--secondary" href="{{ route('public.github', ['lang' => $locale]) }}">{{ $ar ? 'مشاريع GitHub الموثقة' : 'Verified GitHub' }}</a>
                    <a class="landing-button landing-button--secondary" href="{{ route('public.resume', ['lang' => $locale]) }}">{{ $ar ? 'السيرة المهنية' : 'Public resume' }}</a>
                </div>
                <ul class="landing-topics" aria-label="{{ $ar ? 'المجالات التقنية' : 'Technology topics' }}">
                    <li>{{ $ar ? 'قواعد البيانات والبنية التحتية' : 'Databases & infrastructure' }}</li>
                    <li>{{ $ar ? 'تطوير البرمجيات' : 'Software engineering' }}</li>
                    <li>{{ $ar ? 'إدارة المشاريع' : 'Project delivery' }}</li>
                    <li>{{ $ar ? 'هندسة الذكاء الاصطناعي' : 'AI engineering' }}</li>
                </ul>
            </div>
        </div>
    </section>

    <div class="site-wrap landing-workspace" aria-label="{{ $ar ? 'مسارات استكشاف المنصة' : 'Platform discovery shortcuts' }}">
        <div class="device-row">
            <a class="device device--laptop" href="{{ route('public.projects', ['lang' => $locale]) }}">
                <div class="device-top"><span><span class="device-mark">W</span> Waleed Atiya</span><span>{{ $ar ? 'المنصة' : 'Portfolio' }}</span></div>
                <div class="device-screen">
                    <h2>{{ $ar ? 'المشاريع والأعمال الهندسية' : 'Engineering projects & case studies' }}</h2>
                    <p>{{ $ar ? 'أعمال موثقة بمصادر عامة' : 'Public work backed by source evidence' }}</p>
                    <span class="device-link">{{ $ar ? 'استعراض الأعمال' : 'Explore work' }}</span>
                </div>
            </a>
            <a class="device device--phone" href="{{ route('public.lab', ['lang' => $locale]) }}">
                <div class="device-top"><span class="device-mark">W</span><span>{{ $ar ? 'المعرفة' : 'Learn' }}</span></div>
                <div class="device-screen">
                    <h2>{{ $ar ? 'المختبر التقني' : 'Tech lab' }}</h2>
                    <p>{{ $ar ? 'أدوات ومواد للتعلم' : 'Engineering tools and learning' }}</p>
                    <span class="device-link">{{ $ar ? 'افتح المختبر' : 'Open lab' }}</span>
                </div>
            </a>
            <a class="device device--tablet" href="{{ route('public.about', ['lang' => $locale]) }}">
                <div class="device-top"><span class="device-mark">W</span><span>{{ $ar ? 'المسار المهني' : 'Professional profile' }}</span></div>
                <div class="device-screen">
                    <h2>{{ $ar ? 'الخبرات والمعلومات المتحقق منها' : 'Experience with verifiable evidence' }}</h2>
                    <p>{{ $ar ? 'نبذة مهنية ومراجع عامة عند توفرها' : 'Published professional profile and public references' }}</p>
                    <span class="device-link">{{ $ar ? 'اطّلع على النبذة' : 'View profile' }}</span>
                </div>
            </a>
        </div>
    </div>

    <nav class="site-wrap capability-strip" aria-label="{{ $ar ? 'مجالات المنصة' : 'Platform sections' }}">
        <a href="{{ route('public.expertise', ['lang' => $locale]) }}"><span class="capability-symbol" aria-hidden="true">▤</span><span><strong>{{ $ar ? 'قواعد البيانات والبنية التحتية' : 'Database & Infra' }}</strong><small>{{ $ar ? 'المهارات الهندسية' : 'Verified expertise' }}</small></span></a>
        <a href="{{ route('public.projects', ['lang' => $locale]) }}"><span class="capability-symbol" aria-hidden="true">⌘</span><span><strong>{{ $ar ? 'هندسة البرمجيات' : 'Software Engineering' }}</strong><small>{{ $ar ? 'المشاريع ودراسات الحالة' : 'Projects & case studies' }}</small></span></a>
        <a href="{{ route('public.hire', ['lang' => $locale]) }}"><span class="capability-symbol" aria-hidden="true">◇</span><span><strong>{{ $ar ? 'إدارة وتسليم المشاريع' : 'Project Delivery' }}</strong><small>{{ $ar ? 'الملف المهني' : 'Recruiter overview' }}</small></span></a>
        <a href="{{ route('public.github', ['lang' => $locale]) }}"><span class="capability-symbol" aria-hidden="true">⌁</span><span><strong>{{ $ar ? 'GitHub والتطوير' : 'GitHub & DevOps' }}</strong><small>{{ $ar ? 'المستودعات العامة' : 'Public repositories' }}</small></span></a>
        <a href="{{ route('public.blog', ['lang' => $locale]) }}"><span class="capability-symbol" aria-hidden="true">◈</span><span><strong>{{ $ar ? 'المعرفة والذكاء الاصطناعي' : 'AI & Knowledge' }}</strong><small>{{ $ar ? 'المقالات التقنية' : 'Technical articles' }}</small></span></a>
    </nav>

    @if (!empty($publicProfile['claims']))
        <section class="landing-section landing-section--white" aria-labelledby="landing-proof-title">
            <div class="site-wrap">
                <div class="landing-section-heading">
                    <h2 id="landing-proof-title">{{ $ar ? 'خبرات منشورة وموثقة' : 'Published professional evidence' }}</h2>
                    <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $ar ? 'المزيد عن الخبرة' : 'About the experience' }}</a>
                </div>
                <ul class="landing-evidence">
                    @foreach ($publicProfile['claims'] as $claim)
                        <li>{{ $claim['text'] }} — <a href="{{ $claim['evidence_url'] }}" target="_blank" rel="noopener noreferrer">{{ $ar ? 'المصدر' : 'Source' }}</a></li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    <section class="landing-section" aria-labelledby="landing-projects-title">
        <div class="site-wrap">
            <div class="landing-section-heading">
                <h2 id="landing-projects-title">{{ $ar ? 'مشاريع ودراسات حالة موثقة' : 'Verified projects & case studies' }}</h2>
                <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $ar ? 'كل المشاريع' : 'All projects' }}</a>
            </div>
            @if ($featuredProjects->isEmpty())
                <p class="landing-empty">{{ $ar ? 'لا توجد دراسات حالة عامة مستوفية لشروط النشر والتحقق حاليًا.' : 'No evidence-verified public case studies are available yet.' }}</p>
            @else
                <div class="landing-cards">
                    @foreach ($featuredProjects as $project)
                        <article class="landing-card">
                            <h3>{{ $project['name'] }}</h3>
                            <p>{{ \Illuminate\Support\Str::limit($project['summary'] ?? '', 155) }}</p>
                            <a href="{{ route('public.project', ['slug' => $project['slug'], 'lang' => $locale]) }}">{{ $ar ? 'تفاصيل ودليل العمل' : 'Case study & evidence' }}</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="landing-section landing-section--white" aria-labelledby="landing-articles-title">
        <div class="site-wrap">
            <div class="landing-section-heading">
                <h2 id="landing-articles-title">{{ $ar ? 'أحدث المقالات التقنية الموثقة' : 'Latest source-checked articles' }}</h2>
                <a href="{{ route('public.blog', ['lang' => $locale]) }}">{{ $ar ? 'المدونة التقنية' : 'Technical blog' }}</a>
            </div>
            @if ($featuredArticles->isEmpty())
                <p class="landing-empty">{{ $ar ? 'لا توجد مقالات منشورة وموثقة حاليًا.' : 'No source-verified published articles are available yet.' }}</p>
            @else
                <div class="landing-cards">
                    @foreach ($featuredArticles as $article)
                        <article class="landing-card">
                            <h3>{{ $ar ? $article->title_ar : $article->title_en }}</h3>
                            <p>{{ $ar ? 'مقالة تقنية منشورة بعد مراجعة المصادر' : 'Published technical article with verified references' }}</p>
                            <a href="{{ route('public.article', ['slug' => $article->slug, 'lang' => $locale]) }}">{{ $ar ? 'قراءة المقال' : 'Read article' }}</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
