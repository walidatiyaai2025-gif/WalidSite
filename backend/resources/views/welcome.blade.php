@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')

@section('title', $locale === 'ar' ? 'وليد عطية — الموقع المهني' : 'Waleed Atiya — Engineering Portfolio')

@push('styles')
<style>
    .hero-section { padding-block: clamp(38px, 6vw, 92px); }
    .hero-grid { display: grid; grid-template-columns: minmax(0, 1.18fr) minmax(280px, .82fr);
        align-items: center; gap: clamp(32px, 6vw, 90px); }
    .hero-copy { max-width: 690px; }
    .eyebrow { display: inline-flex; align-items: center; gap: 10px; color: var(--wa-primary);
        font-size: 14px; font-weight: 800; letter-spacing: .04em; }
    .eyebrow::before { content: ''; display: inline-block; width: 12px; height: 12px;
        border-radius: 50%; background: var(--wa-accent); }
    .hero-copy h1 { margin: 20px 0 18px; font-size: clamp(42px, 5.2vw, 74px);
        line-height: 1.12; letter-spacing: -.045em; color: var(--wa-primary); }
    .hero-copy p { margin: 0; max-width: 62ch; color: var(--wa-body);
        font-size: clamp(16px, 1.5vw, 19px); line-height: 1.85; }
    .discipline-tags { display: flex; flex-wrap: wrap; margin-top: 25px; gap: 10px; }
    .discipline-tags span { border: 1px solid var(--wa-soft-border); color: var(--wa-primary);
        background: var(--wa-base); border-radius: 999px; padding: 7px 14px; font-size: 14px; font-weight: 700; }
    .build-status { display: inline-flex; align-items: center; gap: 10px; padding: 10px 15px;
        margin-top: 29px; border: 1px solid #CBEAE8; border-radius: 12px;
        color: #115B58; background: #ECFAF7; font-weight: 700; font-size: 14px; }
    .build-status::before { content: ''; width: 10px; height: 10px; border-radius: 50%;
        background: var(--wa-accent); flex: none; }
    .portrait-frame { position: relative; margin: 0; isolation: isolate;
        padding: 14px; border-radius: 32px; background: linear-gradient(145deg, #fff, #e9f3f9);
        box-shadow: 0 18px 58px rgba(22, 59, 102, .10); border: 1px solid var(--wa-soft-border); }
    .portrait-frame::before { content: ''; position: absolute; z-index: -1; inset: -18px 22px 26px -18px;
        border: 2px solid #C9DFEA; border-radius: 38px; }
    .portrait-frame img { width: 100%; height: auto; max-height: 540px; object-fit: contain; display: block;
        background: #f1f6f9; border-radius: 22px; }
    .portrait-frame figcaption { text-align: center; font-size: 13px;
        color: var(--wa-muted-text); padding: 10px 4px 2px; }
    @media (max-width: 900px) {
        .hero-grid { grid-template-columns: 1fr; gap: 32px; }
        .hero-copy { max-width: none; }
        .portrait-frame { max-width: 510px; margin-inline: auto; }
    }
    @media (max-width: 600px) {
        .hero-section { padding-block: 32px 50px; }
        .hero-copy h1 { font-size: clamp(39px, 11vw, 54px); }
        .portrait-frame::before { display: none; }
    }
</style>
@endpush

@section('content')
<section class="hero-section">
    <div class="site-wrap hero-grid">
        <div class="hero-copy">
            <span class="eyebrow">{{ $locale === 'ar' ? 'التقنية والخبرة الهندسية' : 'Engineering and technology' }}</span>
            <h1>Waleed Atiya</h1>
            @if ($locale === 'ar')
                <p>منصة مهنية قيد الإنشاء لتقديم أعمال تقنية موثّقة، ومعرفة عملية في هندسة البرمجيات وقواعد البيانات والذكاء الاصطناعي. ستُنشر التفاصيل عند التحقق من مصادرها واكتمال تطويرها.</p>
            @else
                <p>An engineering portfolio in progress. Verified technical work and practical knowledge in software, databases and AI will be published as the underlying features and source evidence are completed.</p>
            @endif

            <div class="discipline-tags" aria-label="{{ $locale === 'ar' ? 'مجالات المحتوى' : 'Content areas' }}">
                <span>{{ $locale === 'ar' ? 'قواعد البيانات' : 'Databases' }}</span>
                <span>{{ $locale === 'ar' ? 'تطوير البرمجيات' : 'Software engineering' }}</span>
                <span>{{ $locale === 'ar' ? 'الذكاء الاصطناعي' : 'Artificial intelligence' }}</span>
            </div>
            <div class="build-status" role="status">
                {{ $locale === 'ar' ? 'المنصة قيد التطوير والتحقق' : 'Platform foundation in development' }}
            </div>
            <p style="margin-top: 22px; display:flex; flex-wrap:wrap; gap:18px">
                <a href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة وخبرات موثقة' : 'About and experience' }}</a>
                <a href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'الخبرات التقنية' : 'Technical expertise' }}</a>
                <a href="{{ route('public.hire', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'لجهات التوظيف' : 'Recruiter overview' }}</a>
            </p>
            <p style="margin-top: 18px"><a href="{{ route('public.github', ['lang' => $locale]) }}">
                {{ $locale === 'ar' ? 'استعراض المشاريع العامة الموثقة على GitHub' : 'Browse verified public GitHub projects' }}
            </a></p>
        </div>

        <figure class="portrait-frame">
            <img src="{{ 'https://raw.githubusercontent.com/walidatiyaai2025-gif/WalidSite/main/assets/portraits/portrait-light-derived.jpg' }}"
                 alt="{{ $locale === 'ar' ? 'الصورة الشخصية الأصلية المعتمدة لوليد عطية، بخلفية فاتحة' : 'Approved real portrait of Waleed Atiya against a light background' }}"
                 width="640" height="760" decoding="async" fetchpriority="high">
            <figcaption>{{ $locale === 'ar' ? 'صورة المالك المعتمدة' : 'Owner-approved portrait' }}</figcaption>
        </figure>
    </div>
</section>
@endsection
