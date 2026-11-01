@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')

@php($titles = [
    'about' => ['en' => 'About and experience', 'ar' => 'نبذة وخبرات'],
    'expertise' => ['en' => 'Verified expertise', 'ar' => 'الخبرات التقنية الموثقة'],
    'hire' => ['en' => 'Recruiter overview', 'ar' => 'للجهات الباحثة عن الخبرات'],
])
@section('title', $titles[$page][$locale].' — Waleed Atiya')
@php($seoDescriptions = [
    'about' => [
        'en' => 'Explore Waleed Atiya public career history and credentials available after evidence review.',
        'ar' => 'اطّلع على الخبرات المهنية والشهادات المنشورة لوليد عطية بعد مراجعة الأدلة.',
    ],
    'expertise' => [
        'en' => 'Explore Waleed Atiya engineering skills approved for public display after evidence verification.',
        'ar' => 'استكشف مهارات وليد عطية الهندسية المعتمدة للنشر بعد التحقق من أدلتها.',
    ],
    'hire' => [
        'en' => 'Filter evidence-reviewed engineering specialties and open the verified public resume by career track.',
        'ar' => 'صفِّ مجالات الخبرة الهندسية المتحقق منها وافتح السيرة العامة حسب المسار المهني.',
    ],
])
@section('meta_description', $seoDescriptions[$page][$locale])

