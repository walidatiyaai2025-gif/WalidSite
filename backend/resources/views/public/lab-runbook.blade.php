@php($locale = request()->query('lang') === 'ar' ? 'ar' : 'en')
@extends('layouts.site')
@section('title', e($runbook['title']).' — Waleed Atiya')
@push('head')
<link rel="canonical" href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => $locale]) }}">
<link rel="alternate" hreflang="ar" href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => 'ar']) }}">
<link rel="alternate" hreflang="en" href="{{ route('public.lab.runbook', ['id' => $runbook['id'], 'lang' => 'en']) }}">
@endpush
@push('styles')
<style>
.runbook-shell{max-width:920px;padding-block:clamp(30px,6vw,75px)}
.runbook-shell h1{font-size:clamp(32px,5vw,48px);line-height:1.3;color:var(--wa-primary);overflow-wrap:anywhere}
.runbook-nav{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:18px}
.runbook-nav a{color:var(--wa-cta);font-weight:700;display:inline-flex;min-height:44px;align-items:center}
.runbook-meta,.runbook-note{color:var(--wa-muted-text);font-size:14px}
.runbook-body{margin-block:24px;padding:clamp(18px,4vw,40px);background:#fff;border:1px solid var(--wa-soft-border);border-radius:20px;overflow-wrap:anywhere}
.runbook-body :is(h2,h3,h4){line-height:1.4;color:var(--wa-primary)}
.runbook-body pre{background:#132C47;color:#fff;border-radius:10px;padding:20px;overflow-x:auto;direction:ltr;text-align:left}
.runbook-body :not(pre)>code{background:var(--wa-muted-surface);padding:2px 5px;border-radius:4px}
.runbook-body a{color:var(--wa-cta);overflow-wrap:anywhere}
.runbook-copy-action{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin-block:12px 8px;padding:9px 16px;border:0;border-radius:10px;background:var(--wa-cta);color:#fff;font:inherit;font-weight:700;cursor:pointer}
.runbook-copy-action:focus-visible{outline:3px solid var(--wa-primary);outline-offset:3px}
</style>
@endpush
@section('content')
<article class="site-wrap runbook-shell" aria-labelledby="runbook-title">
    <nav class="runbook-nav" aria-label="{{ $locale === 'ar' ? 'تنقل الدليل' : 'Runbook navigation' }}">
        <a href="{{ route('public.lab', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'العودة إلى المختبر التقني' : 'Back to technology lab' }}</a>
        <a href="{{ route('public.contact', ['lang' => $locale]) }}">{{ $locale === 'ar' ? 'التبليغ عن مشكلة بالمحتوى' : 'Report a content issue' }}</a>
    </nav>
    <p class="runbook-meta">{{ $locale === 'ar' ? 'دليل تشغيل معتمد بنسخة محددة' : 'Version-reviewed operational guide' }}</p>
    <h1 id="runbook-title">{{ $runbook['title'] }}</h1>
    <p class="runbook-meta">
        {{ $locale === 'ar' ? 'النسخة' : 'Version' }} {{ $runbook['version'] }}
        · <time datetime="{{ $runbook['validated_at'] }}">{{ \Illuminate\Support\Carbon::parse($runbook['validated_at'])->format('Y-m-d') }}</time>
    </p>
    <div class="runbook-body" id="runbook-content">
        {!! \Illuminate\Support\Str::markdown($runbook['markdown'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
    </div>
    <p id="runbook-copy-feedback" class="runbook-note" role="status" aria-live="polite" aria-atomic="true"></p>
    <p class="runbook-note">{{ $locale === 'ar'
        ? 'هذا الدليل حاصل على مراجعة للنص والنسخة، وليس إثباتًا لإجراء اختبار تشغيل في بيئة حقيقية.'
        : 'Review covers the published text and version; it is not proof of a live execution test.' }}</p>
</article>
<script>
(() => {
    const feedback = document.getElementById('runbook-copy-feedback');
    const arabic = document.documentElement.lang === 'ar';
    document.querySelectorAll('#runbook-content pre').forEach((pre) => {
        // Copy only displayed, version-approved text; never execute runbook commands.
        const code = pre.querySelector('code');
        if (!code || pre.nextElementSibling?.classList.contains('runbook-copy-action')) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'runbook-copy-action';
        button.textContent = arabic ? 'نسخ الكود' : 'Copy code';
        button.addEventListener('click', async () => {
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                feedback.textContent = arabic
                    ? 'نسخ الكود غير متاح في هذا المتصفح'
                    : 'Code copying is unavailable in this browser';
                return;
            }
            try {
                await navigator.clipboard.writeText(code.textContent || '');
                feedback.textContent = arabic ? 'تم نسخ الكود' : 'Code copied';
            } catch (_) {
                feedback.textContent = arabic ? 'تعذر نسخ الكود' : 'Could not copy code';
            }
        });
        pre.insertAdjacentElement('afterend', button);
    });
})();
</script>
@endsection
