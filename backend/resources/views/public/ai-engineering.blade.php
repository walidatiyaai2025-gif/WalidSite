@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@php($ar = $locale === 'ar')
@extends('layouts.site')

@section('title', $ar ? 'هندسة الذكاء الاصطناعي — وليد عطية' : 'AI Engineering — Waleed Atiya')
@section('meta_description', $ar
    ? 'قدرات هندسة الذكاء الاصطناعي المتاحة بعد التحقق مع ملاحظات السلامة.'
    : 'Evidence-gated AI engineering capabilities with explicit safety boundaries.')

{{-- WEB-06 owns these styles. Do not change the shared T007 reference stage:
     other W2 workers are integrating WEB-01/02/03 in parallel. --}}
@push('styles')
<style>
    .ai-evidence-panel {
        padding-block: 48px 76px;
        color: #142E4D;
        background: #fff;
        scroll-margin-block-start: 24px;
    }
    .ai-evidence-eyebrow {
        margin: 0 0 10px;
        color: #008D83;
        font-size: 14px;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .ai-evidence-panel h2 {
        margin: 0 0 12px;
        color: #184876;
        font-size: clamp(26px, 3vw, 34px);
        line-height: 1.24;
    }
    .ai-evidence-lead {
        max-width: 76ch;
        margin: 0 0 24px;
        color: #315471;
        line-height: 1.75;
    }
    .ai-evidence-index {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 26px;
    }
    .ai-evidence-index a {
        display: inline-flex;
        min-height: 44px;
        align-items: center;
        padding: 9px 16px;
        color: #184876;
        background: #EDF7FC;
        border: 1px solid #D3E3ED;
        border-radius: 12px;
        font-weight: 700;
        text-decoration: none;
    }
    .ai-evidence-index a:hover { background: #DCF6F1; }
    .ai-evidence-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 22px;
        align-items: start;
    }
    .ai-evidence-card {
        min-width: 0;
        padding: 24px;
        border: 1px solid #D3E3ED;
        border-radius: 16px;
        background: #F5F9FC;
        scroll-margin-block-start: 20px;
        overflow-wrap: anywhere;
    }
    .ai-evidence-card h3 {
        margin: 0 0 15px;
        font-size: 22px;
        line-height: 1.25;
        color: #184876;
    }
    .ai-evidence-card ul { margin: 0; padding-inline-start: 21px; }
    .ai-evidence-card li { margin-block: 9px; line-height: 1.6; color: #315471; }
    .ai-evidence-card p { margin: 0 0 14px; line-height: 1.65; color: #315471; }
    .ai-evidence-card p:last-child { margin-bottom: 0; }
    .ai-evidence-card [role="status"] {
        padding: 14px;
        background: white;
        border-inline-start: 4px solid #00A999;
        border-radius: 9px;
    }
    .ai-evidence-card a {
        display: inline-flex;
        min-height: 44px;
        align-items: center;
        color: #007C74;
        font-weight: 700;
        text-underline-offset: 3px;
    }
    .ai-evidence-card a + a { margin-inline-start: 14px; }
    .ai-evidence-panel a:focus-visible {
        outline: 3px solid #126DE1;
        outline-offset: 4px;
    }
    @media (max-width: 900px) {
        .ai-evidence-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px) {
        .ai-evidence-panel { padding-block: 28px 48px; }
        .ai-evidence-grid { grid-template-columns: 1fr; }
        .ai-evidence-card { padding: 20px; }
        .ai-evidence-index a { flex: 1 1 auto; justify-content: center; }
    }
    @media (prefers-reduced-motion: reduce) {
        .ai-evidence-panel { scroll-behavior: auto; }
    }
</style>
@endpush

@section('content')
@include('public.t007-reference-stage', ['screen' => 'ai'])

{{-- The visually approved first viewport stays sourced from T007. Everything
     below it is real API-backed evidence, never an illustrated provider claim. --}}
<section class="site-wrap t007-functional ai-evidence-panel" id="ai-evidence"
         aria-labelledby="ai-evidence-title">
    <p class="ai-evidence-eyebrow">{{ $ar ? 'الأدلة العامة' : 'Public evidence' }}</p>
    <h2 id="ai-evidence-title">{{ $ar ? 'هندسة الذكاء الاصطناعي — المراجعة والأدلة' : 'AI engineering — review and evidence' }}</h2>
    <p class="ai-evidence-lead">{{ $ar
        ? 'تظهر هنا فقط المهارات التي اجتازت التحقق والنشر. وجود موضوع تقني في التصميم لا يعني تنفيذ نموذج أو مزوّد أو مشروع فعلي.'
        : 'Only reviewed, published skills are listed here. A topic in the reference design is not proof of a deployed model, provider, workflow, or project.' }}</p>
    <nav class="ai-evidence-index" aria-label="{{ $ar ? 'الانتقال داخل أدلة الذكاء الاصطناعي' : 'AI engineering evidence sections' }}">
        <a href="#ai-skills">{{ $ar ? 'المهارات الموثقة' : 'Verified skills' }}</a>
        <a href="#ai-safety">{{ $ar ? 'حدود السلامة' : 'Safety boundaries' }}</a>
        <a href="#ai-public-work">{{ $ar ? 'المصادر العامة' : 'Public work' }}</a>
    </nav>
    <div class="ai-evidence-grid">
        <section class="ai-evidence-card" id="ai-skills" aria-labelledby="ai-skills-title">
            <h3 id="ai-skills-title">{{ $ar ? 'القدرات المعتمدة للعرض' : 'Evidence-approved AI capabilities' }}</h3>
            @if(empty($aiData['skills']))
                <p role="status">{{ $ar
                    ? 'لا توجد قدرات ذكاء اصطناعي اجتازت بوابة الأدلة للنشر حاليًا.'
                    : 'No AI capabilities have cleared the public evidence gate yet.' }}</p>
            @else
                <ul>
                    @foreach($aiData['skills'] as $skill)
                        <li>{{ $skill['name'] }} <span>— {{ $skill['category'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section class="ai-evidence-card" id="ai-safety" aria-labelledby="ai-safety-title">
            <h3 id="ai-safety-title">{{ $ar ? 'حدود السلامة' : 'Safety boundaries' }}</h3>
            @forelse(($aiData['safety_notes'] ?? []) as $note)
                @if($loop->first)<ul>@endif
                    <li>{{ $note }}</li>
                @if($loop->last)</ul>@endif
            @empty
                <p role="status">{{ $ar
                    ? 'لم تُنشر ملاحظات سلامة معتمدة بعد؛ لا يوجد إثبات لتشغيل آلي.'
                    : 'No approved safety notes are published yet; no automated operation is implied.' }}</p>
            @endforelse
        </section>
        <section class="ai-evidence-card" id="ai-public-work" aria-labelledby="ai-public-work-title">
            <h3 id="ai-public-work-title">{{ $ar ? 'الأدلة ودراسات الحالة' : 'Evidence and case studies' }}</h3>
            @if(empty($aiData['verified_demos']) && empty($aiData['workflows']))
                <p role="status">{{ $ar
                    ? 'لا يتم عرض عروض أو تدفقات عمل على أنها موثقة دون ارتباط أدلة مستقل.'
                    : 'No demo or workflow is presented as verified without an independently approved evidence association.' }}</p>
            @endif
            <p>{{ $ar
                ? 'تُعرض أدلة المشاريع والمستودعات العامة عبر صفحاتها المخصصة مع تطبيق شروط النشر.'
                : 'Explore projects and public repositories through their own publication and evidence gates.' }}</p>
            <a href="{{ route('public.projects', ['lang' => $locale]) }}">{{ $ar ? 'دراسات الحالة الموثقة' : 'Verified case studies' }}</a>
            <a href="{{ route('public.github', ['lang' => $locale]) }}">{{ $ar ? 'مشاريع GitHub العامة' : 'Public GitHub projects' }}</a>
        </section>
    </div>
</section>
@endsection