@push('styles')
<style>
    .professional-page { padding-block: clamp(34px, 5vw, 70px) 80px; }
    .professional-heading { max-width: 780px; margin-bottom: 34px; }
    .professional-heading h1 { font-size: clamp(32px, 4vw, 52px); line-height: 1.2; color: var(--wa-primary); margin: 10px 0 14px; }
    .professional-heading p { margin: 0; color: var(--wa-body); font-size: 17px; }
    .professional-kicker { font-size: 13px; color: #126453; font-weight: 800; letter-spacing: .03em; }
    .professional-columns { display: grid; grid-template-columns: minmax(0,1fr) minmax(260px,.38fr); gap: 26px; align-items: start; }
    .professional-panel { background: var(--wa-base); padding: clamp(20px,3vw,32px);
        border: 1px solid var(--wa-soft-border); border-radius: 22px; }
    .professional-panel h2 { color: var(--wa-primary); margin: 0 0 18px; font-size: 24px; }
    .professional-panel p { color: var(--wa-body); margin: 8px 0; }
    .professional-item { padding-block: 17px; border-top: 1px solid var(--wa-soft-border); }
    .professional-item:first-of-type { border-top: 0; padding-top: 0; }
    .professional-item h3 { font-size: 18px; margin: 0 0 6px; color: var(--wa-strong); }
    .professional-item small { color: var(--wa-muted-text); }
    .professional-tags { display: flex; flex-wrap: wrap; gap: 10px; }
    .professional-tags span { background: var(--wa-muted-surface); color: var(--wa-primary);
        padding: 9px 12px; border: 1px solid var(--wa-soft-border); border-radius: 9px; font-weight: 700; }
    .professional-links { display: flex; flex-wrap: wrap; gap: 10px; }
    .professional-links a { display: inline-flex; align-items: center; min-height: 44px;
        border-radius: 10px; text-decoration: none; padding: 10px 16px; font-weight: 800;
        background: var(--wa-primary); color: white; }
    .professional-links a.secondary { border: 1px solid var(--wa-soft-border); background: white; color: var(--wa-primary); }
    .professional-empty { background: var(--wa-subtle); border: 1px dashed var(--wa-soft-border);
        border-radius: 12px; padding: 19px; color: var(--wa-body); }
    .professional-evidence { margin: 0; padding-inline-start: 24px; }
    .professional-evidence li { padding-block: 9px; border-top: 1px solid var(--wa-soft-border); }
    .professional-evidence a { color: var(--wa-cta); font-weight: 800; text-underline-offset: 3px; }
    .professional-filter { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; margin-bottom: 24px; }
    .professional-filter label { display: block; font-weight: 700; color: var(--wa-primary); margin-bottom: 6px; }
    .professional-filter select { min-width: 200px; min-height: 44px; padding: 9px; font: inherit;
        background: white; color: var(--wa-strong); border: 1px solid #97B4C8; border-radius: 10px; }
    .professional-filter button { background: var(--wa-cta); color: white; border: none;
        border-radius: 10px; padding: 11px 18px; font: inherit; font-weight: 800; min-height: 44px; cursor: pointer; }
    @media (max-width: 850px) { .professional-columns { grid-template-columns:1fr; } }
</style>
@endpush

@section('content')
<div class="site-wrap professional-page">
    <header class="professional-heading">
        <span class="professional-kicker">{{ $locale === 'ar' ? 'المعلومات العامة المثبتة فقط' : 'Evidence-gated public profile' }}</span>
        <h1>{{ $titles[$page][$locale] }}</h1>
        <p>{{ $locale === 'ar'
          ? 'المعلومات المهنية تُعرض بعد مراجعة المصادر والموافقة على النشر؛ لا تُعرض شهادات أو خبرات غير موثقة.'
          : 'Professional details appear only after source verification and publication review. Unverified credentials and career claims remain private.' }}</p>
    </header>

    <div class="professional-columns">
        <section class="professional-panel" aria-labelledby="professional-section">
            @if ($page === 'about')
                <h2 id="professional-section">{{ $locale === 'ar' ? 'الخبرة المهنية' : 'Career experience' }}</h2>
                @forelse ($positions as $position)
                    <article class="professional-item">
                        <h3>{{ $locale === 'ar' ? $position->role_ar : $position->role_en }}</h3>
                        <p>{{ $position->org_name }}</p>
                        <small>{{ $position->started_on->format('Y') }} — {{ $position->ended_on ? $position->ended_on->format('Y') : ($locale === 'ar' ? 'حتى الآن' : 'Present') }}</small>
                    </article>
                @empty
                    <div class="professional-empty" role="status">{{ $locale === 'ar' ? 'لا توجد خبرات منشورة ومتحقق منها بعد.' : 'No career entries have completed publication and evidence review yet.' }}</div>
                @endforelse

                <h2 style="margin-top:28px">{{ $locale === 'ar' ? 'الشهادات المتحقَّق منها' : 'Verified certifications' }}</h2>
                @forelse ($certifications as $certification)
                    <article class="professional-item"><h3>{{ $certification->title }}</h3>
                        @if ($certification->issuer)<p>{{ $certification->issuer }}</p>@endif
                    </article>
                @empty
                    <div class="professional-empty" role="status">{{ $locale === 'ar' ? 'الشهادات قيد مراجعة الأدلة، ولن تُعرض قبل التحقق.' : 'Credentials remain hidden until supporting evidence is verified.' }}</div>
                @endforelse

                @if (count($approvedClaims) > 0)
                    <h2 style="margin-top:28px">{{ $locale === 'ar' ? 'الادعاءات المهنية المدعومة بالأدلة' : 'Evidence-backed professional statements' }}</h2>
                    <ul class="professional-evidence">
                        @foreach ($approvedClaims as $claim)
                            <li>
                                <span>{{ $claim['text'] }}</span>
                                <a href="{{ $claim['evidence_url'] }}" target="_blank" rel="noopener noreferrer"
                                   aria-label="{{ $locale === 'ar' ? 'افتح دليل: ' : 'Open evidence for: ' }}{{ $claim['text'] }}">{{ $locale === 'ar' ? 'عرض الدليل' : 'View evidence' }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @elseif ($page === 'expertise')
                {{-- Canonical skill category values are persisted in English and drive
                     recruiter filters. Only the verified public heading is localized. --}}
                @php($categoryLabelsAr = [
                    'Database & Reliability' => 'قواعد البيانات والموثوقية',
                    'Software & Integration' => 'البرمجيات والتكامل',
                    'Infrastructure & Security' => 'البنية التحتية والأمن',
                    'Project Delivery' => 'إدارة وتسليم المشاريع',
                ])
                <h2 id="professional-section">{{ $locale === 'ar' ? 'المهارات المعتمدة' : 'Evidence-verified skills' }}</h2>
                @if ($skills->isEmpty())
                    <div class="professional-empty" role="status">{{ $locale === 'ar' ? 'لم تُعتمد مهارات للنشر بعد.' : 'No skills have been approved with verified evidence yet.' }}</div>
                @else
                    @foreach ($skills->groupBy('category') as $category => $group)
                        <div class="professional-item">
                            <h3>{{ $locale === 'ar' ? ($categoryLabelsAr[$category] ?? $category) : $category }}</h3>
                            <div class="professional-tags">@foreach ($group as $skill)<span>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</span>@endforeach</div>
                        </div>
                    @endforeach
                @endif
            @else
                <h2 id="professional-section">{{ $locale === 'ar' ? 'مطابقة المجال المهني' : 'Explore relevant experience' }}</h2>
                <form class="professional-filter" action="{{ route('public.hire') }}" method="get">
                    <input type="hidden" name="lang" value="{{ $locale }}">
                    <div>
                        <label for="career-track">{{ $locale === 'ar' ? 'المجال' : 'Career track' }}</label>
                        <select id="career-track" name="track">
                            <option value="all" @selected($track === 'all')>{{ $locale === 'ar' ? 'كل المجالات' : 'All areas' }}</option>
                            <option value="database" @selected($track === 'database')>{{ $locale === 'ar' ? 'قواعد البيانات' : 'Database engineering' }}</option>
                            <option value="software" @selected($track === 'software')>{{ $locale === 'ar' ? 'البرمجيات والبنية التحتية' : 'Software and infrastructure' }}</option>
                            <option value="delivery" @selected($track === 'delivery')>{{ $locale === 'ar' ? 'إدارة المشاريع' : 'Project delivery' }}</option>
                        </select>
                    </div>
                    <button type="submit">{{ $locale === 'ar' ? 'عرض النتائج' : 'Show matches' }}</button>
                </form>
                @if ($skills->isEmpty())
                    <div class="professional-empty" role="status">{{ $locale === 'ar' ? 'لا توجد مهارات موثقة ومتاحة في هذا المجال الآن.' : 'No evidence-verified skills are currently available for this track.' }}</div>
                @else
                    <div class="professional-tags">@foreach ($skills as $skill)<span>{{ $locale === 'ar' ? $skill->name_ar : $skill->name_en }}</span>@endforeach</div>
                @endif
            @endif
        </section>

        <aside class="professional-panel" aria-labelledby="professional-next">
            <h2 id="professional-next">{{ $locale === 'ar' ? 'استكشف الموقع' : 'Continue exploring' }}</h2>
            <p>{{ $locale === 'ar'
                ? 'استعرض السيرة العامة المعتمدة بالمسار المهني المختار، أو افتح صفحة التواصل لمعرفة حالة استقبال الرسائل.'
                : 'Review the evidence-verified public resume for your selected focus, or open the contact page to check message availability.' }}</p>
            <div class="professional-links">
                <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'مشاريع GitHub' : 'GitHub projects' }}</a>
                <a class="secondary" href="{{ route('public.about', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'نبذة وخبرات' : 'About' }}</a>
                <a class="secondary" href="{{ route('public.expertise', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'المهارات' : 'Expertise' }}</a>
                <a class="secondary" href="{{ route('public.hire', ['lang' => $locale, 'track' => $page === 'hire' ? $track : 'all']) }}">{{ $locale === 'ar' ? 'التوظيف' : 'Recruiter' }}</a>
                <a class="secondary" href="{{ route('public.resume', ['lang' => $locale, 'track' => $page === 'hire' ? $track : 'all']) }}">{{ $locale === 'ar' ? 'السيرة العامة حسب المجال' : 'View focused public resume' }}</a>
                <a class="secondary" href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التواصل' : 'Contact' }}</a>
            </div>
        </aside>
    </div>
</div>
@endsection
